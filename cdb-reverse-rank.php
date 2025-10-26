<?php
/**
 * Plugin Name: CDB Reverse Access Rank
 * Description: 全国企業データベース向け 逆アクセスランキング（参照元ドメイン/URL/タイトル・流入数・最新日）を収集・表示・API提供
 * Version: 0.1.0
 * Author: CDB
 */

if (!defined('ABSPATH')) exit;

class CDB_Reverse_Access_Rank {
    const VERSION = '0.1.0';
    const TABLE_EVENTS = 'cdb_ref_events';
    const TRANSIENT_KEY = 'cdb_ref_rank_cache';

    public function __construct() {
        register_activation_hook(__FILE__, [$this, 'activate']);
        add_action('init', [$this, 'maybe_log_referrer']);
        add_action('rest_api_init', [$this, 'register_rest']);
        add_shortcode('cdb_referral_rank', [$this, 'shortcode_rank']);
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('wp_footer', [$this, 'enqueue_beacon'], 100);
        add_action('wp_ajax_cdb_ref_beacon', [$this, 'ajax_beacon']);
        add_action('wp_ajax_nopriv_cdb_ref_beacon', [$this, 'ajax_beacon']);
    }

    public function activate() {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_EVENTS;
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE IF NOT EXISTS $table (
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
            KEY idx_created (created_at)
        ) $charset;";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    // ---- Logging (server-side) ----
    public function maybe_log_referrer() {
        if (is_admin()) return;
        if (wp_doing_ajax()) return; // JSビーコンは別で受ける
        // 簡易ボット除外
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? strtolower($_SERVER['HTTP_USER_AGENT']) : '';
        $bot_signs = ['bot','spider','crawl','slurp','fetch','monitor','pingdom','gtmetrix'];
        foreach ($bot_signs as $b) { if (strpos($ua, $b) !== false) return; }

        // リファラが外部の時のみ
        $ref = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '';
        if (!$ref) return;
        $site = home_url('/');
        if (strpos($ref, $site) === 0) return; // 自サイト内参照は除外

        $this->insert_event($ref, null, null);
        // 表示キャッシュ破棄（軽めに）
        delete_transient(self::TRANSIENT_KEY);
    }

    // ---- Logging helper ----
    private function insert_event($ref_url, $ref_title = null, $utm = []) {
        global $wpdb;
        $domain = $this->extract_domain($ref_url);
        if (!$domain) return;

        $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
        $ip_hash = $ip ? hash('sha256', $ip) : null;
        $ua_hash = $ua ? hash('sha256', $ua) : null;

        // 受け側（会社ページなど）を特定したいときはここで判定
        [$dest_type, $dest_id] = $this->detect_destination();

        $utm_source = $utm['source'] ?? $this->get_qparam($ref_url, 'utm_source');
        $utm_medium = $utm['medium'] ?? $this->get_qparam($ref_url, 'utm_medium');
        $utm_campaign = $utm['campaign'] ?? $this->get_qparam($ref_url, 'utm_campaign');
        $utm_content = $utm['content'] ?? $this->get_qparam($ref_url, 'utm_content');

        $wpdb->insert($wpdb->prefix . self::TABLE_EVENTS, [
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
    }

    private function extract_domain($url) {
        $host = parse_url($url, PHP_URL_HOST);
        return $host ? strtolower($host) : null;
    }

    private function get_qparam($url, $key) {
        $q = parse_url($url, PHP_URL_QUERY);
        if (!$q) return null;
        parse_str($q, $arr);
        return $arr[$key] ?? null;
    }

    private function detect_destination() {
        // 例：会社ページ /company/{corp_num}/ なら dest_type=company, dest_id=corp_num
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        if (preg_match('#/company/(\d{10,13})/#', $uri, $m)) {
            return ['company', intval($m[1])];
        }
        // プレスリリース CPT 例
        if (is_singular('post-pressrelease')) {
            return ['press', get_the_ID()];
        }
        return ['site', 0];
    }

    // ---- REST ----
    public function register_rest() {
        register_rest_route('cdb-ref/v1', '/rank', [
            'methods' => 'GET',
            'callback' => [$this, 'api_rank'],
            'permission_callback' => '__return_true',
        ]);
    }

    public function api_rank($req) {
        $range = $req->get_param('range') ?: '7d'; // 1d/7d/30d/all
        $type  = $req->get_param('type') ?: 'domain'; // domain|page
        $limit = min(intval($req->get_param('limit') ?: 50), 500);
        $rows = $this->get_rank($range, $type, $limit);
        return rest_ensure_response($rows);
    }

    // ---- Rank Query ----
    private function get_rank($range, $type, $limit) {
        $cache_key = self::TRANSIENT_KEY . md5("$range|$type|$limit");
        $cached = get_transient($cache_key);
        if ($cached !== false) return $cached;

        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_EVENTS;
        $where = "1=1";
        if ($range !== 'all') {
            $days = intval(rtrim($range, 'd'));
            $since = gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS);
            $where .= $wpdb->prepare(" AND created_at >= %s", $since);
        }
        if ($type === 'domain') {
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
        } else {
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
        }
        $rows = $wpdb->get_results($wpdb->prepare($sql, $limit), ARRAY_A);
        set_transient($cache_key, $rows, 5 * MINUTE_IN_SECONDS);
        return $rows;
    }

    // ---- Shortcode ----
    public function shortcode_rank($atts) {
        $atts = shortcode_atts([
            'range' => '7d',
            'type'  => 'domain',
            'limit' => 50,
            'csv'   => 'false',
        ], $atts, 'cdb_referral_rank');

        // CSV出力 ?csv=1
        if ((isset($_GET['csv']) && $_GET['csv']) || $atts['csv'] === 'true') {
            $this->output_csv($atts['range'], $atts['type'], intval($atts['limit']));
            return '';
        }

        $rows = $this->get_rank($atts['range'], $atts['type'], intval($atts['limit']));
        ob_start(); ?>
        <div class="cdb-ref-rank">
            <form method="get" class="cdb-ref-rank__filters" style="margin-bottom:8px;">
                <label>期間:
                    <select name="range">
                        <?php foreach (['1d'=>'1日','7d'=>'7日','30d'=>'30日','90d'=>'90日','all'=>'全期間'] as $k=>$v): ?>
                            <option value="<?php echo esc_attr($k); ?>" <?php selected($atts['range'],$k); ?>><?php echo esc_html($v); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>種類:
                    <select name="type">
                        <option value="domain" <?php selected($atts['type'],'domain'); ?>>ドメイン</option>
                        <option value="page" <?php selected($atts['type'],'page'); ?>>ページ</option>
                    </select>
                </label>
                <button type="submit">更新</button>
                <a href="<?php echo esc_url(add_query_arg(['csv'=>1])); ?>">CSVダウンロード</a>
            </form>
            <table class="cdb-ref-table" style="width:100%;border-collapse:collapse;">
                <thead><tr>
                    <th style="text-align:left;border-bottom:1px solid #ddd;">参照元</th>
                    <th style="text-align:right;border-bottom:1px solid #ddd;">流入数</th>
                    <th style="text-align:left;border-bottom:1px solid #ddd;">最新流入日</th>
                </tr></thead>
                <tbody>
                <?php if (!$rows): ?>
                    <tr><td colspan="3">データがまだありません。</td></tr>
                <?php else: foreach ($rows as $r): ?>
                    <tr>
                        <td style="padding:6px 4px;">
                            <?php
                            $label = $r['label'];
                            $url = $r['sample_url'] ?? null;
                            if ($url) {
                                echo '<a href="' . esc_url($url) . '" target="_blank" rel="nofollow noopener">' . esc_html($label) . '</a>';
                            } else {
                                echo esc_html($label);
                            }
                            ?>
                        </td>
                        <td style="text-align:right;"><?php echo number_format_i18n($r['cnt']); ?></td>
                        <td><?php echo esc_html(get_date_from_gmt(gmdate('Y-m-d H:i:s', strtotime($r['last_seen'])), 'Y-m-d H:i')); ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?php
        return ob_get_clean();
    }

    private function output_csv($range, $type, $limit) {
        $rows = $this->get_rank($range, $type, $limit);
        nocache_headers();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="cdb_reverse_rank_'.$range.'_'.$type.'.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['label','count','last_seen','url','domain']);
        foreach ($rows as $r) {
            fputcsv($out, [
                $r['label'] ?? '',
                $r['cnt'] ?? 0,
                $r['last_seen'] ?? '',
                $r['sample_url'] ?? '',
                $r['ref_domain'] ?? ($r['label'] ?? ''),
            ]);
        }
        fclose($out);
        exit;
    }

    // ---- Admin ----
    public function admin_menu() {
        add_menu_page('逆アクセスランキング', '逆アクセス', 'manage_options', 'cdb-rev-rank', [$this, 'admin_page'], 'dashicons-randomize', 58);
    }

    public function admin_page() {
        $range = $_GET['range'] ?? '7d';
        $type  = $_GET['type'] ?? 'domain';
        $limit = intval($_GET['limit'] ?? 100);
        $rows = $this->get_rank($range, $type, $limit);
        echo '<div class="wrap"><h1>逆アクセスランキング</h1>';
        echo '<form method="get"><input type="hidden" name="page" value="cdb-rev-rank">';
        echo '期間 <select name="range">';
        foreach (['1d','7d','30d','90d','all'] as $k) {
            printf('<option value="%s"%s>%s</option>', esc_attr($k), selected($range,$k,false), esc_html($k));
        }
        echo '</select> 種類 <select name="type">';
        foreach (['domain','page'] as $k) {
            printf('<option value="%s"%s>%s</option>', esc_attr($k), selected($type,$k,false), esc_html($k));
        }
        echo '</select> <input type="number" name="limit" value="'.esc_attr($limit).'" min="10" max="500">';
        submit_button('更新', 'secondary', '', false);
        echo ' <a class="button" href="'.esc_url(add_query_arg(['csv'=>1])).'">CSV</a>';
        echo '</form>';

        echo '<table class="widefat fixed striped"><thead><tr><th>参照元</th><th style="text-align:right;">流入数</th><th>最新流入日</th></tr></thead><tbody>';
        if (!$rows) {
            echo '<tr><td colspan="3">データなし</td></tr>';
        } else {
            foreach ($rows as $r) {
                $label = esc_html($r['label']);
                $cnt = number_format_i18n($r['cnt']);
                $last = esc_html(get_date_from_gmt(gmdate('Y-m-d H:i:s', strtotime($r['last_seen'])), 'Y-m-d H:i'));
                $url = $r['sample_url'] ?? '';
                echo '<tr><td>';
                if ($url) echo '<a href="'.esc_url($url).'" target="_blank" rel="nofollow noopener">'.$label.'</a>';
                else echo $label;
                echo '</td><td style="text-align:right;">'.$cnt.'</td><td>'.$last.'</td></tr>';
            }
        }
        echo '</tbody></table></div>';
    }

    // ---- JS Beacon (active mode) ----
    public function enqueue_beacon() {
        if (is_admin()) return;
        ?>
<script>
(function(){
    try{
        if (!document.referrer) return;
        // 内部リファラ除外
        var home = <?php echo json_encode(home_url('/')); ?>;
        if (document.referrer.indexOf(home) === 0) return;

        var fd = new FormData();
        fd.append('action','cdb_ref_beacon');
        fd.append('ref_url', document.referrer);
        fd.append('ref_title', document.title || '');
        // 受け側タイプ/IDはサーバ側で判定
        if (navigator.sendBeacon) {
            navigator.sendBeacon(<?php echo json_encode(admin_url('admin-ajax.php')); ?>, fd);
        } else {
            fetch(<?php echo json_encode(admin_url('admin-ajax.php')); ?>, {method:'POST', body: fd, credentials:'same-origin'});
        }
    }catch(e){}
})();
</script>
        <?php
    }

    public function ajax_beacon() {
        // nonce不要：同一オリジンのページでのみ呼ばれる。さらに最低限のバリデーションを実施
        $ref = isset($_POST['ref_url']) ? esc_url_raw($_POST['ref_url']) : '';
        if (!$ref) wp_die();
        $title = isset($_POST['ref_title']) ? sanitize_text_field($_POST['ref_title']) : null;
        $this->insert_event($ref, $title, []);
        delete_transient(self::TRANSIENT_KEY);
        wp_die('OK');
    }
}

new CDB_Reverse_Access_Rank();
