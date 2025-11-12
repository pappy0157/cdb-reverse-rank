<?php
if (!defined('ABSPATH')) exit;

class CDB_RR_DB {
    const T_EVENTS = 'cdb_ref_events';
    const T_AGG_DAILY = 'cdb_ref_agg_daily';
    const T_SOURCES = 'cdb_ref_sources';

    public static function tbl($name) {
        global $wpdb;
        return $wpdb->prefix . $name;
    }

    public static function create_tables() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();

        $sql1 = "CREATE TABLE IF NOT EXISTS " . self::tbl(self::T_EVENTS) . " (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            ref_domain VARCHAR(255) NOT NULL,
            ref_url TEXT NULL,
            ref_title TEXT NULL,
            dest_type VARCHAR(32) NULL,
            dest_id BIGINT NULL,
            utm_source VARCHAR(128) NULL,
            utm_medium VARCHAR(128) NULL,
            utm_campaign VARCHAR(128) NULL,
            utm_content VARCHAR(128) NULL,
            ua_hash CHAR(64) NULL,
            ip_hash CHAR(64) NULL,
            created_at DATETIME NOT NULL,
            KEY idx_domain (ref_domain(191)),
            KEY idx_created (created_at),
            KEY idx_dest (dest_type, dest_id)
        ) $charset;";

        $sql2 = "CREATE TABLE IF NOT EXISTS " . self::tbl(self::T_AGG_DAILY) . " (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            date DATE NOT NULL,
            ref_domain VARCHAR(255) NOT NULL,
            ref_url TEXT NULL,
            dest_type VARCHAR(32) NULL,
            dest_id BIGINT NULL,
            utm_source VARCHAR(128) NULL,
            utm_medium VARCHAR(128) NULL,
            utm_campaign VARCHAR(128) NULL,
            utm_content VARCHAR(128) NULL,
            cnt BIGINT UNSIGNED NOT NULL DEFAULT 0,
            last_seen DATETIME NULL,
            KEY idx_date_domain (date, ref_domain(191)),
            KEY idx_dest_date (dest_type, dest_id, date)
        ) $charset;";

        $sql3 = "CREATE TABLE IF NOT EXISTS " . self::tbl(self::T_SOURCES) . " (
            ref_domain VARCHAR(255) PRIMARY KEY,
            first_seen DATETIME NULL,
            last_seen DATETIME NULL,
            last_title TEXT NULL,
            opt_out TINYINT(1) NOT NULL DEFAULT 0,
            score INT NULL
        ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql1);
        dbDelta($sql2);
        dbDelta($sql3);
    }

    public static function extract_domain($url) {
        $host = parse_url($url, PHP_URL_HOST);
        return $host ? strtolower($host) : null;
    }

    public static function detect_destination() {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        if (preg_match('#/company/(\d{10,13})/#', $uri, $m)) {
            return ['company', intval($m[1])];
        }
        if (function_exists('is_singular') && is_singular('post-pressrelease')) {
            return ['press', get_the_ID()];
        }
        return ['site', 0];
    }

    public static function insert_event($ref_url, $ref_title = null, $utm = []) {
        global $wpdb;

        $domain = self::extract_domain($ref_url);
        if (!$domain) return false;

        // Check opt-out
        $opt = $wpdb->get_var($wpdb->prepare("SELECT opt_out FROM " . self::tbl(self::T_SOURCES) . " WHERE ref_domain=%s", $domain));
        if ($opt === '1' || $opt === 1) return false;

        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $ip_hash = $ip ? hash('sha256', $ip) : null;
        $ua_hash = $ua ? hash('sha256', $ua) : null;

        [$dest_type, $dest_id] = self::detect_destination();

        $utm_source = $utm['source'] ?? self::qparam($ref_url, 'utm_source');
        $utm_medium = $utm['medium'] ?? self::qparam($ref_url, 'utm_medium');
        $utm_campaign = $utm['campaign'] ?? self::qparam($ref_url, 'utm_campaign');
        $utm_content = $utm['content'] ?? self::qparam($ref_url, 'utm_content');

        $ok = $wpdb->insert(self::tbl(self::T_EVENTS), [
            'ref_domain'   => $domain,
            'ref_url'      => $ref_url,
            'ref_title'    => $ref_title,
            'dest_type'    => $dest_type,
            'dest_id'      => $dest_id,
            'utm_source'   => $utm_source,
            'utm_medium'   => $utm_medium,
            'utm_campaign' => $utm_campaign,
            'utm_content'  => $utm_content,
            'ua_hash'      => $ua_hash,
            'ip_hash'      => $ip_hash,
            'created_at'   => current_time('mysql'),
        ]);

        if ($ok) {
            // upsert sources meta
            $now = current_time('mysql');
            $exists = $wpdb->get_var($wpdb->prepare("SELECT ref_domain FROM " . self::tbl(self::T_SOURCES) . " WHERE ref_domain=%s", $domain));
            if ($exists) {
                $wpdb->update(self::tbl(self::T_SOURCES), [
                    'last_seen' => $now,
                    'last_title'=> $ref_title ?: null,
                ], ['ref_domain'=>$domain]);
            } else {
                $wpdb->insert(self::tbl(self::T_SOURCES), [
                    'ref_domain' => $domain,
                    'first_seen' => $now,
                    'last_seen'  => $now,
                    'last_title' => $ref_title ?: null,
                    'opt_out'    => 0,
                    'score'      => null,
                ]);
            }
        }
        return $ok;
    }

    public static function qparam($url, $key) {
        $q = parse_url($url, PHP_URL_QUERY);
        if (!$q) return null;
        parse_str($q, $arr);
        return $arr[$key] ?? null;
    }

    public static function is_bot_ua($ua) {
        $ua = strtolower($ua ?? '');
        $bot_signs = ['bot','spider','crawl','slurp','fetch','monitor','pingdom','gtmetrix','chrome-lighthouse','facebookexternalhit','line-poker','ahrefs','semrush','mj12bot','yandex'];
        foreach ($bot_signs as $b) { if (strpos($ua, $b) !== false) return true; }
        return false;
    }

    public static function rank($args = []) {
        global $wpdb;
        $defaults = [
            'range' => '7d',
            'type'  => 'domain', // domain|page|utm|dest
            'limit' => 50,
            'dest_type' => null,
            'dest_id' => null,
        ];
        $args = wp_parse_args($args, $defaults);

        $range = $args['range'];
        $limit = intval($args['limit']);
        $limit = min(max($limit,1), 500);

        $table = self::tbl(self::T_EVENTS);
        $where = "1=1";
        $params = [];

        if ($range !== 'all') {
            $days = intval(rtrim($range,'d'));
            $since = gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS);
            $where .= " AND created_at >= %s";
            $params[] = $since;
        }
        if ($args['dest_type']) {
            $where .= " AND dest_type = %s";
            $params[] = $args['dest_type'];
        }
        if (!is_null($args['dest_id'])) {
            $where .= " AND dest_id = %d";
            $params[] = $args['dest_id'];
        }

        switch ($args['type']) {
            case 'page':
                $sql = "SELECT COALESCE(ref_title, ref_url) AS label,
                               COUNT(*) AS cnt,
                               MAX(created_at) AS last_seen,
                               ref_url AS sample_url,
                               ref_domain
                        FROM $table
                        WHERE $where
                        GROUP BY ref_url
                        ORDER BY cnt DESC
                        LIMIT %d";
                break;
            case 'utm':
                $sql = "SELECT CONCAT_WS(':', COALESCE(utm_source,''), COALESCE(utm_medium,''), COALESCE(utm_campaign,'')) AS label,
                               COUNT(*) AS cnt,
                               MAX(created_at) AS last_seen,
                               ANY_VALUE(ref_url) AS sample_url,
                               ANY_VALUE(ref_domain) AS ref_domain
                        FROM $table
                        WHERE $where
                        GROUP BY utm_source, utm_medium, utm_campaign
                        ORDER BY cnt DESC
                        LIMIT %d";
                break;
            case 'dest':
                $sql = "SELECT CONCAT(dest_type, ':', dest_id) AS label,
                               COUNT(*) AS cnt,
                               MAX(created_at) AS last_seen,
                               ANY_VALUE(ref_url) AS sample_url,
                               ANY_VALUE(ref_domain) AS ref_domain
                        FROM $table
                        WHERE $where
                        GROUP BY dest_type, dest_id
                        ORDER BY cnt DESC
                        LIMIT %d";
                break;
            case 'domain':
            default:
                $sql = "SELECT ref_domain AS label,
                               COUNT(*) AS cnt,
                               MAX(created_at) AS last_seen,
                               ANY_VALUE(ref_title) AS sample_title,
                               ANY_VALUE(ref_url) AS sample_url
                        FROM $table
                        WHERE $where
                        GROUP BY ref_domain
                        ORDER BY cnt DESC
                        LIMIT %d";
                break;
        }

        $params[] = $limit;
        $prepared = $wpdb->prepare($sql, $params);
        return $wpdb->get_results($prepared, ARRAY_A);
    }
}
