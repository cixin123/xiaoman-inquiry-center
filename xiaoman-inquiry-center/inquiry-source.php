<?php
// Source is an evidence-based label, separate from campaign and creative ID.
if (!defined('ABSPATH')) return;
final class XI_Inquiry_Source_V1 {
 static function scalar($v) { return is_scalar($v)?trim((string)$v):''; }
 static function query($url) {
  $u=parse_url(self::scalar($url));
  if(!is_array($u) || !in_array($u['scheme']??'',array('http','https'),true) || strtolower($u['host']??'')!==XI_HOST) return array();
  parse_str($u['query']??'',$q); $out=array();
  foreach($q as $k=>$v) if(is_string($v) && !preg_match('/[{}]|__[A-Z_]+__/',$v)) $out[$k]=trim($v);
  return $out;
 }
 static function host($url) { return strtolower((string)parse_url(self::scalar($url),PHP_URL_HOST)); }
 static function domain($host,$domain) { return $host===$domain || substr($host,-strlen('.'.$domain))==='.'. $domain; }
 static function platform($source,$ref) {
  $s=strtolower(trim($source)); $h=self::host($ref);
  $map=array('google'=>'Google','google_ads'=>'Google','adwords'=>'Google','facebook'=>'Facebook','fb'=>'Facebook','meta'=>'Meta',
   'instagram'=>'Instagram','ig'=>'Instagram','tiktok'=>'TikTok','tik_tok'=>'TikTok','tk'=>'TikTok','chatgpt'=>'ChatGPT','chatgpt.com'=>'ChatGPT',
   'openai'=>'ChatGPT','bing'=>'Bing','microsoft'=>'Bing','microsoft_ads'=>'Bing','baidu'=>'百度','duckduckgo'=>'DuckDuckGo','yahoo'=>'Yahoo');
  if(isset($map[$s])) return $map[$s];
  foreach(array('chatgpt.com'=>'ChatGPT','chat.openai.com'=>'ChatGPT','perplexity.ai'=>'Perplexity','gemini.google.com'=>'Gemini',
   'copilot.microsoft.com'=>'Copilot','facebook.com'=>'Facebook','instagram.com'=>'Instagram','tiktok.com'=>'TikTok','youtube.com'=>'YouTube',
   'youtu.be'=>'YouTube','bing.com'=>'Bing','baidu.com'=>'百度','duckduckgo.com'=>'DuckDuckGo','yahoo.com'=>'Yahoo') as $domain=>$name) {
   if(self::domain($h,$domain)) return $name;
  }
  // Anchor the entire host: google.com.evil.example must not match Google.
  if(preg_match('/(^|\.)google\.(com|[a-z]{2}|com\.[a-z]{2}|co\.[a-z]{2})$/D',$h) || $h==='syndicatedsearch.goog') return 'Google';
  return '';
 }
 static function label($values,$tracking=array(),$evidence=array()) {
  $status=$tracking['status']??'';
  $blocked=array('consent_declined'=>'未知（未允许追踪）','consent_pending'=>'未知（尚未同意追踪）',
   'service_missing'=>'未知（追踪服务未找到）','consent_unavailable'=>'未知（同意接口未就绪）');
  if(isset($blocked[$status])) return $blocked[$status];
  $retained=($tracking['basis']??'')==='retained_marketing';
  $landing=self::scalar($evidence['landing']??''); $ref=self::scalar($evidence['ref']??'');
  if(!$landing) {
   $landing=self::scalar($values['R4']??''); $ref=self::scalar($values['R3']??'');
   if($retained) {
    // Old clients: use a saved landing only when its identifiers match the
    // retained advertising fields. Do not pair a fresh organic referrer with old ads.
    $landing=''; $ref=''; $click=self::scalar($values['A5']??''); $campaign=self::scalar($values['A1']??'');
    foreach(array(array('R4','R3'),array('R2','R1')) as $pair) {
     $q=self::query($values[$pair[0]]??'');
     $match=$click!==''?in_array($click,array($q['gclid']??'',$q['fbclid']??''),true):($campaign!=='' && in_array($campaign,array($q['campaign']??'',$q['utm_campaign']??'',$q['campaign_name']??'',$q['campaign_id']??'',$q['campaignid']??''),true));
     if($match) { $landing=self::scalar($values[$pair[0]]??''); $ref=self::scalar($values[$pair[1]]??''); break; }
    }
   }
  }
  $q=self::query($landing); $platform=self::platform($q['utm_source']??'',$ref);
  $medium=strtolower($q['utm_medium']??'');
  $paid=in_array($medium,array('cpc','ppc','paid','paid_social','paidsocial','paid-social','paid_search','display','cpm'),true);
  $suffix=$retained?'（历史营销记录）':'';
  // Google also adds gad_source / gad_campaignid to every ad click; they still mark an ad click when a browser strips the click id.
  $google=!empty($q['gclid']) || !empty($q['gbraid']) || !empty($q['wbraid']) || !empty($q['dclid']) || !empty($q['gad_source']) || !empty($q['gad_campaignid']);
  $tiktok=!empty($q['ttclid']);
  $microsoft=!empty($q['msclkid']);
  if((int)$google+(int)$tiktok+(int)$microsoft+(int)!empty($q['fbclid'])>1
   || ($google && in_array($platform,array('Facebook','Meta','Instagram','TikTok','Bing'),true))) return '未知（渠道标记冲突）';
  if($google) return 'Google Ads'.$suffix;
  if($tiktok) return 'TikTok 广告'.$suffix;
  if($microsoft) return 'Microsoft Ads'.$suffix;
  // Fangwei uses campaign + Google ValueTrack device/location in its ad URLs.
  // pmfk-ou is also an owner-confirmed Google campaign. A missing click ID
  // does not make these established campaign links organic search visits.
  $campaign=self::scalar($q['campaign']??'');
  $known_google=false; // No Rongfeng campaign is assumed without evidence.
  $google_template=$campaign!=='' && in_array($q['device']??'',array('m','c','t'),true)
   && preg_match('/^\d{4,10}$/D',$q['loc']??$q['loc_physical_ms']??'');
  $google_template=$google_template || (preg_match('/^\d+$/D',$q['campaignid']??'')
   && preg_match('/^\d+$/D',$q['adgroupid']??'') && preg_match('/^\d+$/D',$q['creative']??''));
  if(($known_google || $google_template) && in_array($platform,array('','Google'),true)
   && ($medium==='' || $paid) && empty($q['fbclid'])) return 'Google Ads'.$suffix;
  if($paid) return ($platform==='Google'?'Google Ads':($platform!==''?$platform.' 广告':'付费广告（平台未知）')).$suffix;
  if(in_array($platform,array('ChatGPT','Perplexity','Gemini','Copilot'),true)) return $platform.' 推荐'.$suffix;
  if(in_array($platform,array('Google','Bing','百度','DuckDuckGo','Yahoo'),true)) {
   if($medium==='organic') return $platform.' SEO'.$suffix;
   // Every Google Ads click on these sites carries a click id or the campaign codes (auto-tagging and final URL suffixes on all
   // six accounts, checked 2026-11-23), so a Google referrer without them is a natural search visit.
   return $platform==='Google'?'Google 自然搜索'.$suffix:$platform.' 搜索（广告/自然未确定）'.$suffix;
  }
  if(in_array($platform,array('Facebook','Meta','Instagram','TikTok','YouTube'),true)) return $platform.' 社媒（付费/自然未确定）'.$suffix;
  if(!empty($q['fbclid'])) return 'Facebook / Instagram（付费/自然未确定）'.$suffix;
  if($medium==='email') return '邮件'.$suffix;
  if(!empty($q['utm_source'])) return '其他渠道：'.substr(preg_replace('/[\x00-\x1f\x7f<>]/','',$q['utm_source']),0,80).$suffix;
  if(self::host($ref) && !self::domain(self::host($ref),XI_HOST)) return '其他网站：'.self::host($ref).$suffix;
  if(self::scalar($values['A1']??'')!=='' || self::scalar($values['A5']??'')!=='') return '营销来源未确定'.$suffix;
  return $landing!==''?'直接访问 / 来源未提供':'未知（未采集来源）';
 }
 static function fields($fields,$tracking=array(),$evidence=array(),$request_attribution=array()) {
  $values=array(); foreach($fields as $field) if(is_array($field)) $values[$field['id']??'']=$field['value']??'';
  $label=self::label($values,$tracking,$evidence);
  if(($request_attribution['schema_version']??0)===1 && is_string($request_attribution['source_label']??null)) $label=$request_attribution['source_label'];
  $source=array('id'=>'S1','label'=>'询盘来源','value'=>$label,'type'=>'hidden');
  $out=array(); $inserted=false;
  foreach($fields as $field) {
   $id=is_array($field)?($field['id']??''):'';
   if($id==='S1') continue;
   if(!$inserted && in_array($id,array('A7','R1'),true)) { $out[]=$source; $inserted=true; }
   if($id==='A7') { if(self::scalar($field['value']??'')==='') continue; $field['label']='广告ID/名称'; }
   $out[]=$field;
  }
  if(!$inserted) $out[]=$source;
  return $out;
 }
}
