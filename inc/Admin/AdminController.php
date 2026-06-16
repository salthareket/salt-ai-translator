<?php
namespace SAT\Admin;

use SAT\Core\Container;

class AdminController {

    private Container $container;

    public function __construct(Container $container) {
        $this->container = $container;
    }

    public function register(): void {
        add_action('admin_menu',             [$this, 'registerMenus']);
        add_action('admin_menu',             [$this, 'updateMenuBadge'], 999);
        add_action('admin_enqueue_scripts',  [$this, 'enqueueAssets']);
        add_action('admin_footer',           [$this, 'injectQueueBadgeJs']);
        add_action('admin_init',             [$this, 'registerSettings']);
        add_action('add_meta_boxes',         [$this, 'registerMetaBoxes']);
        add_action('admin_head',             [$this, 'hideNotices']);
        add_action('admin_notices',          [$this, 'showAutoTranslateNotice']);

        // AJAX handlers
        $ajax = new AjaxHandler($this->container);
        $ajax->register();
    }

    public function showAutoTranslateNotice(): void {
        $userId = get_current_user_id();
        $data   = get_transient('sat_auto_translate_notice_' . $userId);
        if (!$data) return;
        delete_transient('sat_auto_translate_notice_' . $userId);

        $langs    = implode(', ', array_map('strtoupper', $data['langs']));
        $title    = esc_html($data['title'] ?: '(no title)');
        $queueUrl = esc_url(admin_url('admin.php?page=sat-posts'));
        ?>
        <div class="notice notice-success is-dismissible sat-notice" style="border-left-color:#6366f1;padding:10px 14px;display:flex;align-items:center;gap:12px;">
            <span style="font-size:20px;">🔄</span>
            <div>
                <strong>Auto Translate:</strong>
                "<em><?= $title ?></em>" queued for translation →
                <strong><?= $langs ?></strong>.
                <a href="<?= $queueUrl ?>" style="color:#6366f1;margin-left:6px;">View queue →</a>
            </div>
        </div>
        <?php
    }

    public function registerMenus(): void {
        add_menu_page(
            'Salt AI Translator', 'Salt AI Translator',
            'manage_options', 'sat',
            [$this, 'renderDashboard'],
            'dashicons-translation', 56
        );

        $pages = [
            ['sat-posts',   'Posts',   [$this, 'renderPosts']],
            ['sat-terms',   'Terms',   [$this, 'renderTerms']],
            ['sat-media',   'Media',   [$this, 'renderMedia']],
            ['sat-others',  'Others',  [$this, 'renderOthers']],
            ['sat-credits', 'Credits', [$this, 'renderCredits']],
            ['sat-logs',    'Logs',    [$this, 'renderLogs']],
            ['sat-export',  'Export',  [$this, 'renderExport']],
            ['sat-settings','Settings',[$this, 'renderSettings']],
        ];

        foreach ($pages as [$slug, $title, $cb]) {
            add_submenu_page('sat', $title, $title, 'manage_options', $slug, $cb);
        }
    }

    public function updateMenuBadge(): void {
        global $submenu;
        $queue   = $this->container->get('queue');
        $status  = $queue ? $queue->getStatus() : [];
        $pending = ($status['pending'] ?? 0) + ($status['processing'] ?? 0);
        if ($pending <= 0) return;

        // Submenu'deki "Salt AI Translator" parent link'ine badge ekle
        // $submenu['sat'][0] = ana dashboard linki (ilk submenu item = parent ile aynı)
        if (isset($submenu['sat'][0])) {
            $submenu['sat'][0][0] .= ' <span class="awaiting-mod count-' . $pending . '"><span class="pending-count">' . $pending . '</span></span>';
        }
    }

    /**
     * Admin footer'a queue badge güncelleme JS'i inject et.
     * Sadece SAT sayfalarında çalışır, 60 saniyede bir badge'i günceller.
     * satConfig.nonce kullanılır — ayrı nonce oluşturulmaz.
     */
    public function injectQueueBadgeJs(): void {
        $screen = get_current_screen();
        if (!$screen || !str_contains($screen->id ?? '', 'sat')) return;
        ?>
        <script>
        (function($) {
          // satConfig.nonce kullan — enqueue sırasında zaten oluşturuldu
          var satNonce = (typeof satConfig !== 'undefined') ? satConfig.nonce : '';
          if (!satNonce) return;

          function updateSatQueueBadge() {
            $.post(ajaxurl, { action: 'sat_queue_status', nonce: satNonce }, function(res) {
              if (!res.success) return;
              const d = res.data;
              const pending = (d.pending || 0) + (d.processing || 0);

              const $subMenuFirst = $('#adminmenu li.toplevel_page_sat > ul.wp-submenu > li:first-child > a');
              const $badge = $subMenuFirst.find('.awaiting-mod');

              if (pending > 0) {
                if ($badge.length) {
                  $badge.find('.pending-count').text(pending);
                } else {
                  $subMenuFirst.append('<span class="awaiting-mod"><span class="pending-count">' + pending + '</span></span>');
                }
              } else {
                $badge.remove();
              }
            });
          }
          setTimeout(updateSatQueueBadge, 2000);
          setInterval(updateSatQueueBadge, 60000);
        })(jQuery);
        </script>
        <?php
    }

    public function registerSettings(): void {
        register_setting('sat_options', 'sat_settings', ['sanitize_callback' => [$this, 'sanitizeSettings']]);
    }

    public function sanitizeSettings(array $input): array {
        $settings = $this->container->get('settings');
        $current  = $settings->getAll();

        $translator = sanitize_key($input['translator'] ?? '');
        $current['translator'] = $translator;

        // API keys per translator
        foreach (['openai', 'deepl', 'google', 'claude', 'azure_openai', 'libretranslate'] as $t) {
            $raw = $input['api_keys'][$t] ?? '';
            if (!is_array($raw)) $raw = explode("\n", $raw);
            $current['api_keys'][$t] = array_values(array_filter(array_map('trim', $raw)));
        }

        $current['model']       = sanitize_text_field($input['model'] ?? '');
        $current['temperature'] = sanitize_text_field($input['temperature'] ?? '0.2');
        $current['prompt']      = sanitize_textarea_field($input['prompt'] ?? '');
        $current['retranslate'] = isset($input['retranslate']) ? 1 : 0;
        $current['auto_translate'] = isset($input['auto_translate']) ? 1 : 0;
        $current['translate_slugs'] = isset($input['translate_slugs']) ? 1 : 0;

        $current['exclude_post_types'] = array_map('sanitize_key', (array)($input['exclude_post_types'] ?? []));
        $current['exclude_taxonomies'] = array_map('sanitize_key', (array)($input['exclude_taxonomies'] ?? []));
        $current['exclude_posts']      = array_filter(array_map('intval', (array)($input['exclude_posts'] ?? [])));
        $current['exclude_terms']      = array_filter(array_map('intval', (array)($input['exclude_terms'] ?? [])));

        // Field-level exclusions
        $current['exclude_title_post_types'] = array_map('sanitize_key', (array)($input['exclude_title_post_types'] ?? []));
        $current['exclude_name_taxonomies']  = array_map('sanitize_key', (array)($input['exclude_name_taxonomies'] ?? []));

        // Glossary
        $glossary = [];
        foreach ((array)($input['glossary'] ?? []) as $item) {
            $source = sanitize_text_field($item['source'] ?? '');
            if ($source) {
                $glossary[] = ['source' => $source, 'target' => sanitize_text_field($item['target'] ?? '')];
            }
        }
        $current['glossary'] = $glossary;

        // SEO settings
        foreach (['meta_desc', 'image_alttext'] as $key) {
            if (isset($input['seo'][$key])) {
                $s = $input['seo'][$key];
                $current['seo'][$key]['generate']   = isset($s['generate']) ? 1 : 0;
                $current['seo'][$key]['translate']  = isset($s['translate']) ? 1 : 0;
                $current['seo'][$key]['on_save']    = isset($s['on_save']) ? 1 : 0;
                $current['seo'][$key]['overwrite']  = isset($s['overwrite']) ? 1 : 0;
                $current['seo'][$key]['model']      = sanitize_text_field($s['model'] ?? '');
                $current['seo'][$key]['temperature']= sanitize_text_field($s['temperature'] ?? '');
                $current['seo'][$key]['prompt']     = sanitize_textarea_field($s['prompt'] ?? '');
            }
        }
        if (isset($input['seo']['image_alttext']['image_size'])) {
            $current['seo']['image_alttext']['image_size'] = sanitize_key($input['seo']['image_alttext']['image_size']);
        }

        $current['seo']['seo_title']['translate'] = isset($input['seo']['seo_title']['translate']) ? 1 : 0;
        $current['seo']['og_tags']['translate']   = isset($input['seo']['og_tags']['translate']) ? 1 : 0;

        $current['display']['unpublished_languages'] = array_map('sanitize_key', (array)($input['display']['unpublished_languages'] ?? []));

        // WooCommerce settings
        if (class_exists('WooCommerce')) {
            $current['woo']['translate_attributes'] = isset($input['woo']['translate_attributes']) ? 1 : 0;
            $current['woo']['translate_products']   = isset($input['woo']['translate_products']) ? 1 : 0;
        }

        return $current;
    }

    public function enqueueAssets(string $hook): void {
        // Ana menu slug'ı 'sat', bu yüzden hook prefix'i 'salt-ai-translator_page_' oluyor
        $satPages = [
            'toplevel_page_sat',
            'salt-ai-translator_page_sat-posts',
            'salt-ai-translator_page_sat-terms',
            'salt-ai-translator_page_sat-media',
            'salt-ai-translator_page_sat-others',
            'salt-ai-translator_page_sat-credits',
            'salt-ai-translator_page_sat-logs',
            'salt-ai-translator_page_sat-export',
            'salt-ai-translator_page_sat-settings',
        ];

        wp_enqueue_style('sat-admin', SAT_URL . 'assets/css/admin.css', [], SAT_VERSION);

        if (!in_array($hook, $satPages)) return;

        // Select2 — kendi bundle'ımızı kullan (ACF/WC bağımlılığı yok)
        $select2_js  = SAT_DIR . 'assets/vendor/select2.min.js';
        $select2_css = SAT_DIR . 'assets/vendor/select2.min.css';

        if ( file_exists($select2_js) && file_exists($select2_css) ) {
            // Kendi bundle'ımız var — onu kullan
            wp_enqueue_style( 'sat-select2', SAT_URL . 'assets/vendor/select2.min.css', [], '4.0.13' );
            wp_enqueue_script( 'sat-select2', SAT_URL . 'assets/vendor/select2.min.js', ['jquery'], '4.0.13', true );
            $select2_dep = 'sat-select2';
        } elseif ( class_exists('WooCommerce') ) {
            // WC varsa onunkini kullan
            wp_enqueue_style( 'select2' );
            wp_enqueue_script( 'select2' );
            $select2_dep = 'select2';
        } elseif ( wp_script_is('select2', 'registered') ) {
            // WP'de başka bir plugin tarafından register edilmişse onu kullan
            wp_enqueue_style( 'select2' );
            wp_enqueue_script( 'select2' );
            $select2_dep = 'select2';
        } else {
            // Hiçbiri yok — select2'siz devam et, native multiselect kullanılır
            $select2_dep = 'jquery';
        }

        wp_enqueue_script('sat-admin', SAT_URL . 'assets/js/admin.js', ['jquery', $select2_dep], SAT_VERSION, true);
        wp_localize_script('sat-admin', 'satConfig', [
            'nonce'      => wp_create_nonce('sat_nonce'),
            'ajaxUrl'    => admin_url('admin-ajax.php'),
            'adminUrl'   => admin_url(),
            'settings'   => $this->container->get('settings')->getAll(),
            'queue'      => $this->container->get('queue')->getStatus(),
            'hasSelect2' => $select2_dep !== 'jquery',
        ]);
    }

    public function registerMetaBoxes(): void {
        $integration = $this->container->get('integration');
        if (!$integration) return;

        $postTypes = get_post_types(['public' => true, 'show_ui' => true], 'names');
        $excluded  = $this->container->get('settings')->get('exclude_post_types', []);

        foreach ($postTypes as $type) {
            if (in_array($type, $excluded)) continue;
            add_meta_box('sat-translate', 'Salt Translate', [$this, 'renderMetaBox'], $type, 'side', 'high');
        }
    }

    public function renderMetaBox(\WP_Post $post): void {
        $integration = $this->container->get('integration');
        $languages   = $integration ? $integration->getLanguages() : [];
        $default     = $integration ? $integration->getDefaultLanguage() : '';
        $translator  = $this->container->get('settings')->get('translator');
        $container   = $this->container;

        include SAT_DIR . 'admin/views/meta-box.php';
    }

    public function hideNotices(): void {
        $page = sanitize_key($_GET['page'] ?? '');
        if (str_starts_with($page, 'sat')) {
            echo '<style>.notice:not(.sat-notice),.updated:not(.sat-notice),.error:not(.sat-notice){display:none!important;}</style>';
        }
    }

    // Page renderers
    public function renderDashboard(): void { $this->render('dashboard'); }
    public function renderPosts(): void     { $this->render('posts'); }
    public function renderTerms(): void     { $this->render('terms'); }
    public function renderMedia(): void     { $this->render('media'); }
    public function renderOthers(): void    { $this->render('others'); }
    public function renderCredits(): void   { $this->render('credits'); }
    public function renderLogs(): void      { $this->render('logs'); }
    public function renderExport(): void    { $this->render('export'); }
    public function renderSettings(): void  { $this->render('settings'); }

    private function render(string $view): void {
        $container   = $this->container;
        $settings    = $container->get('settings');
        $integration = $container->get('integration');
        $translator  = $container->get('translator');
        $languages   = $integration ? $integration->getLanguages() : [];

        // Others sayfasında Polylang string'lerinin register edilmesi için hook'ları tetikle
        if ($view === 'others' && class_exists('\PLL_Admin_Strings')) {
            do_action('widgets_init');
        }

        $file = SAT_DIR . "admin/views/{$view}.php";
        if (file_exists($file)) include $file;
    }
}
