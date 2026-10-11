<?php
// Run: php tests/endpoint-smoke.php — WordPress API mocks, no database required.
error_reporting(E_ALL);
define('ABSPATH',__DIR__.'/');
define('DAY_IN_SECONDS',86400);
define('MINUTE_IN_SECONDS',60);
class ResultException extends Exception {public $status; public $response; function __construct($status,$response){$this->status=$status;$this->response=$response;parent::__construct($status);}}
class FakeDB {
    public $prefix='wp_'; public $rows=array();
    public function insert($table,$row,$format){$this->rows[]=$row;return 1;}
}
$wpdb=new FakeDB();
$GLOBALS['wpdb']=$wpdb;
$GLOBALS['transients']=array();
function wp_parse_args($a,$b){return array_merge($b,$a);}
function get_option($key,$fallback=array()){return $fallback;}
function wp_unslash($s){return stripslashes($s);}
function sanitize_text_field($s){return trim(preg_replace('/[\r\n\t]/',' ',$s));}
function wp_parse_url($url,$part=-1){return parse_url($url,$part);}
function esc_url_raw($url){return $url;}
function home_url($path='/'){return 'https://example.org'.$path;}
function apply_filters($name,$result,$arg){return $result;}
function wp_salt($name){return 'unit-test-salt-'.$name;}
function get_transient($key){return $GLOBALS['transients'][$key]??false;}
function set_transient($key,$val,$ttl){$GLOBALS['transients'][$key]=$val;}
function is_user_logged_in(){return false;}
function is_ssl(){return true;}
function wp_date($fmt,$unix){return gmdate($fmt,$unix);}
function absint($x){return abs((int)$x);}
function get_post($id){return (object)array('ID'=>$id,'post_status'=>'publish','post_type'=>'post');}
function is_post_type_viewable($type){return true;}
function get_permalink($id){return 'https://example.org/article/';}
function nocache_headers(){}
function wp_send_json_success($resp){throw new ResultException('success',$resp);}
function wp_send_json_error($resp,$status=400){throw new ResultException('error:'.$status,$resp);}
require dirname(__DIR__).'/includes/class-wpvs-core.php';
function check($expected,$actual,$label){if($expected!==$actual)throw new RuntimeException($label.' expected '.var_export($expected,true).' got '.var_export($actual,true));}
$_SERVER['REQUEST_METHOD']='POST';$_SERVER['HTTP_ORIGIN']='https://example.org';$_SERVER['REMOTE_ADDR']='192.0.2.25';$_SERVER['HTTP_USER_AGENT']='Mozilla/5.0 (X11; Linux x86_64) Chrome/124.0 Safari/537';
$_COOKIE=array(WPVS_Core::COOKIE_VISITOR=>str_repeat('a',32),WPVS_Core::COOKIE_SESSION=>str_repeat('b',32));
$_POST=array('path'=>'/article/','referrer'=>'https://yandex.ru/search?q=private','post_id'=>'10','utm_source'=>'yandex','utm_campaign'=>'topic');
try{WPVS_Core::track();}catch(ResultException $e){check('success',$e->status,'normal request');}
check(1,count($wpdb->rows),'one pageview');
$row=$wpdb->rows[0];
check('/article/',$row['path'],'path');check(10,$row['post_id'],'post mapping');
check('yandex.ru',$row['ref_domain'],'only referrer hostname');
check('Chrome',$row['browser'],'browser');check('desktop',$row['device'],'device');
check(hash_hmac('sha256',str_repeat('a',32),'unit-test-salt-auth'),$row['visitor_hash'],'salted hashed visitor');
check(hash_hmac('sha256',str_repeat('b',32),'unit-test-salt-auth'),$row['session_hash'],'salted hashed session');
check(false,array_key_exists('ip',$row),'no ip saved');
$_SERVER['HTTP_ORIGIN']='https://evil.example';
try{WPVS_Core::track();throw new RuntimeException('should block third-party origin');}catch(ResultException $e){check('error:403',$e->status,'origin blocked');}
$_SERVER['HTTP_ORIGIN']='https://example.org';$_SERVER['HTTP_USER_AGENT']='Googlebot/2.1';
try{WPVS_Core::track();throw new RuntimeException('should block bot');}catch(ResultException $e){check('error:403',$e->status,'bot blocked');}
check(1,count($wpdb->rows),'rejected requests are not tracked');
echo "OK: 12 endpoint assertions\n";
