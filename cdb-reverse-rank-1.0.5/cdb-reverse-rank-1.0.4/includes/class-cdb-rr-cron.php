<?php
if (!defined('ABSPATH')) exit;

class CDB_RR_Cron {
    public static function run_daily_rollup() {
        // For this minimal full build, we rely on on-the-fly aggregation from events table.
        // Here, we can add housekeeping and notifications for strong new referrers.
        self::notify_strong_referrers();
        self::housekeeping_blocklist();
    }

    private static function notify_strong_referrers() {
        $opts = CDB_RR_Admin::opts();
        $url = $opts['webhook_url'];
        if (!$url) return;
        $threshold = max(0, intval($opts['notify_threshold']));

        // Find domains first_seen within last 24h and count last 7d
        global $wpdb;
        $tE = CDB_RR_DB::tbl(CDB_RR_DB::T_EVENTS);
        $tS = CDB_RR_DB::tbl(CDB_RR_DB::T_SOURCES);

        $since = gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS);
        $new_domains = $wpdb->get_col($wpdb->prepare("SELECT ref_domain FROM $tS WHERE first_seen >= %s ORDER BY first_seen DESC LIMIT 100", $since));

        if (!$new_domains) return;

        $seven = gmdate('Y-m-d H:i:s', time() - 7*DAY_IN_SECONDS);
        foreach ($new_domains as $d) {
            $cnt = intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $tE WHERE ref_domain=%s AND created_at >= %s", $d, $seven)));
            if ($cnt >= $threshold) {
                self::send_webhook($d, $cnt, $opts);
            }
        }
    }

    private static function send_webhook($domain, $cnt, $opts) {
        $url = $opts['webhook_url'];
        $type = $opts['webhook_type'];
        $msg = sprintf("🆕 新規強力参照元を検知: *%s* (7日: %d)", $domain, $cnt);

        $args = [
            'headers' => ['Content-Type'=>'application/json'],
            'timeout' => 5,
        ];
        if ($type === 'discord') {
            $args['body'] = wp_json_encode(['content'=>$msg]);
        } else {
            // slack
            $args['body'] = wp_json_encode(['text'=>$msg]);
        }
        // Best-effort
        wp_remote_post($url, $args);
    }

    // optional: auto blocklist obvious spam from settings blocklist
    private static function housekeeping_blocklist() {
        $opts = CDB_RR_Admin::opts();
        $block = array_filter(array_map('trim', preg_split('/\R+/', $opts['blocklist'] ?? '')));
        if (!$block) return;

        global $wpdb;
        $tbl = CDB_RR_DB::tbl(CDB_RR_DB::T_SOURCES);
        foreach ($block as $d) {
            if (!$d) continue;
            $wpdb->query($wpdb->prepare("INSERT INTO $tbl (ref_domain,opt_out) VALUES (%s,1) ON DUPLICATE KEY UPDATE opt_out=1", $d));
        }
    }
}
