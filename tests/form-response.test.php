<?php
// Offline, fake saved row only. No WordPress/CRM/Google or network.
define('ABSPATH',__DIR__.'/fixture/');define('ARRAY_A','ARRAY_A');define('XI_HOST','fixture.example.invalid');define('XI_FILE',__FILE__);define('XI_VERSION','2.0.0-beta.10');define('XI_PROFILE',array('number_prefix'=>'FW-N'));
function wp_parse_args($v,$defaults){return array_merge($defaults,$v);}
function get_option($key,$default=false){global $fixture;return $key==='home'?'https://'.XI_HOST:($key==='xi_conversion_config_v1'?$fixture['config']:$default);}
function wp_parse_url($url,$part=-1){return parse_url($url,$part);}
function untrailingslashit($v){return rtrim($v,'/');}
function home_url($path=''){return 'https://'.XI_HOST.$path;}
function admin_url($path){return home_url('/wp-admin/'.$path);}
function plugins_url($path,$file){return home_url('/plugins/fixture/'.$path);}
function is_admin(){return false;}
function absint($v){return abs((int)$v);}
function add_query_arg($args,$url){return $url.'?'.http_build_query($args);}
function wp_unslash($v){return stripslashes($v);}
function wp_json_encode($v){return json_encode($v);}
function post_type_exists($name){return $name==='rcb-cookie';}
function get_posts($args){global $fixture;$name=$args['meta_value'];return isset($fixture['services'][$name])?array((object)array('ID'=>$name,'post_status'=>$fixture['services'][$name]['status'])):array();}
function get_post_meta($id,$key,$single){global $fixture;return $fixture['services'][$id]['types'];}
function wp_enqueue_script($handle,$url,$deps,$version,$footer){global $fixture;$fixture['asset']=array('url'=>$url,'version'=>$version);}
function wp_add_inline_script($handle,$script,$position){global $fixture;$fixture['pub']=json_decode(substr($script,strlen('window.XIConversionConfig='),-1),true);}
final class XI_Native_Inquiry_Center {
 static $requests=array();
 static function context_key($form){return $form->get_id();}
 static function number($id){return 'FW-N'.$id;}
 static function row($id){global $fixture;return $id===7?$fixture['saved']:null;}
 static function record($row){return $row?:array();}
 static function thank_you_url($r){return home_url('/thank-you/');}
}
final class XI_Bricks {static function saved_id($form){global $fixture;return $fixture['saved_id'];}}
final class XI_Lead_Stages {static function record($id,$stage,$note){global $fixture;$fixture['stages'][]=array($id,$stage);}}
final class XI_Native_Inquiry_Trash {static function has($id){return false;}}
final class FakeDB {
 public $prefix='fixture_';
 function get_col($query){return array('ga4_claim_owner','ads_claim_owner');}
 function prepare($query,...$args){return $query;}
 function get_row($query,$format){global $fixture;return $fixture['row'];}
 function insert($table,$payload){throw new RuntimeException('Receipt issue must reuse the per-request fixture token');}
}
$wpdb=new FakeDB();require dirname(__DIR__).'/xiaoman-inquiry-center/conversions.php';
function check($ok,$message){if(!$ok)throw new RuntimeException($message);}
function fixture(){global $fixture;
 $fixture=array('config'=>array('enabled'=>true,'site_host'=>XI_HOST,'cutover_id'=>0,'ga4_id'=>'G-FIXTURE','ads_id'=>'AW-123','ads_label'=>'FORM','analytics_service'=>'ga','ads_service'=>'ads','user_data_service'=>'ec'),
  'services'=>array('ga'=>array('status'=>'publish','types'=>array('analytics_storage')),'ads'=>array('status'=>'publish','types'=>array('ad_storage')),'ec'=>array('status'=>'publish','types'=>array('ad_user_data'))),
  'row'=>array('submission_id'=>7,'request_uuid'=>'verified','expires_utc'=>gmdate('Y-m-d H:i:s',time()+900),'ga4_state'=>'pending','ads_state'=>'callback'),
  'saved'=>array('uuid'=>'verified','values'=>array('F5'=>'private@example.invalid')),'saved_id'=>7,'stages'=>array());
 XI_Native_Inquiry_Center::$requests=array('abc'=>array('uuid'=>'verified','kind'=>'detail'));XI_Conversions::$tickets=array(7=>str_repeat('a',64));XI_Conversions::$owner_schema=true;XI_Conversions::$enqueued=false;
 $_GET=array();$_SERVER['REQUEST_URI']='/products/';
}
$form=new class {function get_id(){return 'abc';}};
function original(){return array('type'=>'success','message'=>'Saved','inquiryNumber'=>'FW-N7','redirectTo'=>home_url('/original/'),'redirectUrl'=>home_url('/original/'));}
function run_case($name,$fn){fixture();$fn();echo 'PASS '.$name.PHP_EOL;}

run_case('verified saved response publishes the original UTC deadline and server pending mask only',function()use($form){global $fixture;$before=$fixture['row'];$out=XI_Conversions::response(original(),$form);check($out['xiReceiptProtocol']==='owned-v1','wrong protocol');check($out['xiReceiptExpiresAt']===strtotime($before['expires_utc'].' UTC'),'extended or changed deadline');check($out['xiReceiptPendingChannels']===array('ga4'=>true,'ads'=>false),'non-pending channel recovered');check($out['redirectTo']===$out['redirectUrl'],'legacy redirect diverged');check(strpos($out['redirectTo'],'form=quick_quote')!==false&&strpos($out['redirectTo'],'form_type=detail')!==false,'tracking convention changed');check($fixture['row']===$before,'metadata mutated ledger');check(strpos(json_encode($out),'private@')===false&&strpos(json_encode($out),'claim_owner')===false,'private payload leaked');});
run_case('missing saved ID fails verification and cannot return successful conversion metadata',function()use($form){global $fixture;$fixture['saved_id']=0;$out=XI_Conversions::response(original(),$form);check($out['type']==='danger'&&!isset($out['redirectTo'])&&!isset($out['redirectUrl'])&&!isset($out['inquiryNumber'])&&!isset($out['xiReceiptProtocol']),'save failure was treated as success');});
run_case('wrong inquiry number fails verification',function()use($form){$r=original();$r['inquiryNumber']='FW-N8';$out=XI_Conversions::response($r,$form);check($out['type']==='danger'&&!isset($out['xiReceiptExpiresAt']),'wrong saved number accepted');});
run_case('unrelated forms and unsuccessful responses preserve their original fields',function()use($form){XI_Native_Inquiry_Center::$requests=array();$r=original();check(XI_Conversions::response($r,$form)===$r,'unrelated form altered');fixture();$r['type']='danger';check(XI_Conversions::response($r,$form)===$r,'failure intercepted');});
run_case('expired or UUID-mismatched receipts publish no form-dispatch metadata',function()use($form){global $fixture;$fixture['row']['expires_utc']=gmdate('Y-m-d H:i:s',time()-1);check(!isset(XI_Conversions::response(original(),$form)['xiReceiptProtocol']),'expired receipt accepted');fixture();$fixture['saved']['uuid']='other';check(!isset(XI_Conversions::response(original(),$form)['xiReceiptExpiresAt']),'UUID mismatch accepted');});
run_case('legacy schema retains original thanks path without owned form-dispatch metadata',function()use($form){XI_Conversions::$owner_schema=false;$out=XI_Conversions::response(original(),$form);check(isset($out['redirectTo'])&&!isset($out['xiReceiptProtocol']),'legacy schema incorrectly uses owner protocol');});
run_case('without consent the thank-you redirect carries campaign codes in the fragment, never a click ID',function()use($form){
 $set=function($a){XI_Native_Inquiry_Center::$requests['abc']['attribution']=$a;};
 $land='https://fw.example.test/products/x/?campaign=sefk-gn-br&device=m&keyword=a%20b&loc=1&gclid=SECRET&gbraid=SECRET2';
 $set(array('consent_status'=>'rejected','landing_url'=>$land));$out=XI_Conversions::response(original(),$form);$p=explode('#xi=',$out['redirectTo']);check(count($p)===2,'no fragment');parse_str(rawurldecode($p[1]),$q);
 check(($q['campaign']??'')==='sefk-gn-br'&&($q['keyword']??'')==='a b'&&is_numeric($q['t']??'')&&abs((float)$q['t']-microtime(true)*1000)<60000,'codes or time wrong');check(strpos($out['redirectTo'],'SECRET')===false,'click id carried');check(strpos($p[0],'xi_receipt=')!==false,'receipt lost');check($out['redirectUrl']===$out['redirectTo'],'redirectUrl differs');
 $set(array('consent_status'=>'unknown','landing_url'=>$land));check(strpos(XI_Conversions::response(original(),$form)['redirectTo'],'#xi=')!==false,'unknown consent not carried');
 $set(array('consent_status'=>'accepted','landing_url'=>$land));check(strpos(XI_Conversions::response(original(),$form)['redirectTo'],'#')===false,'consented visitor decorated');
 $set(array('consent_status'=>'rejected','landing_url'=>'https://fw.example.test/products/x/?gclid=SECRET'));check(strpos(XI_Conversions::response(original(),$form)['redirectTo'],'#')===false,'click-only landing decorated');
 $set(array());check(strpos(XI_Conversions::response(original(),$form)['redirectTo'],'#')===false,'empty attribution decorated');
});
run_case('Ads pending storage stays absent unless explicitly mapped to a published ad_storage service',function(){global $fixture;XI_Conversions::assets();check($fixture['pub']['pending_ads_storage_service']==='','automatic undeclared Ads storage');fixture();$fixture['config']['pending_ads_storage_service']='ads';XI_Conversions::assets();check($fixture['pub']['pending_ads_storage_service']==='ads','explicit published Ads service unavailable');});
run_case('draft or wrong-purpose Ads storage cannot enable pending recovery',function(){global $fixture;$fixture['config']['pending_ads_storage_service']='ads';$fixture['services']['ads']['status']='draft';XI_Conversions::assets();check($fixture['pub']['pending_ads_storage_service']==='','draft declaration enabled');fixture();$fixture['config']['pending_ads_storage_service']='ga';XI_Conversions::assets();check($fixture['pub']['pending_ads_storage_service']==='','analytics-only service treated as ad_storage');});
echo '9 offline form-response and Ads-storage cases passed.'.PHP_EOL;
