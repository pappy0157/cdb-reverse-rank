<?php
/**
 * Plugin Name: CDB Reverse Access Rank (Full)
 * Description: 全国企業データベース向けの逆アクセスランキング。参照元のドメイン/URL/タイトル・流入数・最新日、期間別ランキング、企業ページ別ランキング、REST API、CSV、ブロック/ショートコード、スパム除外、日次ロールアップ、Slack/Discord通知、オプトアウト、パートナーバッジ。
 * Version: 1.0.6
 * Author: CDB
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) exit;

define('CDB_RR_VER', '1.0.6');
define('CDB_RR_SLUG', 'cdb-reverse-rank');
define('CDB_RR_DIR', plugin_dir_path(__FILE__));
define('CDB_RR_URL', plugin_dir_url(__FILE__));
define('CDB_RR_CREDIT_URL', 'https://companydata.tsujigawa.com/');

require_once CDB_RR_DIR . 'includes/class-cdb-rr-db.php';
require_once CDB_RR_DIR . 'includes/class-cdb-rr-public.php';
require_once CDB_RR_DIR . 'includes/class-cdb-rr-admin.php';
require_once CDB_RR_DIR . 'includes/class-cdb-rr-rest.php';
require_once CDB_RR_DIR . 'includes/class-cdb-rr-cron.php';

class CDB_Reverse_Rank_Full {
    public function __construct() {
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);

        add_action('init', [$this, 'init']);
        add_action('plugins_loaded', [$this, 'plugins_loaded']);
        // Action links on Plugins page
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), function($links){
            $links[] = '<a href="' . admin_url('admin.php?page=cdb-rr-settings') . '">設定</a>';
            $links[] = '<a href="' . admin_url('admin.php?page=cdb-rr') . '">ダッシュボード</a>';
            return $links;
        });

    }

    public function activate() {
        CDB_RR_DB::create_tables();
        // Schedule daily rollup at 03:10 server time
        if (!wp_next_scheduled('cdb_rr_daily_rollup')) {
            wp_schedule_event(time() + 300, 'daily', 'cdb_rr_daily_rollup');
        }
    }

    public function deactivate() {
        wp_clear_scheduled_hook('cdb_rr_daily_rollup');
    }

    public function init() {
        // public features
        CDB_RR_Public::register_shortcodes();
        CDB_RR_Public::register_assets();
        CDB_RR_Public::hook_logging();
        CDB_RR_Public::register_block();

        
        // Admin
        if (is_admin()) {
            add_action('admin_menu', ['CDB_RR_Admin','register_menu']);
            CDB_RR_Admin::register_settings();
        }

    }

    public function plugins_loaded() {
        // REST
        CDB_RR_REST::register_routes();
        // CRON
        add_action('cdb_rr_daily_rollup', ['CDB_RR_Cron','run_daily_rollup']);
    }
}
new CDB_Reverse_Rank_Full();
