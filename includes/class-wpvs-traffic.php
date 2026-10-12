<?php
if (!defined('ABSPATH')) { exit; }
/**
 * Session-first-touch attribution for WP Visit Stats.
 * Uses ref_domain and UTM data already stored by all WPVS versions.
 * No third-party API; no IP/URL/user identifiers in aggregate widget output.
 */
final class WPVS_Traffic {
    public static function channels() {
        return array(
            'search'=>'Поисковые системы',
            'social'=>'Социальные сети',
            'referral'=>'Сайты-источники',
            'ads'=>'Реклама',
            'email'=>'Email-рассылки',
            'campaign'=>'Другие кампании',
            'direct'=>'Прямые заходы'
        );
    }
    private static function normalize_host($host) {
        $host=strtolower(trim((string)$host));
        $host=preg_replace('/^www\./','',$host);
        return preg_match('/^[a-z0-9.-]{1,190}$/D',$host)?trim($host,'.'):'';
    }
    private static function domain_match($host,$domain) {
        return $host===$domain || (strlen($host)>strlen($domain) && substr($host,-strlen($domain)-1)==='.'. $domain);
    }
    private static function classify_domain($host) {
        $search=array('yandex.ru'=>'Яндекс','yandex.com'=>'Яндекс','yandex.by'=>'Яндекс','yandex.kz'=>'Яндекс','ya.ru'=>'Яндекс','google.com'=>'Google','google.ru'=>'Google','google.de'=>'Google','google.by'=>'Google','google.kz'=>'Google','google.co.uk'=>'Google','bing.com'=>'Bing','yahoo.com'=>'Yahoo','yahoo.co.jp'=>'Yahoo','duckduckgo.com'=>'DuckDuckGo','search.mail.ru'=>'Поиск Mail.ru','rambler.ru'=>'Рамблер','baidu.com'=>'Baidu','ecosia.org'=>'Ecosia','startpage.com'=>'Startpage','brave.com'=>'Brave Search','search.brave.com'=>'Brave Search','qwant.com'=>'Qwant','naver.com'=>'Naver');
        $social=array('vk.com'=>'ВКонтакте','vk.ru'=>'ВКонтакте','vkontakte.ru'=>'ВКонтакте','ok.ru'=>'Одноклассники','odnoklassniki.ru'=>'Одноклассники','t.me'=>'Telegram','telegram.me'=>'Telegram','telegram.org'=>'Telegram','instagram.com'=>'Instagram','facebook.com'=>'Facebook','fb.com'=>'Facebook','messenger.com'=>'Messenger','twitter.com'=>'X / Twitter','x.com'=>'X / Twitter','youtube.com'=>'YouTube','youtu.be'=>'YouTube','pinterest.com'=>'Pinterest','linkedin.com'=>'LinkedIn','reddit.com'=>'Reddit','tiktok.com'=>'TikTok','dzen.ru'=>'Дзен','zen.yandex.ru'=>'Дзен','rutube.ru'=>'Rutube','threads.net'=>'Threads','discord.com'=>'Discord','discord.gg'=>'Discord');
        foreach ($search as $domain=>$label) {
            if (self::domain_match($host,$domain)) { return array('search',$label); }
        }
        // Country Google domains (google.fr, google.co.in, etc.), anchored to prevent spoofed URLs.
        if (preg_match('/^google\.[a-z]{2,3}(?:\.[a-z]{2})?$/D',$host)) {return array('search','Google');}
        foreach ($social as $domain=>$label) {
            if (self::domain_match($host,$domain)) {return array('social',$label);}
        }
        return array('referral',$host);
    }
    private static function classify_utm($source) {
        $source=strtolower(trim((string)$source));
        $aliases=array('ya'=>'Яндекс','yandex'=>'Яндекс','yandex_search'=>'Яндекс','google'=>'Google','google_search'=>'Google','bing'=>'Bing','duckduckgo'=>'DuckDuckGo','vk'=>'ВКонтакте','vkontakte'=>'ВКонтакте','vkontakte_ru'=>'ВКонтакте','ok'=>'Одноклассники','odnoklassniki'=>'Одноклассники','tg'=>'Telegram','telegram'=>'Telegram','fb'=>'Facebook','facebook'=>'Facebook','instagram'=>'Instagram','inst'=>'Instagram','twitter'=>'X / Twitter','x'=>'X / Twitter','youtube'=>'YouTube','yt'=>'YouTube','dzen'=>'Дзен','zen'=>'Дзен','tiktok'=>'TikTok','reddit'=>'Reddit','linkedin'=>'LinkedIn');
        if (isset($aliases[$source])) {
            $label=$aliases[$source];
            if (in_array($label,array('Яндекс','Google','Bing','DuckDuckGo'),true)) {return array('search',$label);}
            return array('social',$label);
        }
        $host=self::normalize_host($source);
        if ($host && strpos($host,'.')!==false) {return self::classify_domain($host);}
        return array('campaign',substr(preg_replace('/[[:cntrl:]]+/u','',$source),0,80));
    }
    /** Returns channel slug and display label. Only source/medium/domain, never raw URL. */
    public static function classify($ref_domain,$utm_source='',$utm_medium='',$site_host='') {
        $host=self::normalize_host($ref_domain);
        $own=self::normalize_host($site_host);
        if ($own && $host && (self::domain_match($host,$own) || self::domain_match($own,$host))) {$host='';}
        $medium=strtolower(trim((string)$utm_medium));
        $source=trim((string)$utm_source);
        $from_utm=$source!=='' ? self::classify_utm($source) : array('campaign','Не указан');
        $from_ref=$host!=='' ? self::classify_domain($host) : array('direct','Прямой заход');
        if (in_array($medium,array('cpc','ppc','cpm','cpa','cpv','paid','paidsearch','paid_search','paid-social','paid_social','display','banner','retargeting','remarketing'),true)) {
            return array('ads',$source!==''?$from_utm[1]:(($host!=='')?$from_ref[1]:'Рекламная кампания'));
        }
        if (in_array($medium,array('email','e-mail','newsletter','mailing'),true)) {
            return array('email',$source!==''?$from_utm[1]:'Email-рассылка');
        }
        if (in_array($medium,array('social','social-media','social_network','social-network','sm','organic_social'),true)) {
            return array('social',$source!==''?$from_utm[1]:'Социальные сети');
        }
        if (in_array($medium,array('organic','search','organic_search','seo'),true)) {
            return array('search',$source!==''?$from_utm[1]:(($from_ref[0]==='search')?$from_ref[1]:'Поисковая система'));
        }
        if ($medium==='referral') {return array('referral',$source!==''?$from_utm[1]:(($host!=='')?$host:'Сайт-источник'));}
        if ($source!=='') {return $from_utm;}
        return $from_ref;
    }
    /** SQL groups first registered event of each session in the chosen period. */
    public static function query($from,$to) {
        global $wpdb;
        $table=WPVS_Core::table();
        $sql="SELECT e.ref_domain, e.utm_source, e.utm_medium, COUNT(*) AS sessions
             FROM {$table} e INNER JOIN (
                 SELECT MIN(id) first_id FROM {$table}
                 WHERE local_day BETWEEN %s AND %s GROUP BY session_hash
             ) first_hits ON first_hits.first_id=e.id
             GROUP BY e.ref_domain,e.utm_source,e.utm_medium";
        $rows=$wpdb->get_results($wpdb->prepare($sql,$from,$to),ARRAY_A);
        $host=wp_parse_url(home_url('/'),PHP_URL_HOST) ?: '';
        return self::aggregate((array)$rows,$host);
    }
    /** Input pre-aggregated rows; safe and testable without WP database. */
    public static function aggregate($rows,$site_host='') {
        $channels=self::channels();
        $result=array('total'=>0,'channels'=>array());
        foreach ($channels as $key=>$title) {$result['channels'][$key]=array('key'=>$key,'title'=>$title,'sessions'=>0,'sources'=>array());}
        foreach ($rows as $row) {
            $n=max(0,(int)($row['sessions']??0));
            if (!$n) {continue;}
            list($channel,$source)=self::classify($row['ref_domain']??'',$row['utm_source']??'',$row['utm_medium']??'',$site_host);
            $result['channels'][$channel]['sessions']+=$n;
            $source=(string)$source;
            if ($source==='') {$source='Без названия';}
            $result['channels'][$channel]['sources'][$source]=($result['channels'][$channel]['sources'][$source]??0)+$n;
            $result['total']+=$n;
        }
        foreach ($result['channels'] as &$entry) {arsort($entry['sources'],SORT_NUMERIC);}
        unset($entry);
        return $result;
    }
}
