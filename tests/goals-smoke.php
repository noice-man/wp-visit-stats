<?php
// Run: php tests/goals-smoke.php. No real WordPress, DB, or Yandex needed.
define('ABSPATH',__DIR__.'/');
define('DAY_IN_SECONDS',86400);define('MINUTE_IN_SECONDS',60);
class WP_Error {private $code;function __construct($code,$message){$this->code=$code;}function get_error_code(){return $this->code;}}
class EndRequest extends Exception {public $code2;function __construct($code2){$this->code2=$code2;}}
class FakeDB {public $prefix='wp_';public $rows=[];public function insert($table,$row,$formats){$this->rows[]=$row;return 1;}}
$wpdb = new FakeDB();
$GLOBALS['wpdb']=$wpdb;
$GLOBALS['ops']=['wpvs_options'=>['enabled'=>1,'goal_tracking'=>1,'mirror_metrika'=>1,'exclude_logged_in'=>1,'respect_dnt'=>1,'exclude_bots'=>1,'consent_required'=>1,'retention_days'=>180]];
$GLOBALS['transients']=[];
function wp_parse_args($a,$b){return array_merge($b,$a);}function get_option($key,$fallback=false){return $GLOBALS['ops'][$key]??$fallback;}
function wp_unslash($v){return stripslashes($v);}function sanitize_text_field($s){return trim(preg_replace('/[\r\n\t]/',' ', $s));}
function wp_parse_url($a,$b=-1){return parse_url($a,$b);}function esc_url_raw($a){return $a;}
function home_url($path='/'){return 'https://example.org'.$path;}function apply_filters($n,$a,$b){return $a;}
function wp_salt($n){return 'test-salt-'.$n;}function get_transient($key){return $GLOBALS['transients'][$key]??false;}
function set_transient($key,$value,$ttl){$GLOBALS['transients'][$key]=$value;}function is_user_logged_in(){return false;}
function is_ssl(){return true;}function wp_date($format,$unix){return gmdate($format,$unix);}
function absint($x){return abs((int)$x);}function get_post($id){return (object)['ID'=>$id,'post_status'=>'publish','post_type'=>'post'];}
function is_post_type_viewable($type){return true;}function get_permalink($id){return $id===10?'https://example.org/article/':'https://example.org/other/';}
function url_to_postid($url){return strpos($url,'/article/')!==false?10:0;}function is_wp_error($x){return $x instanceof WP_Error;}
function nocache_headers(){}function wp_send_json_error($error,$status=403){throw new EndRequest('error-'.$status.':'.($error['message']??''));}
function wp_send_json_success($x){throw new EndRequest('success');}
require dirname(__DIR__).'/includes/class-wpvs-core.php';
require dirname(__DIR__).'/includes/class-wpvs-goals.php';
function ok($expr,$msg){if(!$expr){throw new RuntimeException('FAIL: '.$msg);}}
$_SERVER=['REQUEST_METHOD'=>'POST','HTTP_ORIGIN'=>'https://example.org','REMOTE_ADDR'=>'192.0.2.4','HTTP_USER_AGENT'=>'Mozilla/5.0 Chrome/124.0'];
$_COOKIE=[WPVS_Core::COOKIE_VISITOR=>str_repeat('a',32),WPVS_Core::COOKIE_SESSION=>str_repeat('b',32)];
$_POST=['path'=>'/article/','post_id'=>'10','goal'=>'zayavka','source'=>'metrika','counter_id'=>'51634436','consent'=>'yes','params'=>'{email:private@example.org}'];
try {WPVS_Goals::track();}catch(EndRequest $e){ok($e->code2==='success','metrika goal success');}
ok(count($wpdb->rows)===1,'one event');
$row=$wpdb->rows[0];
ok($row['goal_name']==='zayavka' && $row['counter_id']===51634436 && $row['source']==='metrika','goal fields');
ok($row['post_id']===10 && $row['path']==='/article/','post attribution');
ok(!array_key_exists('params',$row) && !array_key_exists('email',$row),'no params copied');
ok(strlen($row['visitor_hash'])===64 && strlen($row['session_hash'])===64,'hashed cookies');
foreach(['test@example.com','my goal','<script>'] as $bad){ok(!WPVS_Goals::valid_goal($bad),'reject private/unsafe name');}
$_POST['goal']='email@example.com';
try {WPVS_Goals::track();}catch(EndRequest $e){ok($e->code2==='error-400:invalid_goal','invalid name rejects');}
ok(count($wpdb->rows)===1,'invalid goal not recorded');
$_POST['goal']='zayavka';$_POST['consent']='no';
try {WPVS_Goals::track();}catch(EndRequest $e){ok($e->code2==='error-403:consent','consent rejects');}
$_POST['consent']='yes';$GLOBALS['ops']['wpvs_options']['mirror_metrika']=0;
try {WPVS_Goals::track();}catch(EndRequest $e){ok($e->code2==='error-403:mirror_disabled','mirror toggle');}
$_POST['source']='manual';
try {WPVS_Goals::track();}catch(EndRequest $e){ok($e->code2==='success','manual remains on');}
ok(count($wpdb->rows)===2,'manual event');
$_SERVER['HTTP_ORIGIN']='https://not-example.org';
try {WPVS_Goals::track();}catch(EndRequest $e){ok($e->code2==='error-403:origin','CSRF origin');}
echo "OK: goal events, privacy, consent, mirroring toggle and origin\n";
