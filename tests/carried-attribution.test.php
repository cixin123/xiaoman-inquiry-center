<?php
// Server side of beta.16: a submit from a later page whose browser packet carries the ad landing's campaign codes
// (form-context.js, '#xi=' fragment, no consent) is labelled from those codes; nothing else about the request changes.
define('ABSPATH', __DIR__ . '/');
define('XI_HOST', 'fixture.example.invalid');
define('DAY_IN_SECONDS', 86400);
function sanitize_text_field($v) { return trim(strip_tags((string) $v)); }
function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
function esc_url_raw($url, $protocols = null) { return $url; }
function wp_unslash($v) { return is_string($v) ? stripslashes($v) : $v; }
function wp_json_encode($v) { return json_encode($v); }
final class XI_Compatibility {
 static function packet($request) { $v = $request['xi_context'] ?? ''; return is_string($v) && $v !== '' && strlen($v) <= 24000 ? $v : ''; }
 static function history_valid($source) { $at = $source['at'] ?? 0; $now = microtime(true) * 1000; return is_numeric($at) && $at > 0 && $at <= $now && $now - $at < 30 * DAY_IN_SECONDS * 1000; }
}
require __DIR__ . '/../xiaoman-inquiry-center/inquiry-source.php';
require __DIR__ . '/../xiaoman-inquiry-center/request-attribution.php';
$failures = 0;
function check($name, $ok) { global $failures; echo ($ok ? 'ok   ' : 'FAIL ') . $name . "\n"; if (!$ok) $failures++; }
function submit($submitPage, $landingAdUrl, $consent = 'rejected', $prev = 'https://fixture.example.invalid/fr/products/compact-tractors/') {
 $packet = array('_request' => array('landing_ad_url' => $landingAdUrl, 'referrer' => $prev, 'consent_status' => $consent), '_tracking' => array('status' => 'campaign_captured', 'carried' => 'previous_page_codes'));
 return XI_Request_Attribution_V1::submission(array('xi_context' => json_encode($packet)), array('HTTP_REFERER' => $submitPage));
}
$page = 'https://fixture.example.invalid/fr/product/30hp-304-compact-tractor/';
$carried = $page . '?campaign=setr-cp-fr&campaignid=24000000001&adgroup=170000000001&device=m&keyword=tracteur+compact&loc=1006094';

$a = submit($page, $carried);
check('carried codes on a later page are labelled Google Ads', ($a['source_label'] ?? '') === 'Google Ads' && ($a['source_platform'] ?? '') === 'google_ads');
check('carried campaign, ad group, keyword, device and location are kept', ($a['campaign'] ?? '') === 'setr-cp-fr' && ($a['campaignid'] ?? '') === '24000000001' && ($a['adgroup'] ?? '') === '170000000001' && ($a['keyword'] ?? '') === 'tracteur compact' && ($a['device'] ?? '') === 'm' && ($a['loc'] ?? '') === '1006094');
check('no click id appears without one in the request', ($a['gclid'] ?? '') === '' && ($a['gbraid'] ?? '') === '' && ($a['wbraid'] ?? '') === '');
check('the landing path stays the page that was actually submitted', ($a['landing_path'] ?? '') === '/fr/product/30hp-304-compact-tractor/');
check('consent stays as the visitor chose', ($a['consent_status'] ?? '') === 'rejected' && ($a['first_touch_scope'] ?? '') === 'current_page_only');
$legacy = XI_Request_Attribution_V1::legacy($a);
check('the inquiry centre campaign column gets the code', $legacy['A1'] === 'setr-cp-fr' && $legacy['A3'] === '170000000001' && $legacy['A5'] === '');

$b = submit($page, $page);
check('without carried codes the result is unchanged (internal visit, source unknown)', ($b['source_label'] ?? '') === '未知（站内访问，未关联首次来源）' && ($b['campaign'] ?? '') === '');

$c = submit($page . '?gclid=REAL-CLICK', $carried . '&gclid=FORGED');
check('a click id in the submitted page URL is never replaced by the browser packet', ($c['gclid'] ?? '') === 'REAL-CLICK' && ($c['source_label'] ?? '') === 'Google Ads');

$d = submit('https://fixture.example.invalid/fr/contact-us/', $carried);
check('a packet for a different page is not used', ($d['campaign'] ?? '') === '');

$e = submit($page, $page . '?campaign=%7Bcampaignid%7D&device=m&loc=1006094');
check('unexpanded placeholders are dropped', ($e['campaign'] ?? '') === '');

$f = submit($page, 'https://evil.example/fr/product/30hp-304-compact-tractor/?campaign=setr-cp-fr&device=m&loc=1006094');
check('a packet URL on another host is ignored', ($f['campaign'] ?? '') === '');

echo $failures ? "$failures failure(s)\n" : "all passed\n";
exit($failures ? 1 : 0);
