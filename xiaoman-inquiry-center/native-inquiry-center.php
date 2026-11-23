<?php
/** Native Bricks inbox. Own storage and a separate native CRM queue. */
if (!defined('ABSPATH')) return;
final class XI_Native_Inquiry_Center {
 const META = XI_RECORD_META;
 const PAGE = 'xiaoman-inquiry-center';
 const CAP = XI_VIEW_CAP;
 const ROLE = XI_VIEW_ROLE;
 const PER_PAGE = 50;
 static $requests = array();
 static function boot() {
  
  add_filter('bricks/element/render_attributes', array(__CLASS__,'attributes'),20,3);
  add_action('wp_enqueue_scripts',array(__CLASS__,'context_assets'));
  add_action('wp_ajax_bricks_form_submit',array(__CLASS__,'preview_request'),1);
  add_filter('bricks/form/validate',array(__CLASS__,'validate'),30,2);
  add_filter('bricks/form/save-submission/form_data',array(__CLASS__,'enrich'),30,3);
  add_filter('bricks/form/response',array(__CLASS__,'response'),30,2);
  add_action('admin_menu',array(__CLASS__,'menu'),30);
  add_action('init',array(__CLASS__,'ensure_role'),5);
  add_action('admin_init',array(__CLASS__,'restrict_admin'),1);
  add_action('admin_menu',array(__CLASS__,'restrict_admin_menu'),999);
  add_filter('login_redirect',array(__CLASS__,'login_redirect'),30,3);
  add_action('admin_bar_menu',array(__CLASS__,'restrict_admin_bar'),999);
  add_filter('admin_body_class',array(__CLASS__,'admin_body_class'));
  add_action('admin_post_xi_native_read',array(__CLASS__,'read_action'));
  add_action('admin_post_xi_native_export',array(__CLASS__,'export'));
 }
 static function text($v,$n=1000) { return XI_Xiaoman_Queue_V1::text($v,$n); }
 static function table() { global $wpdb; return $wpdb->prefix.'bricks_form_submissions'; }
 static function exists() {
  global $wpdb; return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like(self::table())))===self::table();
 }
 static function fields($settings) {
  $aliases=array('Equipment'=>'F1','Product'=>'F2','FullName'=>'F3','LastName'=>'F4','Email'=>'F5','WhatsApp'=>'F6','Message'=>'F9','ProductID'=>'product_id','CategoryID'=>'category_id','CategorySignature'=>'category_signature','CustomizationType'=>'customization_type');
  $out=array(); foreach(($settings['fields']??array()) as $field) {
   if(!is_array($field) || !empty($field['isHoneypot'])) continue;
   $name=$field['name']??'';
   $aliases=array_merge($aliases,XI_Config::get()['field_aliases']);$name=$aliases[$name]??$name;
   if(in_array($name,array('F1','F2','F3','F4','F5','F6','F9','product_id','category_id','category_signature','customization_type'),true)) $out[$name]=$field;
  } return $out;
 }
 static function kind($settings) {
  $fields=self::fields($settings);
  $kinds=array('快速表单'=>'quick','详情页表单'=>'detail');
  $kind=$settings['xiInquiryKind']??($kinds[$settings['submissionFormName']??'']??'');if(!in_array($kind,array('quick','detail'),true))return '';if(!$kind||!isset($fields['F5']))return '';
  if(!isset($fields['F3'],$fields['F6'],$fields['F9']))return '';
  if($kind==='detail' && !isset($fields['F1'],$fields['F2'],$fields['product_id']))return '';
  return $kind;
 }
 static function sign_product($id,$form_id) { return hash_hmac('sha256',absint($id).':'.$form_id,wp_salt('auth')); }
 static function sign_category($id,$form_id) { return hash_hmac('sha256','category:'.absint($id).':'.$form_id,wp_salt('auth')); }
 static function context_assets() {
  wp_enqueue_script('xiaoman-product-context',plugins_url('native-product-context.js',__FILE__),array(),XI_VERSION,array('strategy'=>'defer','in_footer'=>true));
 }

 static function taxonomies() { return XI_PROFILE['taxonomies']; }
 static function term($id) {
  $term=get_term(absint($id));
  return $term && !is_wp_error($term) && in_array($term->taxonomy,self::taxonomies(),true)?$term:null;
 }
 static function equipment($term) {
  $map=XI_Config::get()['equipment_labels'];
  return self::text($map[$term->slug]??html_entity_decode($term->name,ENT_QUOTES,'UTF-8'),150);
 }
 static function product_terms($id) {
  $terms=wp_get_post_terms($id,self::taxonomies());
  if(is_wp_error($terms)) return array();
  return array_values(array_filter($terms,static function($term){return !in_array($term->slug,XI_Config::get()['excluded_terms'],true);}));
 }
 static function category_products($term_id) {
  $term=self::term($term_id); if(!$term) return array();
  $ids=get_posts(array('post_type'=>XI_PROFILE['post_type'],'post_status'=>'publish','posts_per_page'=>-1,'fields'=>'ids',
   'orderby'=>array('menu_order'=>'ASC','title'=>'ASC'),'tax_query'=>array(array('taxonomy'=>$term->taxonomy,'field'=>'term_id','terms'=>$term->term_id,'include_children'=>false))));
  $products=array(); foreach($ids as $id) {
   $p=self::product($id);if(!$p)continue;$p['label']=$p['model'];$products[]=$p;
  }return $products;
 }
 static function category_selection($values,$form_id) {
  $id=absint($values['category_id']??0);$signature=$values['category_signature']??'';
  if((string)($values['category_id']??'')==='0' && is_string($signature) && hash_equals(self::sign_category(0,$form_id),$signature)) {
   if((string)($values['product_id']??'')!=='') return new WP_Error('category_required','Please select a product category first.');
   return array('equipment'=>'Other','product'=>array());
  }
  if(!$id || !is_string($signature) || !hash_equals(self::sign_category($id,$form_id),$signature)) return new WP_Error('category_invalid','Please refresh this category page and try again.');
  $term=self::term($id);if(!$term)return new WP_Error('category_missing','This product category is unavailable.');
  $raw=(string)($values['product_id']??'');$pid=absint($raw);$product=array();
  if($raw!=='') {
   $product=self::product($pid);
   if(!$product || !has_term($id,$term->taxonomy,$pid))return new WP_Error('model_invalid','Please select a product from this category.');
  }
  return array('equipment'=>self::equipment($term),'product'=>$product);
 }
 static function product($id) {
  $id=absint($id);if(get_post_type($id)!==XI_PROFILE['post_type']||get_post_status($id)!=='publish')return array();
  $terms=self::product_terms($id);$category=$terms?self::equipment($terms[0]):'Other';
  return array('id'=>$id,'equipment'=>$category,'model'=>self::text(html_entity_decode(get_the_title($id),ENT_QUOTES,'UTF-8'),300),'url'=>get_permalink($id));
 }
 static function attributes($attributes,$key,$element) {
  if($key!=='_root'||($element->name??'')!=='form'||!($kind=self::kind($element->settings??array())))return $attributes;
  $attributes[$key]['data-xi-inquiry']=$kind;
  $map=array();foreach(self::fields($element->settings??array()) as $semantic=>$field)$map[$semantic]=$field['name']??('form-field-'.($field['id']??''));
  $attributes[$key]['data-xi-field-map']=wp_json_encode($map);
  $term=is_tax(self::taxonomies())?get_queried_object():null;
  $pid=is_singular(XI_PROFILE['post_type'])?get_queried_object_id():0;
  $product=$pid?self::product($pid):array();
  if(!$term && $product){$terms=self::product_terms($pid);$term=$terms[0]??null;}
  if($kind==='quick') {
   if($term instanceof WP_Term)$attributes[$key]['data-xi-equipment']=self::equipment($term);
   return $attributes;
  }
  if($kind!=='detail')return $attributes;
  // Opt-in per form: list every category, starting on this page's category and product.
  $choose=($element->settings['xiEquipmentScope']??'')==='catalog';
  $own=$term instanceof WP_Term && in_array($term->taxonomy,self::taxonomies(),true);
  if(!$choose && $own) {
   $packet=array('id'=>$term->term_id,'name'=>self::equipment($term),'signature'=>self::sign_category($term->term_id,$element->id),'products'=>self::category_products($term->term_id));
   if($product){$attributes[$key]['data-xi-product-page']='true';$packet['selected_product_id']=$pid;}
   $attributes[$key]['data-xi-category']=wp_json_encode($packet);return $attributes;
  }
  // Read the page category before the loop below reuses $term.
  $default=$choose && $own?$term->term_id:0;
  $catalog=array();$terms=get_terms(array('taxonomy'=>self::taxonomies(),'hide_empty'=>true,'orderby'=>'name','order'=>'ASC'));
  if(!is_wp_error($terms))foreach($terms as $term){
   if(in_array($term->slug,XI_Config::get()['excluded_terms'],true))continue;
   $products=self::category_products($term->term_id);if(!$products)continue;
   $catalog[]=array('id'=>$term->term_id,'name'=>self::equipment($term),'signature'=>self::sign_category($term->term_id,$element->id),'products'=>$products);
  }
  $packet=array('categories'=>$catalog,'empty_signature'=>self::sign_category(0,$element->id));
  if($default){$packet['default_category_id']=$default;if($product)$packet['selected_product_id']=$pid;}
  $attributes[$key]['data-xi-catalog']=wp_json_encode($packet);
  return $attributes;
 }
 static function values($settings,$submitted) {
  $out=array(); foreach(self::fields($settings) as $name=>$f) {
   // Names are selected from saved server settings. Random frontend DOM ids are irrelevant.
   $out[$name]=self::text($submitted['form-field-'.($f['id']??'')]??$submitted[$f['name']??'']??$submitted[$name]??'', $name==='F9'?10000:500);
  } return $out;
 }
 static function packet($submitted) {
  $raw=XI_Compatibility::packet($submitted);
  // Native Bricks sends additional FormData keys separately on some versions.
  if(!is_string($raw) || $raw==='') $raw=isset($_POST['xi_context'])?wp_unslash($_POST['xi_context']):'';
  return is_string($raw) && strlen($raw)<=24000?$raw:'';
 }
 static function context_key($form) { return (string)$form->get_id().':'.absint($form->get_post_id()); }
 static function preview_request() {
  // Populate Content previews use the product postId, but the draft form lives in
  // its template. Only an editor's authenticated preview may resolve that template.
  $ref=wp_unslash($_POST['referrer']??$_SERVER['HTTP_REFERER']??'');
  if(!is_string($ref) || wp_parse_url($ref,PHP_URL_HOST)!==XI_HOST) return;
  parse_str((string)wp_parse_url($ref,PHP_URL_QUERY),$q);
  if(($q['post_type']??'')!=='bricks_template' || empty($q['bricks_preview']) || !is_scalar($q['p']??null)) return;
  $id=absint($q['p']);
  if(!$id || !current_user_can('edit_post',$id) || get_post_type($id)!=='bricks_template' || get_post_status($id)!=='draft') return;
  $form_id=is_string($_POST['formId']??null)?wp_unslash($_POST['formId']):'';
  $elements=get_post_meta($id,'_bricks_page_content_2',true);
  if(!is_array($elements)) return;
  foreach($elements as $element) {
   if(($element['id']??'')!==$form_id || ($element['name']??'')!=='form' || !self::kind($element['settings']??array())) continue;
   $_POST['postId']=(string)$id;
   // Bricks continues its own nonce, validation, honeypot, and action checks.
   return;
  }
 }
 static function attribution($request,$server) {
  $p=XI_Request_Attribution_V1::packet($request);$current=is_array($p['_request']??null)?$p['_request']:array();
  $header=XI_Request_Attribution_V1::safe_url(wp_unslash($server['HTTP_REFERER']??''));
  $client=XI_Request_Attribution_V1::safe_url($current['landing_ad_url']??'');
  $same=$header==='' || ($client!=='' && wp_parse_url($header,PHP_URL_PATH)===wp_parse_url($client,PHP_URL_PATH));
  // A new, explicitly tagged visit wins over an older, consented marketing record.
  if($header!=='' && XI_Inquiry_Source_V1::query($header))
   return XI_Request_Attribution_V1::parse($header,$same?($current['referrer']??''):'',$current['consent_status']??'unknown','server_submit_referrer');
  if($same && $client!=='' && XI_Inquiry_Source_V1::query($client))
   return XI_Request_Attribution_V1::parse($client,$current['referrer']??'',$current['consent_status']??'unknown','current_page_fallback');
  return XI_Request_Attribution_V1::submission($request,$server);
 }
 static function validate($errors,$form) {
  if(!is_object($form) || !is_callable(array($form,'get_settings')) || !is_callable(array($form,'get_id'))) return $errors;
  $settings=$form->get_settings(); $kind=self::kind($settings); if(!$kind) return $errors;
  if(!in_array('save-submission',$settings['actions']??array(),true) || !self::exists()) {
   $errors[]='Inquiry saving is unavailable. Please contact us or try again later.'; return $errors;
  }
  $submitted=$form->get_fields(); $values=self::values($settings,$submitted);
  if(($values['F3']??'')==='') $errors[]='Please enter your name.';
  if(!is_email($values['F5']??'')) $errors[]='Please enter a valid email address.';
  if(($values['F6']??'')==='') $errors[]='Please enter your WhatsApp number.';
  $raw=self::packet($submitted); $packet=$raw?json_decode($raw,true):array(); $product=array();
  if($kind==='detail') {
   if(($values['category_id']??'')!=='') {
    $selection=self::category_selection($values,$form->get_id());
    if(is_wp_error($selection)) $errors[]=$selection->get_error_message();
    else {
     $product=$selection['product']; $values['F1']=$selection['equipment'];
     $values['F2']=$product['model']??''; $values['product_id']=isset($product['id'])?(string)$product['id']:'';
    }
   } else {
   $p=is_array($packet['_product']??null)?$packet['_product']:array(); $id=absint($p['id']??0);
   $signature=is_string($p['signature']??null)?$p['signature']:'';
   if($id && hash_equals(self::sign_product($id,$form->get_id()),$signature)) $product=self::product($id);
   // Native product pages also carry a server-selected post context.
   if(!$product) $product=self::product($form->get_post_id());
   if(!$product) $errors[]='The product could not be loaded. Please refresh this product page and try again.';
   else { $values['F1']=$product['equipment']; $values['F2']=$product['model']; $values['product_id']=(string)$product['id']; }
   }
  } elseif(($values['F1']??'')==='') $values['F1']='Other';
  unset($values['category_signature']);
  $request=array('xi_context'=>$raw);
  $attribution=self::attribution($request,$_SERVER);
  if($errors) return $errors;
  $hd=XI_Xiaoman_Queue_V1::request_metadata($request);
  $visitor=XI_Xiaoman_Queue_V1::visitor_context();
  if($attribution) foreach(XI_Request_Attribution_V1::legacy($attribution) as $key=>$value) if($value!=='') $hd[$key]=$value;
  $hd=XI_Request_Attribution_V1::repair_legacy($hd);
  $hd['H3']=$visitor['device']; $hd['H2']=$visitor['ip'];
  // Consented natural Google visit: an ad landing from the same visitor within the window makes it Google Ads (first entry was the ad).
  if(is_array($attribution) && class_exists('XI_Ad_Return_V1')) XI_Ad_Return_V1::check($attribution,$hd,$visitor['ip']);
  $source=self::source($attribution['source_label']??'',$hd);
  if(is_array($attribution) && $source!==($attribution['source_label']??'')) {
   $attribution['source_label']=$source;
   if(strpos($source,'Google Ads')===0) $attribution['source_platform']='google_ads';
  }
  self::$requests[self::context_key($form)]=array('schema'=>1,'uuid'=>wp_generate_uuid4(),'kind'=>$kind,
   'form_name'=>$settings['submissionFormName'],'form_id'=>$form->get_id(),'post_id'=>absint($form->get_post_id()),
   'submitted_at'=>gmdate('Y-m-d H:i:s'),'submitted_timezone'=>'UTC','site_timezone'=>wp_timezone_string(),
   'measurement_consent'=>class_exists('XI_Conversions')?XI_Conversions::states($packet['_measurement']??array()):array(),
   'is_test'=>class_exists('XI_Conversions')&&XI_Conversions::test_record($values),
   'values'=>$values,'country'=>XI_Xiaoman_Queue_V1::country_english($visitor['country']),
   'hd'=>$hd,'source'=>$source,'attribution'=>$attribution,'sync_requested'=>XI_PROFILE['crm'] && (in_array('xiaoman-inquiry',$settings['actions']??array(),true) || in_array('custom',$settings['actions']??array(),true)),'product'=>$product,
   'field_ids'=>array_map(static function($f){return $f['id']??'';},self::fields($settings)));
  return $errors;
 }
 static function enrich($data,$form_id,$post_id) {
  $record=self::$requests[(string)$form_id.':'.absint($post_id)]??null;
  if(!$record || !is_array($data)) return $data;
  foreach($record['values'] as $name=>$value) {
   $id=$record['field_ids'][$name]??'';
   if($id!=='' && isset($data[$id]) && is_array($data[$id])) $data[$id]['value']=$value;
   elseif(isset($data[$name]) && is_array($data[$name])) $data[$name]['value']=$value;
  }
  $data[self::META]=array('type'=>'password','label'=>'询盘中心数据','value'=>wp_json_encode($record));
  return $data;
 }
 static function response($response,$form) {
  if(!is_callable(array($form,'get_id'))) return $response;
  $r=self::$requests[self::context_key($form)]??null;
  if(!$r || ($response['type']??'')!=='success') return $response;
  global $wpdb;
  // Exact request UUID prevents concurrent submits from borrowing each other's saved id.
  $id=$wpdb->get_var($wpdb->prepare('SELECT id FROM '.self::table().' WHERE form_id=%s AND post_id=%d AND form_data LIKE %s LIMIT 1',
   $r['form_id'],$r['post_id'],'%'.$wpdb->esc_like($r['uuid']).'%'));
  if(!$id) return array('type'=>'danger','message'=>'Your inquiry was not saved. Please try again or contact us directly.');
  if(!empty($r['sync_requested']) && class_exists('XI_Native_Xiaoman_Sync')) {
   try { XI_Native_Xiaoman_Sync::capture($id); }
   catch(Throwable $e) { /* Durable metadata lets the background sweep recover this saved inquiry. */ }
  }
  $response['inquiryNumber']=self::number($id);
  return $response;
 }
 static function thank_you_url($record) {
  $base=home_url('/thank-you/');
  if(!class_exists('TRP_Translate_Press')) return $base;
  try {
   $trp=TRP_Translate_Press::get_trp_instance();
   $settings_component=$trp->get_component('settings');
   $converter=$trp->get_component('url_converter');
   $settings=$settings_component->get_settings();
   $languages=is_array($settings['publish-languages']??null)?$settings['publish-languages']:array();
   $source=XI_Request_Attribution_V1::safe_url($record['hd']['H1']??'');
   $source_path=(string)wp_parse_url($source,PHP_URL_PATH);
   foreach($languages as $language) {
    $language_home=$converter->get_url_for_language($language,home_url('/'),'');
    $prefix=rtrim((string)wp_parse_url($language_home,PHP_URL_PATH),'/').'/';
    if($prefix!=='/' && strpos('/'.ltrim($source_path,'/'),$prefix)===0)
     return esc_url_raw($converter->get_url_for_language($language,$base,''));
   }
  } catch(Throwable $e) {
   // Translation lookup must never block a valid inquiry.
  }
  return $base;
 }
 static function number($id) { return XI_PROFILE['number_prefix'].absint($id); }
 static function source($saved,$hd) {
  $saved=is_string($saved)?$saved:'';
  $derived=XI_Inquiry_Source_V1::label(is_array($hd)?$hd:array());
  foreach(array('Google Ads','Microsoft Ads','TikTok ','Facebook ','Meta ','Instagram ') as $prefix)
   if(strpos($derived,$prefix)===0) return $derived;
  return $saved!==''?$saved:$derived;
 }
 static function unread($row) { return !in_array(($row['status']??''),array('read','spam'),true); }
 static function record($row) {
  $data=json_decode($row['form_data']??'',true);
  $r=is_array($data)?($data[self::META]['value']??''):'';
  $r=is_string($r)?json_decode($r,true):null;
  if(!is_array($r) || ($r['schema']??0)!==1) return array();
  $r['hd']=XI_Request_Attribution_V1::repair_legacy(is_array($r['hd']??null)?$r['hd']:array());
  $r['source']=self::source($r['source']??'',$r['hd']);
  if(is_array($r['attribution']??null) && $r['source']!==($r['attribution']['source_label']??'')) {
   $r['attribution']['source_label']=$r['source'];
   if(strpos($r['source'],'Google Ads')===0) $r['attribution']['source_platform']='google_ads';
  }
  return $r;
 }
 static function base_where() {
  global $wpdb; return $wpdb->prepare('form_data LIKE %s','%'.$wpdb->esc_like('"'.self::META.'"').'%');
 }
 static function filters($input) {
  $status=is_string($input['status']??null)?$input['status']:'';
  $kind=is_string($input['kind']??null)?$input['kind']:'';
  return array('status'=>in_array($status,array('read','unread','spam'),true)?$status:'',
   'kind'=>in_array($kind,array('quick','detail'),true)?$kind:'','s'=>self::text($input['s']??'',200),
   'view'=>self::can_manage() && ($input['view']??'')==='trash'?'trash':'');
 }
 static function where($filters) {
  global $wpdb; $where=self::base_where().XI_Native_Inquiry_Trash::clause(($filters['view']??'')==='trash');
  if($filters['status']==='read') $where.=" AND status='read'";
  elseif($filters['status']==='unread') $where.=" AND COALESCE(status,'') NOT IN ('read','spam')";
  elseif($filters['status']==='spam') $where.=" AND status='spam'";
  if($filters['kind']!=='') {
   // form_data contains a JSON string; use the exact escaped metadata pair.
   $where.=$wpdb->prepare(' AND form_data LIKE %s','%'.$wpdb->esc_like('\\"kind\\":\\"'.$filters['kind'].'\\"').'%');
  }
  if($filters['s']!=='') {
   if(preg_match('/^'.preg_quote(XI_PROFILE['number_prefix'],'/').'(\d+)$/iD',$filters['s'],$m)) $where.=$wpdb->prepare(' AND id=%d',absint($m[1]));
   else $where.=$wpdb->prepare(' AND form_data LIKE %s','%'.$wpdb->esc_like($filters['s']).'%');
  } return $where;
 }
 static function paging($count,$requested) {
  $pages=max(1,(int)ceil($count/self::PER_PAGE)); $page=min($pages,max(1,absint($requested)));
  return array('page'=>$page,'pages'=>$pages,'offset'=>($page-1)*self::PER_PAGE);
 }
 static function ensure_role() {
  $caps=array('read'=>true,self::CAP=>true);
  $role=get_role(self::ROLE);
  if(!$role) $role=add_role(self::ROLE,'询盘客服',$caps);
  if($role) foreach($caps as $cap=>$grant) $role->add_cap($cap,$grant);
  $administrator=get_role('administrator');
  if($administrator) $administrator->add_cap(self::CAP,true);
 }
 static function can_manage() { return current_user_can('manage_options'); }
 static function is_viewer() { return is_user_logged_in() && current_user_can(self::CAP) && !self::can_manage(); }
 static function allowed() { return self::can_manage() || current_user_can(self::CAP); }
 static function login_redirect($redirect_to,$requested,$user) {
  if($user instanceof WP_User && in_array(self::ROLE,(array)$user->roles,true)) return self::url();
  return $redirect_to;
 }
 static function restrict_admin() {
  if(!self::is_viewer() || wp_doing_ajax()) return;
  global $pagenow;
  $page=is_scalar($_GET['page']??null)?sanitize_key(wp_unslash($_GET['page'])):'';
  $action=is_scalar($_POST['action']??null)?sanitize_key(wp_unslash($_POST['action'])):'';
  if($pagenow==='admin.php' && $page===self::PAGE) return;
  if($pagenow==='admin-post.php' && $action==='xi_native_read') return;
  wp_safe_redirect(self::url()); exit;
 }
 static function restrict_admin_menu() {
  if(!self::is_viewer()) return;
  global $menu;
  foreach((array)$menu as $item) {
   $slug=$item[2]??'';
   if($slug!==self::PAGE) remove_menu_page($slug);
  }
 }
 static function restrict_admin_bar($bar) {
  if(!self::is_viewer() || !is_object($bar)) return;
  foreach(array('site-name','new-content','comments','updates','wp-logo','search') as $id) $bar->remove_node($id);
 }
 static function admin_body_class($classes) {
  return self::is_viewer()?$classes.' xiaoman-inquiry-viewer':$classes;
 }
 static function guard() { if(!self::allowed()) wp_die('你没有查看询盘的权限。',403); }
 static function menu() {
  if(!self::allowed()) return;
  global $wpdb; $unread=self::exists()?(int)$wpdb->get_var('SELECT COUNT(*) FROM '.self::table()." WHERE COALESCE(status,'') NOT IN ('read','spam') AND ".self::base_where().XI_Native_Inquiry_Trash::clause()):0;
  $badge=$unread?' <span class="awaiting-mod"><span class="pending-count">'.(int)$unread.'</span></span>':'';
  add_menu_page('询盘中心','询盘中心'.$badge,current_user_can('manage_options')?'manage_options':self::CAP,self::PAGE,array(__CLASS__,'page'),'dashicons-email-alt',25);
  add_submenu_page(self::PAGE,'询盘列表','询盘列表',current_user_can('manage_options')?'manage_options':self::CAP,self::PAGE,array(__CLASS__,'page'));
 }
 static function url($args=array()) { return add_query_arg(array_merge(array('page'=>self::PAGE),$args),admin_url('admin.php')); }
 static function row($id) {
  global $wpdb; return $wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table().' WHERE id=%d AND '.self::base_where(),absint($id)),ARRAY_A);
 }
 static function read_action() {
  self::guard(); if(($_SERVER['REQUEST_METHOD']??'')!=='POST') wp_die('请求方式错误。',405);
  check_admin_referer('xi_native_read');
  $status=$_POST['mark']??''; if(!in_array($status,array('read','unread','spam','unspam','trash','restore','delete'),true)) wp_die('状态无效。',400);
  if(in_array($status,array('trash','restore','delete'),true)&&!current_user_can('manage_options')) wp_die('没有删除或恢复询盘的权限。',403);
  $raw=$_POST['ids']??array();
  $valid=is_array($raw)?array_filter($raw,static function($v){return is_scalar($v)&&preg_match('/^[1-9][0-9]*$/D',(string)$v);}):array();
  $ids=array_slice(array_unique(array_map('absint',$valid)),0,self::PER_PAGE);
  global $wpdb;$changed=0;$failed=0;$skipped=0;
  foreach($ids as $id) {
   if(in_array($status,array('trash','restore','delete'),true)) {
    $result=XI_Native_Inquiry_Trash::change($id,$status);
    if(is_wp_error($result)) $failed++;elseif($result) $changed++;
    continue;
   }
   $stored=self::row($id);
   if(!$stored||XI_Native_Inquiry_Trash::has($id)) continue;
   $current=$stored['status']??'';
   if(in_array($status,array('read','unread'),true)&&$current==='spam') {$skipped++;continue;}
   if($status==='unspam'&&$current!=='spam') continue;
   $new_status=$status==='unspam'?'read':$status;
   $result=$wpdb->update(self::table(),array('status'=>$new_status),array('id'=>$id),array('%s'),array('%d'));
   if(false===$result)$failed++;elseif($result)$changed++;
  }
  $args=self::filters(wp_unslash($_POST)); $args['paged']=max(1,absint($_POST['paged']??1));
  if(in_array($status,array('read','unread','spam','unspam'),true)&&count($ids)===1&&!empty($_POST['open_detail'])) $args=array('inquiry'=>$ids[0]);
  $args['updated']=$changed;$args['operation']=$status;$args['failed']=$failed;$args['skipped']=$skipped; wp_safe_redirect(self::url($args)); exit;
 }
 static function labels($kind) {
  return array('number'=>'询盘编号','date'=>'Date','country'=>'Country','F1'=>'Equipment','F2'=>'Model',
   'F3'=>'Full name','F4'=>'Last name','F5'=>'Email','F6'=>'WhatsApp','H3'=>'PC/Mobile','F9'=>'Message','customization_type'=>'Customization type',
   'H1'=>'询盘的页面','H2'=>'IP address','A1'=>'campaign','A2'=>'keyword','A3'=>'adgroup','A4'=>'device','A5'=>'gclid / fbclid','A6'=>'loc',
   'source'=>'询盘来源','A7'=>'广告ID/名称','R1'=>'首次来源网站','R2'=>'首次进入页面','R3'=>'本次来源网站','R4'=>'本次进入页面','R5'=>'询盘前个页面','R6'=>'询盘按钮标识','A8'=>'首次广告进站');
 }
 static function display($row) {
  $r=self::record($row); if(!$r) return array();
  $date=$r['submitted_at']??'';
  $timestamp=strtotime($date.' UTC');
  return array_merge($r['values']??array(),$r['hd']??array(),array('number'=>self::number($row['id']),
   'date'=>$timestamp?wp_date('Y-m-d H:i:s',$timestamp):$date,'country'=>$r['country']??'','source'=>$r['source']??''));
 }
 static function pagination($p,$count,$filters) {
  echo '<div class="tablenav"><div class="tablenav-pages"><span class="displaying-num">共 '.(int)$count.' 条 · 每页 50 条</span> ';
  if($p['page']>1) echo '<a class="button" href="'.esc_url(self::url(array_merge($filters,array('paged'=>$p['page']-1)))).'">上一页</a> ';
  echo '<span>第 '.(int)$p['page'].' / '.(int)$p['pages'].' 页</span> ';
  if($p['page']<$p['pages']) echo '<a class="button" href="'.esc_url(self::url(array_merge($filters,array('paged'=>$p['page']+1)))).'">下一页</a>';
  echo '</div></div>';
 }
 static function hidden_filters($f,$paged=1) {
  foreach(array_merge($f,array('paged'=>$paged)) as $k=>$v) echo '<input type="hidden" name="'.esc_attr($k).'" value="'.esc_attr((string)$v).'">';
 }
 static function page() {
  self::guard();
  echo '<div class="wrap fwn-inbox'.(self::can_manage()?'':' fwn-readonly').'"><h1>询盘中心 <small>Bricks 原生表单</small></h1>';
  echo '<style>.fwn-inbox h1 small{font-size:13px;color:#64748b;margin-left:10px}.fwn-inbox .fwn-tools{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin:18px 0}.fwn-inbox td{vertical-align:top;padding:14px 10px;word-break:break-word}.fwn-inbox th{padding:12px 10px}.fwn-inbox tr.fwn-unread{background:#fffaf7}.fwn-inbox tr.fwn-spam td{background:#e4f6e8!important}.fwn-inbox tr.fwn-spam td:first-child{border-left:4px solid #46b450}.fwn-inbox .fwn-status-spam{display:inline-block;margin-top:7px;padding:2px 7px;border-radius:12px;background:#c9efcf;color:#166534;font-size:12px;font-weight:600}.fwn-inbox .fwn-spam-button{border-color:#46b450!important;color:#166534!important}.fwn-inbox .fwn-sub{color:#64748b;font-size:12px;margin-top:5px}.fwn-inbox .fwn-detail{max-width:1000px}.fwn-detail th{width:165px;background:#f6f7f7}.fwn-detail td{white-space:pre-wrap}.fwn-inbox .fwn-message td{padding-top:26px;padding-bottom:26px}.fwn-inbox .fwn-scroll{overflow:auto}.fwn-inbox .fwn-list{min-width:1280px;table-layout:fixed}.fwn-inbox .fwn-list,.fwn-inbox .fwn-detail{border-collapse:collapse;border:1px solid #c3c4c7}.fwn-inbox .fwn-list th,.fwn-inbox .fwn-list td,.fwn-inbox .fwn-detail th,.fwn-inbox .fwn-detail td{border:1px solid #dcdcde}.fwn-inbox .fwn-list th{font-size:12px}.fwn-inbox .fwn-list td{overflow-wrap:anywhere}.fwn-inbox .fwn-message-preview{display:-webkit-box;-webkit-box-orient:vertical;-webkit-line-clamp:4;overflow:hidden;line-height:1.55;max-height:6.2em}.fwn-inbox tr.fwn-unread td:first-child{border-left:4px solid #d63638}.fwn-inbox .fwn-detail-actions{display:flex;gap:10px;align-items:center;margin:18px 0}.fwn-readonly a[href*="view=trash"],.fwn-readonly .fwn-export{display:none!important}.xiaoman-inquiry-viewer #adminmenumain{display:none!important}.xiaoman-inquiry-viewer #wpcontent,.xiaoman-inquiry-viewer #wpfooter{margin-left:0!important}.xiaoman-inquiry-viewer #wpbody-content{padding-bottom:40px}.xiaoman-inquiry-viewer .fwn-inbox{margin:24px 30px 0}.xiaoman-inquiry-viewer .fwn-inbox .fwn-list{min-width:1180px;width:100%}.xiaoman-inquiry-viewer .fwn-inbox .fwn-scroll{width:100%;background:#fff}@media(max-width:782px){.xiaoman-inquiry-viewer .fwn-inbox{margin:18px 14px 0}}</style>';
  if(!self::exists()) { echo '<div class="notice notice-warning"><p>请在 Bricks 设置开启 Save form submissions in database。</p></div></div>'; return; }
  if(!empty($_GET['inquiry'])) { self::detail(absint($_GET['inquiry'])); echo '</div>'; return; }
  $filters=self::filters(wp_unslash($_GET)); $where=self::where($filters); global $wpdb;
  $count=(int)$wpdb->get_var('SELECT COUNT(*) FROM '.self::table().' WHERE '.$where);
  $paging=self::paging($count,$_GET['paged']??1);
  // Newest submission first. Imported pre-plugin inquiries get later row ids than older live ones, so the id is only the tiebreak.
  $rows=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.self::table().' WHERE '.$where.' ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d',self::PER_PAGE,$paging['offset']),ARRAY_A);
  echo '<p>原生提交保存后出现在这里，按提交时间从新到旧排列。导入的 Bricksforge 历史询盘标为已读，不同步小满；已启用 Custom 的新询盘保存后自动进入小满同步队列。</p>';
  $trash=$filters['view']==='trash';
  $trash_count=(int)$wpdb->get_var('SELECT COUNT(*) FROM '.self::table().' WHERE '.self::base_where().XI_Native_Inquiry_Trash::clause(true));
  echo '<p><a class="button" href="'.esc_url(self::url()).'">询盘列表</a> <a class="button" href="'.esc_url(self::url(array('view'=>'trash'))).'">回收站（'.$trash_count.'）</a></p>';
  if($trash) echo '<p>回收站中的询盘不再自动同步。恢复后可继续处理；永久删除只删除网站记录，小满已有线索保留。</p>';
  if(isset($_GET['updated'])) {
   $verbs=array('trash'=>'已移入回收站','restore'=>'已恢复','delete'=>'已永久删除','read'=>'已标为已读','unread'=>'已标为未读','spam'=>'已标为垃圾询盘','unspam'=>'垃圾询盘已转为已读');
   $operation=is_string($_GET['operation']??null)?$_GET['operation']:'';$verb=$verbs[$operation]??'已更新';
   echo '<div class="notice notice-success"><p>'.esc_html($verb).' '.absint($_GET['updated']).' 条询盘。</p></div>';
  }
  if(!empty($_GET['failed'])) echo '<div class="notice notice-warning"><p>'.absint($_GET['failed']).' 条未处理，可能正在同步或记录状态已改变，请刷新后重试。</p></div>';
  if(!empty($_GET['skipped'])) echo '<div class="notice notice-info"><p>已跳过 '.absint($_GET['skipped']).' 条垃圾询盘；普通已读/未读操作不会改变垃圾状态。</p></div>';
  echo '<form method="get" class="fwn-tools"><input type="hidden" name="page" value="'.esc_attr(self::PAGE).'">';
  echo '<input type="hidden" name="view" value="'.esc_attr($filters['view']).'">';
  echo '<label>表单 <select name="kind">'; foreach(array(''=>'全部表单','quick'=>'快速表单','detail'=>'详情页表单') as $v=>$l) echo '<option value="'.esc_attr($v).'" '.selected($filters['kind'],$v,false).'>'.esc_html($l).'</option>'; echo '</select></label>';
  echo '<label>阅读状态 <select name="status">'; foreach(array(''=>'全部','unread'=>'未读','read'=>'已读','spam'=>'垃圾询盘') as $v=>$l) echo '<option value="'.esc_attr($v).'" '.selected($filters['status'],$v,false).'>'.esc_html($l).'</option>'; echo '</select></label>';
  echo '<label class="screen-reader-text" for="fwn-search">搜索询盘</label><input id="fwn-search" type="search" name="s" value="'.esc_attr($filters['s']).'" placeholder="编号 / 姓名 / 邮箱 / 广告系列"><button class="button">搜索 / 筛选</button><a class="button" href="'.esc_url(self::url()).'">重置</a></form>';
  echo '<form class="fwn-export" method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="xi_native_export">';wp_nonce_field('xi_native_export');self::hidden_filters($filters);echo '<button class="button">导出当前筛选 CSV</button></form>';
  self::pagination($paging,$count,$filters);
  echo '<form class="fwn-bulk" method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="xi_native_read">';wp_nonce_field('xi_native_read');self::hidden_filters($filters,$paging['page']);
  echo '<div class="fwn-tools">';
  if(!$trash) echo '<button class="button" name="mark" value="read">选中标为已读</button><button class="button" name="mark" value="unread">选中标为未读</button><button class="button fwn-spam-button" name="mark" value="spam">选中标为垃圾询盘</button><button class="button" name="mark" value="unspam">垃圾转已读</button>';
  if(current_user_can('manage_options')) {
   if($trash) echo '<button class="button" name="mark" value="restore">恢复选中询盘</button><button class="button" style="color:#b32d2e" name="mark" value="delete">永久删除选中询盘</button>';
   else echo '<button class="button" style="color:#b32d2e" name="mark" value="trash">删除选中询盘</button>';
  }
  echo '<span class="fwn-selected" role="status">已选择 0 条</span></div><div class="fwn-confirm notice notice-warning inline" role="alertdialog" aria-label="确认询盘状态操作" hidden><p class="fwn-confirm-text"></p><p><button type="button" class="button button-primary fwn-confirm-yes">确认</button> <button type="button" class="button fwn-confirm-no">取消</button></p></div><div class="fwn-scroll"><table class="widefat striped fwn-list"><colgroup>';
  foreach(array(3,14,13,9,12,15,13,9,12) as $width) echo '<col style="width:'.$width.'%">';
  echo '</colgroup><thead><tr><th><input type="checkbox" aria-label="选择本页全部" onclick="this.closest(\'form\').querySelectorAll(\'input[name=&quot;ids[]&quot;]\').forEach(e=>e.checked=this.checked)"></th>';
  foreach(array('询盘编号 / 日期 / 表单','国家 / 产品 / 型号','客户全名','邮箱 / WhatsApp','询盘留言','campaign / 关键词 / adgroup','询盘来源 / 设备','询盘留言网址') as $label) echo '<th>'.esc_html($label).'</th>';
  echo '</tr></thead><tbody>';
  foreach($rows as $row) {
   $r=self::record($row);$d=self::display($row);$state=$row['status']??'';$unread=self::unread($row);
   $row_class=$state==='spam'?'fwn-spam':($unread?'fwn-unread':'');
   echo '<tr class="'.$row_class.'"><td><input type="checkbox" name="ids[]" value="'.(int)$row['id'].'" aria-label="选择 '.esc_attr(self::number($row['id'])).'"></td>';
   echo '<td><a href="'.esc_url(self::url(array('inquiry'=>$row['id']))).'"><strong>'.esc_html($d['number']??'').'</strong></a><div class="fwn-sub">'.esc_html($d['date']??'').'</div><div class="fwn-sub">'.esc_html(($r['form_name']??'')?:'—').'</div><div class="fwn-sub">'.esc_html(XI_Native_Xiaoman_Sync::status($row['id'])).'</div>'.($state==='spam'?'<div class="fwn-status-spam">垃圾询盘</div>':'').'</td>';
   echo '<td>'.esc_html(($d['country']??'')?:'未提供').'<div class="fwn-sub">'.esc_html(($d['F1']??'')?:'—').'</div><div class="fwn-sub">'.esc_html(($d['F2']??'')?:'—').'</div></td>';
   echo '<td>'.esc_html(trim(($d['F3']??'').' '.($d['F4']??''))?:'—').'</td>';
   echo '<td>'.esc_html(($d['F5']??'')?:'—').'<div class="fwn-sub">'.esc_html(($d['F6']??'')?:'—').'</div></td>';
   echo '<td><div class="fwn-message-preview">'.esc_html(($d['F9']??'')?:'—').'</div></td>';
   echo '<td>'.esc_html(($d['A1']??'')?:'—').'<div class="fwn-sub">'.esc_html(($d['A2']??'')?:'—').'</div><div class="fwn-sub">'.esc_html(($d['A3']??'')?:'—').'</div></td>';
   echo '<td>'.esc_html(($d['source']??'')?:'—').'<div class="fwn-sub">'.esc_html(($d['H3']??'')?:'—').'</div></td>';
   $inquiry_url=esc_url($d['H1']??'',array('http','https'));
   echo '<td>'.($inquiry_url!==''?'<a href="'.$inquiry_url.'" target="_blank" rel="noopener noreferrer">'.esc_html($d['H1']).'</a>':'—').'</td></tr>';
  }
  if(!$rows) echo '<tr><td colspan="9">暂无符合条件的原生询盘。</td></tr>';
  echo '</tbody></table></div></form>';self::pagination($paging,$count,$filters);
  echo <<<'HTML'
<script>(function(){
 var f=document.querySelector('.fwn-bulk');if(!f)return;
 var panel=f.querySelector('.fwn-confirm'),pending=null,approved=false;
 function count(){return f.querySelectorAll('input[name="ids[]"]:checked').length;}
 f.addEventListener('change',function(){f.querySelector('.fwn-selected').textContent='已选择 '+count()+' 条';panel.hidden=true;pending=null;});
 f.addEventListener('submit',function(e){
  if(!count()){e.preventDefault();f.querySelector('.fwn-selected').textContent='请先勾选询盘。';return;}
  if(approved){approved=false;return;}
  var button=e.submitter,action=button&&button.value;
  if(action!=='trash'&&action!=='delete')return;
  e.preventDefault();pending=button;
  panel.querySelector('.fwn-confirm-text').textContent=action==='trash'?'将选中的 '+count()+' 条询盘移入回收站？未同步任务会暂停，小满已有线索保留。':'永久删除选中的 '+count()+' 条网站询盘？此操作无法恢复，小满已有线索保留。';
  panel.hidden=false;panel.querySelector('.fwn-confirm-yes').focus();
 });
 panel.querySelector('.fwn-confirm-no').addEventListener('click',function(){panel.hidden=true;if(pending)pending.focus();pending=null;});
 panel.querySelector('.fwn-confirm-yes').addEventListener('click',function(){if(!pending)return;approved=true;var button=pending;pending=null;panel.hidden=true;f.requestSubmit(button);});
})();</script>
HTML;
  echo '</div>';
 }
 static function detail($id) {
  if(self::is_viewer() && XI_Native_Inquiry_Trash::has($id)) { echo '<p>未找到该询盘。</p>'; return; }
  $row=self::row($id); if(!$row) {echo '<p>未找到该询盘。</p>';return;}
  $r=self::record($row);$d=self::display($row);$state=$row['status']??'';
  if(XI_Native_Inquiry_Trash::has($id)) echo '<div class="notice notice-warning"><p>这条询盘已在回收站。<a href="'.esc_url(self::url(array('view'=>'trash'))).'">前往回收站恢复或永久删除</a></p></div>';
  $state_label=$state==='spam'?'垃圾询盘':(self::unread($row)?'未读':'已读');
  echo '<div class="fwn-detail-actions"><a class="button" href="'.esc_url(self::url()).'">返回列表</a><strong>'.esc_html(self::number($id)).'</strong><span>'.esc_html($r['form_name']).' · '.esc_html($state_label).'</span>';
  if(!XI_Native_Inquiry_Trash::has($id)) {
   echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="xi_native_read"><input type="hidden" name="ids[]" value="'.(int)$id.'"><input type="hidden" name="open_detail" value="1">';wp_nonce_field('xi_native_read');
   $unread=self::unread($row);
   if($state==='spam') echo '<button class="button" name="mark" value="unspam">垃圾转已读</button>';
   else echo '<button class="button" name="mark" value="'.($unread?'read':'unread').'">'.($unread?'标为已读':'标为未读').'</button><button class="button fwn-spam-button" name="mark" value="spam">标为垃圾询盘</button>';
   echo '</form>';
  }
  echo '</div>';
  echo '<table class="widefat fwn-detail"><tbody>';
  foreach(self::labels($r['kind']) as $key=>$label) {
   if($r['kind']==='quick' && in_array($key,array('F2','F4'),true)) continue;
   echo '<tr'.($key==='F9'?' class="fwn-message"':'').'><th>'.esc_html($label).'</th><td>'.esc_html(($d[$key]??'')?:'—').'</td></tr>';
  }
  echo '<tr><th>小满同步</th><td>'.esc_html(XI_Native_Xiaoman_Sync::status($id)).' </td></tr></tbody></table><p>打开详情不自动改为已读；确认查看后点击“标为已读”。</p>';
 }
 static function csv_cell($value) {
  $value=(string)$value;
  // Spreadsheet formula injection includes whitespace/control prefixes.
  return preg_match('/^[\s\x00-\x20]*[=+@-]/u',$value)?"'".$value:$value;
 }
 static function export() {
  if(!self::can_manage()) wp_die('客服账户没有下载询盘的权限。',403);
  self::guard();if(($_SERVER['REQUEST_METHOD']??'')!=='POST') wp_die('请求方式错误。',405);
  check_admin_referer('xi_native_export');if(!self::exists()) wp_die('尚未开启保存。',400);
  $where=self::where(self::filters(wp_unslash($_POST)));global $wpdb;
  // A fixed upper bound gives a stable export while live inquiries continue arriving.
  $max=(int)$wpdb->get_var('SELECT MAX(id) FROM '.self::table().' WHERE '.$where);
  nocache_headers();header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="xiaoman-inquiries-'.gmdate('Ymd-His').'.csv"');
  $out=fopen('php://output','w');fwrite($out,"\xEF\xBB\xBF");$labels=self::labels('detail');$labels['F3']='Full name / First name';
  fputcsv($out,array_merge(array('表单','阅读状态'),array_values($labels)),',','"','');$last=0;
  do {
   $rows=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.self::table().' WHERE '.$where.' AND id>%d AND id<=%d ORDER BY id ASC LIMIT 200',$last,$max),ARRAY_A);
   foreach($rows as $row) {
    $r=self::record($row);$d=self::display($row);$line=array($r['form_name']??'',self::unread($row)?'未读':'已读');
    foreach($labels as $key=>$label) $line[]=$d[$key]??'';
    fputcsv($out,array_map(array(__CLASS__,'csv_cell'),$line),',','"','');$last=(int)$row['id'];
   }
  } while(count($rows)===200);
  fclose($out);exit;
 }
}
add_action('plugins_loaded',array('XI_Native_Inquiry_Center','boot'),21);
