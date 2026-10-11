<?php
if (!defined('ABSPATH')) { exit; }
final class WPVS_Admin {
    private static $counts=array();
    public static function boot() {
        add_action('admin_menu',array(__CLASS__,'menu'));
        add_action('admin_enqueue_scripts',array(__CLASS__,'assets'));
        add_action('admin_post_wpvs_save_settings',array(__CLASS__,'save_settings'));
        add_action('restrict_manage_posts',array(__CLASS__,'list_period'));
        add_filter('the_posts',array(__CLASS__,'preload_counts'),10,2);
        add_filter('posts_clauses',array(__CLASS__,'sort_clauses'),20,2);
        add_filter('manage_posts_columns',array(__CLASS__,'columns'));
        add_filter('manage_pages_columns',array(__CLASS__,'columns'));
        add_action('manage_posts_custom_column',array(__CLASS__,'cell'),10,2);
        add_action('manage_pages_custom_column',array(__CLASS__,'cell'),10,2);
        foreach (get_post_types(array('public'=>true,'show_ui'=>true),'names') as $type) {
            add_filter("manage_{$type}_posts_columns",array(__CLASS__,'columns'));
            add_filter("manage_edit-{$type}_sortable_columns",array(__CLASS__,'sortable'));
            add_action("manage_{$type}_posts_custom_column",array(__CLASS__,'cell'),10,2);
        }
    }
    public static function menu() {
        add_menu_page('WP Visit Stats','Статистика визитов','manage_options','wp-visit-stats',array(__CLASS__,'dashboard'),'dashicons-chart-line',65);
        add_submenu_page('wp-visit-stats','Настройки','Настройки','manage_options','wp-visit-stats-settings',array(__CLASS__,'settings'));
    }
    public static function assets($hook) {
        $screen=get_current_screen();
        if (!$screen || ($screen->id!=='toplevel_page_wp-visit-stats' && $screen->id!=='visit-stats_page_wp-visit-stats-settings' && $screen->base!=='edit')) { return; }
        wp_enqueue_style('wpvs-admin', WPVS_URL.'assets/admin.css',array(),WPVS_VERSION);
        if ($screen->id==='toplevel_page_wp-visit-stats') {
            wp_enqueue_script('wpvs-admin',WPVS_URL.'assets/admin.js',array(),WPVS_VERSION,true);
        }
    }
    private static function dates() {
        $now=new DateTimeImmutable('now',wp_timezone());
        $today=$now->format('Y-m-d');
        $start=$now->modify('-29 days')->format('Y-m-d');
        $raw_from=isset($_GET['wpvs_from'])?sanitize_text_field(wp_unslash($_GET['wpvs_from'])):'';
        $raw_to=isset($_GET['wpvs_to'])?sanitize_text_field(wp_unslash($_GET['wpvs_to'])):'';
        if (self::valid_date($raw_from) && self::valid_date($raw_to) && $raw_from <= $raw_to && $raw_to <= $today) {
            $min=$now->modify('-364 days')->format('Y-m-d');
            if ($raw_from >= $min && $raw_to >= $min) { return array($raw_from,$raw_to); }
        }
        return array($start,$today);
    }
    public static function valid_date($date) {
        if (!is_string($date)||!preg_match('/^\d{4}-\d\d-\d\d$/D',$date)) { return false; }
        $x=DateTimeImmutable::createFromFormat('!Y-m-d',$date,wp_timezone());
        return $x && $x->format('Y-m-d')===$date;
    }
    public static function columns($columns) {
        $columns['wpvs_visitors']='Посетители';
        $columns['wpvs_views']='Просмотры';
        return $columns;
    }
    public static function sortable($columns) {
        $columns['wpvs_visitors']='wpvs_visitors';
        $columns['wpvs_views']='wpvs_views';
        return $columns;
    }
    public static function list_period($post_type) {
        if (!in_array($post_type,get_post_types(array('public'=>true,'show_ui'=>true),'names'),true)) { return; }
        list($from,$to)=self::dates();
        echo '<label for="wpvs_from" class="screen-reader-text">Дата статистики с</label>';
        echo '<input type="date" id="wpvs_from" name="wpvs_from" value="'.esc_attr($from).'" title="Статистика с" />';
        echo '<label for="wpvs_to" class="screen-reader-text">Дата статистики по</label>';
        echo '<input type="date" id="wpvs_to" name="wpvs_to" value="'.esc_attr($to).'" title="Статистика по" />';
        echo '<span class="wpvs-range-label">Статистика материалов</span>';
    }
    public static function preload_counts($posts,$query) {
        if (!is_admin() || !$query->is_main_query() || !$posts || !function_exists('get_current_screen')) { return $posts; }
        $screen=get_current_screen();
        if (!$screen || $screen->base!=='edit') { return $posts; }
        $ids=array_values(array_unique(array_map(function($p){return (int)$p->ID;},$posts)));
        if (!$ids) {return $posts;}
        global $wpdb;
        list($from,$to)=self::dates();
        $placeholders=implode(',',array_fill(0,count($ids),'%d'));
        $sql='SELECT post_id, COUNT(*) views, COUNT(DISTINCT visitor_hash) visitors FROM '.WPVS_Core::table().
            ' WHERE post_id IN ('.$placeholders.') AND local_day BETWEEN %s AND %s GROUP BY post_id';
        $rows=$wpdb->get_results($wpdb->prepare($sql,...array_merge($ids,array($from,$to))),ARRAY_A);
        self::$counts=array();
        foreach ($rows as $row) { self::$counts[(int)$row['post_id']]=$row; }
        return $posts;
    }
    public static function cell($column,$post_id) {
        if ($column!=='wpvs_visitors' && $column!=='wpvs_views') { return; }
        $metric=$column==='wpvs_views'?'views':'visitors';
        echo '<span class="wpvs-numeric">'.esc_html(number_format_i18n((int)(self::$counts[$post_id][$metric]??0))).'</span>';
    }
    public static function sort_clauses($clauses,$query) {
        if (!is_admin() || !$query->is_main_query() || !function_exists('get_current_screen')) {return $clauses;}
        $screen=get_current_screen();
        if (!$screen || $screen->base!=='edit') {return $clauses;}
        $order_by=$query->get('orderby');
        if (!in_array($order_by,array('wpvs_visitors','wpvs_views'),true)) {return $clauses;}
        list($from,$to)=self::dates();
        global $wpdb;
        $field=$order_by==='wpvs_visitors'?'visitors':'views';
        $direction=strtoupper((string)$query->get('order'))==='ASC'?'ASC':'DESC';
        $table=WPVS_Core::table();
        $subquery=$wpdb->prepare('SELECT post_id, COUNT(*) views, COUNT(DISTINCT visitor_hash) visitors FROM '.$table.' WHERE local_day BETWEEN %s AND %s GROUP BY post_id',$from,$to);
        $clauses['join'].=' LEFT JOIN ('.$subquery.') wpvs_summary ON '.$wpdb->posts.'.ID = wpvs_summary.post_id ';
        $clauses['orderby']='COALESCE(wpvs_summary.'.$field.',0) '.$direction.', '.$wpdb->posts.'.ID DESC';
        return $clauses;
    }
    private static function summary($from,$to) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT COUNT(DISTINCT visitor_hash) visitors, COUNT(DISTINCT session_hash) sessions, COUNT(*) views FROM '.WPVS_Core::table().' WHERE local_day BETWEEN %s AND %s',$from,$to),ARRAY_A);
    }
    private static function by_day($from,$to) {
        global $wpdb;
        $rows=$wpdb->get_results($wpdb->prepare('SELECT local_day, COUNT(DISTINCT visitor_hash) visitors, COUNT(DISTINCT session_hash) sessions, COUNT(*) views FROM '.WPVS_Core::table().' WHERE local_day BETWEEN %s AND %s GROUP BY local_day ORDER BY local_day ASC',$from,$to),ARRAY_A);
        $indexed=array(); foreach($rows as $row){ $indexed[$row['local_day']]=$row; }
        $days=array(); $dt=new DateTimeImmutable($from,wp_timezone()); $end=new DateTimeImmutable($to,wp_timezone());
        for($i=0;$dt<=$end && $i<365;$i++,$dt=$dt->modify('+1 day')){
            $date=$dt->format('Y-m-d');
            $days[]=array('date'=>$date,'visitors'=>(int)($indexed[$date]['visitors']??0),'sessions'=>(int)($indexed[$date]['sessions']??0),'views'=>(int)($indexed[$date]['views']??0));
        }
        return $days;
    }
    private static function top_pages($from,$to) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare('SELECT post_id, COUNT(DISTINCT visitor_hash) visitors, COUNT(*) views FROM '.WPVS_Core::table().' WHERE local_day BETWEEN %s AND %s AND post_id > 0 GROUP BY post_id ORDER BY views DESC LIMIT 20',$from,$to),ARRAY_A);
    }
    private static function breakdown($field,$from,$to) {
        global $wpdb;
        if (!in_array($field,array('device','ref_domain','utm_source'),true)) {return array();}
        return $wpdb->get_results($wpdb->prepare('SELECT '.$field.' label, COUNT(*) views FROM '.WPVS_Core::table().' WHERE local_day BETWEEN %s AND %s GROUP BY '.$field.' ORDER BY views DESC LIMIT 8',$from,$to),ARRAY_A);
    }
    private static function recent($from,$to) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare('SELECT created_at, post_id, path, ref_domain, device, browser FROM '.WPVS_Core::table().' WHERE local_day BETWEEN %s AND %s ORDER BY id DESC LIMIT 20',$from,$to),ARRAY_A);
    }
    public static function dashboard() {
        if (!current_user_can('manage_options')) { wp_die(esc_html__('Недостаточно прав.')); }
        list($from,$to)=self::dates();
        $summary=self::summary($from,$to);
        $days=self::by_day($from,$to);
        echo '<div class="wrap wpvs-wrap"><h1>WP Visit Stats</h1><p class="description">Собственная резервная аналитика WordPress. Данные хранятся на этом сайте и не зависят от Яндекс Метрики.</p>';
        echo '<form method="get" class="wpvs-filter"><input type="hidden" name="page" value="wp-visit-stats" />';
        echo '<label>С <input type="date" required name="wpvs_from" value="'.esc_attr($from).'" /></label> <label>По <input type="date" required name="wpvs_to" value="'.esc_attr($to).'" /></label> ';
        echo '<button type="submit" class="button button-primary">Показать</button> ';
        foreach (array(7,30,90) as $count) {
            $dt=new DateTimeImmutable('now',wp_timezone());
            $url=add_query_arg(array('page'=>'wp-visit-stats','wpvs_from'=>$dt->modify('-'.($count-1).' days')->format('Y-m-d'),'wpvs_to'=>$dt->format('Y-m-d')),admin_url('admin.php'));
            echo '<a class="button" href="'.esc_url($url).'">'.$count.' дней</a> ';
        }
        echo '</form>';
        echo '<div class="wpvs-kpis">';
        foreach (array('visitors'=>'Посетители','sessions'=>'Сеансы','views'=>'Просмотры страниц') as $key=>$label) {
            echo '<div class="wpvs-kpi"><span>'.esc_html($label).'</span><strong>'.esc_html(number_format_i18n((int)($summary[$key]??0))).'</strong></div>';
        }
        echo '</div>';
        echo '<section class="wpvs-panel"><div class="wpvs-panel-top"><h2>Динамика</h2><div class="wpvs-segments" role="group" aria-label="Показатель графика">';
        echo '<button type="button" class="button is-active" data-wpvs-metric="visitors">Посетители</button>';
        echo '<button type="button" class="button" data-wpvs-metric="sessions">Сеансы</button>';
        echo '<button type="button" class="button" data-wpvs-metric="views">Просмотры</button>';
        echo '</div></div><div id="wpvs-chart" role="img" aria-label="Динамика статистики по дням"></div>';
        echo '<details class="wpvs-chart-data"><summary>Числа по дням</summary><table class="widefat striped"><thead><tr><th>Дата</th><th>Посетители</th><th>Сеансы</th><th>Просмотры</th></tr></thead><tbody>';
        foreach ($days as $d) {echo '<tr><td>'.esc_html($d['date']).'</td><td>'.(int)$d['visitors'].'</td><td>'.(int)$d['sessions'].'</td><td>'.(int)$d['views'].'</td></tr>';}
        echo '</tbody></table></details></section>';
        wp_add_inline_script('wpvs-admin','window.WPVS_CHART='.wp_json_encode($days).';','before');
        echo '<section class="wpvs-panel"><h2>Посещаемость материалов</h2><table class="widefat striped"><thead><tr><th>Материал</th><th>Посетители</th><th>Просмотры</th></tr></thead><tbody>';
        foreach (self::top_pages($from,$to) as $item) {
            $id=(int)$item['post_id'];
            $title=get_the_title($id) ?: 'Материал #'.$id;
            $link=get_edit_post_link($id);
            echo '<tr><td>'.($link?'<a href="'.esc_url($link).'">'.esc_html($title).'</a>':esc_html($title)).'</td><td>'.esc_html(number_format_i18n((int)$item['visitors'])).'</td><td>'.esc_html(number_format_i18n((int)$item['views'])).'</td></tr>';
        }
        echo '</tbody></table></section>';
        echo '<div class="wpvs-breakdowns">';
        foreach (array('device'=>'Устройства','ref_domain'=>'Источники переходов','utm_source'=>'UTM source') as $field=>$label) {
            echo '<section class="wpvs-panel"><h2>'.esc_html($label).'</h2><table class="widefat striped"><tbody>';
            foreach (self::breakdown($field,$from,$to) as $row) {
                echo '<tr><td>'.esc_html($row['label'] ?: ($field==='device'?'Не определено':'Прямые / не указано')).'</td><td class="wpvs-numeric">'.esc_html(number_format_i18n((int)$row['views'])).'</td></tr>';
            }
            echo '</tbody></table></section>';
        }
        echo '</div>';
        echo '<section class="wpvs-panel"><h2>Последние просмотры</h2><p>Без IP, полного User-Agent и идентификаторов посетителей. URL без GET-параметров.</p>';
        echo '<table class="widefat striped"><thead><tr><th>Время (UTC)</th><th>Страница</th><th>Источник</th><th>Устройство</th><th>Браузер</th></tr></thead><tbody>';
        foreach (self::recent($from,$to) as $row) {
            echo '<tr><td>'.esc_html($row['created_at']).'</td><td>'.esc_html($row['path']).'</td><td>'.esc_html($row['ref_domain']?:'—').'</td><td>'.esc_html($row['device']).'</td><td>'.esc_html($row['browser']).'</td></tr>';
        }
        echo '</tbody></table></section>';
        echo '<p class="wpvs-small">Уникальные посетители определяются по first-party cookie, сеансы — по 30 минутам отсутствия активности. Данные включают только успешно зарегистрированные браузерные просмотры. Отчёт не является полной заменой Яндекс Метрики.</p>';
        echo '<p><a href="'.esc_url(admin_url('admin.php?page=wp-visit-stats-settings')).'">Настройки</a> · <a target="_blank" rel="noopener noreferrer" href="https://ворд-пресс.рф/solutions/wp-visit-stats-wordpress-setup/">Инструкция</a></p>';
        echo '</div>';
    }
    public static function settings() {
        if (!current_user_can('manage_options')) { wp_die(esc_html__('Недостаточно прав.')); }
        $o=WPVS_Core::options();
        echo '<div class="wrap wpvs-wrap"><h1>WP Visit Stats — настройки</h1>';
        if (isset($_GET['updated'])) {echo '<div class="notice notice-success"><p>Настройки сохранены.</p></div>';}
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="wpvs_save_settings" />';
        wp_nonce_field('wpvs_save_settings');
        echo '<table class="form-table" role="presentation">';
        $flags=array('enabled'=>'Включить сбор статистики','exclude_logged_in'=>'Не учитывать авторизованных пользователей','exclude_bots'=>'Исключать известные боты','respect_dnt'=>'Уважать сигнал Do Not Track','consent_required'=>'Собирать статистику только после согласия пользователя');
        foreach ($flags as $key=>$label) {
            echo '<tr><th scope="row">'.esc_html($label).'</th><td><label><input type="checkbox" name="'.esc_attr($key).'" value="1" '.checked(!empty($o[$key]),true,false).' /> Включено</label></td></tr>';
        }
        echo '<tr><th scope="row"><label for="wpvs_retention">Хранение событий</label></th><td><input type="number" id="wpvs_retention" min="7" max="730" name="retention_days" value="'.esc_attr((int)$o['retention_days']).'" /> дней <p class="description">По умолчанию 180. Очистка работает через WP-Cron и не требует внешнего сервиса.</p></td></tr>';
        echo '</table><p class="submit"><button type="submit" class="button button-primary">Сохранить настройки</button></p></form>';
        echo '<div class="wpvs-panel"><h2>Согласие и конфиденциальность</h2><p>Плагин использует first-party cookie посетителя и сеанса, фиксирует путь страницы, домен источника, UTM и категории устройства/браузера. Сырые IP-адреса и User-Agent не сохраняются.</p>';
        echo '<p>При включении требования согласия запись начинается только после вызова <code>window.WPVisitStats.grantConsent()</code> в браузере. Для отзыва согласия: <code>window.WPVisitStats.denyConsent()</code>. Подключите эти вызовы к собственному баннеру согласия. Без этой интеграции при требовании согласия событий не будет.</p>';
        echo '<p>Отзыв согласия останавливает будущий сбор и удаляет браузерные идентификаторы. Ранее собранные события остаются до окончания установленного срока хранения.</p>';
        echo '<p><a href="https://ворд-пресс.рф/solutions/wp-visit-stats-wordpress-setup/" target="_blank" rel="noopener noreferrer">Подробная инструкция на ворд-пресс.рф</a></p></div></div>';
    }
    public static function save_settings() {
        if (!current_user_can('manage_options')) {wp_die('Недостаточно прав',403);}
        check_admin_referer('wpvs_save_settings');
        $opts=array();
        foreach(array('enabled','exclude_logged_in','exclude_bots','respect_dnt','consent_required') as $key){$opts[$key]=empty($_POST[$key])?0:1;}
        $opts['retention_days']=max(7,min(730,isset($_POST['retention_days'])?absint($_POST['retention_days']):180));
        update_option(WPVS_Core::OPTIONS,$opts,false);
        wp_safe_redirect(add_query_arg('updated',1,admin_url('admin.php?page=wp-visit-stats-settings')));
        exit;
    }
}
