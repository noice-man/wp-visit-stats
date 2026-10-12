<?php
if (!defined('ABSPATH')) { exit; }
final class WPVS_Core {
    const OPTIONS = 'wpvs_options';
    const COOKIE_VISITOR = 'wpvs_vid';
    const COOKIE_SESSION = 'wpvs_sid';
    const AJAX_TRACK = 'wpvs_track';
    const AJAX_OPTOUT = 'wpvs_optout';
    const TIMEOUT = 1800;
    private static $booted = false;

    public static function defaults() {
        return array('enabled'=>1, 'exclude_logged_in'=>1, 'respect_dnt'=>1,
            'consent_required'=>0, 'retention_days'=>180, 'exclude_bots'=>1);
    }
    public static function options() {
        return wp_parse_args((array)get_option(self::OPTIONS, array()), self::defaults());
    }
    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'wpvs_events';
    }
    public static function activate() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table=self::table(); $charset=$wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            created_at datetime NOT NULL,
            local_day date NOT NULL,
            visitor_hash char(64) NOT NULL,
            session_hash char(64) NOT NULL,
            post_id bigint(20) unsigned NOT NULL DEFAULT 0,
            path varchar(255) NOT NULL DEFAULT '',
            ref_domain varchar(190) NOT NULL DEFAULT '',
            utm_source varchar(80) NOT NULL DEFAULT '',
            utm_medium varchar(80) NOT NULL DEFAULT '',
            utm_campaign varchar(120) NOT NULL DEFAULT '',
            device varchar(20) NOT NULL DEFAULT '',
            browser varchar(30) NOT NULL DEFAULT '',
            PRIMARY KEY  (id),
            KEY local_day (local_day),
            KEY post_day (post_id,local_day),
            KEY visitor_day (visitor_hash,local_day),
            KEY session_day (session_hash,local_day),
            KEY created_at (created_at)
        ) {$charset};");
        if (!get_option(self::OPTIONS)) { add_option(self::OPTIONS, self::defaults(), '', false); }
        self::schedule();
    }
    public static function deactivate() { wp_clear_scheduled_hook('wpvs_cleanup'); }
    private static function schedule() {
        if (!wp_next_scheduled('wpvs_cleanup')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'wpvs_cleanup');
        }
    }
    public static function boot() {
        if (self::$booted) { return; }
        self::$booted=true;
        add_action('wp_enqueue_scripts', array(__CLASS__,'enqueue'));
        add_action('wp_ajax_nopriv_' . self::AJAX_TRACK, array(__CLASS__,'track'));
        add_action('wp_ajax_' . self::AJAX_TRACK, array(__CLASS__,'track'));
        add_action('wp_ajax_nopriv_' . self::AJAX_OPTOUT, array(__CLASS__,'optout'));
        add_action('wp_ajax_' . self::AJAX_OPTOUT, array(__CLASS__,'optout'));
        add_action('wpvs_cleanup', array(__CLASS__,'cleanup'));
        add_filter('cron_schedules', array(__CLASS__,'cron_schedules'));
        self::schedule();
    }
    public static function cron_schedules($s) { return $s; }
    public static function enqueue() {
        $o=self::options();
        if (!$o['enabled'] || is_admin() || is_preview() || is_feed() || (int)is_404()) { return; }
        if ($o['exclude_logged_in'] && is_user_logged_in()) { return; }
        wp_enqueue_script('wpvs-tracker', WPVS_URL.'assets/tracker.js', array(), WPVS_VERSION, true);
        $post_id=is_singular() ? get_queried_object_id() : 0;
        if ($post_id && !get_post_status($post_id)) { $post_id=0; }
        wp_add_inline_script('wpvs-tracker', 'window.WPVS_CONFIG='.wp_json_encode(array(
            'endpoint'=>admin_url('admin-ajax.php'), 'postId'=>(int)$post_id,
            'respectDnt'=>(bool)$o['respect_dnt'], 'requireConsent'=>(bool)$o['consent_required']
        )).';', 'before');
    }
    private static function same_origin() {
        $candidate='';
        if (!empty($_SERVER['HTTP_ORIGIN'])) { $candidate=wp_unslash($_SERVER['HTTP_ORIGIN']); }
        elseif (!empty($_SERVER['HTTP_REFERER'])) { $candidate=wp_unslash($_SERVER['HTTP_REFERER']); }
        $h=wp_parse_url(esc_url_raw($candidate), PHP_URL_HOST);
        $expected=wp_parse_url(home_url('/'), PHP_URL_HOST);
        $allowed=$h && $expected && strtolower($h)===strtolower($expected);
        return (bool)apply_filters('wpvs_allow_origin', $allowed, $candidate);
    }
    private static function send_cookie($key, $value, $expires) {
        $path=wp_parse_url(home_url('/'), PHP_URL_PATH);
        $path=($path && $path[0]==='/') ? $path : '/';
        setcookie($key, $value, array('expires'=>$expires,'path'=>$path,
            'secure'=>is_ssl(),'httponly'=>true,'samesite'=>'Lax'));
    }
    private static function client_ip() {
        // Only REMOTE_ADDR: proxy headers cannot be trusted directly.
        return isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '';
    }
    private static function safe_id($name) {
        $value=isset($_COOKIE[$name]) ? (string)wp_unslash($_COOKIE[$name]) : '';
        return preg_match('/^[a-f0-9]{32}$/D',$value) ? $value : '';
    }
    private static function visitor_hash($id) {
        return hash_hmac('sha256', $id, wp_salt('auth'));
    }
    private static function ua_info($agent) {
        $ua=strtolower($agent);
        $device=preg_match('/ipad|tablet|kindle|silk/i',$ua)?'tablet':(preg_match('/mobile|android|iphone|ipod/i',$ua)?'mobile':'desktop');
        if (strpos($ua,'edg/')!==false) { $browser='Edge'; }
        elseif (strpos($ua,'opr/')!==false || strpos($ua,'opera')!==false) { $browser='Opera'; }
        elseif (strpos($ua,'firefox/')!==false) { $browser='Firefox'; }
        elseif (strpos($ua,'chrome/')!==false || strpos($ua,'crios/')!==false) { $browser='Chrome'; }
        elseif (strpos($ua,'safari/')!==false) { $browser='Safari'; }
        else { $browser='Other'; }
        return array($device,$browser);
    }
    public static function is_bot($agent) {
        if (!$agent) { return true; }
        return (bool)preg_match('/bot|spider|crawl|slurp|bingpreview|headless|lighthouse|facebookexternalhit|curl\/|wget\/|python-requests|axios\/|monitoring|uptime|pagespeed|google-inspectiontool/i', $agent);
    }
    /** Match canonical and browser URLs without being sensitive to a trailing slash. */
    public static function paths_match($a,$b) {
        $a=(string)$a;
        $b=(string)$b;
        $a=wp_parse_url($a,PHP_URL_PATH) ?: '/';
        $b=wp_parse_url($b,PHP_URL_PATH) ?: '/';
        return rtrim(rawurldecode($a),'/')===rtrim(rawurldecode($b),'/');
    }
    private static function valid_public_post($id,$path) {
        $post=$id ? get_post($id) : false;
        if (!$post || $post->post_status!=='publish' || !is_post_type_viewable($post->post_type)) {return false;}
        $permalink=get_permalink($id);
        // Plain ?p=123 URLs cannot be disambiguated after stripping query data.
        if (!$permalink || wp_parse_url($permalink,PHP_URL_QUERY)) {return false;}
        return self::paths_match($permalink,$path);
    }
    /** An ID from cached HTML is only a hint, not a trusted attribution. */
    public static function resolve_post_id($path,$hint=0) {
        $hint=absint($hint);
        if ($hint && self::valid_public_post($hint,$path)) {return $hint;}
        if (!function_exists('url_to_postid')) {return 0;}
        $resolved=absint(url_to_postid(home_url($path)));
        if (!$resolved && self::paths_match($path,home_url('/'))
            && get_option('show_on_front')==='page') {
            $resolved=absint(get_option('page_on_front'));
        }
        return self::valid_public_post($resolved,$path) ? $resolved : 0;
    }
    /**
     * Re-attribute historical rows saved as post_id=0 in bounded batches.
     * Other routes (archives, 404, external paths) remain unattributed.
     */
    public static function repair_attribution_batch($cursor=0,$limit=150) {
        global $wpdb;
        $limit=max(1,min(150,absint($limit)));
        // Scan newest entries first, so recent page metrics recover immediately.
        // 0 marks the end of the one-off history scan.
        $cursor=$cursor ? absint($cursor) : PHP_INT_MAX;
        $rows=$wpdb->get_results($wpdb->prepare(
            'SELECT id, path FROM '.self::table().' WHERE post_id=0 AND id < %d ORDER BY id DESC LIMIT %d',
            $cursor,$limit
        ),ARRAY_A);
        $grouped=array();
        $last=$cursor;
        foreach ((array)$rows as $row) {
            $id=(int)$row['id'];
            $last=min($last,$id);
            $path=(string)$row['path'];
            if (!isset($grouped[$path])) {$grouped[$path]=array();}
            $grouped[$path][]=$id;
        }
        $fixed=0;
        foreach ($grouped as $path=>$ids) {
            $post_id=self::resolve_post_id($path);
            if (!$post_id) {continue;}
            // Update only examined primary keys, never an unbounded table-wide scan.
            $placeholders=implode(',',array_fill(0,count($ids),'%d'));
            $args=array_merge(array($post_id),$ids);
            $sql='UPDATE '.self::table().' SET post_id=%d WHERE post_id=0 AND id IN ('.$placeholders.')';
            $count=$wpdb->query($wpdb->prepare($sql,...$args));
            $fixed+=max(0,(int)$count);
        }
        return array('cursor'=>count((array)$rows)<$limit ? 0 : $last,
            'examined'=>count((array)$rows),'fixed'=>$fixed);
    }
    private static function clean_text($key, $max=80) {
        $value=isset($_POST[$key]) ? sanitize_text_field(wp_unslash($_POST[$key])) : '';
        return function_exists('mb_substr') ? mb_substr($value, 0, $max) : substr($value, 0, $max);
    }
    private static function fail($message, $code=403) { wp_send_json_error(array('message'=>$message),$code); }
    public static function track() {
        if (($_SERVER['REQUEST_METHOD']??'')!=='POST' || !self::same_origin()) { self::fail('origin',403); }
        $o=self::options();
        if (!$o['enabled'] || ($o['exclude_logged_in'] && is_user_logged_in())) { self::fail('disabled',403); }
        if ($o['consent_required'] && self::clean_text('consent',10)!=='yes') { self::fail('consent',403); }
        $ua=isset($_SERVER['HTTP_USER_AGENT']) ? (string)wp_unslash($_SERVER['HTTP_USER_AGENT']) : '';
        if ($o['exclude_bots'] && self::is_bot($ua)) { self::fail('bot',403); }
        if ($o['respect_dnt'] && (!empty($_SERVER['HTTP_DNT']) && $_SERVER['HTTP_DNT']==='1')) { self::fail('dnt',403); }

        // Rate limit abuse without persisting raw IP/UA in the database.
        $ip=self::client_ip();
        $rate_key='wpvs_rl_'.substr(hash_hmac('sha256',$ip.'|'.$ua,wp_salt('nonce')),0,28);
        $rate=(int)get_transient($rate_key);
        if ($rate >= 40) { self::fail('rate',429); }
        set_transient($rate_key,$rate+1,MINUTE_IN_SECONDS);

        $visitor=self::safe_id(self::COOKIE_VISITOR);
        if (!$visitor) { $visitor=bin2hex(random_bytes(16)); }
        $session=self::safe_id(self::COOKIE_SESSION);
        if (!$session) { $session=bin2hex(random_bytes(16)); }
        $now=time();
        // Refresh session cookie on activity; inactivity for 30 min starts a new session.
        self::send_cookie(self::COOKIE_VISITOR,$visitor,$now+180*DAY_IN_SECONDS);
        self::send_cookie(self::COOKIE_SESSION,$session,$now+self::TIMEOUT);
        $path=self::clean_text('path',255);
        $parsed=wp_parse_url($path, PHP_URL_PATH);
        if (!is_string($parsed) || !$parsed || $parsed[0]!=='/') { $parsed='/'; }
        $path=function_exists('mb_substr') ? mb_substr($parsed,0,255) : substr($parsed,0,255);
        $referer=self::clean_text('referrer',400);
        $refhost=wp_parse_url(esc_url_raw($referer),PHP_URL_HOST);
        $refhost=is_string($refhost)?substr(strtolower(sanitize_text_field($refhost)),0,190):'';
        $post_id=isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
        // Do not trust the browser-supplied post ID. If a cached page carries
        // a stale or missing ID, resolve the public permalink on the server.
        $post_id=self::resolve_post_id($path,$post_id);
        list($device,$browser)=self::ua_info($ua);
        global $wpdb;
        $success=$wpdb->insert(self::table(),array(
            'created_at'=>gmdate('Y-m-d H:i:s',$now),
            'local_day'=>wp_date('Y-m-d',$now),
            'visitor_hash'=>self::visitor_hash($visitor),
            'session_hash'=>self::visitor_hash($session),
            'post_id'=>$post_id,'path'=>$path,'ref_domain'=>$refhost,
            'utm_source'=>self::clean_text('utm_source',80),
            'utm_medium'=>self::clean_text('utm_medium',80),
            'utm_campaign'=>self::clean_text('utm_campaign',120),
            'device'=>$device,'browser'=>$browser
        ),array('%s','%s','%s','%s','%d','%s','%s','%s','%s','%s','%s','%s'));
        if ($success===false) { self::fail('storage',500); }
        nocache_headers();
        wp_send_json_success(array('ok'=>true));
    }
    public static function optout() {
        if (($_SERVER['REQUEST_METHOD']??'')!=='POST' || !self::same_origin()) { self::fail('origin'); }
        self::send_cookie(self::COOKIE_VISITOR,'',time()-3600);
        self::send_cookie(self::COOKIE_SESSION,'',time()-3600);
        nocache_headers();
        wp_send_json_success(array('ok'=>true));
    }
    public static function cleanup() {
        global $wpdb;
        $o=self::options();
        $days=max(7,min(730,(int)$o['retention_days']));
        $before=wp_date('Y-m-d',strtotime('-'.$days.' days',time()));
        for ($i=0;$i<5;$i++) {
            $removed=$wpdb->query($wpdb->prepare('DELETE FROM '.self::table().' WHERE local_day < %s LIMIT 5000',$before));
            if (!$removed || $removed<5000) { break; }
        }
    }
}
