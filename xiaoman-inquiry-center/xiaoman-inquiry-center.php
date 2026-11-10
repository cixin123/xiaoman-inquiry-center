<?php
/**
 * Plugin Name: 小满询盘中心
 * Description: Bricks 原生表单、询盘后台、条件留言、广告归因和小满同步统一管理。
 * Version: 2.0.0-beta.18
 * Requires at least: 6.9
 * Requires PHP: 8.2
 * Author: Site Operations
 * License: GPL-3.0-or-later
 * Update URI: https://github.com/cixin123/xiaoman-inquiry-center
 */
if (!defined('ABSPATH')) exit;
define('XI_VERSION','2.0.0-beta.18');
define('XI_FILE',__FILE__);
require_once __DIR__.'/configuration.php';
require_once __DIR__.'/compatibility.php';
require_once __DIR__.'/cookies.php';
require_once __DIR__.'/updater.php';
require_once __DIR__.'/readiness.php';
// Register the bundled library before plugins_loaded priority 0. AS selects the
// highest compatible library when other plugins also bundle it.
require_once __DIR__.'/vendor/action-scheduler/action-scheduler.php';
XI_Config::boot(); XI_Updater::boot(); XI_Cookies::boot(); XI_Readiness::boot();
// Permission restrictions remain effective during the migration preparation
// state, including when an old kefu role stores administrator capabilities.
add_filter('user_has_cap',array('XI_Compatibility','restrict_service_caps'),100,4);
$xi_profile=XI_Config::get();
if (!$xi_profile['enabled'] || ($xi_profile['migration']['status']??'')==='import_preparing' || XI_Compatibility::legacy_active()) return;
define('XI_PROFILE',$xi_profile);
define('XI_HOST',strtolower((string)wp_parse_url(home_url(),PHP_URL_HOST)));
define('XI_API',$xi_profile['api']);
define('XI_RECORD_META',$xi_profile['record_meta']);
define('XI_VIEW_CAP','xiaoman_inquiry_view');
define('XI_VIEW_ROLE','xiaoman_inquiry_viewer');
define('XI_QUEUE_PREFIX',$xi_profile['queue_prefix']);
define('XI_QUEUE_CURSOR',$xi_profile['queue_cursor']);
define('XI_QUEUE_CPT',$xi_profile['queue_cpt']);
require_once __DIR__.'/inquiry-source.php';
require_once __DIR__.'/request-attribution.php';
require_once __DIR__.'/provider.php';
require_once __DIR__.'/native-inquiry-center.php';
require_once __DIR__.'/native-inquiry-trash.php';
require_once __DIR__.'/native-xiaoman-sync.php';
require_once __DIR__.'/dashboard/dashboard.php';
require_once __DIR__.'/required/required.php';
require_once __DIR__.'/bricks.php';
require_once __DIR__.'/conversions.php';
require_once __DIR__.'/lead-stages.php';
require_once __DIR__.'/analysis-query.php';
require_once __DIR__.'/mcp-readonly.php';
XI_MCP_Readonly::boot();
XI_Lead_Stages::boot();
XI_Conversions::boot();
XI_Bricks::boot(); XI_Compatibility::runtime();
add_action('init',array('XI_Request_Attribution_V1','capture'),1);
add_action('wp_enqueue_scripts',array('XI_Xiaoman_Queue_V1','tracking'));
register_deactivation_hook(__FILE__,array('XI_Native_Xiaoman_Sync','stop'));
