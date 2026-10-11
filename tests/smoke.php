<?php
// Run: php tests/smoke.php
error_reporting(E_ALL);
define('ABSPATH',__DIR__.'/');
function wp_timezone(){return new DateTimeZone('Europe/Moscow');}
require dirname(__DIR__).'/includes/class-wpvs-core.php';
require dirname(__DIR__).'/includes/class-wpvs-admin.php';
function check($expected,$actual,$label) {
    if ($expected!==$actual) { throw new RuntimeException("$label: expected ".var_export($expected,true).", got ".var_export($actual,true)); }
}
check(true,WPVS_Core::is_bot('Mozilla/5.0 compatible Googlebot/2.1'),'bot');
check(true,WPVS_Core::is_bot('curl/8.2'),'curl bot');
check(false,WPVS_Core::is_bot('Mozilla/5.0 Chrome/122.0 Safari/537'),'browser');
check(true,WPVS_Admin::valid_date('2026-02-28'),'valid date');
check(false,WPVS_Admin::valid_date('2026-02-29'),'invalid leap date');
check(false,WPVS_Admin::valid_date('2026-13-01'),'invalid month');
check(false,WPVS_Admin::valid_date('2026-10-11 OR 1=1'),'SQL input');
$ua=new ReflectionMethod('WPVS_Core','ua_info'); $ua->setAccessible(true);
check(array('mobile','Safari'),$ua->invoke(null,'Mozilla/5.0 (iPhone) AppleWebKit/605 Safari/605'),'iphone');
check(array('desktop','Edge'),$ua->invoke(null,'Mozilla/5.0 Edg/135.0'),'Edge');
check(array('tablet','Chrome'),$ua->invoke(null,'Mozilla/5.0 (iPad) Chrome/135'),'tablet');
check(180,WPVS_Core::defaults()['retention_days'],'default retention');
check(0,WPVS_Core::defaults()['consent_required'],'consent integration enabled by admin');
check(1800,WPVS_Core::TIMEOUT,'30 minute session');
echo "OK: 12 core/admin smoke checks\n";
