<?php
if(!defined('ABSPATH'))exit;
final class XI_Config {
 const OPTION='xi_config_v2';
 private static $save_lock=null;
 private static $save_connection=null;
 private static $save_shutdown_registered=false;
 static function defaults(){return array('enabled'=>false,'crm'=>true,'api'=>'https://api-sandbox.xiaoman.cn','number_prefix'=>'XI-N','post_type'=>'products','taxonomies'=>array(),'record_meta'=>'xi_native_record','queue_prefix'=>'xi_','queue_cursor'=>'xi_capture_cursor_v1','queue_cpt'=>'xi_job','legacy_job'=>'','legacy_sweep'=>'','legacy_group'=>'','legacy_cap'=>'','legacy_role'=>'','consent_service'=>'xiaoman-inquiry-attribution','origin_id'=>'','origin_name'=>'','channel_origin_ids'=>array(),'product_field'=>'','level_field'=>'','level_value'=>'','products'=>array(),'product_lead_names'=>array(),'equipment_labels'=>array(),'excluded_terms'=>array(),'field_aliases'=>array(),'secrets'=>array(),'migration'=>array());}
 static function get(){ $v=get_option(self::OPTION,array());return wp_parse_args(is_array($v)?$v:array(),self::defaults()); }
 static function credential($name){
  $constant=$name==='id'?'XIAOMAN_CLIENT_ID':'XIAOMAN_CLIENT_SECRET';
  if(defined($constant)&&constant($constant))return (string)constant($constant);
  $v=self::get()['secrets'][$name]??'';
  if(!$v||!function_exists('sodium_crypto_secretbox_open'))return '';
  $raw=base64_decode($v,true); if(!$raw||strlen($raw)<40)return '';
  try {$plain=sodium_crypto_secretbox_open(substr($raw,24),substr($raw,0,24),hash('sha256',wp_salt('auth').'xiaoman-inquiry-secrets',true));return $plain===false?'':$plain;}catch(Throwable $e){return '';}
 }
 static function encrypt($value){
  if(!function_exists('sodium_crypto_secretbox'))return new WP_Error('crypto','服务器缺少 Sodium，无法保存加密密钥。');
  $nonce=random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
  return base64_encode($nonce.sodium_crypto_secretbox($value,$nonce,hash('sha256',wp_salt('auth').'xiaoman-inquiry-secrets',true)));
 }
 static function boot(){ add_action('admin_menu',array(__CLASS__,'menu'),45);add_action('admin_post_xi_config_save',array(__CLASS__,'save'));add_action('admin_post_xi_config_inspect',array(__CLASS__,'inspect_page'));add_action('admin_notices',array(__CLASS__,'notice')); }
 static function api_base($value,$required=false){
  if(!is_string($value))return new WP_Error('api','接口基地址无效，请选择提供的环境。');
  $value=trim($value);
  if($value===''&&!$required)return '';
  // Xiaoman documents this hostname as its production API, including "sandbox".
  if($value!=='https://api-sandbox.xiaoman.cn')return new WP_Error('api','请先选择并保存有效的小满接口基地址。');
  return $value;
 }
 static function menu(){
  $parent=class_exists('XI_Native_Inquiry_Center',false)?XI_Native_Inquiry_Center::PAGE:'options-general.php';
  add_submenu_page($parent,'小满询盘设置','小满询盘设置','manage_options','xiaoman-inquiry-settings',array(__CLASS__,'page'));
 }
 static function notice(){if(!current_user_can('manage_options'))return;
  if(XI_Compatibility::legacy_active())echo '<div class="notice notice-warning"><p>小满统一插件处于配置准备状态。请导入并核对原配置、备份后停用旧询盘插件，再启用统一处理。</p></div>';
 }
 private static function acquire_save_lock(){
  global $wpdb;
  if(self::$save_lock!==null)return false;
  // Connection-scoped locks do not expire while a paused request still owns
  // its connection. Disconnects release them; unsupported databases fail shut.
  $identity=(defined('DB_NAME')?DB_NAME:'').'|'.get_current_blog_id().'|'.$wpdb->options;
  $name='xi_config_'.substr(hash('sha256',$identity),0,48);
  if((string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)',$name))!=='1')return false;
  self::$save_lock=$name;self::$save_connection=$wpdb->get_var('SELECT CONNECTION_ID()');
  if(!self::$save_shutdown_registered){register_shutdown_function(array(__CLASS__,'release_save_lock'));self::$save_shutdown_registered=true;}
  if(!self::owns_save_lock()){self::release_save_lock();return false;}
  return true;
 }
 private static function owns_save_lock(){
  global $wpdb;
  return self::$save_lock!==null&&is_numeric(self::$save_connection)&&(string)$wpdb->get_var($wpdb->prepare('SELECT IF(IS_USED_LOCK(%s) = CONNECTION_ID() AND CONNECTION_ID() = %d, 1, 0)',self::$save_lock,self::$save_connection))==='1';
 }
 static function release_save_lock(){
  if(self::$save_lock===null)return;
  global $wpdb;
  try{
   // RELEASE_LOCK itself refuses another connection's ownership. The guard
   // also prevents a reconnected request from releasing a successor's lock.
   if(self::owns_save_lock()){
    if((string)$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',self::$save_lock))!=='1')return;
   }
   self::$save_lock=null;self::$save_connection=null;
  }catch(Throwable $e){/* Shutdown can retry; closing the DB connection releases the lock. */}
 }
 private static function save_guard($expected,$option=self::OPTION){
  global $wpdb;
  if(!self::owns_save_lock())wp_die('配置保存连接或锁已改变，请重新核对后提交。','',array('response'=>409));
  // Read the stored image directly: a request-local options cache may predate
  // another administrator's save. Compare raw values, never merged defaults.
  $stored=$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$option));
  if(($wpdb->last_error??'')!==''||($stored===null?null:maybe_unserialize($stored))!==$expected)wp_die('配置在本次核对期间发生改变，本次未覆盖；请重新载入并核对。','',array('response'=>409));
  return $stored;
 }
 private static function save_option($option,$expected,$value,$failure){
  global $wpdb;$before=self::save_guard($expected,$option);$next=(string)maybe_serialize($value);
  // A reconnect can happen after any preceding SELECT. Test ownership and the
  // original image inside the write statement, including first-time inserts.
  if($before===null)$changed=$wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) SELECT %s,%s,'no' WHERE IS_USED_LOCK(%s)=CONNECTION_ID() AND CONNECTION_ID()=%d",$option,$next,self::$save_lock,self::$save_connection));
  else $changed=$wpdb->query($wpdb->prepare("UPDATE {$wpdb->options} SET option_value=%s, autoload='no' WHERE option_name=%s AND BINARY option_value=BINARY %s AND IS_USED_LOCK(%s)=CONNECTION_ID() AND CONNECTION_ID()=%d",$next,$option,$before,self::$save_lock,self::$save_connection));
  if($changed===false||($changed===0&&$before!==$next)||!self::owns_save_lock())wp_die($failure);
  // Mirror WordPress option-cache invalidation, including old autoloaded rows
  // and negative caches. Never leave get_option() reading the previous image.
  foreach(array($option,'notoptions','alloptions') as $cache)wp_cache_delete($cache,'options');
  if(get_option($option,null)!==$value)wp_die($failure);
 }
 static function save(){
  if(($_SERVER['REQUEST_METHOD']??'')!=='POST')wp_die('请求方式错误。','',array('response'=>405));
  if(!current_user_can('manage_options'))wp_die('没有配置权限。', '', array('response'=>403));check_admin_referer('xi_config_save');
  if(!self::acquire_save_lock())wp_die('另一个配置保存正在进行，请稍后重新核对并提交。','',array('response'=>409));
  try{self::save_locked(get_option(self::OPTION,null));}finally{self::release_save_lock();}
 }
 private static function save_locked($expected){
  $c=self::get();$importing=isset($_POST['import_legacy']);$prepared=null;$required_expected=$importing?get_option('xi_required_v1',null):null;
  if($importing){
   $prepared=XI_Compatibility::prepare_import();
   if(is_wp_error($prepared))wp_die(esc_html($prepared->get_error_message()));
   $c=$prepared['config'];
   $errors=XI_Readiness::profile_errors($c);
   if($errors)wp_die('原配置检查未通过：'.esc_html(implode(', ',$errors)));
  } else {
   $api=self::api_base(wp_unslash($_POST['api']??$c['api']));if(is_wp_error($api))wp_die(esc_html($api->get_error_message()));$c['api']=$api;
   foreach(array('origin_id','product_field','level_field') as $key){$v=sanitize_text_field(wp_unslash($_POST[$key]??''));if($v!==''&&!preg_match('/^[1-9][0-9]*$/D',$v))wp_die('字段 ID 格式无效。');$c[$key]=$v;}
   foreach(array('origin_name','level_value') as $key)$c[$key]=sanitize_text_field(wp_unslash($_POST[$key]??''));
   $raw=json_decode(wp_unslash($_POST['channel_origin_ids']??'{}'),true);if(!is_array($raw))wp_die('社媒来源映射必须为有效 JSON 对象。');
   $c['channel_origin_ids']=array();foreach($raw as $k=>$v){if(!in_array($k,array('fb','ins','tk'),true))wp_die('社媒来源只接受 fb、ins、tk。');$v=is_scalar($v)?trim((string)$v):'';if($v==='')continue;if(!preg_match('/^[1-9][0-9]*$/D',$v))wp_die('字段 ID 格式无效。');$c['channel_origin_ids'][$k]=$v;}
   $c['post_type']=sanitize_key($_POST['post_type']??$c['post_type']);
   if(!post_type_exists($c['post_type']))wp_die('产品文章类型不存在。');
   // Not sanitize_key(): it lowercases, and a registered key such as "Tractors" would then be dropped.
   $c['taxonomies']=array_values(array_filter(array_map(static function($t){return preg_replace('/[^A-Za-z0-9_-]/','',$t);},preg_split('/[\s,]+/',wp_unslash($_POST['taxonomies']??''))),static function($t)use($c){return taxonomy_exists($t)&&is_object_in_taxonomy($c['post_type'],$t);}));
   foreach(array('products','product_lead_names','equipment_labels','field_aliases') as $key){$raw=json_decode(wp_unslash($_POST[$key]??'{}'),true);if(!is_array($raw))wp_die('映射必须为有效 JSON 对象。');$c[$key]=array();foreach($raw as $k=>$v)if(is_string($k)&&is_string($v))$c[$key][sanitize_text_field($k)]=sanitize_text_field($v);}
   foreach(array('id','secret') as $key){$v=trim(wp_unslash($_POST['client_'.$key]??''));if($v!==''){$encrypted=self::encrypt($v);if(is_wp_error($encrypted))wp_die($encrypted->get_error_message());$c['secrets'][$key]=$encrypted;}}
   $c['enabled']=isset($_POST['enabled']);
   if($c['enabled']&&($c['migration']['status']??'')==='import_preparing')wp_die('上次导入尚未完成，请先核对并重新完成导入，再启用统一处理。');
   if(($_POST['crm_control_v1']??null)==='1')$c['crm']=isset($_POST['crm_enabled'])&&$_POST['crm_enabled']==='1';
   if($c['enabled']&&XI_Compatibility::legacy_active())wp_die('请先备份并停用旧询盘插件，避免重复提交。');
  }
  $api=self::api_base($c['api'],!empty($c['enabled']));if(is_wp_error($api))wp_die(esc_html($api->get_error_message()));$c['api']=$api;
  if($importing){
   // Preparation is pure. Start persistence only after every build/API/profile check passes.
   // A failed multi-option write remains visibly incomplete and cannot start the new core.
   $c['enabled']=false;$c['migration']['status']='import_preparing';
   self::save_option(self::OPTION,$expected,$c,'导入配置未能保存，统一处理保持关闭。请检查数据库后重试。');
   $expected=$c;
   if(is_array($prepared['required'])){
    self::save_guard($expected);
    self::save_option('xi_required_v1',$required_expected,$prepared['required'],'必填配置尚未保存完成，导入保持准备状态，统一处理未启用。');
   }
   $c['migration']['status']='imported';
  }
  self::save_option(self::OPTION,$expected,$c,$importing?'导入尚未完成，统一处理保持关闭。请核对已保存配置。':'配置未能保存，请核对数据库后重试；当前仍使用原配置。');
  delete_transient('xi_lead_token_v1');
  wp_safe_redirect(admin_url('admin.php?page=xiaoman-inquiry-settings&updated=1'));exit;
 }
 static function configuration_text($value,$secrets){
  if(!is_scalar($value))return '';
  $value=(string)$value;
  foreach($secrets as $secret)if(is_string($secret)&&$secret!=='')$value=str_replace($secret,'[已隐藏]',$value);
  $value=sanitize_textarea_field($value);
  return function_exists('mb_substr')?mb_substr($value,0,1000):substr($value,0,1000);
 }
 static function configuration_options($value,$secrets,$key='',$depth=0){
  if($depth>12)return '[已省略过深配置]';
  if(preg_match('/token|secret|password|authorization|client[_-]?id|credential|cookie|customer|contact|email|phone|whatsapp/i',(string)$key))return '[已隐藏]';
  // ext_info is field configuration, sometimes a JSON string. Keep its schema intact.
  if(is_string($value)){
   $decoded=json_decode($value,true);
   if(is_array($decoded))return wp_json_encode(self::configuration_options($decoded,$secrets,'',$depth+1),JSON_UNESCAPED_UNICODE);
   return self::configuration_text($value,$secrets);
  }
  if(!is_array($value)){$clean=self::configuration_text($value,$secrets);return is_scalar($value)&&(string)$value===$clean?$value:$clean;}
  $clean=array();foreach($value as $child_key=>$item)$clean[self::configuration_text($child_key,$secrets)]=self::configuration_options($item,$secrets,$child_key,$depth+1);
  return $clean;
 }
 static function configuration_origins($data,$secrets,$depth=0){
  $found=array();if(!is_array($data)||$depth>12)return $found;
  $id=$data['id']??($data['value']??null);$name=$data['name']??($data['label']??null);
  if(is_scalar($data['origin_id']??null)&&is_scalar($data['origin_name']??null)){$id=$data['origin_id'];$name=$data['origin_name'];}
  if(is_scalar($id)&&is_scalar($name))$found[]=array('来源编号'=>self::configuration_text($id,$secrets),'来源名称'=>self::configuration_text($name,$secrets));
  foreach($data as $key=>$child)if(is_array($child)&&!preg_match('/customer|contact|token|secret|password|authorization|credential/i',(string)$key))$found=array_merge($found,self::configuration_origins($child,$secrets,$depth+1));
  return $found;
 }
 static function configuration_fields($data,$secrets,$depth=0){
  $found=array();if(!is_array($data)||$depth>12)return $found;
  if(in_array($data['name']??'',array('意向产品','客户等级'),true)){
   return array(array('字段编号'=>self::configuration_text($data['id']??'',$secrets),'字段名称'=>self::configuration_text($data['name'],$secrets),'字段类型'=>self::configuration_text($data['field_type']??'',$secrets),'选项配置'=>self::configuration_options($data['ext_info']??array(),$secrets)));
  }
  foreach($data as $child)if(is_array($child))$found=array_merge($found,self::configuration_fields($child,$secrets,$depth+1));
  return $found;
 }
 static function configuration_get($api,$path,$token){
  $api=self::api_base($api,true);if(is_wp_error($api))return $api;
  if(!in_array($path,array('/v1/company/fields/selector?field=origin','/v1/lead/fields?type=lead'),true))return new WP_Error('endpoint','字段核对端点无效。');
  $response=wp_remote_get($api.$path,array('timeout'=>15,'redirection'=>0,'limit_response_size'=>1048576,'reject_unsafe_urls'=>true,'sslverify'=>true,'headers'=>array('Accept'=>'application/json','Authorization'=>$token)));
  if(is_wp_error($response))return new WP_Error('network','字段配置读取失败：网络连接异常。');
  $http=(int)wp_remote_retrieve_response_code($response);$data=json_decode(wp_remote_retrieve_body($response),true);
  $code=is_array($data)&&isset($data['code'])&&is_numeric($data['code'])?(int)$data['code']:0;
  if($http<200||$http>=300||!is_array($data)||($code!==0&&$code!==200)||!array_key_exists('data',$data))return new WP_Error('metadata','字段配置读取失败：接口响应无效（状态码 '.$http.'）。');
  return $data['data'];
 }
 static function inspect_configuration(){
  if(!current_user_can('manage_options'))return new WP_Error('permission','没有核对字段配置的权限。');
  $api=self::api_base(self::get()['api'],true);if(is_wp_error($api))return $api;
  $id=self::credential('id');$secret=self::credential('secret');
  if($id===''||$secret==='')return new WP_Error('auth','请先配置并保存客户端编号与客户端密钥。');
  try{
   // An independent token remains in this request only; ordinary lead tokens are untouched.
   $response=wp_remote_post($api.'/v1/oauth2/access_token',array('timeout'=>15,'redirection'=>0,'limit_response_size'=>65536,'reject_unsafe_urls'=>true,'sslverify'=>true,'headers'=>array('Content-Type'=>'application/json','Accept'=>'application/json'),'body'=>wp_json_encode(array('grant_type'=>'client_credentials','client_id'=>$id,'client_secret'=>$secret,'scope'=>'company lead'))));
   if(is_wp_error($response))return new WP_Error('auth','字段核对授权失败：网络连接异常。');
   $http=(int)wp_remote_retrieve_response_code($response);$data=json_decode(wp_remote_retrieve_body($response),true);
   if($http!==200||!is_array($data)||empty($data['access_token'])||!is_string($data['access_token'])||strlen($data['access_token'])>8192||preg_match('/[\r\n]/',$data['access_token']))return new WP_Error('auth','字段核对授权失败：接口响应无效（状态码 '.$http.'）。');
   $token=$data['access_token'];$secrets=array($token,$id,$secret);
   $origin=self::configuration_get($api,'/v1/company/fields/selector?field=origin',$token);
   $fields=self::configuration_get($api,'/v1/lead/fields?type=lead',$token);
   return array('来源配置'=>is_wp_error($origin)?array('错误'=>$origin->get_error_message()):self::configuration_origins($origin,$secrets),'线索映射字段'=>is_wp_error($fields)?array('错误'=>$fields->get_error_message()):self::configuration_fields($fields,$secrets));
  }catch(Throwable $e){return new WP_Error('metadata','字段核对失败，请检查连接与接口权限后重试。');}
 }
 static function inspect_page(){
  nocache_headers();
  if(!current_user_can('manage_options'))wp_die('没有核对字段配置的权限。','',array('response'=>403));
  check_admin_referer('xi_config_inspect');
  $result=self::inspect_configuration();
  $html='<h1>小满字段配置核对</h1><p>以下是已保存环境的来源与字段配置，供管理员核对。返回设置页后可填写并保存映射。</p>';
  $html.=is_wp_error($result)?'<p>'.esc_html($result->get_error_message()).'</p>':'<pre>'.esc_html(wp_json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)).'</pre>';
  $html.='<p><a href="'.esc_url(admin_url('admin.php?page=xiaoman-inquiry-settings')).'">返回小满询盘设置</a></p>';
  wp_die($html,'小满字段配置核对',array('response'=>200));
 }
 static function page(){
  if(!current_user_can('manage_options'))return;$c=self::get();
  echo '<div class="wrap"><h1>小满询盘中心 · '.esc_html(XI_VERSION).'</h1>';
  if(isset($_GET['updated']))echo '<div class="notice notice-success"><p>配置已保存。</p></div>';
  echo '<p>Bricks 原生 Form → 本地保存 → 小满后台同步。请先完成配置核对再启用。</p><p><a href="'.esc_url(admin_url('admin.php?page=xiaoman-readiness')).'">统一配置检查（只读）</a>：核对本站身份、Cookie、Google 与队列准备度。</p><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
  wp_nonce_field('xi_config_save');echo '<input type="hidden" name="action" value="xi_config_save"><table class="form-table">';
  echo '<tr><th>接口基地址</th><td><select name="api">';
  foreach(array(''=>'尚未配置','https://api-sandbox.xiaoman.cn'=>'小满官方接口（正式）') as $value=>$label)echo '<option value="'.esc_attr($value).'" '.selected($c['api'],$value,false).'>'.esc_html($label).'</option>';
  echo '</select><p class="description">使用小满官方文档列出的正式接口。启用统一处理前必须选择有效地址。</p></td></tr>';
  foreach(array('origin_id'=>'小满来源 ID','origin_name'=>'线索名称来源词','product_field'=>'意向产品字段 ID','level_field'=>'客户等级字段 ID（可选）','level_value'=>'客户等级选项（可选）','post_type'=>'产品文章类型') as $key=>$label)echo '<tr><th>'.esc_html($label).'</th><td><input class="regular-text" name="'.esc_attr($key).'" value="'.esc_attr($c[$key]).'"></td></tr>';
  echo '<tr><th>社媒询盘的小满来源 ID</th><td><textarea name="channel_origin_ids" rows="4" cols="70">'.esc_textarea(wp_json_encode((object)(is_array($c['channel_origin_ids'])?$c['channel_origin_ids']:array()),JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)).'</textarea><p class="description">键为 fb（Facebook/Meta）、ins（Instagram）、tk（TikTok），值为小满来源 ID。未填的渠道仍用上面的小满来源 ID；线索名称词按“站名谷歌”换成“站名FB/INS/TK”。YouTube 和其他来源不变。</p></td></tr>';
  echo '<tr><th>产品分类</th><td><input class="large-text" name="taxonomies" value="'.esc_attr(implode(',',$c['taxonomies'])).'"></td></tr>';
  foreach(array('products'=>'产品到小满选项映射','product_lead_names'=>'产品到线索名称词映射','equipment_labels'=>'分类 slug 到产品类别名称','field_aliases'=>'自定义表单字段名到 F1/F2/F3/F4/F5/F6/F9') as $key=>$label)echo '<tr><th>'.esc_html($label).'</th><td><textarea name="'.esc_attr($key).'" rows="5" cols="70">'.esc_textarea(wp_json_encode((object)$c[$key],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)).'</textarea></td></tr>';
  foreach(array('id'=>'客户端编号','secret'=>'客户端密钥') as $key=>$label)echo '<tr><th>'.esc_html($label).'</th><td><input type="password" autocomplete="new-password" name="client_'.esc_attr($key).'" value=""><span> '.(self::credential($key)?'已配置，留空保留':'尚未配置').'</span></td></tr>';
  echo '</table><label><input type="checkbox" name="enabled" value="1" '.checked($c['enabled'],true,false).'>启用统一询盘处理</label>';
  echo '<input type="hidden" name="crm_control_v1" value="1"><p><label><input type="checkbox" name="crm_enabled" value="1" '.checked($c['crm'],true,false).'>允许小满同步</label></p><p class="description">取消勾选仅暂停新任务排队和发送；已在途请求可能完成。现有任务与审核记录保留。</p>';
  submit_button('保存设置');if(XI_Compatibility::legacy_active())submit_button('导入原询盘配置','secondary','import_legacy');echo '</form>';
  echo '<hr><h2>小满字段配置核对</h2><p>先保存接口基地址与凭据，再核对来源及意向产品、客户等级字段。统一询盘处理关闭时也可核对。</p><form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="xi_config_inspect">';wp_nonce_field('xi_config_inspect');submit_button('只读核对小满来源与字段','secondary');echo '</form>';
  echo '<hr><h2>环境检查</h2><pre>'.esc_html(wp_json_encode(self::health(),JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)).'</pre>';
  echo '<h2>Cookies 配置</h2><p>同意服务缺失时仍可提交询盘；跨页面广告归因仅在用户同意后启用。</p><pre>'.esc_html(wp_json_encode(XI_Cookies::definitions(),JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)).'</pre>';
  if(post_type_exists('rcb-cookie')){echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="xi_cookie_prepare">';wp_nonce_field('xi_cookie_prepare');submit_button('准备 Cookies 服务配置','secondary');echo '</form>';}
  echo '<p>升级源：<a href="https://github.com/cixin123/xiaoman-inquiry-center/releases">GitHub Releases</a>。测试版需人工升级，正式稳定版可使用 WordPress 自动更新。</p></div>';
 }
 static function health(){
  $c=self::get();return array('plugin'=>XI_VERSION,'wordpress'=>get_bloginfo('version'),'php'=>PHP_VERSION,'bricks'=>defined('BRICKS_VERSION')?BRICKS_VERSION:'missing','https'=>is_ssl(),'credentials'=>array('id'=>(bool)self::credential('id'),'secret'=>(bool)self::credential('secret')),'storage'=>class_exists('XI_Native_Inquiry_Center',false)?XI_Native_Inquiry_Center::exists():null,'queue'=>function_exists('as_enqueue_async_action'),'cron_disabled'=>defined('DISABLE_WP_CRON')&&DISABLE_WP_CRON,'last_recovery'=>get_option($c['queue_prefix'].'last_recovery_v1',0),'consent'=>XI_Cookies::status(),'legacy_conflict'=>XI_Compatibility::legacy_active(),'migration'=>$c['migration']);
 }
}
