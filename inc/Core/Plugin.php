<?php
namespace SAT\Core;

class Plugin {

    private static ?Plugin $instance = null;
    private Container $container;

    private function __construct() {
        $this->container = new Container();
    }

    public static function getInstance(): static {
        if (!self::$instance) self::$instance = new static();
        return self::$instance;
    }

    public function getContainer(): Container {
        return $this->container;
    }

    public function boot(): void {
        $this->container->set('settings', new Settings());

        // DB migration — her yüklemede kontrol et (activate hook güvenilmez)
        $this->maybeRunMigrations();

        $this->loadIntegration();
        $this->loadServices();
        $this->loadTranslator();

        if (is_admin() || (defined('DOING_AJAX') && DOING_AJAX)) {
            (new \SAT\Admin\AdminController($this->container))->register();
        }

        // Cron hooks
        add_action('sat_queue_posts',   [\SAT\Queue\QueueManager::class, 'processPostQueue']);
        add_action('sat_queue_terms',   [\SAT\Queue\QueueManager::class, 'processTermQueue']);
        add_action('sat_queue_strings', [\SAT\Queue\QueueManager::class, 'processStringQueue']);
        add_action('sat_sync_models',   [\SAT\Translator\ModelSync::class, 'sync']);
        add_action('sat_memory_prune',  [$this, 'pruneTranslationMemory']);

        // Memory prune — haftalık schedule
        if (!wp_next_scheduled('sat_memory_prune')) {
            wp_schedule_event(time(), 'weekly', 'sat_memory_prune');
        }

        // Auto-translate on save
        $settings = $this->container->get('settings');
        if ($settings->get('auto_translate')) {
            add_action('save_post', [$this, 'onSavePost'], 90, 3);
        }
    }

    private function loadIntegration(): void {
        $mlPlugins = [
            'polylang/polylang.php'        => \SAT\Integration\Polylang::class,
            'polylang-pro/polylang.php'    => \SAT\Integration\Polylang::class,
            'qtranslate-xt/qtranslate.php' => \SAT\Integration\QtranslateXT::class,
            'sitepress-multilingual-cms/sitepress.php' => \SAT\Integration\WPML::class,
        ];

        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        foreach ($mlPlugins as $slug => $class) {
            if (is_plugin_active($slug) && file_exists(WP_PLUGIN_DIR . '/' . $slug)) {
                $this->container->set('integration', new $class($this->container));
                $this->container->set('ml_plugin', $slug);
                return;
            }
        }
    }

    private function loadTranslator(): void {
        $settings   = $this->container->get('settings');
        $translator = $settings->get('translator', '');
        $map = [
            'openai'         => \SAT\Translator\OpenAI::class,
            'deepl'          => \SAT\Translator\DeepL::class,
            'google'         => \SAT\Translator\Google::class,
            'claude'         => \SAT\Translator\Claude::class,
            'azure_openai'   => \SAT\Translator\AzureOpenAI::class,
            'libretranslate' => \SAT\Translator\LibreTranslate::class,
        ];
        if ($translator && isset($map[$translator])) {
            $this->container->set('translator', new $map[$translator]($this->container));
        }
    }

    private function loadServices(): void {
        $this->container->set('queue',   new \SAT\Queue\QueueManager($this->container));
        $this->container->set('logger',  new \SAT\Core\Logger($this->container));
        $this->container->set('credits', new \SAT\Core\CreditTracker($this->container));
        $this->container->set('memory',  new \SAT\Core\TranslationMemory());

        // SEO plugin
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $seoPlugins = [
            'wordpress-seo/wp-seo.php'              => \SAT\SEO\Yoast::class,
            'wordpress-seo-premium/wp-seo-premium.php' => \SAT\SEO\Yoast::class,
            'seo-by-rank-math/rank-math.php'        => \SAT\SEO\RankMath::class,
            'seo-by-rank-math-pro/rank-math-pro.php'=> \SAT\SEO\RankMath::class,
        ];
        foreach ($seoPlugins as $slug => $class) {
            if (is_plugin_active($slug)) {
                $this->container->set('seo', new $class());
                break;
            }
        }

        // Media translator (registers alt text filter)
        new \SAT\Content\MediaTranslator($this->container);
    }

    public function onSavePost(int $postId, \WP_Post $post, bool $update): void {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (wp_is_post_revision($postId)) return;
        if ($GLOBALS['sat_doing_translate'] ?? false) return;

        // Translation Lock kontrolü
        if (get_post_meta($postId, '_sat_translation_lock', true)) return;

        $integration = $this->container->get('integration');
        if (!$integration || !$integration->isTranslatablePostType($post->post_type)) return;

        $langs   = $integration->getLanguages();
        $default = $integration->getDefaultLanguage();

        // Sadece default dildeki post'ları çevir
        $postLang = function_exists('pll_get_post_language') ? pll_get_post_language($postId) : $default;
        if ($postLang && $postLang !== $default) return;

        $queued = [];
        foreach ($langs as $code => $label) {
            if ($code === $default) continue;
            \SAT\Queue\QueueManager::addItem('post', $postId, $code);
            $queued[] = $code;
        }

        if (!empty($queued)) {
            // Admin notice için transient set et (current user'a özel)
            set_transient('sat_auto_translate_notice_' . get_current_user_id(), [
                'post_id' => $postId,
                'title'   => $post->post_title,
                'langs'   => $queued,
            ], 60);
        }
    }

    public function isLocalhost(): bool {
        $host = $_SERVER['HTTP_HOST'] ?? '';
        return in_array($host, ['localhost', '127.0.0.1']) || str_contains($host, '.local');
    }

    /**
     * Translation Memory prune — haftalık WP Cron ile çalışır.
     * 90 günden eski, 0 hit'li kayıtları siler.
     */
    public function pruneTranslationMemory(): void {
        $memory = $this->container->get('memory');
        if (!$memory) return;
        $deleted = $memory->prune(90, 0);
        update_option('sat_memory_last_prune', [
            'time'    => time(),
            'deleted' => $deleted,
        ]);
        if ($deleted > 0) {
            error_log('[SAT] Translation Memory pruned — deleted ' . $deleted . ' old entries');
        }
    }

    /**
     * DB migration'larını kontrol et ve gerekirse uygula.
     * Option versiyonu ile karşılaştırır — her request'te değil, sadece versiyon değişince çalışır.
     */
    private function maybeRunMigrations(): void {
        $dbVersion   = get_option('sat_db_version', '0');
        $codeVersion = SAT_VERSION;

        // wp_sat_queue tablosunda field_name kolonu yoksa her zaman migration çalıştır
        global $wpdb;
        $queueTable  = $wpdb->prefix . 'sat_queue';
        $needsQueueMigration = false;
        if ($wpdb->get_var("SHOW TABLES LIKE '{$queueTable}'") === $queueTable) {
            $queueCols = $wpdb->get_col("SHOW COLUMNS FROM {$queueTable} LIKE 'field_name'");
            if (empty($queueCols)) $needsQueueMigration = true;
        }

        if (!$needsQueueMigration && version_compare($dbVersion, $codeVersion, '>=')) return;

        global $wpdb;
        $table = $wpdb->prefix . 'sat_translate_logs';

        // Tablo yoksa oluştur
        \SAT\Core\Logger::createTable();
        \SAT\Queue\QueueManager::createTable();
        \SAT\Core\TranslationMemory::createTable();

        // field_name kolonu — logs tablosu migration
        $columns = $wpdb->get_col("SHOW COLUMNS FROM {$table} LIKE 'field_name'");
        if (empty($columns)) {
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN field_name varchar(150) NOT NULL DEFAULT '' AFTER duration_ms");
        }

        // wp_sat_queue tablosuna field_name kolonu ekle (string queue için)
        $queueTable = $wpdb->prefix . 'sat_queue';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$queueTable}'") === $queueTable) {
            $queueCols = $wpdb->get_col("SHOW COLUMNS FROM {$queueTable} LIKE 'field_name'");
            if (empty($queueCols)) {
                $wpdb->query("ALTER TABLE {$queueTable} ADD COLUMN field_name varchar(150) NOT NULL DEFAULT '' AFTER target_lang");
            }
        }

        update_option('sat_db_version', $codeVersion);
    }
}
