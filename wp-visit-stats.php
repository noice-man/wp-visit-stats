<?php
/**
 * Plugin Name: WP Visit Stats
 * Plugin URI: https://ворд-пресс.рф/plugins/wp-visit-stats/
 * Update URI: https://xn----ctbgf6biedka.xn--p1ai/plugins/wp-visit-stats/
 * Description: Автономная статистика WordPress: посетители, сеансы, просмотры и посещаемость материалов. Резервная базовая аналитика при сбоях Яндекс Метрики.
 * Version: 1.0.8
 * Author: Valery Molodtsov
 * Author URI: https://ворд-пресс.рф/
 * License: GPL-2.0-or-later
 * Text Domain: wp-visit-stats
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */
if (!defined('ABSPATH')) { exit; }
define('WPVS_VERSION', '1.0.8');
define('WPVS_FILE', __FILE__);
define('WPVS_DIR', plugin_dir_path(__FILE__));
define('WPVS_URL', plugin_dir_url(__FILE__));
require_once WPVS_DIR . 'includes/class-wpvs-core.php';
require_once WPVS_DIR . 'includes/class-wpvs-traffic.php';
require_once WPVS_DIR . 'includes/class-wpvs-goals.php';
require_once WPVS_DIR . 'includes/class-wpvs-admin.php';
register_activation_hook(__FILE__, array('WPVS_Core','activate'));
register_deactivation_hook(__FILE__, array('WPVS_Core','deactivate'));
add_action('plugins_loaded', function () {
    WPVS_Core::boot();
    if (is_admin()) { WPVS_Admin::boot(); }
});

/** WPR Update Hub SDK v2; telemetry requires explicit administrator opt-in. */
require_once __DIR__ . '/includes/wpr-updates-client.php';
WPR_Updates_Client_V2::register(__FILE__, array(
    'slug' => 'wp-visit-stats',
    'server' => 'https://xn----ctbgf6biedka.xn--p1ai',
    'telemetry' => true,
));
