<?php
// php tests/browser-update-smoke.php
error_reporting(E_ALL);
define('ABSPATH', __DIR__ . '/');
require dirname(__DIR__).'/includes/class-wpvs-core.php';
$fn=new ReflectionMethod('WPVS_Core','ua_info');
$examples=array(
    'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 YaBrowser/24.12.1.0 Safari/537.36'=>'Яндекс Браузер',
    'Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Mobile YaBrowser/24.6.6.61 Safari/537.36'=>'Яндекс Браузер',
    'Mozilla/5.0 Chrome/128.0.0.0 Safari/537.36'=>'Chrome',
    'Mozilla/5.0 Chrome/128.0.0.0 Edg/128.0.0.0'=>'Edge',
    'Mozilla/5.0 Chrome/126.0.0.0 OPR/112.0.0.0'=>'Opera',
    'Mozilla/5.0 Firefox/125.0'=>'Firefox',
);
foreach($examples as $ua=>$expected){
    $result=$fn->invoke(null,$ua);
    if($result[1]!==$expected){throw new Exception('Browser mismatch for '.$ua.': '.json_encode($result));}
}
if($fn->invoke(null,array_keys($examples)[1])[0]!=='mobile'){throw new Exception('Yandex mobile device mismatch');}

// WordPress environment stubs needed only for SDK hooks.
$filters=array();$options=array();
function add_filter($name,$callback,$priority=10,$args=1){$GLOBALS['filters'][$name][]=$callback;}
function add_action($name,$callback,$priority=10,$args=1){}
function plugin_basename($path){return 'wp-visit-stats/wp-visit-stats.php';}
function untrailingslashit($s){return rtrim($s,'/');}
function wp_parse_url($value,$component=-1){return parse_url($value,$component);}
function get_option($name,$default=false){return $GLOBALS['options'][$name]??$default;}
function wp_next_scheduled($name){return false;}
function get_file_data($path,$headers,$context=''){
    $source=file_get_contents($path);$out=array();
    foreach($headers as $key=>$header){$out[$key]=preg_match('/^\\s*\\*\\s*'.preg_quote($header,'/').'\\s*:\\s*(.*?)\\s*$/mi',$source,$m)?trim($m[1]):'';}
    return $out;
}
require dirname(__DIR__).'/includes/wpr-updates-client.php';
$main=dirname(__DIR__).'/wp-visit-stats.php';
if(WPR_Updates_Client_V2::register($main,array('slug'=>'wp-visit-stats','server'=>'https://xn----ctbgf6biedka.xn--p1ai','telemetry'=>true))!==true){throw new Exception('SDK failed register');}
$state=(object)array('response'=>array(),'no_update'=>array());
foreach($GLOBALS['filters']['site_transient_update_plugins'] as $handler){$state=$handler($state);}
$key='wp-visit-stats/wp-visit-stats.php';
$marked=$state->no_update[$key]??null;
if(!$marked || $marked->new_version!=='1.0.6' || $marked->slug!=='wp-visit-stats') {throw new Exception('SDK no_update support missing');}
if(property_exists($marked,'autoupdate')){throw new Exception('SDK forced automatic updates');}
$already=(object)array('response'=>array($key=>(object)array('new_version'=>'1.1.0')),'no_update'=>array());
foreach($GLOBALS['filters']['site_transient_update_plugins'] as $handler){$already=$handler($already);}
if(isset($already->no_update[$key]) || $already->response[$key]->new_version!=='1.1.0'){throw new Exception('Real update got replaced by local no_update');}
$state2=(object)array('response'=>array(),'no_update'=>array());
foreach($GLOBALS['filters']['pre_set_site_transient_update_plugins'] as $handler){$state2=$handler($state2);}
if(empty($state2->no_update[$key])){throw new Exception('Pre-set transient not marked');}
echo "OK: browser detection (six UAs), local auto-update toggle, no forced updates and real update priority\n";
