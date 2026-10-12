<?php
// Run: php tests/traffic-sources-smoke.php
error_reporting(E_ALL);
define('ABSPATH', __DIR__.'/');
require dirname(__DIR__).'/includes/class-wpvs-traffic.php';
function assert_source($channel, $name, $ref, $source='', $medium='', $site='example.ru') {
    $r=WPVS_Traffic::classify($ref,$source,$medium,$site);
    if ($r!==array($channel,$name)) {
        throw new RuntimeException(json_encode(array('input'=>array($ref,$source,$medium),'expected'=>array($channel,$name),'got'=>$r),JSON_UNESCAPED_UNICODE));
    }
}
assert_source('search','Яндекс','yandex.ru');
assert_source('search','Яндекс','www.yandex.ru');
assert_source('search','Google','google.co.in');
assert_source('search','Google','google.com');
assert_source('search','Bing','www.bing.com');
assert_source('search','Поиск Mail.ru','search.mail.ru');
assert_source('social','ВКонтакте','m.vk.com');
assert_source('social','Telegram','t.me');
assert_source('social','Одноклассники','ok.ru');
assert_source('social','Дзен','dzen.ru');
assert_source('social','X / Twitter','t.co','twitter','social');
assert_source('referral','blog.partner.ru','blog.partner.ru');
assert_source('referral','google.com.evil.site','google.com.evil.site');
assert_source('referral','vk.com.tracker.net','vk.com.tracker.net');
assert_source('direct','Прямой заход','example.ru');
assert_source('direct','Прямой заход','www.example.ru');
assert_source('direct','Прямой заход','');
assert_source('ads','Яндекс','','yandex','cpc');
assert_source('ads','ВКонтакте','vk.com','vk','paid_social');
assert_source('email','newsletter','','newsletter','email');
assert_source('search','Google','','google','organic');
assert_source('social','Telegram','','telegram','social');
assert_source('campaign','partner_special','','partner_special','');
assert_source('referral','sponsor.example','','sponsor.example','referral');
$summary=WPVS_Traffic::aggregate(array(
    array('ref_domain'=>'google.com','utm_source'=>'','utm_medium'=>'','sessions'=>3),
    array('ref_domain'=>'yandex.ru','utm_source'=>'','utm_medium'=>'','sessions'=>5),
    array('ref_domain'=>'google.com','utm_source'=>'','utm_medium'=>'cpc','sessions'=>1),
    array('ref_domain'=>'vk.com','utm_source'=>'','utm_medium'=>'','sessions'=>2),
    array('ref_domain'=>'example.ru','utm_source'=>'','utm_medium'=>'','sessions'=>3),
    array('ref_domain'=>'','utm_source'=>'newsletter','utm_medium'=>'email','sessions'=>2),
    array('ref_domain'=>'other.site','utm_source'=>'','utm_medium'=>'','sessions'=>4),
), 'example.ru');
if ($summary['total']!==20 || $summary['channels']['search']['sessions']!==8 || $summary['channels']['ads']['sessions']!==1 || $summary['channels']['social']['sessions']!==2 || $summary['channels']['email']['sessions']!==2 || $summary['channels']['referral']['sessions']!==4 || $summary['channels']['direct']['sessions']!==3) {
    throw new RuntimeException('wrong traffic distribution: '.json_encode($summary,JSON_UNESCAPED_UNICODE));
}
if ($summary['channels']['search']['sources']['Яндекс']!==5) {throw new RuntimeException('search breakdown');}
print "OK: traffic channels, domain spoofing, internal traffic, UTM, and 20-session aggregation\n";
