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
function submit($submitPage, $landingAdUrl, $consent = 'rejected', $prev = 'https://fixture.example.invalid/fr/products/compact-tractors/', $entry = null) {
 $request = array('landing_ad_url' => $landingAdUrl, 'referrer' => $prev, 'consent_status' => $consent);
 if ($entry !== null) $request['entry_referrer'] = $entry;
 $packet = array('_request' => $request, '_tracking' => array('status' => 'campaign_captured', 'carried' => 'previous_page_codes'));
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

$page2 = 'https://fixture.example.invalid/excavators/mini-excavator/page/2/';
$g = submit($page2, $page2 . '?campaign=pmex-cp-eu&device=m&loc=1006094&gclid=SAME', 'rejected', '');
check('AJAX pagination: a packet rebased onto the current path keeps Google Ads and the campaign', ($g['source_label'] ?? '') === 'Google Ads' && ($g['campaign'] ?? '') === 'pmex-cp-eu' && ($g['landing_path'] ?? '') === '/excavators/mini-excavator/page/2/');

// beta.21: without consent the browser may carry where the visit entered (origin only, or '' = opened directly).
$prev = 'https://fixture.example.invalid/fr/products/compact-tractors/';
$h = submit($page, $page, 'rejected', $prev, 'https://www.google.com/');
check('a carried Google entry on a later page is labelled Google 自然搜索', ($h['source_label'] ?? '') === 'Google 自然搜索' && ($h['source_platform'] ?? '') === 'google_organic');
check('the carried entry is marked, the entry origin is the referrer and the previous page is kept', ($h['first_touch_scope'] ?? '') === 'carried_entry_source' && ($h['referrer'] ?? '') === 'https://www.google.com/' && ($h['previous_page'] ?? '') === $prev && ($h['capture_basis'] ?? '') === 'current_page_fallback');
check('consent and the submitted page stay as they were', ($h['consent_status'] ?? '') === 'rejected' && ($h['landing_path'] ?? '') === '/fr/product/30hp-304-compact-tractor/' && ($h['campaign'] ?? '') === '');
$i = submit($page, $page, 'rejected', $prev, '');
check('a carried "opened directly" entry is labelled direct', ($i['source_label'] ?? '') === '直接访问 / 来源未提供' && ($i['first_touch_scope'] ?? '') === 'carried_entry_source');
$j = submit($page, $page, 'unknown', $prev, 'https://chatgpt.com/');
check('a carried ChatGPT entry is labelled ChatGPT 推荐 (banner never answered)', ($j['source_label'] ?? '') === 'ChatGPT 推荐');
$bad = array();
foreach (array('https://fixture.example.invalid/', 'https://www.fixture.example.invalid/', 'https://www.google.com/search?q=x', 'https://www.google.com', 'javascript:alert(1)', 'https://user@evil.example/', 'https://evil.example:8443/', 'direct') as $v) {
 $k = submit($page, $page, 'rejected', $prev, $v);
 if (($k['source_label'] ?? '') !== XI_Request_Attribution_V1::INTERNAL_UNKNOWN || ($k['first_touch_scope'] ?? '') === 'carried_entry_source') $bad[] = $v;
}
check('this site, paths, queries, ports, user info and other values are ignored as carried entries' . ($bad ? ': ' . implode(' | ', $bad) : ''), !$bad);
$l = submit($page, $page, 'accepted', $prev, 'https://www.google.com/');
check('a consented submit never uses the carried entry', ($l['first_touch_scope'] ?? '') !== 'carried_entry_source');
$m = submit('https://fixture.example.invalid/fr/contact-us/', $page, 'rejected', $prev, 'https://www.google.com/');
check('a carried entry for a different page is not used', ($m['first_touch_scope'] ?? '') !== 'carried_entry_source');
$n = submit($page, $carried, 'rejected', $prev, 'https://www.google.com/');
check('carried campaign codes still decide the label', ($n['source_label'] ?? '') === 'Google Ads' && ($n['campaign'] ?? '') === 'setr-cp-fr');

// beta.21: the thank-you redirect carries the same entry source (no consent only), next to the campaign codes.
require __DIR__ . '/../xiaoman-inquiry-center/conversions.php';
$frag = function ($a) { $f = XI_Conversions::carried_fragment(array('attribution' => $a)); if ($f === '') return null; parse_str(rawurldecode(substr($f, 4)), $q); return $q; };
$q1 = $frag(array('consent_status' => 'rejected', 'landing_url' => $page, 'referrer' => 'https://www.google.com/', 'source_label' => 'Google 自然搜索'));
check('the thank-you marker carries the entry origin without consent', is_array($q1) && ($q1['src'] ?? '') === 'https://www.google.com' && isset($q1['t']) && !isset($q1['campaign']));
$q2 = $frag(array('consent_status' => 'rejected', 'landing_url' => $carried . '&gclid=X', 'referrer' => $prev, 'source_label' => 'Google Ads'));
check('the thank-you marker keeps campaign codes, never a click id, and claims no source for an internal referrer', is_array($q2) && ($q2['campaign'] ?? '') === 'setr-cp-fr' && !isset($q2['gclid']) && !isset($q2['src']));
$q3 = $frag(array('consent_status' => 'unknown', 'landing_url' => $page, 'referrer' => '', 'source_label' => '直接访问 / 来源未提供'));
check('an opened-directly visit carries src=direct', is_array($q3) && ($q3['src'] ?? '') === 'direct');
check('consented and internal-unknown visits get no thank-you marker', $frag(array('consent_status' => 'accepted', 'landing_url' => $carried, 'referrer' => 'https://www.google.com/')) === null
 && $frag(array('consent_status' => 'rejected', 'landing_url' => $page, 'referrer' => $prev, 'source_label' => XI_Request_Attribution_V1::INTERNAL_UNKNOWN)) === null);
check('a referrer with a port, user info or another scheme is not carried', $frag(array('consent_status' => 'rejected', 'landing_url' => $page, 'referrer' => 'https://evil.example:8443/x', 'source_label' => 'x')) === null
 && $frag(array('consent_status' => 'rejected', 'landing_url' => $page, 'referrer' => 'https://u@evil.example/', 'source_label' => 'x')) === null
 && $frag(array('consent_status' => 'rejected', 'landing_url' => $page, 'referrer' => 'ftp://evil.example/', 'source_label' => 'x')) === null);

echo $failures ? "$failures failure(s)\n" : "all passed\n";
exit($failures ? 1 : 0);
