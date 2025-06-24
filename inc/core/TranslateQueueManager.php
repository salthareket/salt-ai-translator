<?php
namespace SaltAI\Core;

use SaltAI\Core\ServiceContainer;

class TranslateQueueManager {

    private $plugin;
    private ServiceContainer $container;
    private $pending_key;
    private $completed_key;

    const POSTS_OPTION = 'salt_translate_queue_posts';
    const TERMS_OPTION = 'salt_translate_queue_terms';

    const POSTS_CRON_HOOK = 'salt_translate_posts_event';
    const TERMS_CRON_HOOK = 'salt_translate_terms_event';

    public function __construct($container) {
        $this->container = $container;
        $this->plugin = $container->get('plugin');

        $options = $this->container->get("plugin")->options;
        $this->pending_key   = $options["keys"]["pending"];
        $this->completed_key = $options["keys"]["completed"];

        add_action('wp_ajax_check_queue_status', [$this, 'check_queue_status']);

        add_action('wp_ajax_start_post_translation_queue', [$this, 'handle_ajax_start_post_queue']);
        add_action('wp_ajax_start_term_translation_queue', [$this, 'handle_ajax_start_term_queue']);

    }

    public function start_queue(string $lang, string $type) {

        $this->reset_items($lang, $type);

        $initial_total = $this->count_pending_items($type);
        $status_key = $this->get_status_key($type);
        update_option($status_key, [
            'lang'           => $lang,
            'started_at'     => time(),
            'status'         => 'processing',
            'completed_at'   => null,
            'initial_total'  => $initial_total,
        ]);

        if ($type === 'post') {
            update_option(self::POSTS_OPTION, ['lang' => $lang]);
            wp_clear_scheduled_hook('salt_translate_posts_event');
            wp_schedule_single_event(time() + 5, 'salt_translate_posts_event');
        } elseif ($type === 'term') {
            update_option(self::TERMS_OPTION, ['lang' => $lang]);
            wp_clear_scheduled_hook(self::TERMS_CRON_HOOK);
            wp_schedule_single_event(time() + 5, self::TERMS_CRON_HOOK);
        }

        $data = get_option($status_key);
        $data["started_at"] = get_date_from_gmt( date( 'Y-m-d H:i:s', $data["started_at"]), 'd.m.Y H:i:s' );

        return $data;
    }

    /*public function reset_items(string $lang, string $type): void {
        global $wpdb;
        if ($type === 'post') {

            $ids = $this->container->get("integration")->get_untranslated_posts($lang);
            $ids = isset($ids['posts']) ? array_column($ids['posts'], 'ID') : [];
            $ids = array_filter(array_map('absint', $ids));
            if (empty($ids)) return;
            $placeholders = implode(',', array_fill(0, count($ids), '%d'));
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$wpdb->postmeta} WHERE (meta_key = '".$this->completed_key."' or meta_key = '".$this->pending_key."') AND post_id IN ($placeholders)",
                ...$ids
            ));

            $rows = [];
            foreach ($ids as $id) {
                $rows[] = $wpdb->prepare('(%d, %s, %s)', $id, $this->pending_key, '1');
            }
            $wpdb->query("INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value) VALUES " . implode(',', $rows));

        } elseif ($type === 'term') {

            $ids = $this->container->get("integration")->get_untranslated_terms($lang);
            $ids = isset($ids['terms']) ? array_column($ids['terms'], 'term_id') : [];
            $ids = array_filter(array_map('absint', $ids));
            if (empty($ids)) return;
            $placeholders = implode(',', array_fill(0, count($ids), '%d'));
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$wpdb->termmeta} WHERE meta_key = '".$this->completed_key."' AND term_id IN ($placeholders)",
                ...$ids
            ));

            $rows = [];
            foreach ($ids as $id) {
                $rows[] = $wpdb->prepare('(%d, %s, %s)', $id, $this->pending_key, '1');
            }
            $wpdb->query("INSERT INTO {$wpdb->termmeta} (term_id, meta_key, meta_value) VALUES " . implode(',', $rows));

        }
    }*/

    public function reset_items(string $lang, string $type): void {
        global $wpdb;

        if (!in_array($type, ['post', 'term'], true)) return;

        $is_post = $type === 'post';

        $get_ids_method = $is_post ? 'get_untranslated_posts' : 'get_untranslated_terms';
        $meta_table     = $is_post ? $wpdb->postmeta : $wpdb->termmeta;
        $id_column      = $is_post ? 'post_id' : 'term_id';
        $raw_ids        = $this->container->get("integration")->$get_ids_method($lang);

        $key            = $is_post ? 'posts' : 'terms';
        $id_key         = $is_post ? 'ID' : 'term_id';

        $ids = isset($raw_ids[$key]) ? array_column($raw_ids[$key], $id_key) : [];
        $ids = array_filter(array_map('absint', $ids));
        if (empty($ids)) return;

        $placeholders = implode(',', array_fill(0, count($ids), '%d'));

        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$meta_table} WHERE (meta_key = %s OR meta_key = %s) AND {$id_column} IN ($placeholders)",
            $this->completed_key,
            $this->pending_key,
            ...$ids
        ));

        $rows = [];
        foreach ($ids as $id) {
            $rows[] = $wpdb->prepare("(%d, %s, %s)", $id, $this->pending_key, '1');
        }

        $wpdb->query("INSERT INTO {$meta_table} ({$id_column}, meta_key, meta_value) VALUES " . implode(',', $rows));
    }


    public function handle_post_queue(): void {
        $this->process_queue('post');
        if ($this->count_pending_items('post') > 0) {
            error_log("🚀 Kuyruk devam ediyor, 30 sn sonra tekrar schedule ediliyor...");
            wp_schedule_single_event(time() + 30, 'salt_translate_posts_event');
        } else {
            error_log("✅ Kuyruk tamamlandı.");
            $this->mark_queue_complete('post');
        }
    }
    public function handle_term_queue(): void {
        $this->process_queue('term');
    }


    /**
     * WP-Cron callback to process post or term queue
     */
    /*public function process_queue(string $type): void {
        $pending = 0;
        error_log("---------------------------process_queue(".$type.") cron name:".self::POSTS_CRON_HOOK);
        if ($type === 'post') {
            $args = [
                'post_type'      => 'any',
                'post_status'    => 'publish',
                'posts_per_page' => 1,
                'fields'         => 'ids',
                'meta_query'     => [[
                    'key'     => $this->pending_key,
                    'value'   => "1",
                    'compare' => '='
                ]],
            ];

            if($this->container->get('plugin')->ml_plugin["key"] == "polylang"){
                $integration = $this->container->get('integration');
                $args["lang"] = $integration->default_language;
            }
            $query = new \WP_Query($args);
 
            $pending = $query->found_posts;

             error_log("pending: ".$pending." post_id: ".$post_id." lang: ".$lang);

            if (empty($query->posts)) {
                $this->mark_queue_complete('post');
                return;
            }

            $post_id = $query->posts[0];
            $lang    = get_option(self::POSTS_OPTION)['lang'] ?? 'en';
           

            $integration = $this->container->get('integration');
            $integration->translate_post($post_id, $lang);

            delete_post_meta($post_id, $this->pending_key);
            update_post_meta($post_id, $this->completed_key, 1);

            wp_schedule_single_event(time() + 5, self::POSTS_CRON_HOOK);

        } elseif ($type === 'term') {

            global $wpdb;

            $term_id = $wpdb->get_var($wpdb->prepare(
                "SELECT term_id FROM {$wpdb->termmeta} WHERE meta_key = %s LIMIT 1",
                $this->pending_key
            ));

            $pending = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->termmeta} WHERE meta_key = %s",
                $this->pending_key
            ));

            if (!$term_id) {
                $this->mark_queue_complete('term');
                return;
            }

            $lang = get_option(self::TERMS_OPTION)['lang'] ?? 'en';

            $integration = $this->container->get('integration');
            $taxonomy = get_term($term_id)->taxonomy ?? '';
            $integration->translate_term((int) $term_id, $taxonomy, $lang);

            delete_term_meta((int) $term_id, $this->pending_key);
            update_term_meta((int) $term_id, $this->completed_key, 1);

            wp_schedule_single_event(time() + 5, self::TERMS_CRON_HOOK);

        }
    }*/

    public function process_queue(string $type): void {
        error_log("---------------------------process_queue(" . $type . ")");
        if ($type === 'post') {
            $this->process_post_queue();
        } elseif ($type === 'term') {
            $this->process_term_queue();
        }
    }
    private function process_post_queue(): void {
        $integration = $this->container->get('integration');
        $plugin      = $this->container->get('plugin');
        $lang        = get_option(self::POSTS_OPTION)['lang'] ?? 'en';

        $args = [
            'post_type'      => 'any',
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'meta_query'     => [[
                'key'     => $this->pending_key,
                'value'   => "1",
                'compare' => '='
            ]],
        ];

        if ($plugin->ml_plugin["key"] === "polylang") {
            $args["lang"] = $integration->default_language;
        }

        $query = new \WP_Query($args);
        if (empty($query->posts)) {
            $this->mark_queue_complete('post');
            return;
        }

        $post_id = $query->posts[0];
        $plugin->log("Post queued: $post_id lang: $lang");

        try {
            $integration->translate_post($post_id, $lang);
            delete_post_meta($post_id, $this->pending_key);
            update_post_meta($post_id, $this->completed_key, 1);
            wp_schedule_single_event(time() + 5, self::POSTS_CRON_HOOK);
        } catch (\Throwable $e) {
            error_log("❌ Post #$post_id çevirisi sırasında hata: " . $e->getMessage());
            // hata durumunda işaretlemeyip sonraki cronla tekrar denenmesini sağlayabiliriz.
        }
    }
    private function process_term_queue(): void {
        global $wpdb;
        $integration = $this->container->get('integration');
        $lang        = get_option(self::TERMS_OPTION)['lang'] ?? 'en';

        $term_id = $wpdb->get_var($wpdb->prepare(
            "SELECT term_id FROM {$wpdb->termmeta} WHERE meta_key = %s LIMIT 1",
            $this->pending_key
        ));

        if (!$term_id) {
            $this->mark_queue_complete('term');
            return;
        }

        $taxonomy = get_term($term_id)->taxonomy ?? '';

        try {
            $integration->translate_term((int) $term_id, $taxonomy, $lang);
            delete_term_meta((int) $term_id, $this->pending_key);
            update_term_meta((int) $term_id, $this->completed_key, 1);
            wp_schedule_single_event(time() + 5, self::TERMS_CRON_HOOK);
        } catch (\Throwable $e) {
            error_log("❌ Term #$term_id çevirisi sırasında hata: " . $e->getMessage());
        }
    }




    /**
     * Get the current status of the queue
     */
    public function get_queue_status(string $type): array {
        $status_key = $this->get_status_key($type);
        $status = get_option($status_key, []);

        $total = $this->count_pending_items($type);

         // Eğer hâlâ processing ise ama cron yoksa tekrar başlat
        if (($status['status'] ?? '') === 'processing') {
            ///$hook = $type === 'post' ? 'salt_translate_posts_event' : self::TERMS_CRON_HOOK;
            $hook = $type === 'post' ? self::POSTS_CRON_HOOK : self::TERMS_CRON_HOOK;

            if (!wp_next_scheduled($hook)) {
                wp_schedule_single_event(time() + 5, $hook);
            }
        }

        return array_merge($status, [
            'queued'    => $total,
            'completed' => max(0, ($status['initial_total'] ?? 0) - $total),
        ]);
    }

    /**
     * Check if queue is still processing
     */
    public function check_process_queue(string $type): string {
        $status = $this->get_queue_status($type);
        return $status['status'] ?? 'idle';
    }

    /**
     * Mark a queue as complete
     */
    private function mark_queue_complete(string $type): void {
        $status_key = $this->get_status_key($type);
        $status = get_option($status_key, []);
        $status['status'] = 'done';
        $status['completed_at'] = time();
        update_option($status_key, $status);
    }

    /**
     * Get queue status key
     */
    private function get_status_key(string $type): string {
        return $type === 'post' ? 'salt_translate_status_posts' : 'salt_translate_status_terms';
    }

    /**
     * Count items currently in the queue
     */
    private function count_pending_items(string $type): int {
        if ($type === 'post') {

            $args = [
                'post_type'      => 'any',
                'post_status'    => 'publish',
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'meta_query'     => [[
                    'key'     => $this->pending_key,
                    'value'   => "1",
                    'compare' => '='
                ]],
            ];

            if($this->container->get("plugin")->ml_plugin["key"] == "polylang"){
                $integration = $this->container->get('integration');
                $args["lang"] = $integration->default_language;
            }
            $query = new \WP_Query($args);
            return $query->found_posts;

        } elseif ($type === 'term') {

            global $wpdb;
            return (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->termmeta} WHERE meta_key = %s",
                $this->pending_key
            ));

        }
        return 0;
    }

    public function check_queue_status() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Yetersiz yetki.');
        }

        $type = sanitize_text_field($_POST['type'] ?? '');
        if (empty($type)) {
            wp_send_json_error('Eksik parametre');
        }

        $data = $this->get_queue_status($type);

        $started_at_timestamp = $data["started_at"] ?? null;
        if (is_numeric($started_at_timestamp)) {
            $data["started_at"] = get_date_from_gmt(
                date('Y-m-d H:i:s', $started_at_timestamp),
                'd.m.Y H:i:s'
            );
        } else {
            $data["started_at"] = "-";
        }

        $completed_at_timestamp = $data["completed_at"] ?? null;
        if (is_numeric($completed_at_timestamp)) {
            $data["completed_at"] = get_date_from_gmt(
                date('Y-m-d H:i:s', $completed_at_timestamp),
                'd.m.Y H:i:s'
            );

            $duration = $completed_at_timestamp - $started_at_timestamp;
            $hours    = floor($duration / 3600);
            $minutes  = floor(($duration % 3600) / 60);
            $seconds  = $duration % 60;

            $duration_parts = [];
            if ($hours > 0) {
                $duration_parts[] = sprintf(
                    _n('%d hour', '%d hours', $hours, 'salt-ai-translator'),
                    $hours
                );
            }
            if ($minutes > 0) {
                $duration_parts[] = sprintf(
                    _n('%d minute', '%d minutes', $minutes, 'salt-ai-translator'),
                    $minutes
                );
            }
            if ($seconds > 0 || empty($duration_parts)) {
                $duration_parts[] = sprintf(
                    _n('%d second', '%d seconds', $seconds, 'salt-ai-translator'),
                    $seconds
                );
            }

            $data["processing_time"] = implode(' ', $duration_parts);
        } else {
            $data["completed_at"] = "-";
        }

        wp_send_json_success($data);
    }


    private function handle_ajax_start_queue(string $type): void {
        check_ajax_referer('salt_ai_translator_nonce', '_ajax_nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error("Yetkisiz işlem");
        }

        $lang = sanitize_text_field($_POST['lang'] ?? '');
        if (!$lang) {
            wp_send_json_error("Dil belirtilmedi");
        }

        $data = $this->start_queue($lang, $type);

        wp_send_json_success($data);
    }
    public function handle_ajax_start_post_queue(): void {
        $this->handle_ajax_start_queue('post');
    }
    public function handle_ajax_start_term_queue(): void {
        $this->handle_ajax_start_queue('term');
    }

}
