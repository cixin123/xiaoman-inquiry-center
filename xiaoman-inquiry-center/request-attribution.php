<?php
// Request context, not a visitor identifier. No cookies, sessions or page-view DB writes.
if (!defined('ABSPATH')) return;
final class XI_Request_Attribution_V1 {
 const HOST = XI_HOST;
 const INTERNAL_UNKNOWN = '未知（站内访问，未关联首次来源）';
 const KEYS = array('utm_source','utm_medium','utm_campaign','utm_content','utm_term','utm_id','utm_adgroup','utm_device','utm_matchtype','utm_network','utm_creative',
  'gclid','gbraid','wbraid','dclid','fbclid','msclkid','ttclid','gad_source','gad_campaignid',
  'campaign','campaignid','campaign_id','campaign_name','adgroup','adgroupid','adgroup_id','adgroup_name',
  'adset_name','adset_id','ad','creative','creative_id','adid','ad_id','ad_name',
  'keyword','kw','matchtype','mt','device','network','placement','targetid','target_id','loc','loc_physical_ms');
 static $initial = null;
 static function clean($v) {
  if(!is_string($v) || strlen($v)>2000) return '';
  $v=preg_replace('/[\x00-\x1f\x7f]/','',$v);
  $v=sanitize_text_field($v);
  return preg_match('/[{}]|__[A-Z_]+__/',$v)?'':substr(trim($v),0,500);
 }
 static function parts($url,$internal=true) {
  if(!is_string($url) || strlen($url)>16000) return array();
  $u=wp_parse_url($url);
  if(!is_array($u) || !in_array($u['scheme']??'',array('http','https'),true) || empty($u['host'])
   || isset($u['user']) || isset($u['pass']) || isset($u['port'])) return array();
  if(!preg_match('/^[a-z0-9.-]+$/iD',$u['host'])) return array();
  if($internal && strtolower($u['host'])!==self::HOST) return array();
  return $u;
 }
 static function safe_url($url,$internal=true,$params=true) {
  $u=self::parts($url,$internal); if(!$u) return '';
  $path=$u['path']??'/';
  if(strlen($path)>1800 || preg_match('/[\x00-\x20\x7f<>"\\\\]/',$path)) return '';
  $q=array();
  if($internal && $params) {
   parse_str($u['query']??'',$raw);
   foreach(self::KEYS as $k) { $v=self::clean($raw[$k]??''); if($v!=='') $q[$k]=$v; }
  }
  $base=($internal?'https://'.self::HOST:$u['scheme'].'://'.strtolower($u['host'])).$path;
  return esc_url_raw($base.($q?'?'.http_build_query($q,'','&',PHP_QUERY_RFC3986):''),array('http','https'));
 }
 static function pick($q,$keys) { foreach($keys as $k) if(($q[$k]??'')!=='') return $q[$k]; return ''; }
 static function consent($v) { return in_array($v,array('unknown','accepted','rejected'),true)?$v:'unknown'; }
 static function parse($url,$ref='',$consent='unknown',$basis='current_request') {
  $landing=self::safe_url($url); if($landing==='') return array();
  $q=XI_Inquiry_Source_V1::query($landing);
  $a=array('schema_version'=>1,'landing_url'=>$landing,'landing_ad_url'=>$landing,
   'landing_path'=>wp_parse_url($landing,PHP_URL_PATH),'referrer'=>self::safe_url($ref,false,false));
  foreach(self::KEYS as $k) $a[$k]=$q[$k]??'';
  // Google Ads click id / ValueTrack ID 本身不等于广告名称。
  // Named fields never borrow an ID. Ambiguous legacy campaign/adgroup/ad stay raw codes.
  $aliases=array('campaign_id'=>array('campaign_id','campaignid','utm_id'),
   'campaign_name'=>array('campaign_name','utm_campaign'),
   'adgroup_id'=>array('adgroup_id','adgroupid','adset_id'),
   'adgroup_name'=>array('adgroup_name','adset_name'),
   'ad_id'=>array('ad_id','creative_id','adid','creative'),
   'ad_name'=>array('ad_name','utm_content','utm_creative'),'keyword'=>array('keyword','utm_term','kw'));
  foreach($aliases as $key=>$keys) $a[$key]=self::pick($q,$keys);
  $a['consent_status']=self::consent($consent);
  $a['capture_basis']=in_array($basis,array('current_request','server_submit_referrer','current_page_fallback','consented_first_touch'),true)?$basis:'current_request';
  $a['first_touch_scope']=$basis==='consented_first_touch'?'consented_history':'current_page_only';
  $a['source_label']=XI_Inquiry_Source_V1::label(array('R4'=>$landing,'R3'=>$a['referrer']));
  if($a['source_label']==='直接访问 / 来源未提供' && $q) $a['source_label']='营销来源未确定';
  $label=$a['source_label'];
  $platforms=array('Google Ads'=>'google_ads','Google 广告'=>'google_ads','Facebook 广告'=>'meta_ads','Meta 广告'=>'meta_ads',
   'Instagram 广告'=>'meta_ads','Microsoft Ads'=>'microsoft_ads','Bing 广告'=>'microsoft_ads','TikTok 广告'=>'tiktok_ads',
   'Google SEO'=>'google_organic','Google 自然搜索'=>'google_organic','Google 搜索（广告/自然未确定）'=>'google_search',
   'ChatGPT 推荐'=>'chatgpt','直接访问 / 来源未提供'=>'direct_or_unknown','营销来源未确定'=>'unknown','未知（渠道标记冲突）'=>'conflict');
  $a['source_platform']=$platforms[$label]??(strpos($label,'Facebook')===0||strpos($label,'Instagram')===0?'meta_unspecified':'other_or_unknown');
  // Without a retained, consented visit we cannot prove that a clean internal page is Direct.
  if($a['source_platform']==='direct_or_unknown' && wp_parse_url($a['referrer'],PHP_URL_HOST)===self::HOST) {
   $a['source_platform']='unknown';$a['source_label']=self::INTERNAL_UNKNOWN;
  }
  return $a;
 }
 static function from_server($server) {
  if(!is_array($server) || strtoupper($server['REQUEST_METHOD']??'GET')!=='GET') return array();
  $host=strtolower(is_string($server['HTTP_HOST']??null)?$server['HTTP_HOST']:'');
  $uri=is_string($server['REQUEST_URI']??null)?wp_unslash($server['REQUEST_URI']):'';
  if($host!==self::HOST || $uri==='' || $uri[0]!=='/' || substr($uri,0,2)==='//' || strlen($uri)>16000) return array();
  $path=wp_parse_url($uri,PHP_URL_PATH);
  if(!is_string($path) || preg_match('#^/(wp-admin|wp-json|wp-login\.php|wp-cron\.php)(/|$)#',$path)) return array();
  // Use the site's fixed HTTPS origin, never a supplied Host / forwarded protocol in output.
  return self::parse('https://'.self::HOST.$uri,wp_unslash($server['HTTP_REFERER']??''));
 }
 static function capture() {
  if(is_admin() || (defined('REST_REQUEST') && REST_REQUEST) || (function_exists('wp_doing_ajax') && wp_doing_ajax())) return;
  self::$initial=self::from_server($_SERVER); self::debug(self::$initial);
  // Never embed this visitor's URL/IDs in shared cached HTML. Cache hits use the JS fallback.
 }
 static function packet($request) {
  $raw=XI_Compatibility::packet($request);
  $p=is_string($raw)&&strlen($raw)<=24000?json_decode($raw,true):null;
  return is_array($p)?$p:array();
 }
 // Without consent, form-context.js may carry where the visit entered along internal links ('#xi=', never stored):
 // an external site's origin, or '' when the entry page had no referrer. Anything else is ignored (null).
 static function carried_entry($r) {
  if(!is_array($r) || !array_key_exists('entry_referrer',$r) || !is_string($r['entry_referrer'])) return null;
  $v=$r['entry_referrer']; if($v==='') return '';
  if(strlen($v)>210 || !preg_match('#^(https?)://([a-z0-9.-]+)/$#iD',$v,$m)) return null;
  $host=strtolower($m[2]);
  return $host===self::HOST || $host==='www.'.self::HOST ? null : strtolower($m[1]).'://'.$host.'/';
 }
 static function submission($request,$server) {
  $p=self::packet($request); $r=is_array($p['_request']??null)?$p['_request']:array();
  $tracking=is_array($p['_tracking']??null)?$p['_tracking']:array();
  $source=is_array($p['_source']??null)?$p['_source']:array();
  $consent=self::consent($r['consent_status']??'');
  $header=self::safe_url(wp_unslash($server['HTTP_REFERER']??''));
  $client=self::safe_url($r['landing_ad_url']??'');
  $same_page=$header==='' || ($client!=='' && wp_parse_url($header,PHP_URL_PATH)===wp_parse_url($client,PHP_URL_PATH));
  // Only a matching current-page snapshot is eligible without history permission.
  $a=$header!==''?self::parse($header,$same_page?($r['referrer']??''):'',$consent,'server_submit_referrer'):array();
  $header_q=$header!==''?XI_Inquiry_Source_V1::query($header):array();
  if($same_page && $client!=='' && (!$a || !$header_q)) $a=self::parse($client,$r['referrer']??'',$consent,'current_page_fallback');
  $client_q=$client!==''?XI_Inquiry_Source_V1::query($client):array();
  // The frontend keeps one explicitly tagged landing page in sessionStorage for
  // this tab. It is valid request evidence even when the consent history API is
  // unavailable; it contains no PII and expires with the 30-minute visit.
  
  if(!$header_q && !$client_q && $same_page && ($consent==='accepted' && XI_Compatibility::history_valid($source))) {
   $history=self::parse($source['landing']??'',$source['ref']??'',$consent,
    $consent==='accepted'?'consented_first_touch':'current_page_fallback');
   if($history) $a=$history;
  }
  // No consent: a clean internal page can still name the visit's entry source when the browser carried it.
  if($consent!=='accepted' && $a && ($a['source_label']??'')===self::INTERNAL_UNKNOWN && $same_page && $client!=='') {
   $entry=self::carried_entry($r);
   $carried=$entry!==null?self::parse($client,$entry,$consent,'current_page_fallback'):array();
   if($carried) { $carried['first_touch_scope']='carried_entry_source'; $carried['previous_page']=(string)($a['referrer']??''); $a=$carried; }
  }
  // An old client without a current-page packet keeps its original legacy handling unless
  // the submit HTTP Referer itself contains explicit marketing parameters.
  if(!$r && !$header_q) return array();
  self::debug($a); return $a;
 }
 static function legacy($a) {
  return array('A1'=>self::pick($a,array('campaign_name','campaign','campaign_id')),'A2'=>self::pick($a,array('keyword','kw')),
   'A3'=>self::pick($a,array('adgroup_name','adgroup','adgroup_id','utm_adgroup')),'A4'=>self::pick($a,array('device','utm_device')),
   'A5'=>self::pick($a,array('gclid','fbclid')),'A6'=>self::pick($a,array('loc','loc_physical_ms')),
   'A7'=>self::pick($a,array('ad_name','ad','ad_id','utm_content','utm_creative')));
 }
 static function repair_legacy($values) {
  $values=is_array($values)?$values:array();
  foreach(array('A1','A2','A3','A4','A5','A6','A7') as $key) if(!isset($values[$key]) || !is_string($values[$key])) $values[$key]='';
  foreach(array('R2','R4','H1') as $url_key) {
   $url=is_string($values[$url_key]??null)?$values[$url_key]:'';
   $parsed=self::parse($url);
   if(!$parsed) continue;
   $candidate=self::legacy($parsed);
   if($values['A5']!=='' && $candidate['A5']!=='' && $values['A5']!==$candidate['A5']) continue;
   foreach($candidate as $key=>$value) if($values[$key]==='' && $value!=='') $values[$key]=$value;
  }
  return $values;
 }
 static function debug_view($a) {
  if(!$a) return array();
  $out=array('landing_ad_url'=>'https://'.self::HOST.$a['landing_path']);
  foreach(array('source_platform','utm_source','utm_medium','campaign_id','adgroup_id','ad_id','gclid','fbclid','consent_status') as $k) {
   $out[$k]=in_array($k,array('source_platform','consent_status'),true)?$a[$k]:($a[$k]!==''?'[present]':'');
  }
  $out['final_source']=$a['source_platform']; return $out;
 }
 static function debug($a) {
  if(defined('FWXM_ATTRIBUTION_DEBUG') && FWXM_ATTRIBUTION_DEBUG===true && defined('WP_DEBUG') && WP_DEBUG===true && $a)
   error_log('[Attribution] '.wp_json_encode(self::debug_view($a)));
 }
 static function remark($a) {
  if(empty($a['schema_version']) || empty($a['source_label'])) return '';
  $lines=array('询盘来源: '.self::clean($a['source_label']));
  foreach(array('campaign_id'=>'Campaign ID','campaign_name'=>'Campaign 名称','adgroup_id'=>'Ad Group ID',
   'adgroup_name'=>'Ad Group 名称','ad_id'=>'Ad ID','ad_name'=>'Ad 名称') as $k=>$label) {
   $v=self::clean($a[$k]??''); if($v!=='') $lines[]=$label.': '.$v;
  }
  foreach(array('campaign'=>'Campaign 代码','adgroup'=>'Ad Group 代码','ad'=>'Ad 代码') as $k=>$label) {
   $v=self::clean($a[$k]??''); if($v!=='' && $v!==($a[$k.'_name']??'')) $lines[]=$label.': '.$v;
  }
  return implode("\n",$lines);
 }
}
