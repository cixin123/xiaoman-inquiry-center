<?php
if (!defined('ABSPATH')) exit;

/** Administrator-only, local checks. Never boots delivery, migrates schema or probes Google/CRM. */
final class XI_Readiness {
 const PAGE = 'xiaoman-readiness';

 static function boot() { add_action('admin_menu', array(__CLASS__, 'menu'), 48); }
 static function menu() {
  $parent = class_exists('XI_Native_Inquiry_Center', false) ? XI_Native_Inquiry_Center::PAGE : 'options-general.php';
  add_submenu_page($parent, '统一配置检查', '统一配置检查', 'manage_options', self::PAGE, array(__CLASS__, 'page'));
 }
 static function matches($value, $pattern) { return is_string($value) && preg_match($pattern, $value) === 1; }

 /** Pure configuration validation, apart from WordPress post-type/taxonomy registration reads. */
 static function profile_errors($c) {
  if (!is_array($c)) return array('profile_invalid');
  $errors = array();
  foreach (array('number_prefix'=>'/^[A-Za-z0-9][A-Za-z0-9_-]{0,31}$/D',
   'record_meta'=>'/^[a-zA-Z0-9_]{1,64}$/D', 'queue_prefix'=>'/^[a-z][a-z0-9_]{0,63}$/D',
   'queue_cursor'=>'/^[a-z][a-z0-9_]{0,100}$/D', 'queue_cpt'=>'/^[a-z][a-z0-9_]{0,19}$/D') as $key=>$pattern) {
   if (!self::matches($c[$key]??null, $pattern)) $errors[] = $key.'_invalid';
  }
  if (!is_bool($c['enabled']??null) || !is_bool($c['crm']??null)) $errors[] = 'core_toggle_invalid';
  if (!self::matches($c['post_type']??null, '/^[a-z][a-z0-9_-]{0,19}$/D') || !post_type_exists($c['post_type'])) $errors[] = 'post_type_missing';
  if (!is_array($c['taxonomies']??null)) $errors[] = 'taxonomies_invalid';
  else foreach ($c['taxonomies'] as $tax) {
   // WordPress keeps taxonomy keys case-sensitive; Tongqu registers "Tractors".
   if (!self::matches($tax, '/^[A-Za-z][A-Za-z0-9_-]{0,31}$/D') || !taxonomy_exists($tax) || !is_object_in_taxonomy($c['post_type']??'', $tax)) $errors[] = 'taxonomy_missing';
  }
  foreach (array('origin_id', 'product_field', 'level_field') as $key) {
   $value=$c[$key]??'';
   if ($value!=='' && !self::matches($value, '/^[1-9][0-9]*$/D')) $errors[]=$key.'_invalid';
  }
  foreach (array('products','product_lead_names','equipment_labels','field_aliases') as $key) if (!is_array($c[$key]??null)) $errors[]=$key.'_invalid';
  return array_values(array_unique($errors));
 }
 static function add(&$checks, $code, $group, $title, $state, $detail) {
  $checks[]=array('code'=>$code,'group'=>$group,'title'=>$title,'state'=>$state,'detail'=>$detail);
 }
 static function table_exists($table) {
  global $wpdb;
  if (!isset($wpdb) || !self::matches($table, '/^[a-zA-Z0-9_]+$/D')) return false;
  return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) === $table;
 }
 static function definitions($value) {
  if (is_string($value)) $value=json_decode($value, true);
  return is_array($value)?$value:array();
 }
 static function deleted_on_optout($value) { return in_array($value, array(true,1,'1'), true); }

 /** Local published-service metadata only; no consent grants or runtime script evaluation. */
 static function service($name, $purpose='', $expected=array()) {
  if (!self::matches($name, '/^[a-zA-Z0-9][a-zA-Z0-9_-]{0,99}$/D')) return array('state'=>'missing','reason'=>'尚未填写有效服务 Unique Name');
  if (!post_type_exists('rcb-cookie')) return array('state'=>'missing','reason'=>'未找到 Real Cookie Banner 服务类型');
  $posts=get_posts(array('post_type'=>'rcb-cookie','post_status'=>array('publish','draft','private'),'posts_per_page'=>2,'meta_key'=>'uniqueName','meta_value'=>$name));
  if (count($posts)!==1) return array('state'=>'missing','reason'=>count($posts)?'存在重复服务 Unique Name':'找不到对应服务');
  if ($posts[0]->post_status!=='publish') return array('state'=>'missing','reason'=>'服务尚未发布');
  $id=$posts[0]->ID;
  $types=self::definitions(get_post_meta($id,'googleConsentModeConsentTypes',true));
  if ($purpose!=='' && !in_array($purpose,$types,true)) return array('state'=>'missing','reason'=>'已发布服务缺少所需用途 '.$purpose);
  if ($expected) {
   $actual=self::definitions(get_post_meta($id,'technicalDefinitions',true));
   foreach ($expected as $definition) {
    $matches=array_values(array_filter($actual,static function($item)use($definition){return is_array($item)&&($item['name']??'')===$definition['name'];}));
    if (count($matches)!==1) return array('state'=>'missing','reason'=>'存储声明缺失或重复：'.$definition['name']);
    $item=$matches[0];$host=strtolower(ltrim(is_string($item['host']??null)?$item['host']:'','.'));
    if (($item['type']??'')!==$definition['type'] || $host!==$definition['host'] || !is_numeric($item['duration']??null) || (float)$item['duration']!==(float)$definition['duration'] || ($item['durationUnit']??'')!==$definition['durationUnit'] || !empty($item['isSessionDuration'])) {
     return array('state'=>'missing','reason'=>'存储类型、域名或有效期不匹配：'.$definition['name']);
    }
   }
   if (!self::deleted_on_optout(get_post_meta($id,'deleteTechnicalDefinitionsAfterOptOut',true))) return array('state'=>'missing','reason'=>'尚未声明撤回后清理存储');
  }
  return array('state'=>'local_match','reason'=>'本地已发布配置匹配；公开编译、同意与清理行为仍需验证');
 }
 static function receipt_structure() {
  global $wpdb;$table=$wpdb->prefix.'xi_conversion_receipts';
  if (!self::table_exists($table)) return false;
  $columns=$wpdb->get_col('SHOW COLUMNS FROM '.$table);
  $required=array('id','submission_id','token_hash','request_uuid','form_kind','created_utc','expires_utc','site_timezone','is_test','consent_at_submit','ga4_state','ads_state','ga4_claimed_utc','ads_claimed_utc','ga4_claim_owner','ads_claim_owner','ga4_callback_utc','ads_callback_utc');
  if (!is_array($columns) || array_diff($required,$columns)) return false;
  $indexes=$wpdb->get_results('SHOW INDEX FROM '.$table, ARRAY_A);$unique=array();
  foreach ((array)$indexes as $index) if ((int)($index['Non_unique']??1)===0) $unique[(string)($index['Key_name']??'')][(int)($index['Seq_in_index']??0)]=(string)($index['Column_name']??'');
  $found=array();foreach ($unique as $parts) if (count($parts)===1) foreach($parts as $column)$found[]=$column;
  return !array_diff(array('id','submission_id','token_hash'),$found);
 }
 static function snapshot() {
  if (!current_user_can('manage_options')) return new WP_Error('forbidden','没有统一配置检查权限。');
  $host=strtolower((string)wp_parse_url(home_url('/'),PHP_URL_HOST));
  $raw=get_option(XI_Config::OPTION,null);$saved=is_array($raw)&&!empty($raw);$c=XI_Config::get();
  $conversion=get_option('xi_conversion_config_v1',array());$conversion=is_array($conversion)?$conversion:array();
  $checks=array();$legacy=XI_Compatibility::legacy_active();
  self::add($checks,'source','环境','共享插件版本','pass','当前安装版本：'.XI_VERSION.'；发布包签名与内容须另行核验');
  self::add($checks,'php','环境','PHP 运行环境',version_compare(PHP_VERSION,'8.2','>=')?'pass':'blocked',PHP_VERSION);
  self::add($checks,'wordpress','环境','WordPress 运行环境',version_compare(get_bloginfo('version'),'6.9','>=')?'pass':'blocked',get_bloginfo('version'));
  self::add($checks,'https','环境','HTTPS',is_ssl()?'pass':'warning',is_ssl()?'当前后台使用 HTTPS':'需验证前台和反向代理的 HTTPS 配置');
  self::add($checks,'bricks','环境','Bricks 原生表单环境',defined('BRICKS_VERSION')?'pass':'blocked',defined('BRICKS_VERSION')?'Bricks '.BRICKS_VERSION:'未检测到 Bricks');
  self::add($checks,'stored_profile','独立配置','本站配置已保存',$saved?'pass':'blocked',$saved?'独立配置存在；升级应保留':'尚未导入或保存本站配置；不能把默认身份当作本站身份');
  $errors=self::profile_errors($c);
  self::add($checks,'profile','独立配置','编号、队列和产品配置',$errors?'blocked':'pass',$errors?'待修正：'.implode(', ',$errors):($saved?'基本格式及已注册产品类型/分类匹配':'默认结构有效，仍需保存本站配置'));
  self::add($checks,'legacy','接管','旧业务核心',$legacy?'warning':'pass',$legacy?'仍检测到旧询盘核心；配置准备期可共存，业务接管前需安排唯一处理者':'未检测到已知旧询盘核心；仍需核旧代码片段和标签');
  self::add($checks,'core','接管','统一处理开关',($c['enabled']??false)===true?'pass':'warning',($c['enabled']??false)===true?'设置为启用；实际原生保存仍需验收':'当前保持关闭/准备状态');
  $incomplete=($c['migration']['status']??'')==='import_preparing';
  self::add($checks,'migration','接管','配置导入完整性',$incomplete?'blocked':'pass',$incomplete?'上次导入未完成；核对同一来源后重试，完成前不能启用统一处理':'未发现正在准备的部分导入；新站仍须完成独立配置');
  $api=XI_Config::api_base($c['api']??'',true);
  self::add($checks,'crm_api','小满','API 环境',is_wp_error($api)?'blocked':'pass',is_wp_error($api)?'尚未选择有效小满接口':'使用允许的小满 API 地址；未发送验证请求');
  $credentials=(bool)XI_Config::credential('id')&&(bool)XI_Config::credential('secret');
  self::add($checks,'credentials','小满','客户端凭据可读取',$credentials?'pass':'warning',$credentials?'已配置；不展示值，也未验证远端权限':'未找到可读取的完整凭据；请在设置中核对');
  $crm=($c['crm']??false)===true;
  self::add($checks,'crm_toggle','小满','同步开关',$crm?'pass':'warning',$crm?'允许同步；本页不排队或发送':'同步暂停；恢复开关不会自动补发全部暂停期间记录');
  $crm_mapping=self::matches($c['origin_id']??'', '/^[1-9][0-9]*$/D')&&self::matches($c['product_field']??'', '/^[1-9][0-9]*$/D');
  self::add($checks,'crm_mapping','小满','来源和产品字段',$crm_mapping?'pass':($crm?'blocked':'warning'),$crm_mapping?'来源/产品字段已填有效 ID；远端字段归属仍需核对':'来源或产品字段尚未配置完整');
  global $wpdb;
  self::add($checks,'native_table','保存与队列','Bricks 提交表',self::table_exists($wpdb->prefix.'bricks_form_submissions')?'pass':'warning','只核表存在性；本页不读取客户提交内容');
  self::add($checks,'scheduler','保存与队列','队列调度接口',function_exists('as_enqueue_async_action')?'pass':'warning',function_exists('as_enqueue_async_action')?'接口存在；外部 cron、消费者和真实重试仍需核验':'尚未检测到队列接口');
  $last=get_option(($c['queue_prefix']??'xi_').'last_recovery_v1',0);
  self::add($checks,'recovery','保存与队列','最近恢复记录',is_numeric($last)&&(int)$last>0?'pass':'warning',is_numeric($last)&&(int)$last>0?'记录时间 UTC：'.gmdate('Y-m-d H:i:s',(int)$last):'未见有效时间；不据此判定实时队列健康');
  $attribute=self::service($c['consent_service']??'','',XI_Cookies::definitions());
  self::add($checks,'attribution_service','Cookie','来源存储声明',$attribute['state']==='local_match'?'pass':'warning',$attribute['reason']);
  $conversion_enabled=($conversion['enabled']??false)===true;$required=$conversion_enabled?'blocked':'warning';
  foreach(array('enabled','enhanced') as $flag)if(array_key_exists($flag,$conversion)&&!is_bool($conversion[$flag]))self::add($checks,'conversion_'.$flag.'_type','Google 配置','转化开关类型','blocked','转化开关必须保存为布尔值，请核对配置');
  foreach(array('gtm_id'=>array('GTM 容器','/^GTM-[A-Z0-9]+$/D'),'ga4_id'=>array('GA4 衡量 ID','/^G-[A-Z0-9]+$/D'),'ads_id'=>array('Ads 转化 ID','/^AW-[0-9]+$/D'),'ads_label'=>array('Ads 表单标签','/^[A-Za-z0-9_-]+$/D')) as $key=>$definition) {
   $value=$conversion[$key]??'';$valid=self::matches($value,$definition[1]);
   self::add($checks,$key,'Google 配置',$definition[0],$valid?'pass':$required,$valid?$value:'尚未填写有效值；必须使用本站账号');
  }
  $bound=$conversion['site_host']??'';
  self::add($checks,'conversion_host','Google 配置','转化域名绑定',$bound===$host?'pass':$required,$bound===$host?'与当前域名一致':'未绑定当前域名；不能发送其他站点的转化');
  $mode=array_key_exists('purpose_consent_enabled',$conversion)?(is_bool($conversion['purpose_consent_enabled'])?($conversion['purpose_consent_enabled']?'purpose':'service'):'invalid'):'absent';
  self::add($checks,'purpose_mode','Google 配置','同意模式显式配置',$mode==='invalid'?'blocked':($mode==='absent'?'warning':'pass'),array('purpose'=>'已显式选择服务与实时用途双重检查；实时 reader 仍需外部验证','service'=>'已显式选择服务模式；升级前核对原链路模式','absent'=>'缺少显式模式；不能据默认值认定已完成接管','invalid'=>'模式类型无效，必须使用布尔 true 或 false')[$mode]);
  $pending=array(array('name'=>'xi_conversion_pending_v1','type'=>'session','host'=>$host,'duration'=>30,'durationUnit'=>'m'));
  foreach(array('analytics_service'=>array('统计及待发收据声明','analytics_storage',$pending),'ads_service'=>array('广告存储服务','ad_storage',array())) as $key=>$definition) {
   $status=self::service($conversion[$key]??'',$definition[1],$definition[2]);
   self::add($checks,$key,'Cookie',$definition[0],$status['state']==='local_match'?'pass':$required,$status['reason']);
  }
  if (!empty($conversion['pending_ads_storage_service'])) {
   $status=self::service($conversion['pending_ads_storage_service'],'ad_storage',$pending);
   self::add($checks,'ads_pending_service','Cookie','仅广告同意的待发收据声明',$status['state']==='local_match'?'pass':$required,$status['reason']);
  } else self::add($checks,'ads_pending_service','Cookie','仅广告同意的待发收据声明','warning','未单独配置；不得假定拒绝统计时仍可存储恢复指针');
  if (($conversion['enhanced']??false)===true) {
   $status=self::service($conversion['user_data_service']??'','ad_user_data');
   self::add($checks,'enhanced_service','Cookie','增强型用途声明',$status['state']==='local_match'?'pass':$required,$status['reason'].'；提交与发送双时点及实际哈希仍需验收');
  } else self::add($checks,'enhanced_service','Cookie','增强型转化','warning','当前未启用；本页不会采集或发送用户数据');
  $receipt=get_option('xi_conversion_receipt_schema_v1','')==='owned-v1'&&self::receipt_structure();
  self::add($checks,'receipt','转化去重','收据表结构与唯一索引',$receipt?'pass':$required,$receipt?'版本、必要列和编号/token 唯一索引匹配；不读取收据内容':'收据结构尚未完整；本页不会创建或升级数据库');
  $mcp=get_option('xi_mcp_readonly_v1',array());$mcp=is_array($mcp)?$mcp:array();
  $mcp_enabled=($mcp['enabled']??false)===true&&($mcp['site_host']??'')===$host;
  self::add($checks,'mcp','只读查询','MCP 查询配置',$mcp_enabled&&function_exists('wp_register_ability')?'pass':'warning',$mcp_enabled?(function_exists('wp_register_ability')?'已绑定本站；实际客户端连接和五项默认脱敏查询仍需验收':'已绑定本站，但尚未检测到 WordPress Abilities API'):'尚未启用本站查询；本页不开放权限或读取客户记录');
  $external=array('原生表单字段、动作顺序、保存/失败、编号、语言跳转和手机布局','GTM 唯一安装、已发布标签、同意先后顺序、实时 reader 及旧发送者停用','RCB 公开编译、适用地域、选择/拒绝/撤回和存储到期清理','Cloudflare、LiteSpeed 与优化器的感谢页/参数/API/非 GET 缓存排除','小满实际字段权限、通知、唯一客户及失败后的安全重试','GA4 实际接收、Ads transaction_id/增强型哈希和成熟报表归因','真实客户端的五项 MCP 查询、默认脱敏及固定快照分页对账');
  $counts=array('pass'=>0,'warning'=>0,'blocked'=>0);
  foreach($checks as $check)$counts[$check['state']]++;
  return array('version'=>XI_VERSION,'site_host'=>$host,'checks'=>$checks,'local_counts'=>$counts,'external_checks'=>$external,'external_acceptance'=>'not_checked','lead_name_date_rule'=>'Asia/Shanghai 17:30 起用次日；实际提交时间不变');
 }
 static function page() {
  if(!current_user_can('manage_options'))return;
  $r=self::snapshot();if(is_wp_error($r))return;
  echo '<div class="wrap"><h1>统一配置检查</h1><p>同一套源码、同一个安装包；以下为本站配置与本地结构检查。</p><p>检查不会保存配置、创建表、排队、提交询盘或发送 Google/小满请求。本地匹配不代表外部配置或业务验收通过。</p>';
  echo '<p>站点：'.esc_html($r['site_host']).'；版本：'.esc_html($r['version']).'。本地匹配 '.(int)$r['local_counts']['pass'].' 项，待核 '.(int)$r['local_counts']['warning'].' 项，阻断 '.(int)$r['local_counts']['blocked'].' 项。</p>';
  echo '<table class="widefat striped"><thead><tr><th>范围</th><th>检查项</th><th>本地状态</th><th>说明</th></tr></thead><tbody>';
  $labels=array('pass'=>'本地匹配','warning'=>'待核','blocked'=>'需修正');
  foreach($r['checks'] as $check)echo '<tr><td>'.esc_html($check['group']).'</td><td>'.esc_html($check['title']).'</td><td>'.esc_html($labels[$check['state']]).'</td><td>'.esc_html($check['detail']).'</td></tr>';
  echo '</tbody></table><h2>外部验收：尚未由本页验证</h2><ul>';
  foreach($r['external_checks'] as $item)echo '<li>'.esc_html($item).'</li>';
  echo '</ul><p>'.esc_html($r['lead_name_date_rule']).'</p><p><a href="'.esc_url(admin_url('admin.php?page=xiaoman-inquiry-settings')).'">小满询盘设置</a> · <a href="'.esc_url(admin_url('admin.php?page=xiaoman-conversions')).'">转化与对账</a></p></div>';
 }
}
