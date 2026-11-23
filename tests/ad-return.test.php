<?php
// beta.20: natural Google search label, gad_source / gad_campaignid as ad markers, and the 72-hour ad return check
// (a consented natural Google visit whose same visitor opened an ad landing in the window becomes Google Ads with a note).
// Offline: temporary access logs only. Usage: php -n tests/ad-return.test.php
define('ABSPATH', __DIR__ . '/');
define('XI_HOST', 'fixture.example.invalid');
define('DAY_IN_SECONDS', 86400);
function sanitize_text_field($v) { return trim(strip_tags((string) $v)); }
function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
function esc_url_raw($url, $protocols = null) { return $url; }
function wp_unslash($v) { return is_string($v) ? stripslashes($v) : $v; }
function wp_json_encode($v) { return json_encode($v); }
final class XI_Compatibility {
 static function packet($request) { $v = $request['xi_context'] ?? ''; return is_string($v) && $v !== '' ? $v : ''; }
 static function history_valid($source) { return true; }
}
final class XI_Config {
 static $c = array();
 static function get() { return self::$c; }
}
require __DIR__ . '/../xiaoman-inquiry-center/inquiry-source.php';
require __DIR__ . '/../xiaoman-inquiry-center/request-attribution.php';
require __DIR__ . '/../xiaoman-inquiry-center/ad-return.php';
$failures = 0;
function check($name, $ok) { global $failures; echo ($ok ? 'ok   ' : 'FAIL ') . $name . "\n"; if (!$ok) $failures++; }

// Labels
$L = function ($landing, $ref) { return XI_Inquiry_Source_V1::label(array('R4' => $landing, 'R3' => $ref)); };
$home = 'https://fixture.example.invalid/';
check('Google referrer without ad markers is natural search', $L($home . 'products/x/', 'https://www.google.com/') === 'Google 自然搜索');
check('Google referrer on a country domain is natural search too', $L($home, 'https://www.google.com.hk/') === 'Google 自然搜索');
check('gad_source alone marks a Google Ads click', $L($home . '?gad_source=1', 'https://www.google.com/') === 'Google Ads');
check('gad_campaignid alone marks a Google Ads click', $L($home . '?gad_campaignid=22287149184', '') === 'Google Ads');
check('utm_medium=organic stays Google SEO', $L($home . '?utm_source=google&utm_medium=organic', 'https://www.google.com/') === 'Google SEO');
check('Bing stays undetermined', $L($home, 'https://www.bing.com/') === 'Bing 搜索（广告/自然未确定）');
$natural = XI_Request_Attribution_V1::parse($home, 'https://www.google.com/', 'accepted', 'consented_first_touch');
check('parse: natural search platform is google_organic', ($natural['source_label'] ?? '') === 'Google 自然搜索' && ($natural['source_platform'] ?? '') === 'google_organic');
$gad = XI_Request_Attribution_V1::parse($home . '?gad_source=1&gad_campaignid=123', 'https://www.google.com/', 'rejected', 'current_page_fallback');
check('parse: gad markers are kept and labelled Google Ads', ($gad['source_label'] ?? '') === 'Google Ads' && ($gad['gad_source'] ?? '') === '1' && ($gad['gad_campaignid'] ?? '') === '123');

// Temporary access logs
$dir = sys_get_temp_dir() . '/xi-ad-return-' . bin2hex(random_bytes(6)); mkdir($dir);
$now = strtotime('2026-11-17 20:27:17 UTC');
$line = function ($ip, $ts, $path, $method = 'GET') { return $ip . ' - - [' . gmdate('d/M/Y:H:i:s', $ts) . ' +0000] "' . $method . ' ' . $path . ' HTTP/1.1" 200 1234' . "\n"; };
$adPath = function ($campaign, $loc = '9213331') { return '/excavators/mini-excavators/?campaign=' . $campaign . '&campaignid=21116749901&adgroup=&device=m&keyword=&loc=' . $loc . '&gad_source=1&gad_campaignid=21116753888&gbraid=FIXTURE-GB&gclid=FIXTURE-CLICK'; };
$current = '';
$current .= $line('203.0.113.7', $now - 506, $adPath('pmex-cp-gl'));                       // 8 minutes before
$current .= $line('203.0.113.7', $now - 240, '/');                                         // natural re-entry
$current .= $line('203.0.113.8', $now - 73 * 3600, $adPath('old-campaign'));                // 73 hours before
$current .= $line('203.0.113.11', $now - 600, '/products/x/?ref=newsletter');                // not an ad
$current .= $line('203.0.113.12', $now - 6000, $adPath('first-campaign'));
$current .= $line('203.0.113.12', $now - 1800, $adPath('second-campaign'));                  // latest wins
$current .= $line('203.0.113.13', $now - 300, $adPath('posted'), 'POST');                    // not a page view
$current .= $line('203.0.113.14', $now - 300, '/?campaign={campaignid}&device=m&loc=1');      // unexpanded template
$current .= $line('203.0.113.15', $now + 300, $adPath('future'));                            // after submit
$current .= $line('2001:db8:1:2:1111:2222:3333:4444', $now - 3600, $adPath('v6-campaign'));   // same /64
$current .= $line('2001:db8:9:9::1', $now - 3600, $adPath('other-v6'));                       // different /64
file_put_contents($dir . '/access.log', $current);
$gzOk = function_exists('gzopen');
if ($gzOk) {
 $g = gzopen($dir . '/access.log.2026_11_15.gz', 'wb'); gzwrite($g, $line('203.0.113.9', $now - 71 * 3600, $adPath('rotated-campaign'))); gzclose($g);
 touch($dir . '/access.log.2026_11_15.gz', $now - 70 * 3600);
 $g = gzopen($dir . '/access.log.2026_11_01.gz', 'wb'); gzwrite($g, $line('203.0.113.10', $now - 10 * 86400, $adPath('ancient'))); gzclose($g);
 touch($dir . '/access.log.2026_11_01.gz', $now - 9 * 86400);
}
XI_Config::$c = array('ad_return_logs' => $dir . '/access.log*', 'ad_return_hours' => 72);
$run = function ($ip, $consent = 'accepted', $label = null) use ($home, $now) {
 $a = XI_Request_Attribution_V1::parse($home, 'https://www.google.com/', $consent, $consent === 'accepted' ? 'consented_first_touch' : 'current_page_fallback');
 if ($label !== null) $a['source_label'] = $label;
 $hd = array('A1' => '', 'A2' => '', 'A3' => '', 'A4' => '', 'A5' => '', 'A6' => '', 'A7' => '', 'H2' => $ip);
 $changed = XI_Ad_Return_V1::check($a, $hd, $ip, $now);
 return array($changed, $a, $hd);
};

list($changed, $a, $hd) = $run('203.0.113.7');
check('ad landing 8 minutes earlier: changed', $changed === true);
check('label becomes Google Ads (the first entry was the ad)', ($a['source_label'] ?? '') === 'Google Ads' && ($a['source_platform'] ?? '') === 'google_ads' && isset($a['ad_return']));
check('campaign codes come from the ad landing', ($a['campaign'] ?? '') === 'pmex-cp-gl' && ($a['campaignid'] ?? '') === '21116749901' && ($a['device'] ?? '') === 'm' && ($a['loc'] ?? '') === '9213331');
check('click ids are never copied', ($a['gclid'] ?? '') === '' && ($a['gbraid'] ?? '') === '' && ($a['wbraid'] ?? '') === '' && $hd['A5'] === '');
check('legacy fields A1 / A4 / A6 are filled', $hd['A1'] === 'pmex-cp-gl' && $hd['A4'] === 'm' && $hd['A6'] === '9213331');
check('the note reads 之前点过广告：系列 pmex-cp-gl，8 分钟前', ($hd['A8'] ?? '') === '之前点过广告：系列 pmex-cp-gl，8 分钟前');
check('the original natural entry stays as the landing', ($a['landing_path'] ?? '') === '/' && ($a['ad_return']['landing_path'] ?? '') === '/excavators/mini-excavators/' && ($a['ad_return']['minutes'] ?? -1) === 8 && ($a['ad_return']['match'] ?? '') === 'ip');
check('the inquiry source label follows the attribution', XI_Ad_Return_V1::LABEL === $a['source_label']);

list($changed, $a) = $run('203.0.113.8');
check('ad landing 73 hours earlier stays natural search', $changed === false && $a['source_label'] === 'Google 自然搜索' && ($a['campaign'] ?? '') === '');
if ($gzOk) {
 list($changed, $a, $hd) = $run('203.0.113.9');
 check('a rotated .gz log inside the window is read (71 hours)', $changed === true && $a['campaign'] === 'rotated-campaign' && $hd['A8'] === '之前点过广告：系列 rotated-campaign，71 小时前');
 list($changed) = $run('203.0.113.10');
 check('a rotated log that ended before the window is skipped', $changed === false);
} else echo "skip gz cases (zlib missing)\n";
list($changed) = $run('198.51.100.1');
check('another visitor is unchanged', $changed === false);
list($changed, $a) = $run('203.0.113.7', 'rejected');
check('no consent: unchanged even with an ad landing', $changed === false && $a['source_label'] === 'Google 自然搜索' && !isset($a['ad_return']));
list($changed, $a) = $run('203.0.113.7', 'accepted', 'Google Ads');
check('only natural search inquiries are checked', $changed === false && $a['source_label'] === 'Google Ads');
list($changed) = $run('203.0.113.11');
check('a non-ad URL with parameters is not an ad landing', $changed === false);
list($changed, $a) = $run('203.0.113.12');
check('the latest ad landing wins', $changed === true && $a['campaign'] === 'second-campaign');
list($changed) = $run('203.0.113.13');
check('POST requests are ignored', $changed === false);
list($changed) = $run('203.0.113.14');
check('unexpanded template values are ignored', $changed === false);
list($changed) = $run('203.0.113.15');
check('requests after the submit are ignored', $changed === false);
list($changed, $a) = $run('2001:db8:1:2::abcd');
check('same IPv6 /64 matches', $changed === true && $a['campaign'] === 'v6-campaign' && $a['ad_return']['match'] === 'v6/64');
list($changed) = $run('2001:db8:1:3::abcd');
check('a different IPv6 /64 does not match', $changed === false);
list($changed) = $run('not-an-ip');
check('an invalid visitor IP is ignored', $changed === false);
XI_Config::$c = array('ad_return_logs' => '', 'ad_return_hours' => 72);
list($changed) = $run('203.0.113.7');
check('setting empty: the check is off', $changed === false);
XI_Config::$c = array('ad_return_logs' => $dir . '/access.log*', 'ad_return_hours' => 1);
list($changed) = $run('203.0.113.12');
check('a 1-hour window: 30 minutes counts', $changed === true);
XI_Config::$c = array('ad_return_logs' => $dir . '/access.log*', 'ad_return_hours' => 0);
check('an invalid window falls back to 72 hours', XI_Ad_Return_V1::settings()['hours'] === 72);
XI_Config::$c = array('ad_return_logs' => $dir . '/missing*', 'ad_return_hours' => 72);
list($changed) = $run('203.0.113.7');
check('missing log files leave the inquiry unchanged', $changed === false);
check('note formats', XI_Ad_Return_V1::note(0, 'x') === '之前点过广告：系列 x，0 分钟前' && XI_Ad_Return_V1::note(3000, '') === '之前点过广告：系列 （未带系列代码），50 小时前');

foreach (glob($dir . '/*') as $f) unlink($f); rmdir($dir);
echo $failures ? "$failures failure(s)\n" : "all passed\n";
exit($failures ? 1 : 0);
