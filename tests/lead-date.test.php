<?php
/**
 * Pure offline regression: loads the reviewed provider file, calls only payload().
 * No WordPress bootstrap, database, CRM call, Google event or real customer input.
 * Usage: php -n tests/lead-date.test.php [absolute/path/to/provider.php]
 */
declare(strict_types=1);

define('ABSPATH', __DIR__ . '/offline-only/');
define('XI_API', 'https://fixture.example.invalid');
define('XI_PROFILE', array('number_prefix' => 'FW-TEST-'));
define('HOUR_IN_SECONDS', 3600);
$GLOBALS['fixture_timezone'] = new DateTimeZone('+08:00');
$GLOBALS['fixture_http_attempts'] = 0;

final class WP_Error {
    public string $code;
    public string $message;
    function __construct($code, $message) { $this->code = $code; $this->message = $message; }
}
function is_wp_error($value): bool { return $value instanceof WP_Error; }
function wp_timezone(): DateTimeZone { return $GLOBALS['fixture_timezone']; }
function wp_date($format, $timestamp = null, $timezone = null): string {
    return (new DateTimeImmutable('@' . (int)$timestamp))
        ->setTimezone($timezone ?? wp_timezone())->format($format);
}
function sanitize_textarea_field($value): string { return strip_tags((string)$value); }
function sanitize_email($value): string { return trim((string)$value); }
function is_email($value) { return filter_var($value, FILTER_VALIDATE_EMAIL) ?: false; }
function absint($value): int { return abs((int)$value); }
function offline_network_blocked() {
    $GLOBALS['fixture_http_attempts']++;
    throw new RuntimeException('Network access is forbidden in this offline regression.');
}
function wp_remote_post(...$args) { return offline_network_blocked(); }
function wp_remote_get(...$args) { return offline_network_blocked(); }
function wp_remote_request(...$args) { return offline_network_blocked(); }
function get_transient(...$args) { throw new RuntimeException('Token access is forbidden.'); }
function set_transient(...$args) { throw new RuntimeException('Token writes are forbidden.'); }

final class XI_Config {
    static function get(): array {
        return array(
            'origin_id' => '42', 'origin_name' => 'FIXTURE-',
            'products' => array('fixture-product' => 'Fixture product'),
            'product_lead_names' => array('fixture-product' => 'PRODUCT-'),
            'product_field' => '', 'level_field' => '', 'level_value' => ''
        ) + ($GLOBALS['fixture_config_extra'] ?? array());
    }
    static function credential(...$args) { throw new RuntimeException('Credentials are forbidden.'); }
}

$provider = realpath($argv[1] ?? (dirname(__DIR__) . '/xiaoman-inquiry-center/provider.php'));
if (!$provider || !is_file($provider)) {
    fwrite(STDERR, "Provider source not found.\n");
    exit(2);
}
require $provider;

$checks = array();
function check(bool $ok, string $name, $actual = null, $expected = null): void {
    $row = array('check' => $name, 'passed' => $ok);
    if (!$ok) { $row['actual'] = $actual; $row['expected'] = $expected; }
    $GLOBALS['checks'][] = $row;
}
function submission(string $originalUtc): array {
    return array('id' => 70001, 'submitted_at' => $originalUtc, 'fields' => array(
        array('id' => 'F1', 'value' => 'fixture-product'),
        array('id' => 'F3', 'value' => 'XI-OFFLINE-TEST'),
        array('id' => 'F5', 'value' => 'xi-offline-test@example.com'),
        array('id' => 'F6', 'value' => '+12025550123'),
        array('id' => 'F9', 'value' => 'Synthetic offline payload. Never send to CRM.'),
        array('id' => 'A1', 'value' => 'CAMPAIGN')
    ));
}

// Expected dates are fixed business-rule examples, rather than implementation-derived.
$cases = array(
    array('morning', '2026-10-06 00:00:00', '261006'),
    array('at_midday', '2026-10-06 12:00:00', '261006'),
    array('old_cutoff_before', '2026-10-06 17:00:00', '261006'),
    array('before_cutoff', '2026-10-06 17:29:59', '261006'),
    array('at_cutoff', '2026-10-06 17:30:00', '261007'),
    array('former_cutoff', '2026-10-06 18:00:00', '261007'),
    array('before_midnight', '2026-10-06 23:59:59', '261007'),
    array('afternoon', '2026-10-06 21:24:00', '261007'),
    array('month_before_cutoff', '2026-10-31 17:29:59', '261031'),
    array('month_at_cutoff', '2026-10-31 17:30:00', '261101'),
    array('year_at_cutoff', '2026-12-31 17:30:00', '270101'),
    array('leap_feb28', '2028-02-28 17:30:00', '280229'),
    array('leap_feb29', '2028-02-29 17:30:00', '280301')
);
foreach ($cases as [$label, $originalLocal, $expectedDate]) {
    $stamp = (new DateTimeImmutable($originalLocal, wp_timezone()))->getTimestamp();
    $originalUtc = gmdate('Y-m-d H:i:s', $stamp);
    $input = submission($originalUtc);
    $before = serialize($input);
    $payload = XI_Xiaoman_Queue_V1::payload($input, $stamp);
    if (is_wp_error($payload)) {
        check(false, $label . '_payload_valid', $payload->code, 'array');
        continue;
    }
    $expectedName = $expectedDate . 'FIXTURE-PRODUCT-CAMPAIGN';
    check($payload['name'] === $expectedName, $label . '_lead_name_beijing_1730_cutoff', $payload['name'], $expectedName);
    check(str_contains($payload['remark'], $originalLocal), $label . '_remark_original_local_time');
    $shiftedTime = wp_date('Y-m-d H:i:s', $stamp + 18 * HOUR_IN_SECONDS);
    check(!str_contains($payload['remark'], $shiftedTime), $label . '_remark_not_shifted');
    check(serialize($input) === $before && $input['submitted_at'] === $originalUtc, $label . '_original_saved_utc_unchanged');
}

$retryStamp = (new DateTimeImmutable('2026-12-31 18:30:00', wp_timezone()))->getTimestamp();
$retryInput = submission(gmdate('Y-m-d H:i:s', $retryStamp));
$retryResults = array();
for ($attempt = 0; $attempt < 3; $attempt++) {
    // Pass the same original saved timestamp on every retry; no current clock is used.
    $retryResults[] = XI_Xiaoman_Queue_V1::payload($retryInput, $retryStamp);
}
check($retryResults[0] === $retryResults[1] && $retryResults[1] === $retryResults[2], 'retry_same_original_timestamp_identical_payload');
check($retryResults[2]['name'] === '270101FIXTURE-PRODUCT-CAMPAIGN', 'retry_business_date_not_accumulated');
check(str_contains($retryResults[2]['remark'], '2026-12-31 18:30:00'), 'retry_remark_original_local_time');
check(!array_intersect(array('created', 'created_at', 'created_time', 'create_time', 'submitted_at'), array_keys($retryResults[2])), 'no_crm_system_created_override');

// A changed PHP runtime default timezone must not replace the Beijing business rule.
date_default_timezone_set('America/New_York');
$sameSitePayload = XI_Xiaoman_Queue_V1::payload($retryInput, $retryStamp);
check($sameSitePayload === $retryResults[0], 'php_runtime_timezone_does_not_override_beijing_date');

// Explicitly verify another WordPress timezone using a fixed UTC instant (09:30 UTC = 17:30 Beijing).
$GLOBALS['fixture_timezone'] = new DateTimeZone('+00:00');
$edge = (new DateTimeImmutable('2026-10-06 09:29:59', new DateTimeZone('UTC')))->getTimestamp();
check(substr(XI_Xiaoman_Queue_V1::payload(submission('2026-10-06 09:29:59'), $edge)['name'], 0, 6) === '261006', 'utc_0929_59_same_day');
check(substr(XI_Xiaoman_Queue_V1::payload(submission('2026-10-06 09:30:00'), $edge + 1)['name'], 0, 6) === '261007', 'utc_0930_next_day');
$GLOBALS['fixture_timezone'] = new DateTimeZone('+00:00');
$utcStamp = (new DateTimeImmutable('2026-10-06 10:00:00', new DateTimeZone('UTC')))->getTimestamp();
$utcPayload = XI_Xiaoman_Queue_V1::payload(submission('2026-10-06 10:00:00'), $utcStamp);
check($utcPayload['name'] === '261007FIXTURE-PRODUCT-CAMPAIGN', 'beijing_cutoff_independent_of_utc_wp_timezone');
check(str_contains($utcPayload['remark'], '2026-10-06 10:00:00'), 'uses_configured_wp_timezone_for_original_remark');
$GLOBALS['fixture_timezone'] = new DateTimeZone('America/New_York');
$nyPayload = XI_Xiaoman_Queue_V1::payload(submission('2026-10-06 10:00:00'), $utcStamp);
check($nyPayload['name'] === $utcPayload['name'], 'beijing_cutoff_independent_of_new_york_wp_timezone');
check(str_contains($nyPayload['remark'], '2026-10-06 06:00:00'), 'new_york_remark_keeps_original_site_time');
// beta.19: Facebook/Meta, Instagram and TikTok sources get "<brand>FB/INS/TK" and, when set, their own Xiaoman origin; YouTube and the rest keep "<brand>谷歌".
$GLOBALS['fixture_timezone'] = new DateTimeZone('Asia/Shanghai');
foreach (array(
    array('Facebook 社媒（付费/自然未确定）', '华腾FB'), array('Facebook 广告', '华腾FB'), array('Meta 广告', '华腾FB'),
    array('Facebook / Instagram（付费/自然未确定）', '华腾FB'), array('Instagram 社媒（付费/自然未确定）', '华腾INS'), array('Instagram 广告', '华腾INS'),
    array('TikTok 社媒（付费/自然未确定）', '华腾TK'), array('TikTok 广告', '华腾TK'),
    array('YouTube 社媒（付费/自然未确定）', '华腾谷歌'), array('YouTube 广告', '华腾谷歌'), array('Google Ads', '华腾谷歌'), array('Google 搜索（广告/自然未确定）', '华腾谷歌'),
    array('直接访问 / 来源未提供', '华腾谷歌'), array('', '华腾谷歌'), array('ChatGPT 推荐', '华腾谷歌')) as [$src, $word]) {
    check(XI_Xiaoman_Queue_V1::origin_word('华腾谷歌', $src) === $word, 'title_word_' . $word . '_' . $src, XI_Xiaoman_Queue_V1::origin_word('华腾谷歌', $src), $word);
}
check(XI_Xiaoman_Queue_V1::origin_word('FIXTURE-', 'Facebook 广告') === 'FIXTURE-', 'title_word_unchanged_without_google_suffix');
$social = submission('2026-11-05 06:50:46'); $social['source'] = 'YouTube 社媒（付费/自然未确定）'; $stamp = strtotime('2026-11-05 06:50:46 UTC');
check(XI_Xiaoman_Queue_V1::payload($social, $stamp)['name'] === '261105FIXTURE-PRODUCT-CAMPAIGN', 'payload_keeps_fixture_word');
$social2 = submission('2026-11-05 06:50:46'); $social2['attribution'] = array('source_label' => 'Facebook 社媒（付费/自然未确定）');
check(XI_Xiaoman_Queue_V1::origin_word('方威谷歌', $social2['attribution']['source_label']) === '方威FB', 'other_site_brand_kept');
$GLOBALS['fixture_config_extra'] = array('channel_origin_ids' => array('fb' => '501', 'ins' => '502', 'tk' => '503'));
foreach (array(array('Facebook 社媒（付费/自然未确定）', '501'), array('Meta 广告', '501'), array('Instagram 广告', '502'), array('TikTok 社媒（付费/自然未确定）', '503'),
    array('YouTube 社媒（付费/自然未确定）', '42'), array('Google Ads', '42'), array('', '42')) as [$src, $id]) {
    $row = submission('2026-11-05 06:50:46'); $row['source'] = $src;
    $p = XI_Xiaoman_Queue_V1::payload($row, strtotime('2026-11-05 06:50:46 UTC'));
    check(!is_wp_error($p) && $p['origin_list'] === array($id), 'origin_' . $id . '_' . $src, is_wp_error($p) ? $p->message : $p['origin_list'], array($id));
}
$GLOBALS['fixture_config_extra'] = array('channel_origin_ids' => array('fb' => '501'));
$row = submission('2026-11-05 06:50:46'); $row['source'] = 'TikTok 广告';
check(XI_Xiaoman_Queue_V1::payload($row, strtotime('2026-11-05 06:50:46 UTC'))['origin_list'] === array('42'), 'origin_falls_back_when_channel_unset');
$GLOBALS['fixture_config_extra'] = array('channel_origin_ids' => array('fb' => 'not-an-id', 'tk' => '0'));
$row['source'] = 'Facebook 广告';
check(XI_Xiaoman_Queue_V1::payload($row, strtotime('2026-11-05 06:50:46 UTC'))['origin_list'] === array('42'), 'origin_ignores_invalid_channel_id');
$row = submission('2026-11-05 06:50:46'); $row['attribution'] = array('source_label' => 'Instagram 广告'); $GLOBALS['fixture_config_extra'] = array('channel_origin_ids' => array('ins' => '502'));
check(XI_Xiaoman_Queue_V1::payload($row, strtotime('2026-11-05 06:50:46 UTC'))['origin_list'] === array('502'), 'origin_from_attribution_label');
$GLOBALS['fixture_config_extra'] = array();
check($GLOBALS['fixture_http_attempts'] === 0, 'no_http_or_crm_attempt');

$failed = count(array_filter($checks, fn($row) => !$row['passed']));
echo json_encode(array(
    'provider' => $provider,
    'provider_sha256' => hash_file('sha256', $provider),
    'fixture_initial_site_timezone' => '+08:00',
    'check_count' => count($checks), 'passed' => count($checks) - $failed, 'failed' => $failed,
    'http_attempts' => $GLOBALS['fixture_http_attempts'],
    'scope' => 'Offline actual provider payload only; no storage, queue runner, CRM, Google or historical rename tested.',
    'checks' => $checks
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
exit($failed ? 1 : 0);
