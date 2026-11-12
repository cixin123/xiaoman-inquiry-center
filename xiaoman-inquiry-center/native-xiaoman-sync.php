<?php
/** Durable native-inquiry queue. IDs and locks are separate from Bricksforge. */
if (!defined('ABSPATH')) return;
final class XI_Native_Xiaoman_Sync {
 const JOB='xi_send_v1';
 const SWEEP='xi_recover_v1';
 const GROUP='xiaoman-xiaoman';
 const PAGE='xiaoman-inquiry-sync';
 const CURSOR=XI_QUEUE_CURSOR;
 static function crm_enabled() { return !empty(XI_Config::get()['crm']); }
 static function boot() {
  
  add_action('init',array(__CLASS__,'setup'),25);
  add_action(self::JOB,array(__CLASS__,'send'),10,1);
  add_action(self::SWEEP,array(__CLASS__,'recover'));
  add_action('admin_menu',array(__CLASS__,'menu'),40);
 }
 static function key($id) { return XI_QUEUE_PREFIX.'job_v1_'.absint($id); }
 static function row($id) { return get_option(self::key($id),array()); }
 static function requested($record,$id) {
  return !XI_Native_Inquiry_Trash::has($id) && (!empty($record['sync_requested']) || (bool)get_option(XI_QUEUE_PREFIX.'manual_v1_'.absint($id),false));
 }
 static function authorize_history($id) {
  if(!current_user_can('manage_options')) return false;
  if(!self::crm_enabled()) return false;
  $id=absint($id);$saved=XI_Native_Inquiry_Center::row($id);$record=$saved?XI_Native_Inquiry_Center::record($saved):array();
  // Imported history inquiries stay on the website only (owner decision 2026-10-24); they are never sent to Xiaoman.
  if(!$saved || !$record || !empty($record['import']) || XI_Native_Inquiry_Trash::has($id)) return false;
  if(self::row($id)) return true;
  add_option(XI_QUEUE_PREFIX.'manual_v1_'.$id,array('user_id'=>get_current_user_id(),'at'=>gmdate('Y-m-d H:i:s')),'',false);
  return self::capture($id);
 }
 static function setup() {
  register_post_type(XI_QUEUE_CPT,array('public'=>false,'publicly_queryable'=>false,'show_ui'=>false,'show_in_rest'=>false,'supports'=>array()));
  if(get_option(self::CURSOR,null)===null && XI_Native_Inquiry_Center::exists()) {
   global $wpdb;
   // Enable new native inquiries only. Historical QA records are never auto-sent.
   add_option(self::CURSOR,(int)$wpdb->get_var('SELECT MAX(id) FROM '.XI_Native_Inquiry_Center::table()),'',false);
  }
  if(function_exists('as_schedule_recurring_action') && !as_has_scheduled_action(self::SWEEP,array(),self::GROUP))
   as_schedule_recurring_action(time()+60,60,self::SWEEP,array(),self::GROUP,true);
 }
 static function stop() {
  if(function_exists('as_unschedule_all_actions')) {
   as_unschedule_all_actions(self::SWEEP,array(),self::GROUP);
   as_unschedule_all_actions(self::JOB,array(),self::GROUP);
  }
 }
 static function payload($saved) {
  $record=XI_Native_Inquiry_Center::record($saved);
  if(!$record || !self::requested($record,$saved['id']??0)) return new WP_Error('source','此询盘没有启用 Custom 同步动作');
  $stamp=strtotime(($record['submitted_at']??'').' UTC');
  if(!$stamp) return new WP_Error('date','原始询盘时间无效');
  $values=array_merge($record['values']??array(),$record['hd']??array(),array('IP'=>$record['country']??''));
  // A model line represents a saved selection from a detail/category form only.
  // Ignore a stale title when no product was chosen, and never add one to quick forms.
  if(($record['kind']??'')!=='detail' || !absint($values['product_id']??0)) $values['F2']='';
  $fields=array(); $labels=XI_Native_Inquiry_Center::labels($record['kind']);
  foreach($values as $key=>$value) $fields[]=array('id'=>$key,'label'=>$labels[$key]??$key,'value'=>$value);
  $payload=XI_Xiaoman_Queue_V1::payload(array('id'=>(int)$saved['id'],'native'=>true,'fields'=>$fields,'attribution'=>$record['attribution']??array(),'source'=>(string)($record['source']??'')),$stamp);
  return $payload;
 }
 static function index($id,$row) {
  if(!empty($row['index_id'])) return true;
  $index=wp_insert_post(array('post_type'=>XI_QUEUE_CPT,'post_status'=>'private','post_title'=>XI_Native_Inquiry_Center::number($id),
   'meta_input'=>array('_'.XI_QUEUE_PREFIX.'submission'=>$id,'_'.XI_QUEUE_PREFIX.'status'=>$row['status'])),true);
  return !is_wp_error($index)&&$index&&self::set($id,array('index_id'=>$index));
 }
 static function capture($id) {
  // False keeps a recovery pass from advancing its cursor while CRM is paused.
  if(!self::crm_enabled()) return false;
  global $wpdb; $insert=$wpdb->insert_id;
  try {
   $id=absint($id); if(XI_Native_Inquiry_Trash::has($id)) return true;
   $existing=self::row($id);
   if($existing) return self::index($id,$existing);
   $saved=XI_Native_Inquiry_Center::row($id); $record=XI_Native_Inquiry_Center::record($saved?:array());
   if(!$record || !self::requested($record,$id)) return true;
   $payload=self::payload($saved); $bad=is_wp_error($payload);
   $row=array('submission_id'=>$id,'form_id'=>$record['form_id'],'created_at'=>$record['submitted_at'],'updated_at'=>gmdate('Y-m-d H:i:s'),
    'status'=>$bad?'invalid':'queued','attempts'=>0,'next_at'=>time(),'lead_id'=>'','lead_name'=>$bad?'':$payload['name'],
    'detail'=>$bad?$payload->get_error_message():'原生询盘已保存，等待小满同步','payload'=>$bad?'':wp_json_encode($payload),'index_id'=>0);
   if(!add_option(self::key($id),$row,'',false)) return (bool)self::row($id);
   $indexed=self::index($id,$row);
   if(!$bad) self::enqueue($id);
   return $indexed;
  } finally { $wpdb->insert_id=$insert; }
 }
 static function set($id,$values) {
  $row=self::row($id); if(!$row) return false;
  $row=array_merge($row,$values,array('updated_at'=>gmdate('Y-m-d H:i:s')));
  update_option(self::key($id),$row,false);
  if(self::row($id)!==$row) return false;
  if(!empty($row['index_id'])) update_post_meta($row['index_id'],'_'.XI_QUEUE_PREFIX.'status',$row['status']);
  return true;
 }
 static function enqueue($id,$delay=0) {
  if(!self::crm_enabled()) return;
  if(!function_exists('as_enqueue_async_action')) return;
  if($delay) as_schedule_single_action(time()+$delay,self::JOB,array((int)$id),self::GROUP,true);
  else as_enqueue_async_action(self::JOB,array((int)$id),self::GROUP,true);
 }
 static function retry($id,$attempt,$detail) {
  if(!self::crm_enabled()) return;
  if($attempt>=4) { self::set($id,array('status'=>'failed','detail'=>$detail.'；自动重试已用完'));return; }
  $delays=array(1=>60,2=>300,3=>1800);$delay=$delays[$attempt]??1800;
  if(self::set($id,array('status'=>'retry','detail'=>$detail,'next_at'=>time()+$delay))) self::enqueue($id,$delay);
 }
 static function send($id) {
  if(!self::crm_enabled()) return;
  $id=absint($id); $lock=XI_QUEUE_PREFIX.'lock_v1_'.$id;
  if(!add_option($lock,time(),'',false)) return;
  try {
   $row=self::row($id);
   if(!$row||!in_array($row['status'],array('queued','retry'),true)||$row['next_at']>time()) return;
   if(XI_Native_Inquiry_Trash::has($id)) return;
   if(!XI_Native_Inquiry_Center::row($id)) {self::set($id,array('status'=>'deleted','payload'=>'','detail'=>'原生询盘已不存在，停止同步'));return;}
   $attempt=(int)$row['attempts']+1;
   if(!self::set($id,array('status'=>'authorizing','attempts'=>$attempt))) return;
   $token=XI_Xiaoman_Queue_V1::token();
   if(is_wp_error($token)) { self::retry($id,$attempt,$token->get_error_message());return; }
   if(empty($row['payload'])||!is_array(json_decode($row['payload'],true))) {self::set($id,array('status'=>'invalid','detail'=>'同步数据缺失'));return;}
   // Never replay a create request with an unknown outcome.
   if(!self::set($id,array('status'=>'sending','detail'=>'正在请求小满'))) return;
   $response=wp_remote_post(XI_Xiaoman_Queue_V1::API.'/v1/lead/push',array('timeout'=>25,'redirection'=>0,
    'headers'=>array('Content-Type'=>'application/json','Accept'=>'application/json','Authorization'=>$token),'body'=>$row['payload']));
   if(is_wp_error($response)) {self::set($id,array('status'=>'uncertain','detail'=>'创建请求网络异常，结果未知，请按询盘编号核对小满'));return;}
   $http=(int)wp_remote_retrieve_response_code($response);$data=json_decode(wp_remote_retrieve_body($response),true);
   $code=is_array($data)&&is_numeric($data['code']??null)?(int)$data['code']:0;
   $lead=is_array($data['data']??null)?($data['data']['lead_id']??''):'';
   if($http>=200&&$http<300&&$code===200&&is_scalar($lead)&&preg_match('/^[1-9][0-9]*$/D',(string)$lead)) {
    self::set($id,array('status'=>'success','lead_id'=>(string)$lead,'detail'=>'已创建小满线索','payload'=>''));return;
   }
   $summary='HTTP '.$http.' / API '.$code;
   if($http===401||($http<500&&$code===401)) {delete_transient(XI_Xiaoman_Queue_V1::TOKEN);self::retry($id,$attempt,'令牌已清除，等待重试；'.$summary);return;}
   if($http===429||($http<500&&$code===429)) {self::retry($id,$attempt,'接口限流；'.$summary);return;}
   if(in_array($http,array(400,403,404,422),true)||($http>=200&&$http<300&&in_array($code,array(400,403,404,422),true))) {
    self::set($id,array('status'=>'rejected','detail'=>'小满拒绝；'.$summary.'；'.XI_Xiaoman_Queue_V1::error_hint($data,$row['payload'],$token)));return;
   }
   self::set($id,array('status'=>'uncertain','detail'=>'结果无法确认；'.$summary.'；请核对小满后处理'));
  } catch(Throwable $e) {self::set($id,array('status'=>'uncertain','detail'=>'后台执行中断，请按询盘编号核对小满'));}
  finally {delete_option($lock);}
 }
 static function recover() {
  if(!self::crm_enabled()) return;
  if(!XI_Native_Inquiry_Center::exists()) return;
  global $wpdb;$cursor=get_option(self::CURSOR,null);if($cursor===null)return;
  $rows=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.XI_Native_Inquiry_Center::table().' WHERE id>%d ORDER BY id ASC LIMIT 100',(int)$cursor),ARRAY_A);
  foreach($rows as $saved) {
   if(!self::capture($saved['id'])) break;
   update_option(self::CURSOR,(int)$saved['id'],false);
  }
  $posts=get_posts(array('post_type'=>XI_QUEUE_CPT,'post_status'=>'private','numberposts'=>50,'orderby'=>'ID','order'=>'ASC',
   'meta_query'=>array(array('key'=>'_'.XI_QUEUE_PREFIX.'status','value'=>array('queued','retry','authorizing','sending'),'compare'=>'IN'))));
  foreach($posts as $post) {
   $id=absint(get_post_meta($post->ID,'_'.XI_QUEUE_PREFIX.'submission',true));$row=self::row($id);if(!$row||XI_Native_Inquiry_Trash::has($id))continue;
   $lock=(int)get_option(XI_QUEUE_PREFIX.'lock_v1_'.$id,0);$stale=strtotime($row['updated_at'].' UTC')<time()-600;
   if(($lock&&$lock<time()-600)||(!$lock&&$stale&&in_array($row['status'],array('sending','authorizing'),true))) {
    self::set($id,array('status'=>'uncertain','detail'=>'后台进程中断，请核对小满，未重复创建'));continue;
   }
   if(!$lock&&in_array($row['status'],array('queued','retry'),true)&&$row['next_at']<=time()) self::enqueue($id);
  }
  update_option(XI_QUEUE_PREFIX.'last_recovery_v1',time(),false);
 }
 static function status($id) {
  $row=self::row($id);$labels=array('queued'=>'待同步','retry'=>'等待重试','authorizing'=>'验证接口','sending'=>'同步中','success'=>'同步成功','invalid'=>'字段待修正','rejected'=>'接口拒绝','failed'=>'同步失败','uncertain'=>'待核对，暂停重试','trashed'=>'回收站 · 同步已暂停','deleted'=>'网站询盘已删除');
  $text=$row?($labels[$row['status']]??$row['status']).(!empty($row['lead_id'])?' · '.$row['lead_id']:''):'未进入同步队列';
  return XI_Native_Inquiry_Trash::has($id)&&($row['status']??'')!=='trashed'?'回收站 · '.$text:$text;
 }
 static function resolve($id,$lead_id,$review){
  if(!current_user_can('manage_options'))return new WP_Error('permission','没有核对权限。');
  if(!self::crm_enabled())return new WP_Error('crm_paused','小满同步已暂停，请先启用 CRM 同步。');
  $id=absint($id);$review=XI_Xiaoman_Queue_V1::text($review,500);
  if($review==='')return new WP_Error('review','请填写小满核对结果。');
  if($lead_id!==''&&!preg_match('/^[1-9][0-9]*$/D',$lead_id))return new WP_Error('lead','小满线索 ID 格式无效。');
  $lock=XI_QUEUE_PREFIX.'lock_v1_'.$id;$old=get_option($lock,0);
  if($old&&is_numeric($old)&&(int)$old<time()-600){global $wpdb;$wpdb->delete($wpdb->options,array('option_name'=>$lock,'option_value'=>(string)$old),array('%s','%s'));wp_cache_delete($lock,'options');wp_cache_delete('notoptions','options');}
  if(!add_option($lock,time(),'',false))return new WP_Error('busy','任务仍在运行，请稍后核对。');
  try{$row=self::row($id);if(!$row||$row['status']!=='uncertain'||XI_Native_Inquiry_Trash::has($id))return new WP_Error('state','任务状态不支持核对。');
   $values=array('review'=>array('at'=>gmdate('c'),'user_id'=>get_current_user_id(),'note'=>$review));
   if($lead_id!=='')$values=array_merge($values,array('status'=>'success','lead_id'=>$lead_id,'payload'=>'','detail'=>'已人工核对小满线索'));
   else{if(empty($row['payload']))return new WP_Error('payload','缺少待同步内容，不能重试。');$values=array_merge($values,array('status'=>'queued','attempts'=>0,'next_at'=>time(),'detail'=>'已核对小满未创建，人工重新排队'));}
   if(!self::set($id,$values))return new WP_Error('save','保存核对结果失败。');if($lead_id==='')self::enqueue($id);return true;
  }finally{delete_option($lock);}
 }
 static function url() {return admin_url('admin.php?page='.self::PAGE);}
 static function menu() {add_submenu_page(XI_Native_Inquiry_Center::PAGE,'小满询盘同步','小满询盘同步','manage_options',self::PAGE,array(__CLASS__,'page'));}
 static function page() {
  if(!current_user_can('manage_options')) wp_die('没有权限',403);
  if(($_SERVER['REQUEST_METHOD']??'')==='POST' && !self::crm_enabled()) {
   echo '<div class="notice notice-warning"><p>小满同步已暂停，任务和原生询盘已保留。请先启用 CRM 同步再执行操作。</p></div>';
  } elseif(($_SERVER['REQUEST_METHOD']??'')==='POST') {
   if(isset($_POST['backfill_id'])) {
    check_admin_referer('xi_backfill');$raw=trim((string)wp_unslash($_POST['backfill_id']));
    if(preg_match('/^(?:'.preg_quote(XI_PROFILE['number_prefix'],'/').')?([1-9][0-9]*)$/iD',$raw,$m) && self::authorize_history($m[1]))
     echo '<div class="notice notice-success"><p>指定询盘已加入同步队列；已同步的记录不会重复创建。</p></div>';
    else echo '<div class="notice notice-error"><p>补同步失败，请检查原生询盘编号。</p></div>';
   }
   if(isset($_POST['wake'])) {check_admin_referer('xi_wake');self::recover();}
   if(isset($_POST['resolve_id'])){$id=absint($_POST['resolve_id']);check_admin_referer('xi_resolve_'.$id);$lead=trim(sanitize_text_field(wp_unslash($_POST['review_lead']??'')));$result=self::resolve($id,$lead,wp_unslash($_POST['review_note']??''));echo '<div class="notice '.(is_wp_error($result)?'notice-error':'notice-success').'"><p>'.esc_html(is_wp_error($result)?$result->get_error_message():'核对结果已保存。').'</p></div>';}
   if(isset($_POST['send_id'])) { $id=absint($_POST['send_id']);check_admin_referer('xi_send_'.$id);self::send($id); }
   if(isset($_POST['retry_id'])) {
    $id=absint($_POST['retry_id']);check_admin_referer('xi_retry_'.$id);$row=self::row($id);
    if($row&&in_array($row['status'],array('invalid','rejected','failed'),true)&&empty($row['lead_id'])) {
     $payload=self::payload(XI_Native_Inquiry_Center::row($id)?:array());
     if(is_wp_error($payload)) self::set($id,array('detail'=>$payload->get_error_message()));
     elseif(self::set($id,array('status'=>'queued','attempts'=>0,'next_at'=>time(),'payload'=>wp_json_encode($payload),'lead_name'=>$payload['name'],'detail'=>'配置修正后重新排队'))) self::enqueue($id);
    }
   }
  }
  $page=max(1,absint($_GET['paged']??1));$q=new WP_Query(array('post_type'=>XI_QUEUE_CPT,'post_status'=>'private','posts_per_page'=>50,'paged'=>$page,'orderby'=>'ID','order'=>'DESC'));
  echo '<div class="wrap"><h1>小满询盘同步</h1><p>网站收到的询盘先保存在本地，再由后台同步小满。每页 50 条。</p>';
  echo '<p><a href="'.esc_url(XI_Native_Inquiry_Center::url()).'">返回询盘中心</a> · <a href="'.esc_url(admin_url('admin.php?page=xiaoman-inquiry-settings')).'">接口配置检查</a></p>';
  echo '<p>队列组件：'.(function_exists('as_enqueue_async_action')?'已就绪':'未加载').'；凭证：'.(XI_Config::credential('id')&&XI_Config::credential('secret')?'已读取':'未配置').'。创建结果未知时暂停，避免重复线索。</p>';
  echo '<form method="post">';wp_nonce_field('xi_wake');echo '<button class="button" name="wake" value="1">唤醒待同步任务</button> <a class="button" href="'.esc_url(self::url()).'">刷新状态</a></form><br>';
  echo '<details><summary>补同步指定的历史原生询盘</summary><p>仅处理填写的询盘编号。请先确认它是真实询盘，并且未在小满手工创建。</p><form method="post">';
  wp_nonce_field('xi_backfill');
  echo '<label>询盘编号 <input type="text" name="backfill_id" placeholder="'.esc_attr(XI_Native_Inquiry_Center::number(11)).'" required></label> <button class="button">补同步此询盘</button></form></details><br>';
  echo '<table class="widefat striped"><thead><tr><th>询盘编号 / 日期</th><th>状态</th><th>尝试</th><th>小满线索 ID / 名称</th><th>结果 / 操作</th></tr></thead><tbody>';
  foreach($q->posts as $post) {
   $id=absint(get_post_meta($post->ID,'_'.XI_QUEUE_PREFIX.'submission',true));$row=self::row($id);if(!$row)continue;
   echo '<tr><td><a href="'.esc_url(XI_Native_Inquiry_Center::url(array('inquiry'=>$id))).'">'.esc_html(XI_Native_Inquiry_Center::number($id)).'</a><br>'.esc_html(wp_date('Y-m-d H:i:s',strtotime($row['created_at'].' UTC'))).'</td><td>'.esc_html(self::status($id)).'</td><td>'.(int)$row['attempts'].'</td><td>'.esc_html($row['lead_id']).'<br>'.esc_html($row['lead_name']).'</td><td>'.esc_html($row['detail']);
   if(!XI_Native_Inquiry_Trash::has($id)&&in_array($row['status'],array('queued','retry'),true)) {echo '<form method="post">';wp_nonce_field('xi_send_'.$id);echo '<button class="button" name="send_id" value="'.$id.'">执行待同步任务</button></form>';}
   if(!XI_Native_Inquiry_Trash::has($id)&&in_array($row['status'],array('invalid','rejected','failed'),true)) {echo '<form method="post">';wp_nonce_field('xi_retry_'.$id);echo '<button class="button" name="retry_id" value="'.$id.'">修正后重试</button></form>';}
   if(!XI_Native_Inquiry_Trash::has($id)&&$row['status']==='uncertain'){echo '<details><summary>人工核对小满结果</summary><form method="post">';wp_nonce_field('xi_resolve_'.$id);echo '<input type="hidden" name="resolve_id" value="'.$id.'"><p>先在小满按询盘编号核对。已创建则填写线索 ID；确认未创建才留空重新排队。</p><p><input name="review_lead" placeholder="已创建的小满线索 ID"></p><p><input name="review_note" placeholder="填写已核对的结果" required></p><button class="button">保存核对结果</button></form></details>';}
   echo '</td></tr>';
  }
  if(!$q->posts)echo '<tr><td colspan="5">暂无原生同步记录。新提交后自动进入队列。</td></tr>';
  echo '</tbody></table><p>共 '.(int)$q->found_posts.' 条，第 '.$page.' / '.max(1,(int)$q->max_num_pages).' 页 ';
  if($page>1)echo '<a class="button" href="'.esc_url(add_query_arg('paged',$page-1,self::url())).'">上一页</a> ';
  if($page<$q->max_num_pages)echo '<a class="button" href="'.esc_url(add_query_arg('paged',$page+1,self::url())).'">下一页</a>';
  echo '</p></div>';
 }
}
add_action('plugins_loaded',array('XI_Native_Xiaoman_Sync','boot'),22);
