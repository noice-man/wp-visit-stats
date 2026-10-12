<?php
if (!defined('ABSPATH')) { exit; }
/** First-party goal events. No Metrika request parameters or personal data are stored. */
final class WPVS_Goals {
    const DB_VERSION = '1';
    const DB_OPTION = 'wpvs_goals_schema_version';
    public static function table() {global $wpdb; return $wpdb->prefix.'wpvs_goals';}
    public static function install() {
        global $wpdb;
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        $table=self::table(); $charset=$wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            created_at datetime NOT NULL,
            local_day date NOT NULL,
            visitor_hash char(64) NOT NULL,
            session_hash char(64) NOT NULL,
            post_id bigint(20) unsigned NOT NULL DEFAULT 0,
            path varchar(255) NOT NULL DEFAULT '',
            goal_name varchar(80) NOT NULL,
            counter_id bigint(20) unsigned NOT NULL DEFAULT 0,
            source varchar(12) NOT NULL DEFAULT 'manual',
            PRIMARY KEY  (id),
            KEY goal_day (goal_name,local_day),
            KEY session_day (session_hash,local_day),
            KEY local_day (local_day),
            KEY created_at (created_at)
        ) {$charset};");
        update_option(self::DB_OPTION,self::DB_VERSION,false);
    }
    public static function maybe_install() {
        if (get_option(self::DB_OPTION) !== self::DB_VERSION) {self::install();}
    }
    public static function valid_goal($goal) {
        return is_string($goal) && preg_match('/^[a-zA-Z0-9_.:-]{1,80}$/D',$goal) === 1;
    }
    public static function track() {
        $context=WPVS_Core::goal_context();
        if (is_wp_error($context)) {
            $status=$context->get_error_code()==='rate'?429:403;
            wp_send_json_error(array('message'=>$context->get_error_code()),$status);
            return;
        }
        $goal=isset($_POST['goal'])?(string)wp_unslash($_POST['goal']):'';
        if (!self::valid_goal($goal)) {wp_send_json_error(array('message'=>'invalid_goal'),400);return;}
        $source=isset($_POST['source'])?(string)wp_unslash($_POST['source']):'manual';
        if (!in_array($source,array('manual','metrika'),true)) {wp_send_json_error(array('message'=>'source'),400);return;}
        $options=WPVS_Core::options();
        if ($source==='metrika' && empty($options['mirror_metrika'])) {wp_send_json_error(array('message'=>'mirror_disabled'),403);return;}
        $counter=isset($_POST['counter_id'])?(string)wp_unslash($_POST['counter_id']):'0';
        if (!preg_match('/^[0-9]{1,12}$/D',$counter)) {wp_send_json_error(array('message'=>'counter_id'),400);return;}
        global $wpdb;
        $ok=$wpdb->insert(self::table(),array(
            'created_at'=>gmdate('Y-m-d H:i:s',$context['time']),
            'local_day'=>wp_date('Y-m-d',$context['time']),
            'visitor_hash'=>$context['visitor_hash'],
            'session_hash'=>$context['session_hash'],
            'post_id'=>$context['post_id'],'path'=>$context['path'],
            'goal_name'=>$goal, 'counter_id'=>(int)$counter,'source'=>$source
        ),array('%s','%s','%s','%s','%d','%s','%s','%d','%s'));
        if ($ok===false) {wp_send_json_error(array('message'=>'storage'),500);return;}
        nocache_headers();
        wp_send_json_success(array('ok'=>true));
    }
    public static function cleanup() {
        global $wpdb;
        $o=WPVS_Core::options();
        $days=max(7,min(730,(int)$o['retention_days']));
        $before=wp_date('Y-m-d',strtotime('-'.$days.' days',time()));
        for($i=0;$i<5;$i++) {
            $count=$wpdb->query($wpdb->prepare('DELETE FROM '.self::table().' WHERE local_day < %s LIMIT 5000',$before));
            if (!$count || $count<5000) {break;}
        }
    }
    public static function overview($from,$to) {
        global $wpdb;
        return (array)$wpdb->get_results($wpdb->prepare(
            'SELECT goal_name,COUNT(*) hits,COUNT(DISTINCT session_hash) sessions,COUNT(DISTINCT visitor_hash) visitors,MAX(created_at) last_hit FROM '.self::table().' WHERE local_day BETWEEN %s AND %s GROUP BY goal_name ORDER BY hits DESC LIMIT 300',$from,$to
        ),ARRAY_A);
    }
    public static function by_source($from,$to) {
        global $wpdb;
        return (array)$wpdb->get_results($wpdb->prepare(
            'SELECT source,counter_id,COUNT(*) hits FROM '.self::table().' WHERE local_day BETWEEN %s AND %s GROUP BY source,counter_id ORDER BY hits DESC LIMIT 30',$from,$to
        ),ARRAY_A);
    }
    public static function by_day($from,$to,$goal='') {
        global $wpdb;
        if ($goal && self::valid_goal($goal)) {
            return (array)$wpdb->get_results($wpdb->prepare('SELECT local_day day,COUNT(*) hits,COUNT(DISTINCT session_hash) sessions FROM '.self::table().' WHERE local_day BETWEEN %s AND %s AND goal_name=%s GROUP BY local_day ORDER BY local_day',$from,$to,$goal),ARRAY_A);
        }
        return (array)$wpdb->get_results($wpdb->prepare('SELECT local_day day,COUNT(*) hits,COUNT(DISTINCT session_hash) sessions FROM '.self::table().' WHERE local_day BETWEEN %s AND %s GROUP BY local_day ORDER BY local_day',$from,$to),ARRAY_A);
    }
}
