<?php
namespace SAT\Core;

class Logger {
    const TABLE = 'sat_translate_logs';
    private Container $container;

    public function __construct(Container $container) {
        $this->container = $container;
    }

    public static function createTable(): void {
        global $wpdb;
        $table   = $wpdb->prefix . self::TABLE;
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id           bigint(20)    NOT NULL AUTO_INCREMENT,
            object_type  varchar(20)   NOT NULL DEFAULT 'post',
            object_id    bigint(20)    NOT NULL DEFAULT 0,
            source_lang  varchar(10)   NOT NULL DEFAULT '',
            target_lang  varchar(10)   NOT NULL DEFAULT '',
            translator   varchar(50)   NOT NULL DEFAULT '',
            model        varchar(100)  NOT NULL DEFAULT '',
            tokens_input int(11)       NOT NULL DEFAULT 0,
            tokens_output int(11)      NOT NULL DEFAULT 0,
            cost_usd     decimal(10,6) NOT NULL DEFAULT 0,
            status       varchar(20)   NOT NULL DEFAULT 'success',
            error_msg    text          NULL,
            duration_ms  int(11)       NOT NULL DEFAULT 0,
            field_name   varchar(100)  NOT NULL DEFAULT '',
            created_at   datetime      NOT NULL,
            PRIMARY KEY (id),
            KEY object_idx (object_type, object_id),
            KEY status_idx (status),
            KEY created_idx (created_at)
        ) {$charset};";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    public function log(array $data): void {
        global $wpdb;
        // 'source' key'i 'field_name' olarak map et (DB column adı hâlâ field_name)
        if (isset($data['source']) && !isset($data['field_name'])) {
            $data['field_name'] = $data['source'];
            unset($data['source']);
        }
        $wpdb->insert($wpdb->prefix . self::TABLE, array_merge([
            'object_type'  => 'post',
            'object_id'    => 0,
            'source_lang'  => '',
            'target_lang'  => '',
            'translator'   => '',
            'model'        => '',
            'tokens_input' => 0,
            'tokens_output'=> 0,
            'cost_usd'     => 0,
            'status'       => 'success',
            'error_msg'    => null,
            'duration_ms'  => 0,
            'field_name'   => '',
            'created_at'   => current_time('mysql'),
        ], $data));
    }

    public function getLogs(array $args = []): array {
        global $wpdb;
        $table  = $wpdb->prefix . self::TABLE;
        $where  = '1=1';
        $params = [];

        if (!empty($args['status'])) {
            $where   .= ' AND status = %s';
            $params[] = $args['status'];
        }
        if (!empty($args['object_type'])) {
            $where   .= ' AND object_type = %s';
            $params[] = $args['object_type'];
        }
        if (!empty($args['target_lang'])) {
            $where   .= ' AND target_lang = %s';
            $params[] = $args['target_lang'];
        }
        if (!empty($args['field_name'])) {
            $where   .= ' AND field_name = %s';
            $params[] = $args['field_name'];
        }
        if (!empty($args['date_from'])) {
            $where   .= ' AND DATE(created_at) >= %s';
            $params[] = $args['date_from'];
        }
        if (!empty($args['date_to'])) {
            $where   .= ' AND DATE(created_at) <= %s';
            $params[] = $args['date_to'];
        }

        $limit  = (int) ($args['limit'] ?? 50);
        $offset = (int) ($args['offset'] ?? 0);
        $sql    = "SELECT * FROM {$table} WHERE {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d";
        $params[] = $limit;
        $params[] = $offset;

        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A) ?: [];
        // field_name → source alias ekle
        return array_map(function($row) {
            $row['source'] = $row['field_name'] ?? '';
            return $row;
        }, $rows);
    }

    public function getStats(array $args = []): array {
        global $wpdb;
        $table  = $wpdb->prefix . self::TABLE;
        $where  = '1=1';
        $params = [];

        if (!empty($args['status'])) {
            $where   .= ' AND status = %s';
            $params[] = $args['status'];
        }
        if (!empty($args['object_type'])) {
            $where   .= ' AND object_type = %s';
            $params[] = $args['object_type'];
        }
        if (!empty($args['target_lang'])) {
            $where   .= ' AND target_lang = %s';
            $params[] = $args['target_lang'];
        }
        if (!empty($args['date_from'])) {
            $where   .= ' AND DATE(created_at) >= %s';
            $params[] = $args['date_from'];
        }
        if (!empty($args['date_to'])) {
            $where   .= ' AND DATE(created_at) <= %s';
            $params[] = $args['date_to'];
        }

        $sql  = "SELECT status, COUNT(*) as cnt, SUM(cost_usd) as cost, SUM(tokens_input + tokens_output) as tokens
                 FROM {$table} WHERE {$where} GROUP BY status";
        $rows = empty($params)
            ? ($wpdb->get_results($sql, ARRAY_A) ?: [])
            : ($wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A) ?: []);

        $stats = ['total' => 0, 'success' => 0, 'error' => 0, 'total_cost' => 0.0, 'total_tokens' => 0];
        foreach ($rows as $row) {
            $stats[$row['status']] = (int) $row['cnt'];
            $stats['total']        += (int) $row['cnt'];
            $stats['total_cost']   += (float) $row['cost'];
            $stats['total_tokens'] += (int) $row['tokens'];
        }
        $stats['total_cost'] = round($stats['total_cost'], 6);
        return $stats;
    }
}
