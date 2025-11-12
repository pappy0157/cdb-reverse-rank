<?php
if (!defined('ABSPATH')) exit;

class CDB_RR_Public {
    const TRANSIENT_PREFIX = 'cdb_rr_rank_';

    /**
     * フロント用アセット登録（必要時にenqueue）
     */
    public static function register_assets() {
        // 参照元タイトル取得のためのビーコン
        wp_register_script(
            'cdb-rr-credit',
            CDB_RR_URL . 'assets/js/credit.js',
            [],
            CDB_RR_VER,
            true
        );
        wp_localize_script('cdb-rr-credit','CDBRR_CREDIT',[ 'credit_url' => CDB_RR_CREDIT_URL ]);

        wp_register_script(
            'cdb-rr-beacon',
            CDB_RR_URL . 'assets/js/beacon.js',
            [],
            CDB_RR_VER,
            true
        );
        // 公開UI用のモダンCSS
        wp_register_style(
            'cdb-rr-public',
            CDB_RR_URL . 'assets/css/public.css',
            [],
            CDB_RR_VER
        );
    }

    /**
     * 参照元ログ取得（サーバ／クライアント）
     */
    public static function hook_logging() {
        // サーバ側：HTTP_REFERER から受動記録
        add_action('template_redirect', function () {
            if (is_admin() || wp_doing_ajax()) return;

            $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
            if (CDB_RR_DB::is_bot_ua($ua)) return;

            $ref = $_SERVER['HTTP_REFERER'] ?? '';
            if (!$ref) return;

            $home = home_url('/');
            if (strpos($ref, $home) === 0) return; // 自サイト遷移は除外

            CDB_RR_DB::insert_event($ref, null, []);
        });

        // クライアント側：document.title をビーコンで送信
        add_action('wp_footer', function () {
            if (is_admin()) return;
            wp_enqueue_script('cdb-rr-beacon');
            wp_localize_script('cdb-rr-beacon', 'CDBRR', [
                'ajax'  => admin_url('admin-ajax.php'),
                'home'  => home_url('/'),
                'nonce' => wp_create_nonce('cdbrr_beacon'),
            ]);
        }, 100);

        // ビーコン受け口
        add_action('wp_ajax_cdb_ref_beacon', [__CLASS__, 'ajax_beacon']);
        add_action('wp_ajax_nopriv_cdb_ref_beacon', [__CLASS__, 'ajax_beacon']);
    }

    /**
     * ビーコン Ajax
     */
    public static function ajax_beacon() {
        check_ajax_referer('cdbrr_beacon', 'nonce');
        $ref = isset($_POST['ref_url']) ? esc_url_raw($_POST['ref_url']) : '';
        if (!$ref) wp_send_json_error('no-ref');

        $title = isset($_POST['ref_title']) ? sanitize_text_field($_POST['ref_title']) : null;
        CDB_RR_DB::insert_event($ref, $title, []);
        wp_send_json_success('ok');
    }

    /**
     * ショートコード登録
     */
    public static function register_shortcodes() {
        add_shortcode('cdb_referral_rank',  [__CLASS__, 'sc_rank']);
        add_shortcode('cdb_referrer_badge', [__CLASS__, 'sc_badge']);
    }

    /**
     * 公開用ランキング（CSVボタンなし / GETフィルタ反映 / Top5強調 / パートナーバッジ）
     */
    public static function sc_rank($atts) {
                // クレジットを必ず出力（フッター未実装テーマ対策）
        if (function_exists('__return_true')) { /* noop to ensure WP loaded */ }
        if (method_exists(__CLASS__, 'output_credit_badge')) { call_user_func([__CLASS__,'output_credit_badge']); }
        wp_enqueue_script('cdb-rr-credit');
// 既定値
        $atts = shortcode_atts([
            'range'     => '30d',
            'type'      => 'domain',   // domain|page|utm|dest
            'limit'     => 50,
            'dest_type' => '',
            'dest_id'   => '',
        ], $atts, 'cdb_referral_rank');

        // ★ 公開UIフォーム（GET）の値で上書き
        if (isset($_GET['range']))     { $atts['range']     = sanitize_text_field(wp_unslash($_GET['range'])); }
        if (isset($_GET['type']))      { $atts['type']      = sanitize_text_field(wp_unslash($_GET['type'])); }
        if (isset($_GET['limit']))     { $atts['limit']     = intval($_GET['limit']); }
        if (isset($_GET['dest_type'])) { $atts['dest_type'] = sanitize_text_field(wp_unslash($_GET['dest_type'])); }
        if (isset($_GET['dest_id']))   { $atts['dest_id']   = intval($_GET['dest_id']); }

        // 変数整形
        $range     = $atts['range'];
        $type      = $atts['type'];
        $limit     = intval($atts['limit']);
        $dest_type = ($atts['dest_type'] !== '') ? $atts['dest_type'] : null;
        $dest_id   = ($atts['dest_id']   !== '') ? intval($atts['dest_id']) : null;

        // データ取得（5分キャッシュ）
        $cache_key = self::TRANSIENT_PREFIX . md5(json_encode([$range, $type, $limit, $dest_type, $dest_id]));
        $rows = get_transient($cache_key);
        if ($rows === false) {
            $rows = CDB_RR_DB::rank(compact('range', 'type', 'limit', 'dest_type', 'dest_id'));
            set_transient($cache_key, $rows, 300);
        }

        // 公開CSS
        wp_enqueue_style('cdb-rr-public');

        // パートナー表示条件（アロウリスト or しきい値）
        $opts          = CDB_RR_Admin::opts();
        $allow         = array_filter(array_map('trim', preg_split('/\R+/', $opts['allowlist'] ?? '')));
        $allow_map     = array_flip($allow);
        $partner_min   = max(0, intval($opts['notify_threshold'] ?? 100)); // 7日合計の目安として利用

        ob_start(); ?>
        <div class="cdb-rr-requires-credit" data-requires-credit="1">
        <div class="cdb-ref-card">
          <div class="cdb-ref-header">
            <div class="cdb-ref-title"><img decoding="async" class="imgemoji alignnone wp-image-132980" src="https://companydata.tsujigawa.com/wp-content/uploads/2025/08/cdb-888px.svg" alt="CDB rainbow" width="23" height="23"></div>
            <div class="cdb-ref-controls">
              <form id="cdb-ref-form" method="get" style="display:flex; gap:8px; align-items:center;">
                <label>期間
                  <select name="range">
                    <?php foreach (['1d'=>'1日','7d'=>'7日','30d'=>'30日','90d'=>'90日','all'=>'全期間'] as $k=>$v): ?>
                      <option value="<?php echo esc_attr($k); ?>" <?php selected($range, $k); ?>><?php echo esc_html($v); ?></option>
                    <?php endforeach; ?>
                  </select>
                </label>
                <label>種類
                  <select name="type">
                    <?php foreach (['domain'=>'ドメイン','page'=>'ページ','utm'=>'UTM','dest'=>'入口'] as $k=>$v): ?>
                      <option value="<?php echo esc_attr($k); ?>" <?php selected($type, $k); ?>><?php echo esc_html($v); ?></option>
                    <?php endforeach; ?>
                  </select>
                </label>
                <label>件数
                  <input type="number" name="limit" value="<?php echo esc_attr($limit); ?>" min="10" max="500" style="width:80px;">
                </label>
                <?php if (!is_null($dest_type)): ?><input type="hidden" name="dest_type" value="<?php echo esc_attr($dest_type); ?>"><?php endif; ?>
                <?php if (!is_null($dest_id)):   ?><input type="hidden" name="dest_id"   value="<?php echo esc_attr($dest_id);   ?>"><?php endif; ?>
                <button type="submit">更新</button>
              </form>
            </div>
          </div>

          <table class="cdb-ref-table">
            <thead>
              <tr>
                <th>順位</th>
                <th>参照元</th>
                <th class="cdb-count">流入数</th>
                <th>最新流入日</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$rows): ?>
                <tr><td colspan="4">データがまだありません。</td></tr>
              <?php else: $i = 0; foreach ($rows as $r): $i++;
                    $row_class = ($i <= 5) ? ' cdb-top-' . $i : '';
                    $label     = $r['label']      ?? '';
                    $url       = $r['sample_url'] ?? '';
                    $domain    = $r['ref_domain'] ?? ($r['label'] ?? '');
                    $cnt       = intval($r['cnt']  ?? 0);
                    // ドメインがアロウリストにある、または（type=domain のとき）流入がしきい値以上ならパートナー表示
                    $is_partner = isset($allow_map[$domain]) || ($type === 'domain' && $cnt >= $partner_min);
              ?>
                <tr class="<?php echo esc_attr($row_class); ?>">
                  <td data-col="rank" data-label="順位">
  <span class="cdb-rank-badge"><?php echo esc_html($i); ?></span>
</td>

<td data-col="label" data-label="参照元" class="cdb-label">
  <?php if ($url): ?>
    <a href="<?php echo esc_url($url); ?>" target="_blank" rel="nofollow noopener"><?php echo esc_html($label); ?></a>
  <?php else: ?>
    <?php echo esc_html($label); ?>
  <?php endif; ?>
  <?php if ($is_partner): ?><span class="cdb-partner-chip">✔ パートナー</span><?php endif; ?>
</td>

<td data-col="count" data-label="流入数" class="cdb-count">
  <?php echo number_format_i18n($cnt); ?>
</td>

<td data-col="lastseen" data-label="最新流入日" class="cdb-lastseen">
  <?php
    $ls = $r['last_seen'] ?? '';
    if ($ls) echo esc_html( date_i18n('Y-m-d H:i', strtotime($ls)) );
  ?>
</td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
        <?php
        $html = ob_get_clean();
        $html .= '<noscript><div class="cdb-rr-locked">このプラグインを利用するには、クレジット表記（全国企業データベース）を表示する必要があります。</div></noscript>';
        $html .= '</div>'; // close cdb-rr-requires-credit
        return $html;
    }

    /**
     * 参照元サイトからの来訪時だけ出す「Top Referrer」バッジ
     * 例: [cdb_referrer_badge min="50" range="30d"]
     */
    public static function sc_badge($atts) {
        $atts = shortcode_atts([
            'min'   => 50,
            'range' => '30d',
        ], $atts, 'cdb_referrer_badge');

        $ref = $_SERVER['HTTP_REFERER'] ?? '';
        if (!$ref) return '';
        $domain = CDB_RR_DB::extract_domain($ref);
        if (!$domain) return '';

        $rows = CDB_RR_DB::rank(['range' => $atts['range'], 'type' => 'domain', 'limit' => 500]);
        $qualified = false;
        foreach ($rows as $r) {
            if (($r['label'] ?? '') === $domain && intval($r['cnt']) >= intval($atts['min'])) {
                $qualified = true;
                break;
            }
        }
        if (!$qualified) return '';

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="180" height="48" viewBox="0 0 180 48" role="img" aria-label="Top Referrer"><rect width="180" height="48" rx="8" fill="#111"/><text x="54" y="30" fill="#fff" font-size="14" font-family="system-ui, sans-serif">Top Referrer</text><circle cx="24" cy="24" r="14" fill="#00C853"/><path d="M18 24l4 4 8-8" stroke="#fff" stroke-width="3" fill="none" stroke-linecap="round" stroke-linejoin="round"/></svg>';
        return '<div class="cdb-ref-badge" style="display:inline-block">' . $svg . '</div>';
    }

    /**
     * ブロック（Gutenberg）登録：レンダリングはショートコードと同一
     */
    
/**
 * 必須クレジットの出力（フッター）
 */
public static function output_credit_badge(){
    static $done = false;
    if ($done) return;
    $done = true;
    echo '<div id="cdb-rr-credit" class="cdb-rr-credit" aria-live="polite" role="note" data-required="1">'
        . '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20l9-5-9-5-9 5 9 5z"/><path d="M12 12l9-5-9-5-9 5 9 5z"/></svg>'
        . 'Powered by <a href="' . esc_url(CDB_RR_CREDIT_URL) . '" target="_blank" rel="noopener sponsored">全国企業データベース</a>'
        . '</div>';
    wp_enqueue_style('cdb-rr-public');
    wp_enqueue_script('cdb-rr-credit');
}

public static function register_block() {

        register_block_type(
            CDB_RR_DIR . 'blocks/reverse-rank',
            [
                'render_callback' => function ($atts) {
                    $atts = shortcode_atts(['range' => '7d', 'type' => 'domain', 'limit' => 50], $atts);
                    return self::sc_rank($atts);
                }
            ]
        );
    }
}
