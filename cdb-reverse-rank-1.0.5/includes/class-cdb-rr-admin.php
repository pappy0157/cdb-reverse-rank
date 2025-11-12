<?php
if (!defined('ABSPATH')) exit;
add_action('admin_init', function(){ if (class_exists('CDB_RR_Admin')) { CDB_RR_Admin::pretty_admin_redirect(); } });


class CDB_RR_Admin {
    public static function pretty_admin_redirect() {
        if (!is_admin()) return;
        $req = $_SERVER['REQUEST_URI'] ?? '';
        if (!$req) return;
        // Normalize
        $path = parse_url($req, PHP_URL_PATH);
        if (!$path) return;

        // Map pretty paths to admin.php?page=...
        $map = [
            '/wp-admin/cdb-rr' => 'cdb-rr',
            '/wp-admin/cdb-rr-sources' => 'cdb-rr-sources',
            '/wp-admin/cdb-rr-settings' => 'cdb-rr-settings',
        ];
        foreach ($map as $pretty => $page) {
            if (rtrim($path,'/') === $pretty) {
                wp_safe_redirect(admin_url('admin.php?page=' . $page));
                exit;
            }
        }
    }

    const OPTS = 'cdb_rr_options';
    // options: blocklist (newline domains), allowlist (optional), webhook_url, webhook_type(slack|discord), api_restrict (0/1), api_key

    public static function register_menu() {
        add_menu_page('逆アクセスランキング', '逆アクセス', 'manage_options', 'cdb-rr', [__CLASS__,'page_overview'], 'dashicons-randomize', 58);
        add_submenu_page('cdb-rr','参照元一覧','参照元一覧','manage_options','cdb-rr-sources',[__CLASS__,'page_sources']);
        add_submenu_page('cdb-rr','設定','設定','manage_options','cdb-rr-settings',[__CLASS__,'page_settings']);
    }

    public static function register_settings() {
        register_setting(self::OPTS, self::OPTS, ['sanitize_callback'=>[__CLASS__,'sanitize']]);
    }

    public static function opts() {
        $opts = get_option(self::OPTS, []);
        $defaults = [
            'blocklist' => "",
            'allowlist' => "",
            'webhook_url' => "",
            'webhook_type' => "slack",
            'notify_threshold' => 100,
            'api_restrict' => 0,
            'api_key' => wp_generate_password(24, false, false),
        ];
        return wp_parse_args($opts, $defaults);
    }

    public static function sanitize($input) {
        $out = self::opts();
        $out['blocklist'] = sanitize_textarea_field($input['blocklist'] ?? '');
        $out['allowlist'] = sanitize_textarea_field($input['allowlist'] ?? '');
        $out['webhook_url'] = esc_url_raw($input['webhook_url'] ?? '');
        $out['webhook_type'] = in_array($input['webhook_type'] ?? 'slack', ['slack','discord']) ? $input['webhook_type'] : 'slack';
        $out['notify_threshold'] = max(0, intval($input['notify_threshold'] ?? 100));
        $out['api_restrict'] = isset($input['api_restrict']) ? 1 : 0;
        $out['api_key'] = sanitize_text_field($input['api_key'] ?? $out['api_key']);
        return $out;
    }

    public static function page_overview() {
        if (!current_user_can('manage_options')) return;
        $range = $_GET['range'] ?? '7d';
        $type  = $_GET['type'] ?? 'domain';
        $limit = intval($_GET['limit'] ?? 100);
        $dest_type = $_GET['dest_type'] ?? '';
        $dest_id = $_GET['dest_id'] ?? '';

        $rows = CDB_RR_DB::rank([
            'range'=>$range,
            'type'=>$type,
            'limit'=>$limit,
            'dest_type'=>$dest_type ?: null,
            'dest_id'=>$dest_id !== '' ? intval($dest_id) : null,
        ]);

        echo '<div class="wrap"><h1>逆アクセスランキング</h1>';
        echo '<form method="get"><input type="hidden" name="page" value="cdb-rr">';
        echo '期間 <select name="range">';
        foreach (['1d','7d','30d','90d','all'] as $k) {
            printf('<option value="%s"%s>%s</option>', esc_attr($k), selected($range,$k,false), esc_html($k));
        }
        echo '</select> 種類 <select name="type">';
        foreach (['domain'=>'domain','page'=>'page','utm'=>'utm','dest'=>'dest'] as $k=>$v) {
            printf('<option value="%s"%s>%s</option>', esc_attr($k), selected($type,$k,false), esc_html($v));
        }
        echo '</select> 入口タイプ <input type="text" name="dest_type" value="'.esc_attr($dest_type).'" placeholder="site/company/press" style="width:160px;">';
        echo ' 入口ID <input type="number" name="dest_id" value="'.esc_attr($dest_id).'" style="width:120px;">';
        echo ' <input type="number" name="limit" value="'.esc_attr($limit).'" min="10" max="500" style="width:90px">';
        submit_button('更新', 'secondary', '', false);
        echo ' <a class="button" href="'.esc_url(add_query_arg(['csv'=>1,'range'=>$range,'type'=>$type])).'">CSV</a>';
        echo '</form>';

        // CSV direct
        if (isset($_GET['csv'])) {
            self::output_csv($rows, $range, $type);
            exit;
        }

        echo '<table class="widefat fixed striped"><thead><tr><th>参照元</th><th style="text-align:right;">流入数</th><th>最新流入日</th></tr></thead><tbody>';
        if (!$rows) {
            echo '<tr><td colspan="3">データなし</td></tr>';
        } else {
            foreach ($rows as $r) {
                $label = esc_html($r['label'] ?? '');
                $cnt = number_format_i18n(intval($r['cnt'] ?? 0));
                $last = esc_html(get_date_from_gmt(gmdate('Y-m-d H:i:s', strtotime($r['last_seen'] ?? '')), 'Y-m-d H:i'));
                $url = $r['sample_url'] ?? '';
                echo '<tr><td>';
                if ($url) echo '<a href="'.esc_url($url).'" target="_blank" rel="nofollow noopener">'.$label.'</a>';
                else echo $label;
                echo '</td><td style="text-align:right;">'.$cnt.'</td><td>'.$last.'</td></tr>';
            }
        }
        echo '</tbody></table></div>';
    }

    private static function output_csv($rows, $range, $type) {
        nocache_headers();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="cdb_reverse_rank_admin_'.$range.'_'.$type.'.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['label','count','last_seen','url','domain']);
        foreach ($rows as $r) {
            fputcsv($out, [
                $r['label'] ?? '',
                $r['cnt'] ?? 0,
                $r['last_seen'] ?? '',
                $r['sample_url'] ?? '',
                $r['ref_domain'] ?? '',
            ]);
        }
        fclose($out);
    }

    public static function page_sources() {
        if (!current_user_can('manage_options')) return;
        global $wpdb;
        $tbl = CDB_RR_DB::tbl(CDB_RR_DB::T_SOURCES);

        // Update opt-out
        if (isset($_POST['domain'])) {
            check_admin_referer('cdb_rr_sources');
            $domain = sanitize_text_field($_POST['domain']);
            $opt = isset($_POST['opt_out']) ? 1 : 0;
            $wpdb->query($wpdb->prepare("INSERT INTO $tbl (ref_domain,opt_out) VALUES (%s,%d) ON DUPLICATE KEY UPDATE opt_out=VALUES(opt_out)", $domain, $opt));
            echo '<div class="updated"><p>更新しました</p></div>';
        }

        $q = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
        $where = '1=1';
        $params = [];
        if ($q) { $where .= " AND ref_domain LIKE %s"; $params[] = "%$q%"; }

        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM $tbl WHERE $where ORDER BY last_seen DESC LIMIT 500", $params), ARRAY_A);

        echo '<div class="wrap"><h1>参照元一覧</h1>';
        echo '<form method="get"><input type="hidden" name="page" value="cdb-rr-sources">';
        echo '<input type="search" name="s" value="'.esc_attr($q).'" placeholder="example.com"> ';
        submit_button('検索','secondary','',false);
        echo '</form>';

        echo '<h2>オプトアウト切替</h2>';
        echo '<form method="post">';
        wp_nonce_field('cdb_rr_sources');
        echo 'ドメイン: <input type="text" name="domain" placeholder="example.com" required> ';
        echo '<label><input type="checkbox" name="opt_out" value="1"> ランキングから除外（オプトアウト）</label> ';
        submit_button('保存','primary','',false);
        echo '</form>';

        echo '<table class="widefat fixed striped"><thead><tr><th>ドメイン</th><th>初回検知</th><th>最終検知</th><th>タイトル</th><th>除外</th></tr></thead><tbody>';
        if (!$rows) {
            echo '<tr><td colspan="5">データなし</td></tr>';
        } else {
            foreach ($rows as $r) {
                echo '<tr>';
                echo '<td>'.esc_html($r['ref_domain']).'</td>';
                echo '<td>'.esc_html($r['first_seen']).'</td>';
                echo '<td>'.esc_html($r['last_seen']).'</td>';
                echo '<td>'.esc_html(wp_trim_words($r['last_title'] ?? '', 10)).'</td>';
                echo '<td>'.(intval($r['opt_out']) ? '除外中' : '表示').'</td>';
                echo '</tr>';
            }
        }
        echo '</tbody></table></div>';
    }

    public static function page_settings() {
        if (!current_user_can('manage_options')) return;
        $o = self::opts();
        echo '<div class="wrap"><h1>設定</h1><form method="post" action="options.php">';
        settings_fields(self::OPTS);
        echo '<table class="form-table">';
        echo '<tr><th scope="row">ブロックリスト</th><td><textarea name="'.self::OPTS.'[blocklist]" rows="6" cols="60" placeholder="spam.com&#10;adult.example">'.esc_textarea($o['blocklist']).'</textarea><p class="description">1行1ドメイン。除外対象。</p></td></tr>';
        echo '<tr><th scope="row">アロウリスト</th><td><textarea name="'.self::OPTS.'[allowlist]" rows="4" cols="60" placeholder="partner.example">'.esc_textarea($o['allowlist']).'</textarea><p class="description">空欄の場合は全許可。</p></td></tr>';
        echo '<tr><th scope="row">通知Webhook</th><td><input type="url" name="'.self::OPTS.'[webhook_url]" value="'.esc_attr($o['webhook_url']).'" size="60"> ';
        echo '<select name="'.self::OPTS.'[webhook_type]"><option value="slack"'.selected($o['webhook_type'],'slack',false).'>Slack</option><option value="discord"'.selected($o['webhook_type'],'discord',false).'>Discord</option></select> ';
        echo '<p class="description">新規強力参照元検知時に通知します。</p></td></tr>';
        echo '<tr><th scope="row">通知しきい値</th><td><input type="number" name="'.self::OPTS.'[notify_threshold]" value="'.esc_attr($o['notify_threshold']).'" min="0" step="1"></td></tr>';
        echo '<tr><th scope="row">REST API制限</th><td><label><input type="checkbox" name="'.self::OPTS.'[api_restrict]" '.checked($o['api_restrict'],1,false).'> APIキー必須にする</label><br>APIキー: <input type="text" name="'.self::OPTS.'[api_key]" value="'.esc_attr($o['api_key']).'" size="32"></td></tr>';
        echo '</table>';
        submit_button('保存');
        echo '</form></div>';
    }
}
