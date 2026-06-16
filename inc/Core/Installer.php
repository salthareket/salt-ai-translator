<?php
namespace SAT\Core;

class Installer {
    public static function activate(): void {
        Logger::createTable();
        \SAT\Queue\QueueManager::createTable();
        \SAT\Core\TranslationMemory::createTable();

        // Add field_name column if missing (migration)
        global $wpdb;
        $columns = $wpdb->get_col("SHOW COLUMNS FROM {$wpdb->prefix}sat_translate_logs LIKE 'field_name'");
        if (empty($columns)) {
            $wpdb->query("ALTER TABLE {$wpdb->prefix}sat_translate_logs ADD COLUMN field_name varchar(100) NOT NULL DEFAULT '' AFTER duration_ms");
        }

        // Model sync cron — haftalık
        if (!wp_next_scheduled('sat_sync_models')) {
            wp_schedule_event(time(), 'weekly', 'sat_sync_models');
        }
    }

    public static function deactivate(): void {
        wp_clear_scheduled_hook('sat_sync_models');
        wp_clear_scheduled_hook('sat_queue_posts');
        wp_clear_scheduled_hook('sat_queue_terms');
        wp_clear_scheduled_hook('sat_memory_prune');
    }
}
