<?php
// Run php tests/repair-smoke.php. Tests automatic re-attribution for legacy events.
error_reporting(E_ALL);
define('ABSPATH',__DIR__.'/');
define('ARRAY_A','ARRAY_A');
class FakeWpdb {
 public $prefix='wp_';
 public $updates=array();
 public function prepare($q,...$args){return vsprintf(str_replace(array('%d','%s'),array('%d',"'%s'"),$q),$args);}
 public function get_results($sql,$mode) {return array(array('id'=>7,'path'=>'/archive/'),array('id'=>6,'path'=>'/article/'),array('id'=>5,'path'=>'/article/'));}
 public function query($sql){$this->updates[]=$sql;return 2;}
}
$wpdb=new FakeWpdb();$GLOBALS['wpdb']=$wpdb;
function absint($x){return abs((int)$x);}
function wp_parse_url($url,$part=-1){return parse_url($url,$part);}
function home_url($path='/'){return 'https://example.org'.$path;}
function get_post($id){return $id===12?(object)array('ID'=>12,'post_status'=>'publish','post_type'=>'post'):null;}
function is_post_type_viewable($type){return $type==='post';}
function get_permalink($id){return 'https://example.org/article';}
function url_to_postid($url){return strpos($url,'/article')!==false?12:0;}
require dirname(__DIR__).'/includes/class-wpvs-core.php';
$res=WPVS_Core::repair_attribution_batch(PHP_INT_MAX,150);
if ($res['cursor']!==0 || $res['examined']!==3 || $res['fixed']!==2 || count($wpdb->updates)!==1) {
 throw new Exception('Bad recovery: '.json_encode($res));
}
if (!WPVS_Core::paths_match('https://example.org/article/','/article')) {throw new Exception('Trailing slash mismatch');}
if (WPVS_Core::paths_match('/article/','/archive/')) {throw new Exception('False URL match');}
echo "OK: backfill attribution and strict URL matching\n";
