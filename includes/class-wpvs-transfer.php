<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Export/import retained WP Visit Stats events as newline-delimited JSON.
 * Imports are additive and idempotent: event_uid is unique in each table.
 */
final class WPVS_Transfer {
    const DB_VERSION = '1';
    const DB_OPTION = 'wpvs_transfer_schema_version';
    const FORMAT = 'wp-visit-stats-ndjson';
    const SCHEMA = 1;
    const MAX_IMPORT_BYTES = 104857600;
    const MAX_LINE_BYTES = 1048576;

    public static function boot() {
        add_action('init', array(__CLASS__, 'maybe_install'), 20);
        if (is_admin()) {
            add_action('admin_menu', array(__CLASS__, 'menu'));
            add_action('admin_post_wpvs_export_data', array(__CLASS__, 'export'));
            add_action('admin_post_wpvs_import_data', array(__CLASS__, 'import'));
        }
    }

    public static function menu() {
        add_submenu_page(
            'wp-visit-stats',
            'Перенос данных',
            'Перенос данных',
            'manage_options',
            'wp-visit-stats-transfer',
            array(__CLASS__, 'page')
        );
    }

    private static function instance_id() {
        $id = (string) get_option('wpvs_instance_id', '');
        if (!preg_match('/^[a-f0-9-]{36}$/iD', $id)) {
            $id = function_exists('wp_generate_uuid4')
                ? wp_generate_uuid4()
                : sprintf('%08x-%04x-4%03x-a%04x-%012x', mt_rand(), mt_rand(0, 65535), mt_rand(0, 4095), mt_rand(0, 4095), mt_rand());
            update_option('wpvs_instance_id', $id, false);
        }
        return strtolower($id);
    }

    private static function table_exists($table) {
        global $wpdb;
        if (!preg_match('/^[A-Za-z0-9_]+$/D', $table)) { return false; }
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
        return $found === $table;
    }

    private static function ensure_table_schema($table, $kind, $instance) {
        global $wpdb;
        if (!self::table_exists($table)) { return false; }

        $columns = array(
            'event_uid' => 'char(64) NULL DEFAULT NULL',
            'visitor_key' => 'char(64) NULL DEFAULT NULL',
            'session_key' => 'char(64) NULL DEFAULT NULL'
        );
        foreach ($columns as $name => $definition) {
            $exists = $wpdb->get_var("SHOW COLUMNS FROM {$table} LIKE '{$name}'");
            if (!$exists) {
                if ($wpdb->query("ALTER TABLE {$table} ADD COLUMN {$name} {$definition}") === false) {
                    return false;
                }
            }
        }

        $indexes = array(
            'event_uid' => 'UNIQUE KEY event_uid (event_uid)',
            'visitor_key_day' => 'KEY visitor_key_day (visitor_key,local_day)',
            'session_key_day' => 'KEY session_key_day (session_key,local_day)'
        );
        foreach ($indexes as $name => $definition) {
            $exists = $wpdb->get_var("SHOW INDEX FROM {$table} WHERE Key_name = '{$name}'");
            if (!$exists) {
                if ($wpdb->query("ALTER TABLE {$table} ADD {$definition}") === false) {
                    return false;
                }
            }
        }

        // Existing rows receive deterministic IDs. Re-running this is safe.
        $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET event_uid = SHA2(CONCAT(%s, %s, id), 256) WHERE event_uid IS NULL OR event_uid = ''",
            'wpvs-legacy:',
            $instance . ':' . $kind . ':'
        ));
        $remaining = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE event_uid IS NULL OR event_uid = ''");
        return $remaining === 0;
    }

    public static function maybe_install() {
        if (get_option(self::DB_OPTION) === self::DB_VERSION) { return true; }
        $instance = self::instance_id();
        $ok_events = self::ensure_table_schema(WPVS_Core::table(), 'events', $instance);
        $ok_goals = self::ensure_table_schema(WPVS_Goals::table(), 'goals', $instance);
        if (!$ok_events || !$ok_goals) { return false; }
        update_option(self::DB_OPTION, self::DB_VERSION, false);
        return true;
    }

    public static function valid_uid($uid) {
        return is_string($uid) && preg_match('/^[a-f0-9]{64}$/iD', $uid) === 1;
    }

    public static function legacy_uid($source_instance, $kind, $source_row_id) {
        return hash('sha256', 'wpvs-legacy:' . (string) $source_instance . ':' . (string) $kind . ':' . (int) $source_row_id);
    }

    private static function write_line($handle, $data) {
        $json = wp_json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) { return false; }
        return fwrite($handle, $json . "\n") !== false;
    }

    private static function export_table($handle, $kind, $columns, $instance) {
        global $wpdb;
        $table = $kind === 'events' ? WPVS_Core::table() : WPVS_Goals::table();
        $last_id = 0;
        $batch_size = 500;
        do {
            $select = 'id,' . implode(',', $columns);
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT {$select} FROM {$table} WHERE id > %d ORDER BY id ASC LIMIT %d",
                $last_id,
                $batch_size
            ), ARRAY_A);
            $rows = (array) $rows;
            foreach ($rows as $row) {
                $source_id = (int) $row['id'];
                $last_id = max($last_id, $source_id);
                $uid = isset($row['event_uid']) ? strtolower((string) $row['event_uid']) : '';
                if (!self::valid_uid($uid)) {
                    $uid = self::legacy_uid($instance, $kind, $source_id);
                }
                unset($row['id']);
                $row['event_uid'] = $uid;
                if (!self::write_line($handle, array(
                    'type' => $kind === 'events' ? 'event' : 'goal',
                    'source_row_id' => $source_id,
                    'data' => $row
                ))) {
                    return false;
                }
            }
        } while (count($rows) === $batch_size);
        return true;
    }

    public static function export() {
        if (!current_user_can('manage_options')) { wp_die('Недостаточно прав', 403); }
        check_admin_referer('wpvs_export_data');
        if (!self::maybe_install()) { wp_die('Не удалось подготовить таблицы WP Visit Stats для экспорта.', 500); }

        $instance = self::instance_id();
        $timezone = function_exists('wp_timezone_string') ? wp_timezone_string() : 'UTC';
        $filename = 'wp-visit-stats-' . gmdate('Ymd-His') . '.ndjson';

        nocache_headers();
        header('Content-Type: application/x-ndjson; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

        $handle = fopen('php://output', 'wb');
        if (!$handle) { wp_die('Не удалось начать экспорт.', 500); }

        self::write_line($handle, array(
            'format' => self::FORMAT,
            'schema' => self::SCHEMA,
            'plugin' => 'wp-visit-stats',
            'plugin_version' => defined('WPVS_VERSION') ? WPVS_VERSION : '',
            'source_instance_id' => $instance,
            'exported_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'timezone' => $timezone
        ));

        $events = array(
            'event_uid','created_at','local_day','visitor_hash','session_hash',
            'visitor_key','session_key','post_id','path','ref_domain',
            'utm_source','utm_medium','utm_campaign','device','browser'
        );
        $goals = array(
            'event_uid','created_at','local_day','visitor_hash','session_hash',
            'visitor_key','session_key','post_id','path','goal_name','counter_id','source'
        );
        $ok = self::export_table($handle, 'events', $events, $instance);
        if ($ok) { $ok = self::export_table($handle, 'goals', $goals, $instance); }
        fclose($handle);
        if (!$ok) { error_log('WP Visit Stats: export stream ended before all rows were written.'); }
        exit;
    }

    private static function clean_text($value, $max) {
        if (!is_scalar($value)) { return ''; }
        $value = sanitize_text_field((string) $value);
        return function_exists('mb_substr') ? mb_substr($value, 0, $max) : substr($value, 0, $max);
    }

    private static function valid_day($value) {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) { return false; }
        $parts = explode('-', $value);
        return checkdate((int) $parts[1], (int) $parts[2], (int) $parts[0]);
    }

    private static function valid_datetime($value) {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value)) { return false; }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new DateTimeZone('UTC'));
        return $date && $date->format('Y-m-d H:i:s') === $value;
    }

    private static function normalize_record($kind, $data, $source_instance, $source_row_id) {
        if (!is_array($data)) { return false; }
        $uid = isset($data['event_uid']) ? strtolower((string) $data['event_uid']) : '';
        if (!self::valid_uid($uid)) {
            if ((int) $source_row_id < 1) { return false; }
            $uid = self::legacy_uid($source_instance, $kind, $source_row_id);
        }

        $created_at = isset($data['created_at']) ? (string) $data['created_at'] : '';
        $local_day = isset($data['local_day']) ? (string) $data['local_day'] : '';
        $visitor_hash = isset($data['visitor_hash']) ? strtolower((string) $data['visitor_hash']) : '';
        $session_hash = isset($data['session_hash']) ? strtolower((string) $data['session_hash']) : '';
        if (!self::valid_datetime($created_at) || !self::valid_day($local_day)
            || !preg_match('/^[a-f0-9]{64}$/D', $visitor_hash)
            || !preg_match('/^[a-f0-9]{64}$/D', $session_hash)) {
            return false;
        }

        $visitor_key = isset($data['visitor_key']) ? strtolower((string) $data['visitor_key']) : '';
        $session_key = isset($data['session_key']) ? strtolower((string) $data['session_key']) : '';
        if (!self::valid_uid($visitor_key)) { $visitor_key = null; }
        if (!self::valid_uid($session_key)) { $session_key = null; }

        $path = self::clean_text($data['path'] ?? '/', 255);
        if ($path === '' || $path[0] !== '/') { $path = '/'; }
        $post_id = absint($data['post_id'] ?? 0);
        if (class_exists('WPVS_Core')) {
            $post_id = WPVS_Core::resolve_post_id($path, $post_id);
        }

        $row = array(
            'event_uid' => $uid,
            'created_at' => $created_at,
            'local_day' => $local_day,
            'visitor_hash' => $visitor_hash,
            'session_hash' => $session_hash,
            'visitor_key' => $visitor_key,
            'session_key' => $session_key,
            'post_id' => $post_id,
            'path' => $path
        );

        if ($kind === 'events') {
            $ref_domain = strtolower(self::clean_text($data['ref_domain'] ?? '', 190));
            if ($ref_domain !== '' && !preg_match('/^[a-z0-9.-]+$/D', $ref_domain)) { $ref_domain = ''; }
            $device = self::clean_text($data['device'] ?? '', 20);
            if (!in_array($device, array('desktop','mobile','tablet'), true)) { $device = ''; }
            $row['ref_domain'] = $ref_domain;
            $row['utm_source'] = self::clean_text($data['utm_source'] ?? '', 80);
            $row['utm_medium'] = self::clean_text($data['utm_medium'] ?? '', 80);
            $row['utm_campaign'] = self::clean_text($data['utm_campaign'] ?? '', 120);
            $row['device'] = $device;
            $row['browser'] = self::clean_text($data['browser'] ?? '', 30);
            return $row;
        }

        if ($kind === 'goals') {
            $goal_name = self::clean_text($data['goal_name'] ?? '', 80);
            $source = self::clean_text($data['source'] ?? 'manual', 12);
            $counter_id = isset($data['counter_id']) ? (string) $data['counter_id'] : '0';
            if (!WPVS_Goals::valid_goal($goal_name)
                || !in_array($source, array('manual','metrika'), true)
                || !preg_match('/^\d{1,12}$/D', $counter_id)) {
                return false;
            }
            $row['goal_name'] = $goal_name;
            $row['counter_id'] = (int) $counter_id;
            $row['source'] = $source;
            return $row;
        }

        return false;
    }

    private static function insert_record($kind, $row) {
        global $wpdb;
        if ($kind === 'events') {
            $table = WPVS_Core::table();
            $columns = array(
                'event_uid','created_at','local_day','visitor_hash','session_hash','visitor_key','session_key',
                'post_id','path','ref_domain','utm_source','utm_medium','utm_campaign','device','browser'
            );
            $formats = array('%s','%s','%s','%s','%s','%s','%s','%d','%s','%s','%s','%s','%s','%s','%s');
        } else {
            $table = WPVS_Goals::table();
            $columns = array(
                'event_uid','created_at','local_day','visitor_hash','session_hash','visitor_key','session_key',
                'post_id','path','goal_name','counter_id','source'
            );
            $formats = array('%s','%s','%s','%s','%s','%s','%s','%d','%s','%s','%d','%s');
        }

        $values = array();
        foreach ($columns as $column) { $values[] = $row[$column] ?? null; }
        $placeholders = array();
        foreach ($formats as $format) { $placeholders[] = $format; }
        $sql = 'INSERT IGNORE INTO ' . $table . ' (' . implode(',', $columns) . ') VALUES (' . implode(',', $placeholders) . ')';
        $prepared = $wpdb->prepare($sql, ...$values);
        if (!is_string($prepared)) { return false; }
        $result = $wpdb->query($prepared);
        return $result === false ? false : (int) $result;
    }

    private static function finish($result) {
        set_transient('wpvs_transfer_result_' . get_current_user_id(), $result, 5 * MINUTE_IN_SECONDS);
        wp_safe_redirect(admin_url('admin.php?page=wp-visit-stats-transfer'));
        exit;
    }

    public static function import() {
        if (!current_user_can('manage_options')) { wp_die('Недостаточно прав', 403); }
        check_admin_referer('wpvs_import_data');
        if (!self::maybe_install()) {
            self::finish(array('error' => 'Не удалось подготовить таблицы статистики для импорта.'));
        }

        $upload = $_FILES['wpvs_import_file'] ?? null;
        if (!is_array($upload) || !isset($upload['tmp_name'], $upload['error'], $upload['name'])
            || (int) $upload['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($upload['tmp_name'])) {
            self::finish(array('error' => 'Не удалось загрузить файл. Проверьте размер файла и настройки загрузки PHP.'));
        }
        $size = isset($upload['size']) ? (int) $upload['size'] : (int) @filesize($upload['tmp_name']);
        $max_size = function_exists('wp_max_upload_size') ? (int) wp_max_upload_size() : self::MAX_IMPORT_BYTES;
        if ($max_size < 1 || $max_size > self::MAX_IMPORT_BYTES) { $max_size = self::MAX_IMPORT_BYTES; }
        if ($size < 1 || $size > $max_size || $size > self::MAX_IMPORT_BYTES) {
            self::finish(array('error' => 'Файл пустой или превышает допустимый размер загрузки (' . size_format($max_size) . ').'));
        }
        $extension = strtolower(pathinfo(sanitize_file_name($upload['name']), PATHINFO_EXTENSION));
        if (!in_array($extension, array('ndjson','jsonl'), true)) {
            self::finish(array('error' => 'Поддерживается экспорт WP Visit Stats в формате .ndjson или .jsonl.'));
        }

        $handle = fopen($upload['tmp_name'], 'rb');
        if (!$handle) { self::finish(array('error' => 'Не удалось прочитать загруженный файл.')); }
        $header_line = fgets($handle, 65536);
        $meta = is_string($header_line) ? json_decode($header_line, true) : null;
        $source_instance = is_array($meta) ? (string) ($meta['source_instance_id'] ?? '') : '';
        if (!is_array($meta) || ($meta['format'] ?? '') !== self::FORMAT
            || (int) ($meta['schema'] ?? 0) !== self::SCHEMA
            || ($meta['plugin'] ?? '') !== 'wp-visit-stats'
            || !preg_match('/^[a-f0-9-]{36}$/iD', $source_instance)) {
            fclose($handle);
            self::finish(array('error' => 'Файл не является корректным экспортом WP Visit Stats или имеет неподдерживаемую схему.'));
        }

        $stats = array('inserted'=>0,'duplicates'=>0,'invalid'=>0,'errors'=>0,'timezone_warning'=>false);
        $current_timezone = function_exists('wp_timezone_string') ? wp_timezone_string() : 'UTC';
        $stats['timezone_warning'] = !empty($meta['timezone']) && $meta['timezone'] !== $current_timezone;

        while (($line = fgets($handle, self::MAX_LINE_BYTES)) !== false) {
            if (trim($line) === '') { continue; }
            if (strlen($line) >= self::MAX_LINE_BYTES - 1 && substr($line, -1) !== "\n") {
                $stats['invalid']++;
                continue;
            }
            $entry = json_decode($line, true);
            if (!is_array($entry) || !isset($entry['type'], $entry['data'])) {
                $stats['invalid']++;
                continue;
            }
            $type = $entry['type'] === 'event' ? 'events' : ($entry['type'] === 'goal' ? 'goals' : '');
            if ($type === '') {
                $stats['invalid']++;
                continue;
            }
            $source_row_id = isset($entry['source_row_id']) ? absint($entry['source_row_id']) : 0;
            $row = self::normalize_record($type, $entry['data'], $source_instance, $source_row_id);
            if ($row === false) {
                $stats['invalid']++;
                continue;
            }
            $result = self::insert_record($type, $row);
            if ($result === 1) { $stats['inserted']++; }
            elseif ($result === 0) { $stats['duplicates']++; }
            else { $stats['errors']++; }
        }
        fclose($handle);
        self::finish($stats);
    }

    public static function page() {
        if (!current_user_can('manage_options')) { wp_die(esc_html__('Недостаточно прав.')); }
        $result_key = 'wpvs_transfer_result_' . get_current_user_id();
        $result = get_transient($result_key);
        if ($result) { delete_transient($result_key); }

        echo '<div class="wrap wpvs-wrap"><h1>WP Visit Stats — перенос данных</h1>';
        echo '<p class="description">Экспортируйте статистику с одного экземпляра WordPress и объединяйте её с данными другого. Перенос включает просмотры страниц и цели.</p>';

        if (is_array($result)) {
            if (!empty($result['error'])) {
                echo '<div class="notice notice-error"><p>' . esc_html($result['error']) . '</p></div>';
            } else {
                echo '<div class="notice notice-success"><p><strong>Импорт завершён.</strong> Добавлено: ' . esc_html(number_format_i18n((int) ($result['inserted'] ?? 0))) .
                    ' · дубликатов пропущено: ' . esc_html(number_format_i18n((int) ($result['duplicates'] ?? 0))) .
                    ' · некорректных записей: ' . esc_html(number_format_i18n((int) ($result['invalid'] ?? 0))) .
                    ' · ошибок БД: ' . esc_html(number_format_i18n((int) ($result['errors'] ?? 0))) . '.</p></div>';
                if (!empty($result['timezone_warning'])) {
                    echo '<div class="notice notice-warning"><p>Часовой пояс источника отличается от текущего. Даты событий сохранены такими, какими были при сборе на исходном экземпляре.</p></div>';
                }
            }
        }

        echo '<div class="wpvs-panel"><h2>Экспорт</h2>';
        echo '<p>Скачивается вся сохранённая история просмотров и целей, включая UTM, источники, устройства, браузеры и обезличенные идентификаторы посетителей/сеансов. Сырые IP-адреса и User-Agent не экспортируются.</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="wpvs_export_data" />';
        wp_nonce_field('wpvs_export_data');
        echo '<button type="submit" class="button button-primary">Скачать экспорт .ndjson</button></form></div>';

        echo '<div class="wpvs-panel"><h2>Импорт и объединение</h2>';
        echo '<p>Выберите файл экспорта с другого экземпляра WP Visit Stats. Импорт добавляет только отсутствующие события: повторная загрузка того же файла безопасна, дубликаты пропускаются, существующие записи не удаляются и не перезаписываются.</p>';
        echo '<form method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="wpvs_import_data" />';
        wp_nonce_field('wpvs_import_data');
        echo '<input type="file" name="wpvs_import_file" accept=".ndjson,.jsonl,application/x-ndjson" required />';
        echo '<p><button type="submit" class="button button-primary">Импортировать и объединить</button></p></form></div>';

        echo '<div class="notice notice-info inline"><p><strong>Как объединять два сервера:</strong> обновите WP Visit Stats на обоих экземплярах, экспортируйте данные с A и импортируйте в B, затем экспортируйте данные с B и импортируйте в A. Повторяйте обмен после переключений DNS. Обмен двусторонний: ни один экземпляр не назначается главным.</p>';
        echo '<p>Для новых событий идентификаторы посетителей и сеансов совместимы между экземплярами одного домена. Старые записи связываются с новым идентификатором, когда посетитель возвращается. Если старые данные собирались на независимых установках с разными ключами WordPress и посетитель больше не возвращается на один из серверов, его исторические записи могут учитываться как разные посетители.</p></div>';
        echo '</div>';
    }
}
