<?php
// Run php tests/admin-counts-smoke.php. A WP_Query may suppress the_posts on the list screen.
error_reporting(E_ALL);
define('ABSPATH',__DIR__.'/');
define('ARRAY_A','ARRAY_A');
class FakeWpdb {
    public $prefix='wp_';
    public $queried=array();
    public function prepare($q,...$args) {return vsprintf(str_replace(array('%d','%s'),array('%d',"'%s'"),$q),$args);}
    public function get_results($sql,$output) {
        $this->queried[]=$sql;
        return array(array('effective_id'=>16,'views'=>'8','visitors'=>'3'));
    }
}
$wpdb=new FakeWpdb();$GLOBALS['wpdb']=$wpdb;
$wp_query=(object)array('posts'=>array((object)array('ID'=>16),(object)array('ID'=>17)));
$GLOBALS['wp_query']=$wp_query;
function wp_timezone(){return new DateTimeZone('Europe/Moscow');}
function get_option($key,$default=0){return $default;}
function sanitize_text_field($v){return trim($v);}
function wp_unslash($v){return $v;}
function is_admin(){return true;}
function get_current_screen(){return (object)array('base'=>'edit');}
function number_format_i18n($x){return (string)$x;}
function esc_html($v){return $v;}
function current_user_can($cap){return true;}
function get_permalink($id){return 'https://example.org/article-'.$id.'/';}
function wp_parse_url($url,$part=-1){return parse_url($url,$part);}
require dirname(__DIR__).'/includes/class-wpvs-core.php';
require dirname(__DIR__).'/includes/class-wpvs-admin.php';
ob_start(); WPVS_Admin::cell('wpvs_views',16); $views=strip_tags(ob_get_clean());
ob_start(); WPVS_Admin::cell('wpvs_visitors',16); $visitors=strip_tags(ob_get_clean());
ob_start(); WPVS_Admin::cell('wpvs_views',17); $zero=strip_tags(ob_get_clean());
if ($views!=='8' || $visitors!=='3' || $zero!=='0' || count($wpdb->queried)!==1) {
    throw new Exception('Wrong lazy counters or repeated SQL queries: '.json_encode(array($views,$visitors,$zero,$wpdb->queried)));
}
if (strpos($wpdb->queried[0],"post_id=0 AND path IN")===false || strpos($wpdb->queried[0],"GROUP BY effective_id")===false) {throw new Exception('Legacy path fallback missing');}
echo "OK: lazy admin counts, legacy path fallback, single SQL query\n";
