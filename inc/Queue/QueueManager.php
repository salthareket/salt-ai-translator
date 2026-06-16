<?php
namespace SAT\Queue;

use SAT\Core\Container;

/**
 * QueueManager
 *
 * WP Cron veya Action Scheduler ile arka plan çeviri kuyruğu yönetir.
 * Action Scheduler mevcutsa (WooCommerce veya standalone) otomatik kullanılır.
 * Yoksa WP Cron'a fallback yapar.
 *
 * @version 1.2.0
 * @changelog
 *   1.2.0 - 2026-06-09
 *     - Add: getNextRunTime() — AS veya WP Cron'dan bir sonraki çalışma zamanını döndürür
 *     - Add: getStatus() response'a next_run (Unix timestamp) eklendi
 *   1.1.0 - 2026-06-09
 *     - Add: Action Scheduler entegrasyonu — mevcutsa otomatik kullanılır
 *     - Add: useActionScheduler() — AS aktif mi kontrol
 *     - Add: scheduleNext() — AS veya WP Cron seçer
 *     - Add: cancelScheduled() — AS veya WP Cron iptal eder
 *     - Fix: processBatch() — AS ile her item ayrı action olarak schedule edilir (daha güvenilir)
 *   1.0.0 - 2026-05-XX
 *     - Add: Initial release — WP Cron tabanlı batch processing
 */
class QueueManager {

    const TABLE        = 'sat_queue';
    const CRON_POSTS   = 'sat_queue_posts';
    const CRON_TERMS   = 'sat_queue_terms';
    const CRON_STRINGS = 'sat_queue_strings';
    const AS_GROUP     = 'salt-ai-translator';
    const AS_HOOK_POST   = 'sat_as_process_post';
    const AS_HOOK_TERM   = 'sat_as_process_term';
    const AS_HOOK_STRING = 'sat_as_process_string';
    const MAX_RETRIES  = 3;
    const BATCH_SIZE   = 5;

    private Container $container;

    public function __construct(Container $container) {
        $this->container = $container;
        add_action('wp_ajax_sat_queue_status',  [$this, 'ajaxStatus']);
        add_action('wp_ajax_sat_queue_start',   [$this, 'ajaxStart']);
        add_action('wp_ajax_sat_queue_cancel',  [$this, 'ajaxCancel']);
        add_action('wp_ajax_sat_queue_retry',   [$this, 'ajaxRetry']);

        // Action Scheduler hook'larını kaydet
        if ($this->useActionScheduler()) {
            add_action(self::AS_HOOK_POST,   [self::class, 'asProcessPost'],   10, 2);
            add_action(self::AS_HOOK_TERM,   [self::class, 'asProcessTerm'],   10, 3);
            add_action(self::AS_HOOK_STRING, [self::class, 'asProcessString'], 10, 2);
        }
    }

    /**
     * Action Scheduler mevcut mu?
     * WooCommerce, WC Subscription veya standalone AS yüklüyse true döner.
     */
    public static function useActionScheduler(): bool {
        return function_exists('as_enqueue_async_action') || function_exists('as_schedule_single_action');
    }

    public static function createTable(): void {
        global $wpdb;
        $table   = $wpdb->prefix . self::TABLE;
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id           bigint(20)  NOT NULL AUTO_INCREMENT,
            object_type  varchar(20) NOT NULL DEFAULT 'post',
            object_id    bigint(20)  NOT NULL,
            target_lang  varchar(10) NOT NULL,
            status       varchar(20) NOT NULL DEFAULT 'pending',
            retries      tinyint(3)  NOT NULL DEFAULT 0,
            error_msg    text        NULL,
            created_at   datetime    NOT NULL,
            updated_at   datetime    NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY unique_item (object_type, object_id, target_lang),
            KEY status_idx (status),
            KEY type_idx (object_type, status)
        ) {$charset};";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    public static function addItem(string $type, int $objectId, string $lang): void {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$table} (object_type, object_id, target_lang, status, created_at, updated_at)
             VALUES (%s, %d, %s, 'pending', %s, %s)
             ON DUPLICATE KEY UPDATE status = IF(status='done', 'pending', status), updated_at = %s",
            $type, $objectId, $lang,
            current_time('mysql'), current_time('mysql'), current_time('mysql')
        ));
    }

    /**
     * String item'larını queue'ya ekle.
     * Her string için field_name = "group::name::original_string" formatında saklanır.
     * object_id = string'in Polylang listesindeki hash (benzersizlik için).
     */
    public static function addStringBatch(array $strings, array $langs): int {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $count = 0;
        $now   = current_time('mysql');

        foreach ($strings as $s) {
            $name   = $s['name'] ?? '';
            $string = (string)($s['string'] ?? '');
            $group  = $s['group'] ?? '';

            // Benzersiz ID = group+name+string hash
            $hash      = abs(crc32($group . '::' . $name . '::' . $string));
            $fieldName = substr($group . '::' . $name, 0, 100); // field_name kolonu

            foreach ($langs as $lang) {
                // String data'yı error_msg yerine field_name'e koy (group::name format)
                // Orijinal string'i ayrı bir yerde saklamamız gerekiyor
                // Çözüm: object_id = hash, field_name = group::name, error_msg = JSON(string data)
                $wpdb->query($wpdb->prepare(
                    "INSERT INTO {$table}
                     (object_type, object_id, target_lang, status, field_name, error_msg, created_at, updated_at)
                     VALUES ('string', %d, %s, 'pending', %s, %s, %s, %s)
                     ON DUPLICATE KEY UPDATE
                       status = IF(status='done', 'pending', status),
                       error_msg = VALUES(error_msg),
                       updated_at = %s",
                    $hash, $lang,
                    $fieldName,
                    wp_json_encode(['name' => $name, 'string' => $string, 'group' => $group]),
                    $now, $now, $now
                ));
                $count++;
            }
        }
        return $count;
    }

    public static function addBatch(string $type, array $objectIds, array $langs): int {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $count = 0;
        $now   = current_time('mysql');

        foreach ($objectIds as $id) {
            foreach ($langs as $lang) {
                $wpdb->query($wpdb->prepare(
                    "INSERT INTO {$table} (object_type, object_id, target_lang, status, created_at, updated_at)
                     VALUES (%s, %d, %s, 'pending', %s, %s)
                     ON DUPLICATE KEY UPDATE status = IF(status='done', 'pending', status), updated_at = %s",
                    $type, (int)$id, $lang, $now, $now, $now
                ));
                $count++;
            }
        }
        return $count;
    }

    public function getStatus(string $type = ''): array {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $where = $type ? $wpdb->prepare("WHERE object_type = %s", $type) : '';

        $rows = $wpdb->get_results(
            "SELECT status, COUNT(*) as cnt FROM {$table} {$where} GROUP BY status",
            ARRAY_A
        );

        $stats = ['pending' => 0, 'processing' => 0, 'done' => 0, 'error' => 0, 'total' => 0];
        foreach ($rows as $row) {
            $stats[$row['status']] = (int) $row['cnt'];
            $stats['total'] += (int) $row['cnt'];
        }
        $stats['percent'] = $stats['total'] > 0
            ? round(($stats['done'] / $stats['total']) * 100)
            : 0;
        $stats['driver'] = self::useActionScheduler() ? 'action_scheduler' : 'wp_cron';

        // Şu an işlenen item bilgisi
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $typeWhere2 = $type ? $wpdb->prepare("AND object_type = %s", $type) : '';
        $currentItem = $wpdb->get_row(
            "SELECT object_type, object_id, target_lang FROM {$table}
             WHERE status = 'processing' {$typeWhere2}
             ORDER BY updated_at DESC LIMIT 1",
            ARRAY_A
        );
        if ($currentItem) {
            $stats['current_item'] = $currentItem;
            // Object başlığını da ekle
            if ($currentItem['object_type'] === 'post') {
                $post = get_post((int)$currentItem['object_id']);
                $stats['current_item']['title'] = $post ? $post->post_title : '#' . $currentItem['object_id'];
            } elseif ($currentItem['object_type'] === 'term') {
                $term = get_term((int)$currentItem['object_id']);
                $stats['current_item']['title'] = (!is_wp_error($term) && $term) ? $term->name : '#' . $currentItem['object_id'];
            } elseif ($currentItem['object_type'] === 'string') {
                $stats['current_item']['title'] = 'String #' . $currentItem['object_id'];
            } else {
                $stats['current_item']['title'] = ucfirst($currentItem['object_type']) . ' #' . $currentItem['object_id'];
            }
        }

        // Bir sonraki çalışma zamanını hesapla
        $stats['next_run'] = $this->getNextRunTime($type);

        // Aktif queue meta bilgisi (hangi diller/types çevrilecek)
        $meta = get_option('sat_queue_meta_' . ($type ?: 'all'), []);
        if (!empty($meta)) {
            $stats['langs']      = $meta['langs'] ?? [];
            $stats['post_types'] = $meta['post_types'] ?? [];
            $stats['started_at'] = $meta['started_at'] ?? '';
        }

        return $stats;
    }

    /**
     * Bir sonraki queue çalışma zamanını Unix timestamp olarak döndür.
     * pending item yoksa null döner.
     */
    private function getNextRunTime(string $type = ''): ?int {
        // pending item yoksa next_run gösterme
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $typeWhere = $type ? $wpdb->prepare("AND object_type = %s", $type) : '';
        $hasPending = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status IN ('pending','processing') {$typeWhere}");
        if (!$hasPending) return null;

        $hooks = $type
            ? [$type === 'post' ? self::CRON_POSTS : ($type === 'term' ? self::CRON_TERMS : self::CRON_STRINGS)]
            : [self::CRON_POSTS, self::CRON_TERMS, self::CRON_STRINGS];

        $earliest = null;

        if (self::useActionScheduler()) {
            foreach ($hooks as $hook) {
                $next = as_next_scheduled_action($hook, [], self::AS_GROUP);
                if ($next && ($earliest === null || $next < $earliest)) {
                    $earliest = $next;
                }
            }
        } else {
            foreach ($hooks as $hook) {
                $next = wp_next_scheduled($hook);
                if ($next && ($earliest === null || $next < $earliest)) {
                    $earliest = $next;
                }
            }
        }

        return $earliest ?: null;
    }

    // ── Scheduler Helpers ─────────────────────────────────────────────────────

    /**
     * Sonraki batch'i schedule et.
     * Action Scheduler mevcutsa AS kullanır, yoksa WP Cron.
     */
    private static function scheduleNext(string $type, int $delaySeconds = 3): void {
        $hook = match($type) {
            'post'   => self::CRON_POSTS,
            'term'   => self::CRON_TERMS,
            'string' => self::CRON_STRINGS,
            default  => self::CRON_POSTS,
        };

        if (self::useActionScheduler()) {
            if (!as_next_scheduled_action($hook, [], self::AS_GROUP)) {
                as_schedule_single_action(time() + $delaySeconds, $hook, [], self::AS_GROUP);
            }
        } else {
            if (!wp_next_scheduled($hook)) {
                wp_schedule_single_event(time() + $delaySeconds, $hook);
            }
        }
    }

    private static function cancelScheduled(string $type = ''): void {
        if (self::useActionScheduler()) {
            if ($type === '' || $type === 'post')   as_unschedule_all_actions(self::CRON_POSTS,   [], self::AS_GROUP);
            if ($type === '' || $type === 'term')   as_unschedule_all_actions(self::CRON_TERMS,   [], self::AS_GROUP);
            if ($type === '' || $type === 'string') as_unschedule_all_actions(self::CRON_STRINGS, [], self::AS_GROUP);
        } else {
            if ($type === '' || $type === 'post')   wp_clear_scheduled_hook(self::CRON_POSTS);
            if ($type === '' || $type === 'term')   wp_clear_scheduled_hook(self::CRON_TERMS);
            if ($type === '' || $type === 'string') wp_clear_scheduled_hook(self::CRON_STRINGS);
        }
    }

    // ── Action Scheduler: Her item ayrı action ───────────────────────────────

    /**
     * Action Scheduler ile tek post'u çevir.
     * Her item ayrı AS action'ı olarak schedule edilir — daha güvenilir ve izlenebilir.
     */
    public static function asProcessPost(int $queueItemId, int $retries): void {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $item  = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $queueItemId), ARRAY_A);
        if (!$item) return;

        $wpdb->update($table, ['status' => 'processing', 'updated_at' => current_time('mysql')], ['id' => $queueItemId]);

        $plugin = sat_plugin();
        $integration = $plugin->getContainer()->get('integration');
        if (!$integration) return;

        try {
            $integration->translatePost((int)$item['object_id'], $item['target_lang']);
            $wpdb->update($table, ['status' => 'done', 'updated_at' => current_time('mysql')], ['id' => $queueItemId]);
        } catch (\Throwable $e) {
            $newRetries = (int)$item['retries'] + 1;
            $status     = $newRetries >= self::MAX_RETRIES ? 'error' : 'pending';
            $wpdb->update($table, [
                'status'     => $status,
                'retries'    => $newRetries,
                'error_msg'  => $e->getMessage(),
                'updated_at' => current_time('mysql'),
            ], ['id' => $queueItemId]);
            // AS'e hata fırlat — log'a yazılsın
            if ($status === 'pending') {
                throw $e; // AS retry mekanizması devreye girer
            }
        }
    }

    /**
     * Action Scheduler ile tek term'i çevir.
     */
    public static function asProcessTerm(int $queueItemId, int $retries, string $taxonomy): void {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $item  = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $queueItemId), ARRAY_A);
        if (!$item) return;

        $wpdb->update($table, ['status' => 'processing', 'updated_at' => current_time('mysql')], ['id' => $queueItemId]);

        $plugin = sat_plugin();
        $integration = $plugin->getContainer()->get('integration');
        if (!$integration) return;

        try {
            $integration->translateTerm((int)$item['object_id'], $taxonomy, $item['target_lang']);
            $wpdb->update($table, ['status' => 'done', 'updated_at' => current_time('mysql')], ['id' => $queueItemId]);
        } catch (\Throwable $e) {
            $newRetries = (int)$item['retries'] + 1;
            $status     = $newRetries >= self::MAX_RETRIES ? 'error' : 'pending';
            $wpdb->update($table, [
                'status'     => $status,
                'retries'    => $newRetries,
                'error_msg'  => $e->getMessage(),
                'updated_at' => current_time('mysql'),
            ], ['id' => $queueItemId]);
            if ($status === 'pending') throw $e;
        }
    }

    /**
     * Action Scheduler ile tek string'i çevir.
     * object_id: pll_strings tablosundaki string ID
     * target_lang: hedef dil kodu
     */
    public static function asProcessString(int $queueItemId, int $retries): void {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $item  = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $queueItemId), ARRAY_A);
        if (!$item) return;

        $wpdb->update($table, ['status' => 'processing', 'updated_at' => current_time('mysql')], ['id' => $queueItemId]);

        $plugin    = sat_plugin();
        $container = $plugin->getContainer();

        try {
            // String data error_msg'dan JSON olarak oku
            // Format: {"name":"...","string":"...","group":"..."} veya {"_err":"...","_data":{...}}
            $raw        = $item['error_msg'] ?? '';
            $parsedRaw  = json_decode($raw, true);

            // Eğer daha önce hata olmuşsa, data _data key'inde saklanmış olabilir
            $stringData = isset($parsedRaw['_data']) ? $parsedRaw['_data'] : $parsedRaw;

            if (!is_array($stringData) || empty($stringData['string'])) {
                throw new \RuntimeException('Invalid string data in queue item #' . $queueItemId);
            }

            $translator = $container->get('translator');
            if (!$translator) throw new \RuntimeException('No translator configured');

            $lang   = $item['target_lang'];
            $name   = $stringData['name'] ?? '';
            $string = (string)($stringData['string'] ?? '');
            $group  = $stringData['group'] ?? '';

            $translator->setContext([
                'object_type' => 'string',
                'object_id'   => (int)$item['object_id'],
                'source_lang' => '',
                'target_lang' => $lang,
                'field_name'  => ($group ?: 'string') . ':' . $name,
            ]);

            $translated = $translator->translate($string, $lang);

            if (empty($translated)) {
                throw new \RuntimeException('Translation returned empty for: ' . $string);
            }

            // PLL_MO ile Polylang'a kaydet
            if (class_exists('\PLL_MO')) {
                $mo = new \PLL_MO();
                $mo->import_from_db($lang);
                $mo->add_entry($mo->make_entry($string, $translated));
                $mo->export_to_db($lang);
            }

            // Done — error_msg temizle
            $wpdb->update($table, [
                'status'     => 'done',
                'error_msg'  => null,
                'updated_at' => current_time('mysql'),
            ], ['id' => $queueItemId]);

        } catch (\Throwable $e) {
            $newRetries = (int)$item['retries'] + 1;
            $status     = $newRetries >= self::MAX_RETRIES ? 'error' : 'pending';

            // JSON data'yı koru, hata mesajını _err key'ine ekle
            $originalRaw  = $item['error_msg'] ?? '';
            $parsedForErr = json_decode($originalRaw, true);
            $dataToKeep   = isset($parsedForErr['_data']) ? $parsedForErr['_data'] : ($parsedForErr ?? []);
            $newErrJson   = wp_json_encode([
                '_err'  => substr($e->getMessage(), 0, 200),
                '_data' => $dataToKeep,
            ]);

            $wpdb->update($table, [
                'status'     => $status,
                'retries'    => $newRetries,
                'error_msg'  => $newErrJson,
                'updated_at' => current_time('mysql'),
            ], ['id' => $queueItemId]);

            if ($status === 'pending') throw $e;
        }
    }

    // ── WP Cron batch processor (fallback) ───────────────────────────────────

    public static function processPostQueue(): void {
        $plugin = sat_plugin();
        $self   = new static($plugin->getContainer());
        $self->processBatch('post');
    }

    public static function processTermQueue(): void {
        $plugin = sat_plugin();
        $self   = new static($plugin->getContainer());
        $self->processBatch('term');
    }

    public static function processStringQueue(): void {
        $plugin = sat_plugin();
        $self   = new static($plugin->getContainer());
        $self->processBatch('string');
    }
    private function processBatch(string $type): void {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;

        // Stale processing recovery — 10 dakikadan uzun süredir 'processing' olan item'ları pending'e döndür
        $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET status = 'pending', updated_at = %s
             WHERE object_type = %s AND status = 'processing'
             AND updated_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE)",
            current_time('mysql'), $type
        ));

        if (self::useActionScheduler()) {
            // AS modunda: pending item'ları tek tek AS action'ı olarak schedule et
            $items = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$table}
                 WHERE object_type = %s AND status = 'pending' AND retries < %d
                 ORDER BY id ASC LIMIT %d",
                $type, self::MAX_RETRIES, 50 // AS modunda daha büyük batch alınabilir
            ), ARRAY_A);

            foreach ($items as $item) {
                if ($type === 'post') {
                    if (!as_next_scheduled_action(self::AS_HOOK_POST, ['queueItemId' => (int)$item['id'], 'retries' => (int)$item['retries']], self::AS_GROUP)) {
                        as_enqueue_async_action(self::AS_HOOK_POST, ['queueItemId' => (int)$item['id'], 'retries' => (int)$item['retries']], self::AS_GROUP);
                    }
                } elseif ($type === 'term') {
                    $term = get_term((int)$item['object_id']);
                    $taxonomy = $term->taxonomy ?? '';
                    if (!as_next_scheduled_action(self::AS_HOOK_TERM, ['queueItemId' => (int)$item['id'], 'retries' => (int)$item['retries'], 'taxonomy' => $taxonomy], self::AS_GROUP)) {
                        as_enqueue_async_action(self::AS_HOOK_TERM, ['queueItemId' => (int)$item['id'], 'retries' => (int)$item['retries'], 'taxonomy' => $taxonomy], self::AS_GROUP);
                    }
                } else { // string
                    if (!as_next_scheduled_action(self::AS_HOOK_STRING, ['queueItemId' => (int)$item['id'], 'retries' => (int)$item['retries']], self::AS_GROUP)) {
                        as_enqueue_async_action(self::AS_HOOK_STRING, ['queueItemId' => (int)$item['id'], 'retries' => (int)$item['retries']], self::AS_GROUP);
                    }
                }
            }
            return;
        }

        // WP Cron fallback: batch process
        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE object_type = %s AND status = 'pending' AND retries < %d
             ORDER BY id ASC LIMIT %d",
            $type, self::MAX_RETRIES, self::BATCH_SIZE
        ), ARRAY_A);

        if (empty($items)) return;

        $integration = $this->container->get('integration');
        if (!$integration) return;

        foreach ($items as $item) {
            $wpdb->update($table, ['status' => 'processing', 'updated_at' => current_time('mysql')], ['id' => $item['id']]);

            try {
                if ($type === 'post') {
                    $integration->translatePost((int)$item['object_id'], $item['target_lang']);
                } elseif ($type === 'term') {
                    $term = get_term((int)$item['object_id']);
                    $integration->translateTerm((int)$item['object_id'], $term->taxonomy ?? '', $item['target_lang']);
                } elseif ($type === 'string') {
                    // String data JSON'dan oku
                    $stringData = json_decode($item['error_msg'] ?? '', true);
                    if (is_array($stringData) && !empty($stringData['string'])) {
                        $translator = $this->container->get('translator');
                        if ($translator) {
                            $translated = $translator->translate((string)$stringData['string'], $item['target_lang']);
                            if (!empty($translated) && class_exists('\PLL_MO')) {
                                $mo = new \PLL_MO();
                                $mo->import_from_db($item['target_lang']);
                                $mo->add_entry($mo->make_entry($stringData['string'], $translated));
                                $mo->export_to_db($item['target_lang']);
                            }
                        }
                    }
                }
                $wpdb->update($table, ['status' => 'done', 'updated_at' => current_time('mysql')], ['id' => $item['id']]);
            } catch (\Throwable $e) {
                $retries = (int)$item['retries'] + 1;
                $status  = $retries >= self::MAX_RETRIES ? 'error' : 'pending';
                $wpdb->update($table, [
                    'status'     => $status,
                    'retries'    => $retries,
                    'error_msg'  => $type === 'string' ? $item['error_msg'] : $e->getMessage(), // string'de data'yı koru
                    'updated_at' => current_time('mysql'),
                ], ['id' => $item['id']]);
            }
        }

        // Hâlâ pending varsa yeniden schedule et
        $remaining = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE object_type = %s AND status = 'pending'", $type
        ));
        if ($remaining > 0) {
            self::scheduleNext($type, 10);
        }
    }

    // ── AJAX Handlers ─────────────────────────────────────────────────────────

    public function ajaxStatus(): void {
        check_ajax_referer('sat_nonce', 'nonce');
        $type   = sanitize_key($_POST['type'] ?? '');

        // Stale processing recovery — 5 dakikadan uzun processing item'ları pending'e döndür
        global $wpdb;
        $table  = $wpdb->prefix . self::TABLE;
        $where  = $type ? $wpdb->prepare("AND object_type = %s", $type) : '';
        $wpdb->query(
            "UPDATE {$table} SET status = 'pending', updated_at = NOW()
             WHERE status = 'processing'
             AND updated_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE) {$where}"
        );

        $status = $this->getStatus($type);

        // Pending var ama schedule yok → yeniden schedule et
        if ($status['pending'] > 0) {
            if (!$type || $type === 'post')   self::scheduleNext('post',   5);
            if (!$type || $type === 'term')   self::scheduleNext('term',   5);
            if (!$type || $type === 'string') self::scheduleNext('string', 5);
        }

        wp_send_json_success($status);
    }

    public function ajaxStart(): void {
        check_ajax_referer('sat_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');

        $type           = sanitize_key($_POST['type'] ?? 'post');
        $langs          = array_map('sanitize_key', (array)($_POST['langs'] ?? []));
        $ids            = array_map('intval', (array)($_POST['ids'] ?? []));
        $all            = (bool)($_POST['all'] ?? false);
        $selectedTypes  = array_map('sanitize_key', (array)($_POST['post_types'] ?? []));
        $selectedTaxs   = array_map('sanitize_key', (array)($_POST['taxonomies'] ?? []));
        $skipTranslated = (bool)(int)($_POST['skip_translated'] ?? 1);
        $group          = sanitize_text_field($_POST['group'] ?? '');

        $integration = $this->container->get('integration');
        if (!$integration) wp_send_json_error('No integration');

        // ── String type — özel handling ──────────────────────────────────────
        if ($type === 'string') {
            if (empty($langs)) wp_send_json_error('No languages');

            // Polylang'dan string listesini al
            $allStrings = [];
            if (class_exists('\PLL_Admin_Strings')) {
                $pllStrings = \PLL_Admin_Strings::get_strings();
                if (is_array($pllStrings)) {
                    foreach ($pllStrings as $s) {
                        // Group filtresi
                        if ($group && ($s['context'] ?? '') !== $group) continue;

                        // Skip translated kontrolü
                        if ($skipTranslated) {
                            $allTranslated = true;
                            foreach ($langs as $l) {
                                $mo = new \PLL_MO();
                                $mo->import_from_db($l);
                                $existing = $mo->translate((string)($s['string'] ?? ''));
                                if (empty($existing) || $existing === (string)($s['string'] ?? '')) {
                                    $allTranslated = false;
                                    break;
                                }
                            }
                            if ($allTranslated) continue;
                        }

                        $allStrings[] = [
                            'name'   => $s['name'] ?? '',
                            'string' => (string)($s['string'] ?? ''),
                            'group'  => $s['context'] ?? '',
                        ];
                    }
                }
            }

            if (empty($allStrings)) wp_send_json_error('No strings to queue');

            $count = self::addStringBatch($allStrings, $langs);

            update_option('sat_queue_meta_string', [
                'langs'      => $langs,
                'group'      => $group ?: 'All groups',
                'started_at' => current_time('mysql'),
                'total'      => $count,
            ], false);

            self::cancelScheduled('string');
            self::scheduleNext('string', 3);

            wp_send_json_success([
                'queued' => $count,
                'status' => $this->getStatus('string'),
                'driver' => self::useActionScheduler() ? 'action_scheduler' : 'wp_cron',
            ]);
        }
        // ─────────────────────────────────────────────────────────────────────

        if ($all) {
            $allIds = [];
            foreach ($langs as $l) {
                if ($type === 'post') {
                    if ($skipTranslated) {
                        $langIds = $integration->getUntranslatedPostIds($l, $selectedTypes ?: []);
                    } else {
                        $ptypes  = $selectedTypes ?: array_values(array_filter(
                            array_keys(get_post_types(['public' => true])),
                            [$integration, 'isTranslatablePostType']
                        ));
                        $langIds = get_posts([
                            'post_type'      => $ptypes,
                            'post_status'    => 'publish',
                            'posts_per_page' => -1,
                            'fields'         => 'ids',
                            'lang'           => $integration->getDefaultLanguage(),
                        ]);
                    }
                } else {
                    if ($skipTranslated) {
                        $langIds = $integration->getUntranslatedTermIds($l, $selectedTaxs ?: []);
                    } else {
                        $taxs    = $selectedTaxs ?: array_values(array_filter(
                            array_keys(get_taxonomies(['public' => true])),
                            [$integration, 'isTranslatableTaxonomy']
                        ));
                        $terms   = get_terms(['taxonomy' => $taxs, 'hide_empty' => false,
                                              'lang'     => $integration->getDefaultLanguage(), 'number' => 0]);
                        $langIds = is_wp_error($terms) ? [] : wp_list_pluck($terms, 'term_id');
                    }
                }
                foreach ((array)$langIds as $id) {
                    $allIds[(int)$id] = true;
                }
            }
            $ids = array_keys($allIds);
        }

        if (empty($ids) || empty($langs)) wp_send_json_error('No items or languages');

        $count = self::addBatch($type, $ids, $langs);

        // Queue meta bilgisini kaydet (UI'da göstermek için)
        update_option('sat_queue_meta_' . $type, [
            'langs'      => $langs,
            'post_types' => $selectedTypes,
            'taxonomies' => $selectedTaxs,
            'started_at' => current_time('mysql'),
            'total'      => $count,
        ], false);

        // İlk batch'i başlat
        self::cancelScheduled($type);
        self::scheduleNext($type, 3);

        wp_send_json_success([
            'queued' => $count,
            'status' => $this->getStatus($type),
            'driver' => self::useActionScheduler() ? 'action_scheduler' : 'wp_cron',
        ]);
    }

    public function ajaxCancel(): void {
        check_ajax_referer('sat_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');

        global $wpdb;
        $type  = sanitize_key($_POST['type'] ?? '');
        $table = $wpdb->prefix . self::TABLE;
        $where = $type ? $wpdb->prepare("AND object_type = %s", $type) : '';
        $wpdb->query("DELETE FROM {$table} WHERE status = 'pending' {$where}");

        self::cancelScheduled($type);

        wp_send_json_success(['message' => 'Queue cancelled']);
    }

    public function ajaxRetry(): void {
        check_ajax_referer('sat_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');

        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $wpdb->query("UPDATE {$table} SET status='pending', retries=0, error_msg=NULL WHERE status='error'");

        // Her iki queue type'ını da schedule et
        self::scheduleNext('post', 3);
        self::scheduleNext('term', 3);

        wp_send_json_success(['message' => 'Errors requeued']);
    }
}
