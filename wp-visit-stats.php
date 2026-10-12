<?php
/**
 * Plugin Name: WP Visit Stats
 * Plugin URI: https://ворд-пресс.рф/plugins/wp-visit-stats/
 * Description: Автономная статистика WordPress: посетители, сеансы, просмотры и посещаемость материалов. Резервная базовая аналитика при сбоях Яндекс Метрики.
 * Version: 1.0.1
 * Author: Valery Molodtsov
 * Author URI: https://ворд-пресс.рф/
 * License: GPL-2.0-or-later
 * Text Domain: wp-visit-stats
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */
if (!defined('ABSPATH')) { exit; }
define('WPVS_VERSION', '1.0.1');
define('WPVS_FILE', __FILE__);
define('WPVS_DIR', plugin_dir_path(__FILE__));
define('WPVS_URL', plugin_dir_url(__FILE__));
require_once WPVS_DIR . 'includes/class-wpvs-core.php';
require_once WPVS_DIR . 'includes/class-wpvs-admin.php';
register_activation_hook(__FILE__, array('WPVS_Core','activate'));
register_deactivation_hook(__FILE__, array('WPVS_Core','deactivate'));
add_action('plugins_loaded', function () {
    WPVS_Core::boot();
    if (is_admin()) { WPVS_Admin::boot(); }
});
