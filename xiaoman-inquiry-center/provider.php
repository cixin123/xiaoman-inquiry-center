<?php
if(!defined('ABSPATH'))exit;
final class XI_Xiaoman_Queue_V1 {
 const API=XI_API;
 const LEAD_DATE_TIMEZONE='Asia/Shanghai';
 const LEAD_DATE_CUTOFF_MINUTE=1050; // 17:30 Beijing time
 const TOKEN='xi_lead_token_v1';
 static function hd_labels() {
  return array('H3'=>'PC/Mobile','H1'=>'Form Url','H2'=>'IP address',
   'A1'=>'campaign','A2'=>'keyword','A3'=>'adgroup','A4'=>'device','A5'=>'gclid / fbclid','A6'=>'loc','A7'=>'广告ID/名称',
   'R1'=>'首次来源网站','R2'=>'首次进入页面','R3'=>'本次来源网站','R4'=>'本次进入页面','R5'=>'询盘前个页面','R6'=>'询盘按钮标识');
 }
 static function metadata_url($value,$internal=false) {
  return XI_Request_Attribution_V1::safe_url($value,$internal,$internal);
 }
 static function request_metadata($request) {
  $request=is_array($request)?$request:array();
  $raw=XI_Compatibility::packet($request);
  $packet=is_string($raw) && strlen($raw)<=24000 ? json_decode($raw,true) : null;
  $packet=is_array($packet)?$packet:array(); $values=array();
  foreach(self::hd_labels() as $key=>$label) {
   if(in_array($key,array('H2','H3'),true)) continue;
   // Legacy fields remain usable during rollout; new forms need no HD elements.
   $value=array_key_exists($key,$packet)?$packet[$key]:($request['form-field-'.$key]??'');
   $value=is_string($value)?$value:'';
   if(in_array($key,array('H1','R1','R2','R3','R4','R5'),true)) $value=self::metadata_url($value,in_array($key,array('H1','R2','R4','R5'),true));
   else $value=self::text(preg_replace('/[\x00-\x1f\x7f]/','',$value),500);
   $values[$key]=$value;
  }
  if($values['H1']==='') $values['H1']=self::metadata_url($_SERVER['HTTP_REFERER']??'',true);
  return $values;
 }
 static function text($v, $limit = 1000) {
  if (is_array($v)) $v = implode(', ', array_filter($v, 'is_scalar'));
  $v = is_scalar($v) ? sanitize_textarea_field((string)$v) : '';
  return function_exists('mb_substr') ? mb_substr($v, 0, $limit) : substr($v, 0, $limit);
 }
 static function visitor_context() {
  $raw=strtoupper(trim((string)($_SERVER['HTTP_CF_IPCOUNTRY']??'')));
  $country=preg_match('/^[A-Z]{2}$/D',$raw)?self::country($raw):'';
  $ip=(string)($_SERVER['HTTP_CF_CONNECTING_IP']??'');
  if (!filter_var($ip,FILTER_VALIDATE_IP)) $ip='';
  $has_device=!empty($_SERVER['HTTP_USER_AGENT']) || isset($_SERVER['HTTP_SEC_CH_UA_MOBILE']);
  return array('country'=>$country,'ip'=>$ip,'device'=>$has_device?(wp_is_mobile()?'Mobile':'PC'):'Unknown');
 }
 static function country_english($code) {
  static $names=null;
  if ($names===null) {
   $path=__DIR__.'/country-names-en.json';
   $names=is_readable($path)?json_decode(file_get_contents($path),true):array();
   if (!is_array($names)) $names=array();
  }
  return $names[$code]??$code;
 }
 static function country($raw) {
  static $map=null;
  if ($map===null) {
   $path=__DIR__.'/countries.json';
   $map=is_readable($path)?json_decode(file_get_contents($path),true):array();
   if(!is_array($map)) $map=array();
  }
  $raw=self::text($raw,150);
  $key=function_exists('mb_strtolower')?mb_strtolower($raw,'UTF-8'):strtolower($raw);
  return $map[$key]??'';
 }
 static function country_name($code) {
  static $names=null;
  if ($names===null) {
   $path=__DIR__.'/country-names-zh.json';
   $names=is_readable($path)?json_decode(file_get_contents($path),true):array();
   if(!is_array($names)) $names=array();
  }
  return $names[$code]??'';
 }
 static function token() {
  $cached=get_transient(self::TOKEN);
  if (is_string($cached) && $cached!=='') return $cached;
  if (!XI_Config::credential('id') || !XI_Config::credential('secret')) return new WP_Error('auth','API 凭证未配置');
  $r=wp_remote_post(self::API.'/v1/oauth2/access_token',array('timeout'=>15,'redirection'=>0,
   'headers'=>array('Content-Type'=>'application/json','Accept'=>'application/json'),
   'body'=>wp_json_encode(array('grant_type'=>'client_credentials','client_id'=>XI_Config::credential('id'),'client_secret'=>XI_Config::credential('secret'),'scope'=>'lead'))));
  if (is_wp_error($r)) return new WP_Error('auth','获取令牌时网络连接失败');
  $http=(int)wp_remote_retrieve_response_code($r); $d=json_decode(wp_remote_retrieve_body($r),true);
  if ($http!==200 || empty($d['access_token']) || !is_string($d['access_token'])) return new WP_Error('auth','获取令牌失败，HTTP '.$http);
  $token=$d['access_token']; $ttl=4*HOUR_IN_SECONDS;
  // OKKI validates the token; a 401 clears this cache before a bounded retry.
  if (!empty($d['expires_in']) && is_numeric($d['expires_in'])) $ttl=min($ttl,(int)$d['expires_in']-120);
  if ($ttl>0) set_transient(self::TOKEN,$token,$ttl);
  return $token;
 }
 static function error_hint($response,$payload,$token) {
  $message=is_array($response)&&isset($response['message'])&&is_string($response['message'])?$response['message']:'请检查字段、来源和 API 权限';
  $values=array($token);
  $values[]=XI_Config::credential('id');$values[]=XI_Config::credential('secret');
  $data=json_decode($payload,true);
  if(is_array($data)) {
   $values[]=$data['name']??'';
   foreach(($data['customers']??array()) as $customer) foreach(array('name','email','whatsapp') as $key) $values[]=$customer[$key]??'';
  }
  foreach($values as $value) if(is_string($value)&&$value!=='') $message=str_replace($value,'[已隐藏]',$message);
  $message=preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i','[邮箱]',$message);
  $message=preg_replace('/\+?[0-9]{7,}/','[编号]',$message);
  return self::text($message,180);
 }
 static function configuration_data($value,$secrets,$key='') {
  // Metadata is rendered as text. Never display tokens/credentials if an API echoes them.
  if(preg_match('/token|secret|password|authorization|client_id/i',(string)$key)) return '[已隐藏]';
  if(is_array($value)) {
   $clean=array(); foreach($value as $k=>$v) $clean[$k]=self::configuration_data($v,$secrets,$k);
   return $clean;
  }
  if(is_string($value)) foreach($secrets as $secret) if(is_string($secret)&&$secret!=='') $value=str_replace($secret,'[已隐藏]',$value);
  return $value;
 }
 static function configuration_get($path,$token) {
  // Fixed read-only allowlist: no customer endpoints or caller-supplied remote URLs.
  if(!in_array($path,array('/v1/company/fields/selector?field=origin','/v1/lead/fields?type=lead'),true)) return new WP_Error('endpoint','字段检测端点无效');
  $response=wp_remote_get(self::API.$path,array('timeout'=>15,'redirection'=>0,
   'headers'=>array('Accept'=>'application/json','Authorization'=>$token)));
  if(is_wp_error($response)) return new WP_Error('network','字段配置读取失败：网络连接异常');
  $http=(int)wp_remote_retrieve_response_code($response);
  $data=json_decode(wp_remote_retrieve_body($response),true);
  $code=is_array($data)&&isset($data['code'])&&is_numeric($data['code'])?(int)$data['code']:0;
  if($http<200||$http>=300||!is_array($data)||($code!==0&&$code!==200)||!array_key_exists('data',$data))
   return new WP_Error('metadata','字段配置读取失败：HTTP '.$http.' / API '.$code);
  return $data['data'];
 }
 static function configuration_product_fields($data) {
  $found=array(); if(!is_array($data)) return $found;
  if(in_array($data['name']??'',array('意向产品','客户等级'),true)) {
   $item=array(); foreach(array('id','name','field_type','ext_info') as $key) $item[$key]=$data[$key]??null;
   return array($item);
  }
  foreach($data as $child) if(is_array($child)) $found=array_merge($found,self::configuration_product_fields($child));
  return $found;
 }
 static function inspect_configuration() {
  if(!current_user_can('manage_options')) return new WP_Error('permission','没有检测字段配置的权限');
  if(!XI_Config::credential('id')||!XI_Config::credential('secret'))
   return new WP_Error('auth','API 凭证未配置');
  // Independent, uncached token for this read. Leave the ordinary lead token untouched.
  $response=wp_remote_post(self::API.'/v1/oauth2/access_token',array('timeout'=>15,'redirection'=>0,
   'headers'=>array('Content-Type'=>'application/json','Accept'=>'application/json'),
   'body'=>wp_json_encode(array('grant_type'=>'client_credentials','client_id'=>XI_Config::credential('id'),'client_secret'=>XI_Config::credential('secret'),'scope'=>'company lead'))));
  if(is_wp_error($response)) return new WP_Error('auth','字段检测授权失败：网络连接异常');
  $http=(int)wp_remote_retrieve_response_code($response);$data=json_decode(wp_remote_retrieve_body($response),true);
  if($http!==200||!is_array($data)||empty($data['access_token'])||!is_string($data['access_token']))
   return new WP_Error('auth','字段检测授权失败：HTTP '.$http);
  $token=$data['access_token']; $secrets=array($token,XI_Config::credential('id'),XI_Config::credential('secret'));
  $origin=self::configuration_get('/v1/company/fields/selector?field=origin',$token);
  $fields=self::configuration_get('/v1/lead/fields?type=lead',$token);
  return array(
   '来源配置'=>is_wp_error($origin)?array('error'=>$origin->get_error_message()):array('data'=>self::configuration_data($origin,$secrets)),
   '线索映射字段'=>is_wp_error($fields)?array('error'=>$fields->get_error_message()):array('data'=>self::configuration_data(self::configuration_product_fields($fields),$secrets)));
 }
 static function tracking() {
  if(is_admin() || (function_exists('bricks_is_builder') && bricks_is_builder())) return;
  wp_enqueue_script('xi-form-context',plugins_url('form-context.js',__FILE__),array(),XI_VERSION,array('strategy'=>'defer','in_footer'=>true));
  wp_localize_script('xi-form-context','XIContextConfig',array('host'=>XI_HOST,'service'=>XI_Config::get()['consent_service']));
 }
 static function product_mapping($raw) {
  $key=strtolower(trim(self::text($raw,100)));
  $map=XI_Config::get()['products'];
  $value=$map[$key]??'';
  $lead=XI_Config::get()['product_lead_names'][$key]??'';
  return array('lead_name'=>$lead!==''?$lead:($value!==''?$value:'其他'),'product_name'=>$value);
 }
 static function lead_name_date($submitted_at) {
  $date=(new DateTimeImmutable('@'.(int)$submitted_at))->setTimezone(new DateTimeZone(self::LEAD_DATE_TIMEZONE));
  if((int)$date->format('G')*60+(int)$date->format('i')>=self::LEAD_DATE_CUTOFF_MINUTE) $date=$date->modify('+1 day');
  return $date->format('ymd');
 }
 static function payload($submission,$submitted_at=null) {
  $submitted_at=$submitted_at??time(); $config=XI_Config::get();
  if(!preg_match('/^[1-9][0-9]*$/D',$config['origin_id'])) return new WP_Error('origin','尚未核对本站的小满来源配置');
  $fields=array();foreach(($submission['fields']??array()) as $f) if(is_array($f)&&isset($f['id'])) $fields[$f['id']]=$f['value']??'';
  $email=sanitize_email(self::text($fields['F5']??'',150));
  if(!is_email($email)) return new WP_Error('mapping','原始询盘邮箱无效');
  $name=self::text(trim(($fields['F3']??'').' '.($fields['F4']??'')),150);
  if($name==='') $name=$email; // Preserve existing saved test records with no name.
  $phone=preg_replace('/[^0-9+]/','',self::text($fields['F6']??'',50));
  $country=self::country($fields['IP']??'');
  $product=self::product_mapping($fields['F1']??'');
  $campaign=self::text($fields['A1']??'',100);
  // Lead names use the next Beijing calendar date from 17:30, using the original submission time.
  // Remarks, saved timestamps and retries continue to use that original time.
  $lead_name=self::lead_name_date($submitted_at).$config['origin_name'].$product['lead_name'].self::country_name($country).$campaign;
  $contact=array('name'=>$name,'main_customer_flag'=>1,'email'=>$email);
  if($phone!=='') $contact['whatsapp']=$phone;
  $payload=array('lead_id'=>0,'name'=>$lead_name,'origin_list'=>array($config['origin_id']),
   'remark'=>self::inquiry_remark($fields,$submitted_at,absint($submission['id']??0)), 'customers'=>array($contact));
  if($country!=='') $payload['country']=$country;
  if($config['product_field']!=='' && $product['product_name']!=='') $payload[$config['product_field']]=array($product['product_name']);
  if($config['level_field']!=='' && $config['level_value']!=='') $payload[$config['level_field']]=$config['level_value'];
  return $payload;
 }
 static function inquiry_remark($fields,$time,$id) {
  $date=(new DateTimeImmutable('@'.(int)$time))->setTimezone(wp_timezone())->format('Y-m-d H:i:s');
  $parts=array();
  foreach(array('编号'=>XI_PROFILE['number_prefix'].$id,'时间'=>$date,'国家'=>self::country_name(self::country($fields['IP']??'')),
   '需求产品'=>$fields['F1']??'','需求型号'=>$fields['F2']??'','定制方式'=>$fields['customization_type']??'') as $label=>$value)
   if(($value=self::text($value,500))!=='')$parts[]=$label.'：'.$value;
  if(($message=self::text($fields['F9']??'',10000))!=='') { $parts[]='';$parts[]='留言信息：';$parts[]=$message; }
  if(($url=self::text($fields['H1']??'',1800))!=='') { $parts[]='';$parts[]='询盘留言页面：';$parts[]=$url; }
  return implode("\n",$parts);
 }
}
