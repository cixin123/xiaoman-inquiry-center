<?php
/**
 * Ad return check (beta.20; owner decisions 2026-11-23).
 * A consented visitor who reached the site from Google search without ad parameters ("Google 自然搜索"),
 * but whose same IP (or same IPv6 /64) opened a Google Ads landing page in the previous N hours (default 72)
 * according to the server access log, is labelled "Google Ads" and gets that landing's campaign codes: the first entry
 * was the ad click. The note "之前点过广告：系列 xx，xx 分钟前" and attribution.ad_return keep it distinguishable.
 * The visitor accepted consent before submitting; the earlier ad page view happened before consent and left
 * nothing in the browser, so only the server log can link it.
 * Click ids (gclid, gbraid, wbraid, ...) are never copied. Older ad visits (beyond the window) stay natural search.
 * Off unless the site setting ad_return_logs names the access log files. The log is read only, with a time and
 * size budget; any failure leaves the inquiry unchanged.
 */
if (!defined('ABSPATH')) return;
final class XI_Ad_Return_V1 {
 const LABEL='Google Ads';
 const PLATFORM='google_ads';
 const NATURAL='Google 自然搜索';
 const MAX_BYTES=314572800;
 const MAX_SECONDS=3.0;
 const CLICK_KEYS=array('gclid','gbraid','wbraid','dclid','fbclid','msclkid','ttclid');
 static function settings() {
  $c=class_exists('XI_Config')?XI_Config::get():array();
  $glob=is_string($c['ad_return_logs']??null)?trim($c['ad_return_logs']):'';
  $hours=(int)($c['ad_return_hours']??72);
  return array('glob'=>$glob,'hours'=>($hours>=1&&$hours<=168)?$hours:72);
 }
 static function eligible($attribution,$ip) {
  return is_array($attribution) && ($attribution['consent_status']??'')==='accepted'
   && ($attribution['source_label']??'')===self::NATURAL && is_string($ip) && filter_var($ip,FILTER_VALIDATE_IP)!==false;
 }
 // A logged request path is an ad landing when this plugin's own source rules would label it Google Ads.
 static function is_ad($path) {
  return strpos($path,'?')!==false && XI_Inquiry_Source_V1::label(array('R4'=>'https://'.XI_HOST.$path,'R3'=>''))==='Google Ads';
 }
 // Latest ad landing from this visitor in [now - hours, now]: array(ts, path, match) or null.
 static function find($ip,$now,$hours,$glob) {
  $from=$now-$hours*3600; $start=microtime(true); $read=0; $best=null;
  $v6=strpos($ip,':')!==false; $prefix=$v6?substr((string)@inet_pton($ip),0,8):'';
  $files=glob($glob); if(!$files) return null;
  foreach($files as $f) {
   if(!is_file($f) || !is_readable($f)) continue;
   $mtime=@filemtime($f); if($mtime!==false && $mtime<$from) continue; // the file ends before the window
   $gz=substr($f,-3)==='.gz'; if($gz && !function_exists('gzopen')) continue;
   $fh=$gz?@gzopen($f,'rb'):@fopen($f,'rb'); if(!$fh) continue;
   try {
    while(($line=$gz?gzgets($fh,16384):fgets($fh,16384))!==false) {
     $read+=strlen($line);
     if($read>self::MAX_BYTES || microtime(true)-$start>self::MAX_SECONDS) return $best;
     $sp=strpos($line,' '); if($sp===false || $sp>45) continue;
     $lip=substr($line,0,$sp); $match='';
     if($lip===$ip) $match='ip';
     elseif($v6 && $prefix!=='' && strpos($lip,':')!==false) { $b=@inet_pton($lip); if($b!==false && substr($b,0,8)===$prefix) $match='v6/64'; }
     if($match==='') continue;
     if(!preg_match('/\[(\d{2})\/(\w{3})\/(\d{4}):(\d{2}):(\d{2}):(\d{2}) ([+-]\d{4})\] "GET (\/\S*)/',$line,$m)) continue;
     $ts=strtotime("$m[1] $m[2] $m[3] $m[4]:$m[5]:$m[6] $m[7]");
     if(!$ts || $ts<$from || $ts>$now || ($best && $ts<=$best['ts']) || !self::is_ad($m[8])) continue;
     $best=array('ts'=>$ts,'path'=>$m[8],'match'=>$match);
    }
   } finally { $gz?gzclose($fh):fclose($fh); }
  }
  return $best;
 }
 static function note($minutes,$campaign) {
  $m=max(0,(int)$minutes);
  $when=$m<120?$m.' 分钟前':(int)round($m/60).' 小时前';
  return '之前点过广告：系列 '.($campaign!==''?$campaign:'（未带系列代码）').'，'.$when;
 }
 static function apply(&$attribution,&$hd,$hit,$now,$hours) {
  $ad=XI_Request_Attribution_V1::parse('https://'.XI_HOST.$hit['path'],'',$attribution['consent_status'],'current_request');
  if(!$ad) return false;
  foreach(self::CLICK_KEYS as $k) $ad[$k]='';
  foreach(XI_Request_Attribution_V1::KEYS as $k) if(!in_array($k,self::CLICK_KEYS,true) && ($ad[$k]??'')!=='') $attribution[$k]=$ad[$k];
  foreach(array('campaign_id','campaign_name','adgroup_id','adgroup_name','ad_id','ad_name','keyword') as $k) if(($ad[$k]??'')!=='') $attribution[$k]=$ad[$k];
  $minutes=max(0,(int)round(($now-$hit['ts'])/60));
  $legacy=XI_Request_Attribution_V1::legacy($ad); $legacy['A5']='';
  foreach($legacy as $k=>$v) if($v!=='' && ($hd[$k]??'')==='') $hd[$k]=$v;
  $attribution['source_label']=self::LABEL; $attribution['source_platform']=self::PLATFORM;
  $attribution['ad_return']=array('minutes'=>$minutes,'landing_path'=>(string)$ad['landing_path'],'window_hours'=>(int)$hours,'match'=>$hit['match']);
  $hd['A8']=self::note($minutes,$legacy['A1']);
  return true;
 }
 static function check(&$attribution,&$hd,$ip,$now=null) {
  try {
   $s=self::settings();
   if($s['glob']==='' || !is_array($hd) || !self::eligible($attribution,$ip)) return false;
   $now=$now===null?time():(int)$now;
   $hit=self::find($ip,$now,$s['hours'],$s['glob']);
   return $hit?self::apply($attribution,$hd,$hit,$now,$s['hours']):false;
  } catch(Throwable $e) { return false; }
 }
}
