<?php
if (!defined('ABSPATH')) exit;

class CDB_RR_REST {
    public static function register_routes() {
        add_action('rest_api_init', function(){
            register_rest_route('cdb-ref/v1', '/rank', [
                'methods'  => 'GET',
                'callback' => [__CLASS__, 'rank'],
                'permission_callback' => '__return_true',
                'args' => [
                    'range'=>['default'=>'7d'],
                    'type'=>['default'=>'domain'],
                    'limit'=>['default'=>50],
                    'dest_type'=>[],
                    'dest_id'=>[],
                    'api_key'=>[],
                ],
            ]);
        });
    }

    public static function rank($req) {
        $opts = CDB_RR_Admin::opts();
        if ($opts['api_restrict']) {
            $key = $req->get_param('api_key');
            if (!$key || $key !== $opts['api_key']) {
                return new WP_Error('forbidden','API key required', ['status'=>403]);
            }
        }

        $args = [
            'range' => sanitize_text_field($req->get_param('range') ?: '7d'),
            'type'  => sanitize_text_field($req->get_param('type') ?: 'domain'),
            'limit' => intval($req->get_param('limit') ?: 50),
        ];
        if ($req->get_param('dest_type')) $args['dest_type'] = sanitize_text_field($req->get_param('dest_type'));
        if (!is_null($req->get_param('dest_id'))) $args['dest_id'] = intval($req->get_param('dest_id'));

        $rows = CDB_RR_DB::rank($args);
        return rest_ensure_response($rows);
    }
}
