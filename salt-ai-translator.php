<?php

use SaltAI\Core\ServiceContainer;

use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\IOFactory as WordIO;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\IOFactory as ExcelIO;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\RichText\Run;

/**
 * Plugin Name: Salt AI Translator
 * Text Domain: salt-ai-translator
 * Description: Otomatik çok dilli çeviri sistemi. OpenAI, DeepL vb. destekler.
 * Version: 1.0.7
 * Author: Tolga Koçak
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) exit;

define('SALT_AI_TRANSLATOR_PREFIX', 'salt_ai_translator');
define('SALT_AI_TRANSLATOR_DIR', plugin_dir_path(__FILE__));
define('SALT_AI_TRANSLATOR_URL', plugin_dir_url(__FILE__));

if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
    require __DIR__ . '/vendor/autoload.php';
}

class Salt_AI_Translator_Plugin {

    public $options = [];
    
    public $container;
    public $ml_plugin = '';
    private $supported_ml_plugins = [
        'polylang/polylang.php'       => [
            'name'   => "Polylang",
            'key'    => 'polylang',      
            'file'   => 'polylang.php',
            'is_pro' => false,
            'url'     => 'https://wordpress.org/plugins/polylang/',
        ],
        'polylang-pro/polylang.php'   => [
            'name'   => "Polylang PRO",
            'key'    => 'polylang',      
            'file'   => 'polylang.php',
            'is_pro' => true,
            'url'     => 'https://polylang.pro/',
        ],
        'qtranslate-xt/qtranslate.php' => [
            'name'    => "qTranslate-XT",
            'key'     => 'qtranslate-xt',
            'file'    => 'qtranslate-xt.php',
            'is_pro'  => false,
            'url'     => 'https://github.com/qtranslate/qtranslate-xt',
        ],
    ];
    private $seo_plugin = '';
    private $supported_seo_plugins = [
        // Yoast
        'wordpress-seo/wp-seo.php' => [
            'name'   => "Yoast SEO",
            'key'    => 'yoast',
            'file'   => 'yoast-seo.php',
            'is_pro' => false,
            'url'    => 'https://wordpress.org/plugins/wordpress-seo/',
        ],
        'wordpress-seo-premium/wp-seo-premium.php' => [
            'name'   => 'Yoast SEO Premium',
            'key'    => 'yoast',
            'file'   => 'yoast-seo.php',
            'is_pro' => true,
            'url'    => 'https://yoast.com/seo-blog/yoast-seo-premium/',
        ],

        // Rank Math
        'seo-by-rank-math/rank-math.php' => [
            'name'   => 'Rank Math SEO',
            'key'    => 'rankmath',
            'file'   => 'rank-math.php',
            'is_pro' => false,
            'url'    => 'https://wordpress.org/plugins/seo-by-rank-math/',
        ],
        'seo-by-rank-math-pro/rank-math-pro.php' => [
            'name'   => 'Rank Math SEO PRO',
            'key'    => 'rankmath',
            'file'   => 'rank-math.php',
            'is_pro' => true,
            'url'    => 'https://rankmath.com/pricing/',
        ],

        // AIOSEO
        'all-in-one-seo-pack/all_in_one_seo_pack.php' => [
            'name'   => 'All in One SEO',
            'key'    => 'aioseo',
            'file'   => 'aioseo.php',
            'is_pro' => false,
            'url'    => 'https://wordpress.org/plugins/all-in-one-seo-pack/',
        ],
        'all-in-one-seo-pack-pro/all_in_one_seo_pack.php' => [
            'name'   => 'All in One SEO PRO',
            'key'    => 'aioseo',
            'file'   => 'aioseo.php',
            'is_pro' => true,
            'url'    => 'https://aioseo.com/pricing/',
        ],
    ];

    public $languages = [];

    public function __construct() {

        // 1. ML Plugin Kontrolü (Hem DB hem Fiziksel Dosya)
        if ( ! $this->check_required_ml_plugins() ) {
            add_action('admin_notices', [$this, 'show_missing_ml_notice']);
            return; 
        }

        if (!class_exists('SaltAI\Core\ServiceContainer')) {
            require_once SALT_AI_TRANSLATOR_DIR . 'inc/core/ServiceContainer.php';
        }
        $this->container = new ServiceContainer();

        $options = get_option(SALT_AI_TRANSLATOR_PREFIX . '_settings', []);
        $this->options = array_merge($this->get_default_options(), is_array($options) ? $options : []);

        if($this->isLocalhost()){
            $this->options["seo"]["image_alttext"]["generate"] = 0;
            $this->options["seo"]["image_alttext"]["translate"] = 0;
            $this->options["seo"]["image_alttext"]["on_save"] = 0;
            $this->options["seo"]["image_alttext"]["overwrite"] = 0;
        }

        $this->container->set('plugin', $this);

        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('init', [$this, 'load_textdomain']);

        //add_action('plugins_loaded', [$this, 'initialize_services']);
        add_action('init', [$this, 'initialize_services']);
        add_action('init', [$this, 'unpublish_languages']);


        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);

        add_action('wp_ajax_get_untranslated_posts', [$this, 'get_untranslated_posts']);
        add_action('wp_ajax_translate_post', [$this, 'translate_post']);

        add_action('wp_ajax_get_untranslated_terms', [$this, 'get_untranslated_terms']);
        add_action('wp_ajax_translate_term', [$this, 'translate_term']);

        add_action('wp_ajax_get_untranslated_posts_terms', [$this, 'get_untranslated_posts_terms']);

        add_action('wp_ajax_translate_menu', [$this, 'translate_menu']);
        add_action('wp_ajax_translate_strings', [$this, 'translate_strings']);
        add_action('wp_ajax_translate_post_type_taxonomy', [$this, 'translate_post_type_taxonomy']);

        add_action('wp_ajax_salt_autocomplete_posts', [$this, 'autocomplete_posts']);
        add_action('wp_ajax_salt_autocomplete_terms', [$this, 'autocomplete_terms']);

        add_action('wp_ajax_get_sitemap_urls', [$this, 'get_sitemap_urls']);
        add_action('wp_ajax_get_translations_by_url', [$this, 'get_translations_by_url']);
        add_action('wp_ajax_export_translations_download', [$this, 'export_translations_download']);
        add_action('wp_ajax_export_translations_download_cache', [$this, 'export_translations_download_cache']);
        
        add_action('add_meta_boxes', [$this, 'add_translate_post_meta_box']);
        add_action('wp_ajax_salt_translate_post_manual_ajax', [$this, 'handle_translate_post_meta_box_ajax']);
        
        add_action('admin_init', [$this, 'add_translate_term_meta_box']);
        add_action('wp_ajax_salt_translate_term_manual_ajax', [$this, 'handle_translate_term_meta_box_ajax']);

        add_action('admin_head', function () {
            $pages = ["salt-ai-translator", 'salt-ai-translator-posts', 'salt-ai-translator-terms', 'salt-ai-translator-others', 'salt-ai-translator-export' ];
            if (isset($_GET['page']) && in_array($_GET['page'], $pages)) {
                remove_all_actions('admin_notices');
                remove_all_actions('all_admin_notices');
            }
        });
    }

    /**
     * Hem veritabanında aktif mi bakar hem de dosya gerçekten yerinde mi kontrol eder.
     */
    private function check_required_ml_plugins() {
        if ( ! function_exists( 'is_plugin_active' ) ) {
            require_once( ABSPATH . 'wp-admin/includes/plugin.php' );
        }

        foreach ( $this->supported_ml_plugins as $plugin_path => $data ) {
            // 1. DB'de aktif mi? 
            // 2. Fiziksel dosya wp-content/plugins/ içinde var mı?
            if ( is_plugin_active( $plugin_path ) && file_exists( WP_PLUGIN_DIR . '/' . $plugin_path ) ) {
                $this->ml_plugin = $plugin_path; 
                return true; // Biri bulunduysa yeterli
            }
        }
        
        return false; // Hiçbiri şartı sağlamıyor
    }

    /**
     * Admin Uyarısı
     */
    public function show_missing_ml_notice() {
        $plugin_names = array_column($this->supported_ml_plugins, 'name');
        $names_list = '<strong>' . implode('</strong>, <strong>', $plugin_names) . '</strong>';
        
        echo '<div class="notice notice-error">';
        echo '<p>' . sprintf( 
            esc_html__( 'Salt AI Translator: Çalışmak için şu çok dilli (ML) pluginlerden en az birinin hem yüklü hem de etkin olmasına ihtiyaç duyar: %s. Lütfen birini kurun veya etkinleştirin.', 'salt-ai-translator' ), 
            $names_list 
        ) . '</p>';
        echo '</div>';
    }

    private function get_default_options() {
        return [
            'api_keys'             => ['openai' => []],
            'translator'           => '',
            'prompt'               => '',
            'model'                => '',
            'temperature'          => '0.2',
            'retranslate' => 0,
            'auto_translate'       => 0,
            'exclude_post_types'   => [],
            'exclude_taxonomies'   => [],
            'exclude_posts'        => [],
            'exclude_terms'        => [],
            'display' => [
                "unpublished_languages" => []
            ],
            'seo' => [
                "meta_desc" => [
                    "generate" => 0,
                    "translate" => 0,
                    "on_save" => 0,
                    "on_changed" => 0,
                    "overwrite" => 0,
                    "prompt" => "",
                    "model" => "gpt-4",
                    "temperature" => "0.5"
                ],
                "image_alttext" => [
                    "generate" => 0,
                    "translate" => 0,
                    "on_save" => 0,
                    "overwrite" => 0,
                    "image_size" => "medium",
                    "prompt" => "",
                    "model" => "gpt-4",
                    "temperature" => "0.4"
                ],
            ],
            'menu' => [
                'retranslate' => 0
            ],
            'strings' => [
                'retranslate' => 0
            ],
            'keys' => [
                'pending'   => '_salt_translate_pending',
                'completed' => '_salt_translate_completed',
            ]
        ];
    }

    public function load_textdomain() {
        load_plugin_textdomain('salt-ai-translator', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }

    public function initialize_services() {

        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        if (!isset($this->container)) {
            return;
        }

        $container = $this->container;
        $plugin    = $this;

        /**
         * 1. ML Plugin (Polylang / qTranslate XT)
         */

        $ml_plugin_found = false; // Kontrol bayrağı

        foreach ($this->supported_ml_plugins as $plugin_slug => $plugin_data) {
            $physical_file = WP_PLUGIN_DIR . '/' . $plugin_slug;
            $is_active = is_plugin_active($plugin_slug);
            $file_exists = file_exists($physical_file);
            if ($is_active && $file_exists) {
                $this->ml_plugin = $plugin_data;
                $integration_file = SALT_AI_TRANSLATOR_DIR . 'inc/integrations/' . ($plugin_data['file'] ?? '');
                if (file_exists($integration_file)) {
                    require_once $integration_file;
                    if (class_exists('SaltAI\Integrations\Integration')) {
                        $ml_plugin_found = true;
                        $integration = new \SaltAI\Integrations\Integration($container);
                        $container->set('integration', $integration);

                        if($this->options["seo"]["meta_desc"]["on_save"]){
                            add_action('wp_insert_post_data', [$this, 'pre_autotranslate_post'], 999, 2);
                        }

                        if($this->options["auto_translate"]){
                            add_action('save_post', [ $this, 'autotranslate_post'], 90, 3);
                        }
                    }
                }
                break;
            }
        }

        /**
         * 2. Translator Engine (openai / deepl vb.)
         */
        $translator = $this->options['translator'] ?? '';
        if ($ml_plugin_found && $translator && file_exists(SALT_AI_TRANSLATOR_DIR . "inc/translator/{$translator}.php")) {
            require_once SALT_AI_TRANSLATOR_DIR . "inc/translator/{$translator}.php";
            if (class_exists('SaltAI\Translator\Translator')) {
                $translator_instance = new \SaltAI\Translator\Translator($container);
                $container->set('translator', $translator_instance);
            }
        }

        /**
         * 3. SEO Plugin (yoast / rankmath)
         */
        foreach ($this->supported_seo_plugins as $plugin_slug => $plugin_data) {
            if (is_plugin_active($plugin_slug)) {
                $this->seo_plugin = $plugin_data;

                $seo_file = SALT_AI_TRANSLATOR_DIR . 'inc/integrations/' . ($plugin_data['file'] ?? '');
                if ($ml_plugin_found && file_exists($seo_file)) {
                    require_once $seo_file;
                    if (class_exists('SaltAI\Integrations\SEOIntegration')) {
                        $seo_instance = new \SaltAI\Integrations\SEOIntegration($container);
                        $container->set('seo', $seo_instance);
                    }
                }
                break;
            }
        }



        /**
         * 4. Manager (TranslateQueueManager)
         */
        require_once SALT_AI_TRANSLATOR_DIR . 'inc/core/TranslateQueueManager.php';
        if (class_exists('SaltAI\Core\TranslateQueueManager')) {
            $manager = new \SaltAI\Core\TranslateQueueManager($container);
            $container->set('manager', $manager);

            // Cron hook'ları şimdi ekle, çünkü artık tüm bileşenler yüklü
            //add_action('salt_translate_posts_event', [$manager, 'handle_post_queue']);
           // add_action('salt_translate_terms_event', [$manager, 'handle_term_queue']);
            add_action($manager::POSTS_CRON_HOOK, [$manager, 'handle_post_queue']);
            add_action($manager::TERMS_CRON_HOOK, [$manager, 'handle_term_queue']);

        }

        if (!class_exists('SaltAI\Helper\Image')) {
            require_once SALT_AI_TRANSLATOR_DIR . 'inc/helper/image.php';
            $image = new \SaltAI\Helper\Image($container);
            $container->set('image', $image);
        }

        /**
         * 5. Dilleri çek ve kaydet
         */

        $plugin = $this; // $this = Salt_AI_Translator_Plugin
        add_action('init', function () use ($container) {
            if (!is_admin() && !defined('DOING_AJAX') && !defined('DOING_CRON')) return;
            $integration = $container->get('integration');
            if ($integration && method_exists($integration, 'get_languages')) {
                $this->languages = $integration->get_languages();
            }
        }, 20);
    }
    public function unpublish_languages(){
        if (!is_admin() && (!is_user_logged_in() || (is_user_logged_in() && !current_user_can('manage_options')))) {
            $integration = $this->container->get('integration');
            if (!$integration) {
                return;
            }
            $languages = $this->options["display"]["unpublished_languages"];
            $this->container->get('integration')->unpublish_languages($languages);
        }
    }

    public function admin_menu() {
        add_menu_page('Salt AI Translator', 'Salt AI Translator', 'manage_options', 'salt-ai-translator', [$this, 'settings_page'], 'dashicons-translation', 56);
        add_submenu_page('salt-ai-translator', 'Posts', 'Posts', 'manage_options', 'salt-ai-translator-posts', [$this, 'posts_page'], 1);
        add_submenu_page('salt-ai-translator', 'Terms', 'Terms', 'manage_options', 'salt-ai-translator-terms', [$this, 'terms_page'], 2);
        add_submenu_page('salt-ai-translator', 'Others', 'Others', 'manage_options', 'salt-ai-translator-others', [$this, 'others_page'], 3);
        add_submenu_page('salt-ai-translator', 'SEO', 'SEO', 'manage_options', 'salt-ai-translator-seo', [$this, 'seo_page'], 4);
        add_submenu_page('salt-ai-translator', 'Export', 'Export', 'manage_options', 'salt-ai-translator-export', [$this, 'export_page'], 5);
    }

    public function register_settings() {
        register_setting(SALT_AI_TRANSLATOR_PREFIX . '_options', SALT_AI_TRANSLATOR_PREFIX . '_settings', [
            'sanitize_callback' => [$this, 'sanitize_settings']
        ]);
    }

    public function sanitize_settings($input) {
        $existing = $this->options;//get_option(SALT_AI_TRANSLATOR_PREFIX . '_settings', []);
        $translator = $input['translator'] ?? '';

        $api_keys_input = $input['api_keys'][$translator] ?? '';
        if (!is_array($api_keys_input)) {
            $api_keys_input = explode("\n", $api_keys_input);
        }

        $api_keys_input = array_filter(array_map(function ($item) {
            return is_string($item) ? trim($item) : '';
        }, $api_keys_input));

        $existing['api_keys'][$translator] = $api_keys_input;
        $existing['translator'] = $translator;

        if ($translator === 'openai') {
            $existing['prompt'] = $input['prompt'] ?? '';
            $existing['model'] = $input['model'] ?? '';
            $existing['temperature'] = $input['temperature'] ?? '';
        }

        $existing['retranslate'] = isset($input['retranslate']) ? 1 : 0;
        $existing['auto_translate'] = isset($input['auto_translate']) ? 1 : 0;
        $existing['exclude_post_types'] = $input['exclude_post_types'] ?? [];
        $existing['exclude_taxonomies'] = $input['exclude_taxonomies'] ?? [];
        $existing['exclude_posts'] = array_filter(array_map('intval', $input['exclude_posts'] ?? []));
        $existing['exclude_terms'] = array_filter(array_map('intval', $input['exclude_terms'] ?? []));
        $existing['display']['unpublished_languages'] = $input['display']['unpublished_languages'] ?? [];

        if (isset($input['seo']['meta_desc'])) {
            $existing['seo']['meta_desc']['on_content_changed'] = isset($input['seo']['meta_desc']['on_content_changed']) ? 1 : 0;
            $existing['seo']['meta_desc']['on_save'] = isset($input['seo']['meta_desc']['on_save']) ? 1 : 0;
            $existing['seo']['meta_desc']['generate'] = isset($input['seo']['meta_desc']['generate']) ? 1 : 0;
            $existing['seo']['meta_desc']['translate'] = isset($input['seo']['meta_desc']['translate']) ? 1 : 0;
            $existing['seo']['meta_desc']['preserve'] = isset($input['seo']['meta_desc']['preserve']) ? 1 : 0;
            $existing['seo']['meta_desc']['prompt'] = isset($input['seo']['meta_desc']['prompt']) ? $input['seo']['meta_desc']['prompt'] : "";
            $existing['seo']['meta_desc']['model'] = isset($input['seo']['meta_desc']['model']) ? $input['seo']['meta_desc']['model'] : "";
            //$existing['seo']['meta_desc']['temperature'] = isset($input['seo']['meta_desc']['model']) ? $input['seo']['meta_desc']['temperature'] : "";
            $existing['seo']['meta_desc']['temperature'] = isset($input['seo']['meta_desc']['temperature']) ? $input['seo']['meta_desc']['temperature'] : "";
        }
        if (isset($input['seo']['image_alttext'])) {
            $existing["seo"]["image_alttext"]['image_size'] = isset($input['seo']['image_alttext']['image_size']) ? $input['seo']['image_alttext']['image_size'] : "medium";
            $existing['seo']['image_alttext']['on_save'] = isset($input['seo']['image_alttext']['on_save']) ? 1 : 0;
            $existing['seo']['image_alttext']['generate'] = isset($input['seo']['image_alttext']['generate']) ? 1 : 0;
            $existing['seo']['image_alttext']['translate'] = isset($input['seo']['image_alttext']['translate']) ? 1 : 0;
            $existing['seo']['image_alttext']['preserve'] = isset($input['seo']['image_alttext']['preserve']) ? 1 : 0;
            $existing['seo']['image_alttext']['prompt'] = isset($input['seo']['image_alttext']['prompt']) ? $input['seo']['image_alttext']['prompt'] : "";
            $existing['seo']['image_alttext']['model'] = isset($input['seo']['image_alttext']['model']) ? $input['seo']['image_alttext']['model'] : "";
            //$existing['seo']['image_alttext']['temperature'] = isset($input['seo']['image_alttext']['model']) ? $input['seo']['image_alttext']['temperature'] : "";
            $existing['seo']['image_alttext']['temperature'] = isset($input['seo']['image_alttext']['temperature']) ? $input['seo']['image_alttext']['temperature'] : "";
        }
        if (isset($input['menu'])) {
            $existing['menu']['retranslate'] = isset($input['menu']['retranslate']) ? 1 : 0;
        }
        if (isset($input['strings'])) {
            $existing['strings']['retranslate'] = isset($input['strings']['retranslate']) ? 1 : 0;
        }

        return $existing;
    }

    public function enqueue_assets($hook) {

        // Post ve Term edit ekranları için AJAX kullanılacaksa
        if (in_array($hook, ['post.php', 'post-new.php'])) {
            wp_enqueue_script('salt-ai-translator-admin', SALT_AI_TRANSLATOR_URL . 'js/admin.js', ['jquery', 'wp-i18n'], false, true);
            wp_localize_script('salt-ai-translator-admin', 'saltTranslator', [
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce'    => wp_create_nonce('salt_translate_post_manual_ajax')
            ]);
            wp_set_script_translations('salt-ai-translator-admin', 'salt-ai-translator', plugin_dir_path(__FILE__) . 'languages');
        }


        if (in_array($hook, ['edit-tags.php', 'term.php'])) {
            wp_enqueue_script('salt-ai-translator-admin', SALT_AI_TRANSLATOR_URL . 'js/admin.js', ['jquery', 'wp-i18n'], false, true);
            wp_localize_script('salt-ai-translator-admin', 'saltTranslator', [
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce'    => wp_create_nonce('salt_translate_term_manual_ajax')
            ]);
            wp_set_script_translations('salt-ai-translator-admin', 'salt-ai-translator', plugin_dir_path(__FILE__) . 'languages');
        }

        //wp_enqueue_style('salt-ai-translator-bootstrap', 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css');
        wp_enqueue_style('salt-ai-translator-style', SALT_AI_TRANSLATOR_URL . 'css/admin.css');

        if (strpos($hook, 'salt-ai-translator') === false) return;

        $api_keys = $this->options['api_keys'] ?? [];

        // Sayfa bazlı script yükleme
        if ($hook === 'toplevel_page_salt-ai-translator') {
            wp_enqueue_script('jquery');
            wp_enqueue_style('select2-css', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css');
            wp_enqueue_script('select2-js', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js', ['jquery'], null, true);
            wp_enqueue_script('salt-ai-translator-admin', SALT_AI_TRANSLATOR_URL . 'js/admin.js', ['wp-i18n'], false, true);
            wp_set_script_translations('salt-ai-translator-admin', 'salt-ai-translator', plugin_dir_path(__FILE__) . 'languages');
            wp_add_inline_script('salt-ai-translator-admin', file_get_contents(SALT_AI_TRANSLATOR_DIR . 'js/admin-dynamic.js'));
            wp_add_inline_script('salt-ai-translator-admin', 'window.saltTranslatorKeys = ' . json_encode($api_keys) . ';', 'before');
            wp_localize_script('salt-ai-translator-admin', 'saltTranslator', [
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce'    => wp_create_nonce('salt_ai_translator_nonce'),
                'settings'  => $this->options
            ]);
            wp_add_inline_script('salt-ai-translator-admin', "
                jQuery(document).ready(function($){
                    $('.select2').select2({ width: '100%' });
                });
            ");
        }

        if ($hook === 'salt-ai-translator_page_salt-ai-translator-posts') {
            wp_enqueue_script('salt-ai-translator-posts', SALT_AI_TRANSLATOR_URL . 'js/posts.js', ['wp-i18n'], false, true);
            wp_localize_script('salt-ai-translator-posts', 'saltTranslator', [
                'nonce' => wp_create_nonce('salt_ai_translator_nonce'),
                'settings'  => $this->options,
                'queue' => $this->container->get("manager")->check_process_queue("post")
            ]);
            wp_set_script_translations('salt-ai-translator-posts', 'salt-ai-translator', plugin_dir_path(__FILE__) . 'languages');
        }

        if ($hook === 'salt-ai-translator_page_salt-ai-translator-terms') {
            wp_enqueue_script('salt-ai-translator-terms', SALT_AI_TRANSLATOR_URL . 'js/taxonomies.js', ['wp-i18n'], false, true);
            wp_localize_script('salt-ai-translator-terms', 'saltTranslator', [
                'nonce' => wp_create_nonce('salt_ai_translator_nonce'),
                'settings'  => $this->options
            ]);
            wp_set_script_translations('salt-ai-translator-terms', 'salt-ai-translator', plugin_dir_path(__FILE__) . 'languages');
        }

        if ($hook === 'salt-ai-translator_page_salt-ai-translator-others') {
            wp_enqueue_script('salt-ai-translator-others', SALT_AI_TRANSLATOR_URL . 'js/others.js', ['wp-i18n'], false, true);
            wp_localize_script('salt-ai-translator-others', 'saltTranslator', [
                'nonce' => wp_create_nonce('salt_ai_translator_nonce'),
                'settings'  => $this->options,
            ]);
            wp_set_script_translations('salt-ai-translator-others', 'salt-ai-translator', plugin_dir_path(__FILE__) . 'languages');
        }
        if ($hook === 'salt-ai-translator_page_salt-ai-translator-export') {
            wp_enqueue_script('salt-ai-translator-export', SALT_AI_TRANSLATOR_URL . 'js/export.js', ['wp-i18n'], false, true);
            wp_localize_script('salt-ai-translator-export', 'saltTranslator', [
                'nonce' => wp_create_nonce('salt_ai_translator_nonce'),
                'settings'  => $this->options,
            ]);
            wp_set_script_translations('salt-ai-translator-export', 'salt-ai-translator', plugin_dir_path(__FILE__) . 'languages');
        }
    }
    
    // Single Post Translate Meta Box
    public function add_translate_post_meta_box() {
        $excluded_posts = $this->options['exclude_posts'] ?? [];
        $excluded_post_types = $this->options['exclude_post_types'] ?? [];

        $screens = get_post_types(['public' => true, 'show_ui' => true], 'names');
        foreach ($screens as $screen) {
            // Eğer bu post type excluded listesinde varsa geç
            if (in_array($screen, $excluded_post_types, true)) {
                continue;
            }
            add_meta_box(
                'salt_translate_meta_box',
                __('Salt Translate', 'salt-ai-translator'),
                [$this, 'render_post_translate_meta_box'],
                $screen,
                'side',
                'high'
            );
        }
    }
    public function render_post_translate_meta_box($post) {
        $integration = $this->container->get('integration');
        $excluded_posts = $this->options['exclude_posts'] ?? [];

        $is_excluded = in_array($post->ID, $excluded_posts, true);
        $is_translatable = $integration->is_translatable_post_type($post->post_type);

        if (!$is_translatable || $is_excluded) {
            echo '<p style="margin:0;">';

            if (!$is_translatable) {
                echo esc_html__('Translation is not enabled for this post type.', 'salt-ai-translator') . '<br>';
                echo sprintf(
                    esc_html__('You can enable it from the %s settings.', 'salt-ai-translator'),
                    esc_html($this->ml_plugin['name'])
                );
            } elseif ($is_excluded) {
                echo esc_html__('This post is excluded from translation.', 'salt-ai-translator');
            }

            echo '</p>';
            return;
        }

        wp_nonce_field('salt_translate_post_manual_ajax', 'salt_translate_manual_nonce');

        echo '<select id="salt_translate_lang_' . $post->ID . '" class="salt-translate-lang widefat">';
        echo ' <option value="">'. __('Select a language to translate', 'salt-ai-translator') .'</option>';
        foreach ($this->languages as $code => $label) {
            echo "<option value=\"$code\">$label</option>";
        }
        echo '</select><br><br>';

        if (($this->options['translator'] ?? '') === 'openai') {
            echo '<textarea id="salt_translate_prompt_' . $post->ID . '" class="widefat" rows="3" placeholder="' . __("Custom Prompt", "salt-ai-translator") . ' (' . __("Optional", "salt-ai-translator") . ')"></textarea><br>';
        }

        echo '<button type="button" class="button button-primary salt-translate-manual-submit mt-3" data-post-id="' . $post->ID . '">'. __("Translate", "salt-ai-translator") .'</button>';
        echo '<div class="salt-translate-response" style="margin-top: 10px;"></div>';
    }
    public function handle_translate_post_meta_box_ajax() {
        check_ajax_referer('salt_translate_post_manual_ajax', 'nonce');
        $post_id = intval($_POST['post_id'] ?? 0);
        $lang    = sanitize_text_field($_POST['language'] ?? '');
        $prompt  = sanitize_text_field($_POST['prompt'] ?? '');
        if (!$post_id || !$lang) {
            wp_send_json_error('Eksik bilgi');
        }
        try {
            $integration = $this->container->get('integration');
            $translator  = $this->container->get('translator');
            if (($this->options['translator'] ?? '') === 'openai' && $prompt && method_exists($translator, 'set_custom_prompt')) {
                $translator->set_custom_prompt($prompt);
            }
            $this->log($post_id." postunun metasından ".$lang." diline ceviri isteği geldi.");

            // Çeviri sırasında QueryCache'i sustur
            $this->suspend_query_cache();

            $translated_id = $integration->translate_post($post_id, $lang);

            // Çeviri bitti, cache'i temizle
            $this->flush_post_cache($post_id);
            if ($translated_id && $translated_id !== $post_id) {
                $this->flush_post_cache($translated_id);
            }

            $this->resume_query_cache();

            wp_send_json_success();
        } catch (Exception $e) {
            $this->resume_query_cache();
            wp_send_json_error($e->getMessage());
        }
    }





    //Single Term Translate Meta Box
    public function add_translate_term_meta_box() {
        $taxonomies = get_taxonomies(['public' => true], 'names');
        $excluded = $this->options['exclude_taxonomies'] ?? [];
        $taxonomies = array_diff($taxonomies, $excluded);
        foreach ($taxonomies as $taxonomy) {
            //add_action("{$taxonomy}_edit_form_fields", [$this, 'render_translate_term_meta_box'], 10, 2);
            add_action("{$taxonomy}_edit_form", [$this, 'render_translate_term_meta_box'], 10, 2);
        }
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }
    public function render_translate_term_meta_box($term, $taxonomy) {
        $integration = $this->container->get('integration');

        $excluded_terms = $this->options['exclude_terms'] ?? [];
        $excluded_taxonomies = $this->options['exclude_taxonomies'] ?? [];

        $is_excluded = in_array($term->term_id, $excluded_terms, true);
        $is_translatable = $integration->is_translatable_taxonomy($taxonomy);

        $languages = $this->languages ?? [];
        $translator = $this->options['translator'] ?? '';

        wp_nonce_field('salt_translate_term_manual_ajax', 'salt_translate_manual_nonce');

        echo '<div class="postbox" style="margin-top:20px;">';
            echo '<div class="postbox-header"><h2 class="handle"><span>Salt Translate</span></h2></div>';
            echo '<div class="inside">';

            if (!$is_translatable || $is_excluded) {

                echo '<p style="margin:0;">';
                if (!$is_translatable) {
                    echo esc_html__('Translation is not enabled for this post type.', 'salt-ai-translator') . '<br>';
                    echo sprintf(
                        esc_html__('You can enable it from the %s settings.', 'salt-ai-translator'),
                        esc_html($this->ml_plugin['name'])
                    );
                } elseif ($is_excluded) {
                    echo esc_html__('This post is excluded from translation.', 'salt-ai-translator');
                }
                echo '</p>';

            }else{

                echo '<select name="salt_translate_lang" class="salt-translate-lang widefat">';
                echo '<option value="">'. __('Select a language to translate', 'salt-ai-translator').'</option>';
                foreach ($this->languages as $code => $label) {
                    echo "<option value=\"$code\">$label</option>";
                }
                echo '</select><br><br>';
                
                if ($translator === 'openai') {
                    echo '<textarea name="salt_translate_prompt" class="widefat" rows="3" placeholder="'.__("Custom Prompt", "salt-ai-translator").' ('. __("Optional", "salt-ai-translator").')"></textarea><br>';
                }

                echo '<button type="button" class="button button-primary salt-translate-manual-submit mt-3" data-term-id="' . $term->term_id . '" data-taxonomy="' . esc_attr($taxonomy) . '">'.__("Translate", "salt-ai-translator").'</button>';    
                            
            }

            echo '</div>';
        echo '</div>';
    }
    public function handle_translate_term_meta_box_ajax() {
        check_ajax_referer('salt_translate_term_manual_ajax', 'nonce');
        $term_id  = intval($_POST['term_id'] ?? 0);
        $taxonomy = sanitize_text_field($_POST['taxonomy'] ?? '');
        $lang     = sanitize_text_field($_POST['language'] ?? '');
        $prompt   = sanitize_textarea_field($_POST['prompt'] ?? '');
        if (!$term_id || !$taxonomy || !$lang) {
            wp_send_json_error('Eksik bilgi');
        }
        try {
            $integration = $this->container->get('integration');
            $translator  = $this->container->get('translator');
            if (($this->options['translator'] ?? '') === 'openai' && $prompt && method_exists($translator, 'set_custom_prompt')) {
                $translator->set_custom_prompt($prompt);
            }

            $this->suspend_query_cache();
            $translated_id = $integration->translate_term($term_id, $taxonomy, $lang);
            $this->flush_term_cache($term_id);
            if ($translated_id && $translated_id !== $term_id) {
                $this->flush_term_cache($translated_id);
            }
            $this->resume_query_cache();

            wp_send_json_success('Başarılı');
        } catch (Exception $e) {
            $this->resume_query_cache();
            wp_send_json_error('Hata: ' . $e->getMessage());
        }
    }




    public function posts_page() {
        include SALT_AI_TRANSLATOR_DIR . 'admin/views/posts-ui.php';
    }
    public function terms_page() {
        include SALT_AI_TRANSLATOR_DIR . 'admin/views/terms-ui.php';
    }
    public function settings_page() {
        include SALT_AI_TRANSLATOR_DIR . 'admin/views/settings-ui.php';
    }
    public function others_page(){
        include SALT_AI_TRANSLATOR_DIR . 'admin/views/others-ui.php';
    }
    public function seo_page(){
        echo '<div class="wrap"><h1>' . esc_html__('SEO Settings', 'salt-ai-translator') . '</h1>';
        echo '<p>' . esc_html__('SEO settings will be available in a future update.', 'salt-ai-translator') . '</p></div>';
    }
    public function export_page() {
        include SALT_AI_TRANSLATOR_DIR . 'admin/views/export-ui.php';
    }


    private function render_post_row_html($post_id, $post_id_translated = null, $lang = "en") {
        $post = get_post($post_id);
        if (!$post) return '';

        $thumbnail = get_the_post_thumbnail($post_id, [60, 60]);
        $post_type = $post->post_type;
        if($this->ml_plugin['key'] == "qtranslate-xt"){
            $integration = $this->container->get('integration');
            $title = qtranxf_use($integration->default_language, $post->post_title, false, false);
            $title_translated = qtranxf_use($lang, $post->post_title, false, false);
            $permalink = get_permalink($post_id);
            $permalink = qtranxf_convertURL( $permalink, $lang);
        }
        if($this->ml_plugin['key'] == "polylang"){
            $title = $post->post_title;
            $title_translated = '—';
            if ($post_id_translated) {
                $translated = get_post($post_id_translated);
                if ($translated && !is_wp_error($translated)) {
                    $title_translated = $translated->post_title;
                    $permalink = get_permalink((int) $post_id_translated);
                }
            }
        }
        

        ob_start();
        ?>
        <tr>
            <td style="padding: 6px; vertical-align: middle;width:60px;">
                <span style="display:inline-block;width:60px;height:60px;background:#eee;text-align:center;line-height:60px;border-radius:12px;overflow:hidden;">
                <?php 
                if ($thumbnail) {
                   echo $thumbnail;
                }
                ?>
                </span>    
            </td>
            <td style="padding: 6px; vertical-align: middle;">#<?php echo esc_html($post_id); ?></td>
            <td style="padding: 6px; vertical-align: middle;white-space: nowrap; font-size:12px; font-weight:600;text-transform: uppercase;"><?php echo esc_html($post_type); ?></td>
            <td style="padding: 6px; vertical-align: middle;">
                <div style="color:#888;"><?php echo esc_html($title); ?></div>
                <strong style="color:#000;"><?php echo esc_html($title_translated); ?></strong>
            </td>
            <td style="padding: 6px; vertical-align: middle;">
    
                <a href="<?php echo esc_url($permalink); ?>" class="salt-button salt-primary" target="_blank">Visit</a>

            </td>
        </tr>
        <?php
        return ob_get_clean();
    }
    private function render_term_row_html($term_id, $term_id_translated = null, $lang = "en") {
        $term = get_term($term_id);
        if (!$term || is_wp_error($term)) return '';

        $taxonomy = $term->taxonomy;
        $integration = $this->container->get('integration');
        $default_language = $integration->default_language;

        if($this->ml_plugin['key'] == "qtranslate-xt"){
            $term_name = $integration->get_term_i18n_value($term, "name", $default_language);
            $term_name_translated = $integration->get_term_i18n_value($term, "name", $lang);
            if(empty($term_name_translated) || $term_name_translated === $term_name){
                $term_name_translated = '—';
            }
        }else{
            $term_name = $term->name;
            $term_name_translated = '—';
            if ($term_id_translated) {
                $translated = get_term($term_id_translated);
                if ($translated && !is_wp_error($translated)) {
                    $term_name_translated = $translated->name;
                }
            }
        }

        ob_start();
        ?>
        <tr>
            <td style="padding: 6px; vertical-align: middle;width:60px;">
                <span style="display:inline-block;width:60px;height:60px;background:#eee;text-align:center;line-height:60px;border-radius:12px;overflow:hidden;">
                    <span style="font-size:18px; font-weight: bold; color: #999;">T</span>
                </span>    
            </td>
            <td style="padding: 6px; vertical-align: middle;">#<?php echo esc_html($term_id); ?></td>
            <td style="padding: 6px; vertical-align: middle;white-space: nowrap; font-size:12px; font-weight:600;text-transform: uppercase;"><?php echo esc_html($taxonomy); ?></td>
            <td style="padding: 6px; vertical-align: middle;">
                <div style="color:#888;"><?php echo esc_html($term_name); ?></div>
                <strong style="color:#000;"><?php echo esc_html($term_name_translated); ?></strong>
            </td>
            <td style="padding: 6px; vertical-align: middle;">
                <?php 
                if ($term_id_translated){ 
                ?>
                <a href="<?php echo esc_url(get_term_link((int) $term_id_translated, $taxonomy)); ?>" target="_blank">Visit</a>
                <?php 
                }
                ?>
            </td>
        </tr>
        <?php
        return ob_get_clean();
    }



    public function get_untranslated_posts() {
        check_ajax_referer('salt_ai_translator_nonce', '_ajax_nonce');
        $lang = sanitize_text_field($_POST['lang'] ?? '');

        $integration = $this->container->get('integration');

        if (!$integration || !$lang) {
            wp_send_json_error('Translator veya dil seçimi eksik.');
        }

        if (!method_exists($integration, 'get_untranslated_posts')) {
            wp_send_json_error('Yöntem mevcut değil.');
        }

        $posts = $integration->get_untranslated_posts($lang);
        wp_send_json_success($posts);
    }
    public function translate_post() {
        // Güvenlik
        check_ajax_referer('salt_ai_translator_nonce', '_ajax_nonce');

        $post_id = intval($_POST['post_id'] ?? 0);
        $lang    = sanitize_text_field($_POST['lang'] ?? '');

        if (!$post_id || !$lang) {
            wp_send_json_error('Eksik parametre.');
        }

        $integration = $this->container->get('integration');

        if (!$integration || !method_exists($integration, 'translate_post')) {
            wp_send_json_error('Çeviri yöntemi bulunamadı.');
        }

        try {
            // Çeviri sırasında QueryCache'i sustur — her update_field çağrısı cache fırtınası yaratmasın
            $this->suspend_query_cache();

            $post_id_translated = $integration->translate_post($post_id, $lang);

            // Çeviri bitti, ilgili post'un cache'ini temizle
            $this->flush_post_cache($post_id);
            if ($post_id_translated && $post_id_translated !== $post_id) {
                $this->flush_post_cache($post_id_translated);
            }

            $this->resume_query_cache();

            $html = $this->render_post_row_html($post_id, $post_id_translated, $lang);
            wp_send_json_success(["html" => $html]);
        } catch (Exception $e) {
            $this->resume_query_cache();
            wp_send_json_error($e->getMessage());
        }
    }

    public function pre_autotranslate_post($data, $postarr){
        if (!empty($postarr['ID']) && $this->is_doing_translate()) {
            unset($_POST['yoast_wpseo_metadesc']);
        }
        return $data;
    }
    public function autotranslate_post($post_id, $post, $update){
        static $already_run = false;
        if ($already_run) return;
        $already_run = true;

        error_log("----------- autotranslate_post");
        
        if (!is_admin()) return;
        if (defined('DOING_AJAX') && DOING_AJAX) return;
        if (defined('DOING_CRON') && DOING_CRON) return;
        if (wp_is_post_revision($post_id)) return;
        if (get_post_status($post_id) !== 'publish') return;

        error_log("----------- autotranslate_post -> start");

        $excluded_post_types = $this->container->get("plugin")->options['exclude_post_types'] ?? [];
        $post_types = get_post_types([
            'public'   => true,
            'show_ui'  => true,
            '_builtin' => false
        ], 'names');
        $post_types = array_merge(['post', 'page'], $post_types);
        $post_types = array_diff($post_types, $excluded_post_types);
        $post_types = array_filter($post_types, function ($post_type) {
            $integration = $this->container->get('integration');
            return $integration->is_translatable_post_type($post_type);
        });

        error_log("----------- autotranslate_post -> control -> ".$post->post_type." ok:".(in_array($post->post_type, $post_types)));

        if (!in_array($post->post_type, $post_types)) return;

        error_log("------- çeviri başlaaar");

        $integration = $this->container->get("integration");
        $languages   = $integration->get_languages();
        error_log(print_r($languages, true));

        if ($languages) {
            // Çeviri sırasında QueryCache'i sustur
            $this->suspend_query_cache();

            $translated_ids = [];
            foreach ($languages as $key => $language) {
                error_log("integration->translate_post(".$post_id.", ".$key.");");
                $translated_id = $integration->translate_post($post_id, $key);
                if ($translated_id) {
                    $translated_ids[] = $translated_id;
                }
            }

            // Çeviri bitti, kaynak ve hedef post'ların cache'ini temizle
            $this->flush_post_cache($post_id);
            foreach ($translated_ids as $tid) {
                $this->flush_post_cache($tid);
            }

            $this->resume_query_cache();
        }
    }
    /*public function handle_ajax_start_post_queue() {
        check_ajax_referer('salt_ai_translator_nonce', '_ajax_nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error("Yetkisiz işlem");
        }

        $lang = sanitize_text_field($_POST['lang'] ?? '');

        if (!$lang) {
            wp_send_json_error("Dil belirtilmedi");
        }

        $data = $this->container->get("manager")->start_queue($lang, 'post');

        wp_send_json_success($data);
    }*/



    public function get_untranslated_terms() {
        check_ajax_referer('salt_ai_translator_nonce', '_ajax_nonce');
        $lang = sanitize_text_field($_POST['lang'] ?? '');

        $integration = $this->container->get('integration');

        if (!$integration || !$lang) {
            wp_send_json_error('Translator veya dil seçimi eksik.');
        }

        if (!method_exists($integration, 'get_untranslated_terms')) {
            wp_send_json_error('Yöntem mevcut değil.');
        }

        $terms = $integration->get_untranslated_terms($lang); 
        wp_send_json_success($terms);
    }
    public function translate_term() {
        check_ajax_referer('salt_ai_translator_nonce', '_ajax_nonce');

        $term_id  = intval($_POST['term_id'] ?? 0);
        $taxonomy = sanitize_text_field($_POST['taxonomy'] ?? '');
        $lang     = sanitize_text_field($_POST['lang'] ?? '');

        if (!$term_id || !$taxonomy || !$lang) {
            wp_send_json_error('Eksik parametre.');
        }

        $integration = $this->container->get('integration');

        if (!$integration || !method_exists($integration, 'translate_term')) {
            wp_send_json_error('Çeviri yöntemi bulunamadı.');
        }

        try {
            $this->suspend_query_cache();

            $term_id_translated = $integration->translate_term($term_id, $taxonomy, $lang);

            $this->flush_term_cache($term_id);
            if ($term_id_translated && $term_id_translated !== $term_id) {
                $this->flush_term_cache($term_id_translated);
            }

            $this->resume_query_cache();

            $html = $this->render_term_row_html($term_id, $term_id_translated, $lang);
            wp_send_json_success(["html" => $html]);
        } catch (Exception $e) {
            $this->resume_query_cache();
            wp_send_json_error($e->getMessage());
        }
    }
    /*public function handle_ajax_start_term_queue(): void {
        check_ajax_referer('salt_translator_nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error("Yetkisiz işlem");
        }

        $lang = sanitize_text_field($_POST['lang'] ?? '');

        if (!$lang) {
            wp_send_json_error("Dil belirtilmedi");
        }

        $this->start_queue($lang, 'term');

        wp_send_json_success("Term Çeviri kuyruğu başlatıldı");
    }*/



    public function autocomplete_posts() {
        check_ajax_referer('salt_ai_translator_nonce', 'nonce');
        $query = sanitize_text_field($_POST['q'] ?? '');
        $page = intval($_POST['page'] ?? 1);

        //$this->load_translator_class();
        $integration = $this->container->get('integration');

        if (!$integration) {
            wp_send_json_error('Translator veya dil seçimi eksik.');
        }

        if (!method_exists($integration, 'autocomplete_posts')) {
            wp_send_json_error('Yöntem mevcut değil.');
        }

        $results = $integration->autocomplete_posts($query, $page);
        wp_send_json($results);
    }
    public function autocomplete_terms() {
        check_ajax_referer('salt_ai_translator_nonce', 'nonce');
        $query = sanitize_text_field($_POST['q'] ?? '');
        $page = intval($_POST['page'] ?? 1);

        //$this->load_translator_class();
        $integration = $this->container->get('integration');

        if (!$integration) {
            wp_send_json_error('Translator veya dil seçimi eksik.');
        }

        if (!method_exists($integration, 'autocomplete_terms')) {
            wp_send_json_error('Yöntem mevcut değil.');
        }

        $results = $integration->autocomplete_terms($query, $page);
        wp_send_json($results);
    }


    public function get_untranslated_posts_terms() {
        check_ajax_referer('salt_ai_translator_nonce', '_ajax_nonce');
        $lang = sanitize_text_field($_POST['lang'] ?? '');

        $integration = $this->container->get('integration');

        if (!$integration || !$lang) {
            wp_send_json_error('Translator veya dil seçimi eksik.');
        }

        if (!method_exists($integration, 'get_untranslated_posts_terms')) {
            wp_send_json_error('Yöntem mevcut değil.');
        }

        $terms = $integration->get_untranslated_posts_terms($lang); 
        wp_send_json_success($terms);
    }



    public function translate_menu() {
        check_ajax_referer('salt_ai_translator_nonce', '_ajax_nonce');
        $lang = sanitize_text_field($_POST['lang'] ?? '');
        $retranslate = sanitize_text_field($_POST['retranslate'] ?? 0);

        $integration = $this->container->get('integration');

        if (!$integration || !$lang) {
            wp_send_json_error('Translator veya dil seçimi eksik.');
        }

        if (!method_exists($integration, 'translate_menu')) {
            wp_send_json_error('Yöntem mevcut değil.');
        }

        $data = $integration->translate_menu($lang, $retranslate); 
        wp_send_json_success($data);
    }
    public function translate_strings() {
        check_ajax_referer('salt_ai_translator_nonce', '_ajax_nonce');
        $lang = sanitize_text_field($_POST['lang'] ?? '');
        $retranslate = sanitize_text_field($_POST['retranslate'] ?? 0);

        $integration = $this->container->get('integration');

        if (!$integration || !$lang) {
            wp_send_json_error('Translator veya dil seçimi eksik.');
        }

        if (!method_exists($integration, 'translate_strings')) {
            wp_send_json_error('Yöntem mevcut değil.');
        }

        $data = $integration->translate_strings($lang, $retranslate); 
        wp_send_json_success($data);
    }
    public function translate_post_type_taxonomy() {
        check_ajax_referer('salt_ai_translator_nonce', '_ajax_nonce');
        $lang = sanitize_text_field($_POST['lang'] ?? '');

        $integration = $this->container->get('integration');

        if (!$integration || !$lang) {
            wp_send_json_error('Translator veya dil seçimi eksik.');
        }

        if (!method_exists($integration, 'translate_post_type_taxonomy')) {
            wp_send_json_error('Yöntem mevcut değil.');
        }

        $data = $integration->translate_post_type_taxonomy($lang); 
        wp_send_json_success($data);
    }   


    public function get_sitemap_urls(){
        check_ajax_referer('salt_ai_translator_nonce', '_ajax_nonce');
        $integration = $this->container->get('integration');
        $lang_source = sanitize_text_field($_POST['lang_source'] ?? '');
        $lang = sanitize_text_field($_POST['lang'] ?? '');
        $format = sanitize_text_field($_POST['format'] ?? '');

        //$integration = $this->container->get('integration');
        $seo = $this->container->get('seo');

        if (!$seo || !$lang || !$format) {
            wp_send_json_error('Translator veya dil seçimi eksik.');
        }

        if (!method_exists($seo, 'get_sitemap_urls')) {
            wp_send_json_error('Yöntem mevcut değil.');
        }

        $urls = $seo->get_sitemap_urls();
        $total = count($urls);
        $status_text = sprintf(
            __('The translations of the pages belonging to "%1$s" URL will be generated in %3$s format for the "%2$s" language.', 'salt-ai-translator'),
            $total,
            $integration->get_language_label($lang),
            $format
        );
        $results = [
            "total" => $total,
            "status_text" => $status_text,
            "posts" => $urls
        ];
        wp_send_json_success($results);
    }
    public function get_translations_by_url() {

        check_ajax_referer('salt_ai_translator_nonce', '_ajax_nonce');

        $lang        = sanitize_text_field($_POST['lang'] ?? '');
        $lang_source = sanitize_text_field($_POST['lang_source'] ?? '');
        $format      = sanitize_text_field($_POST['format'] ?? '');
        $data        = json_decode( stripslashes( $_POST['data'] ?? [] ), true );

        if (!$data || !$lang) {
            wp_send_json_error('Eksik parametre.');
        }

        $integration = $this->container->get('integration');

        if (!$integration || !method_exists($integration, 'get_post_translations') || !method_exists($integration, 'get_term_translations')) {
            wp_send_json_error('Çeviri yöntemi bulunamadı.');
        }

        $transient_key = 'salt_translations_output';
        $existing = get_transient($transient_key) ?: [];

        try {
            $translations = [];
            switch($data["type"]){
                case "post":
                    $translations = $integration->get_post_translations($data["id"], $lang_source, $lang );
                    //$html = $this->render_post_row_html($data["id"], $data["id"]);
                break;
                case "term":
                    $translations = $integration->get_term_translations($data["id"], $data["post_type"], $lang_source, $lang );
                    //$html = $this->render_term_row_html($data["id"], $data["id"]);
                break;
            }
            /*$results = [
                "translations" => $translations,
                "html" => $html
            ];*/
            $existing = array_merge($existing, $translations);
            set_transient($transient_key, $existing, HOUR_IN_SECONDS);
            wp_send_json_success();

        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }
    public function get_translations_dictionary($lang_source = "", $lang_target = "") {
        $translations = [];

        $integration = $this->container->get('integration');

        $theme_dir = get_template_directory();
        $lang_dir  = trailingslashit($theme_dir) . 'languages/';
        $domain    = defined('TEXT_DOMAIN') ? TEXT_DOMAIN : basename($theme_dir);

        require_once ABSPATH . 'wp-includes/pomo/po.php';

        $source_po = new PO();
        $source_file_path = '';

        // Kaynak dosyasını belirle:
        // Varsayılan dil kontrolü
        // Varsayılan dilin değerini $default_language_code değişkenine ataman gerekiyor.
        // Örneğin, 'tr', 'en', 'de' gibi

        if (!empty($lang_source) && $lang_source === $integration->default_language) {
            // Kaynak dil varsayılan dil ise, doğrudan POT dosyasını kullan
            $source_file_path = $lang_dir . $domain . '.pot';
            if (!file_exists($source_file_path)) {
                error_log("Template dosyası bulunamadı: " . $source_file_path);
                return [];
            }
        } elseif (!empty($lang_source)) {
            // Kaynak dil varsayılan dil değilse, PO dosyasını bulmaya çalış
            $source_pattern = $lang_dir . $lang_source . '_*.po';
            $source_files = glob($source_pattern);
            if (empty($source_files)) {
                $source_files = glob($lang_dir . $lang_source . '.po');
            }

            if (!empty($source_files)) {
                $source_file_path = $source_files[0];
            } else {
                error_log("Kaynak dil dosyası bulunamadı: " . $lang_source);
                return [];
            }
        } else {
            // lang_source belirtilmezse, doğrudan POT dosyasını kullan
            $source_file_path = $lang_dir . $domain . '.pot';
            if (!file_exists($source_file_path)) {
                error_log("Template dosyası bulunamadı: " . $source_file_path);
                return [];
            }
        }
        
        $source_po->import_from_file($source_file_path);

        // Hedef dilin PO dosyasını bul ve yükle
        $target_pattern = $lang_dir . $lang_target . '_*.po';
        $target_files = glob($target_pattern);
        if (empty($target_files)) {
            $target_files = glob($lang_dir . $lang_target . '.po');
        }
        if (empty($target_files)) {
            error_log("Hedef dil dosyası bulunamadı: " . $lang_target);
            return [];
        }
        $target_po = new PO();
        $target_po->import_from_file($target_files[0]);

        // Çevirileri oluştur
        foreach ($source_po->entries as $entry) {
            $key = $entry->singular;
            $source_text = '';
            $target_text = '';

            // Kaynak metni al
            if (!empty($entry->is_plural)) {
                $source_text = implode(', ', $entry->translations);
            } else {
                $source_text = $entry->translations[0] ?? $key;
            }

            // Hedef çeviriyi al
            $target_entry = $target_po->entries[$entry->key()] ?? null;
            if ($target_entry) {
                if (!empty($target_entry->is_plural)) {
                    $target_text = implode(', ', $target_entry->translations);
                } else {
                    $target_text = $target_entry->translations[0] ?? '';
                }
            }
            
            $translations[] = [
                $lang_source => $source_text,
                $lang_target => $target_text
            ];
        }

        return $translations;
    }
    public function export_translations_download(){
        check_ajax_referer('salt_ai_translator_nonce', '_ajax_nonce');
        $lang_source = sanitize_text_field($_POST['lang_source'] ?? '');
        if(empty($lang_source)){
            $integration = $this->container->get('integration');
            $lang_source = $integration->default_language;
        }
        $lang_target = sanitize_text_field($_POST['lang_target']);
        $format = sanitize_text_field($_POST['format'] ?? 'excel');
        $transient_key = 'salt_translations_output';
        $translations  = get_transient($transient_key) ?? [];
        if (!$translations || empty($format)) wp_die('No translations available.');

        $dictionary = $this->get_translations_dictionary($lang_source, $lang_target);
        $translations = array_merge($translations, $dictionary );
        set_transient($transient_key, $translations, HOUR_IN_SECONDS);

        $file = $this->export_translations($translations, $format, 'salt-translations-' . $lang_target);
        wp_send_json_success(["file" => $file]);
    }
    public function export_translations_download_cache(){
        check_ajax_referer('salt_ai_translator_nonce', '_ajax_nonce');
        $lang = sanitize_text_field($_POST['lang'] ?? 'en');
        $format = sanitize_text_field($_POST['format'] ?? 'excel');
        $transient_key = 'salt_translations_output';
        $translations  = get_transient($transient_key) ?? [];
        if (!$translations || empty($format)) wp_die('No translations available.');
        $file = $this->export_translations($translations, $format, 'salt-translations-' . $lang);
        wp_send_json_success(["file" => $file]);
    }
    public function sanitize_html_for_export($html) {
        // WordPress block yorumlarını temizle
        //$html = preg_replace('/<!-- wp:[\s\S]*?-->/', '', $html);

        // &nbsp; gibi HTML entity’leri dönüştür
        //$html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Fazla boşluk ve satırları temizle
        //$html = trim(preg_replace('/\n\s*\n+/', "\n\n", $html));

        return $html;
    }
    public function export_translations($data, $format = 'word', $filename = 'salt-translations') {

        $upload_dir = wp_upload_dir();
        $base_path  = trailingslashit($upload_dir['basedir']);
        $base_url   = trailingslashit($upload_dir['baseurl']);

        $file_path = $base_path . $filename;
        $file_url  = $base_url . $filename;

        if ($format === 'word') {
            $file_path .= '.docx';
            $file_url  .= '.docx';

            $phpWord = new PhpWord();
            $section = $phpWord->addSection();

            foreach ($data as $item) {
                foreach ($item as $text) {
                    if (is_array($text)) {
                        $text = implode(' ', $text);
                    }
                    
                    $text = (string) $text; // Tipi string'e dönüştür
                    $plainText = strip_tags(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

                    $section->addText($plainText);
                    $section->addTextBreak(1);
                }
            }
            
            $writer = WordIO::createWriter($phpWord, 'Word2007');
            $writer->save($file_path);

        } elseif ($format === 'excel') {

            $file_path .= '.xlsx';
            $file_url  .= '.xlsx';

            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $rowIndex = 1;

            $headerRow = array_keys($data[0]);
            $colIndex = 1;
            foreach ($headerRow as $lang) {
                $columnString = Coordinate::stringFromColumnIndex($colIndex);
                $cellCoordinate = $columnString . $rowIndex;
                
                $sheet->setCellValue($cellCoordinate, strtoupper($lang));
                $sheet->getStyle($cellCoordinate)->applyFromArray([
                    'font' => [
                        'bold' => true,
                        'color' => ['rgb' => 'FFFFFF'],
                    ],
                    'fill' => [
                        'fillType' => Fill::FILL_SOLID,
                        'startColor' => ['rgb' => '000000'],
                    ],
                ]);
                
                $colIndex++;
            }
            $rowIndex++;

            foreach ($data as $item) {
                $colIndex = 1;
                foreach ($item as $text) {
                    if (is_array($text)) {
                        $text = implode(' ', $text);
                    }
                    
                    // DOMDocument'e UTF-8 kodlamasını zorla
                    $dom = new DOMDocument();
                    @$dom->loadHTML('<?xml encoding="UTF-8">' . '<div>' . (string)$text . '</div>');

                    $richText = new RichText();
                    
                    $this->processDomNode($dom->documentElement, $richText);

                    $columnString = Coordinate::stringFromColumnIndex($colIndex);
                    $cellCoordinate = $columnString . $rowIndex;
                    $sheet->setCellValue($cellCoordinate, $richText);
                    $sheet->getStyle($cellCoordinate)->getAlignment()->setWrapText(true);
                    
                    $sheet->getColumnDimension($columnString)->setWidth(60);
                    
                    $colIndex++;
                }
                $rowIndex++;
            }

            $writer = ExcelIO::createWriter($spreadsheet, 'Xlsx');
            $writer->save($file_path);
        }

        return $file_url;
    }
    
    /**
     * HTML ağacını gezerek PhpSpreadsheet'in RichText nesnesini oluşturur.
     *
     * @param DOMNode $node İşlenecek DOM düğümü.
     * @param RichText $richText Ana RichText nesnesi.
     */
    private function processDomNode($node, &$richText) {
        if ($node->nodeType === XML_TEXT_NODE) {
            $richText->createText(html_entity_decode($node->nodeValue, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        } elseif ($node->nodeType === XML_ELEMENT_NODE) {
            $style = $this->getStyleFromTag($node->tagName);

            if ($style) {
                $run = $richText->createTextRun('');
                if (isset($style['bold']) && $style['bold']) {
                    $run->getFont()->setBold(true);
                }
                if (isset($style['size'])) {
                    $run->getFont()->setSize($style['size']);
                }
                
                $innerText = '';
                foreach ($node->childNodes as $child) {
                    $innerText .= $child->textContent;
                }
                $run->setText(html_entity_decode($innerText, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                
            } else {
                foreach ($node->childNodes as $child) {
                    $this->processDomNode($child, $richText);
                }
            }
            
            if ($node->tagName === 'br' || $node->tagName === 'p' || preg_match('/^h[1-6]$/', $node->tagName)) {
                 $richText->createText("\n");
            }
        }
    }
    
    /**
     * Etiket adlarına göre PhpSpreadsheet stil ayarlarını döndürür.
     *
     * @param string $tag Etiket adı (örn: 'h1', 'strong').
     * @return array|null Stil ayarları dizisi veya null.
     */
    private function getStyleFromTag($tag) {
        switch (strtolower($tag)) {
            case 'h1':
            case 'h2':
            case 'h3':
            case 'h4':
            case 'h5':
            case 'h6':
                return ['bold' => true, 'size' => 12 + (6 - intval(substr($tag, 1)))];
            case 'strong':
            case 'b':
                return ['bold' => true];
            default:
                return null;
        }
    }



    
    public function isLocalhost() {
        $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';
        $serverAddr = $_SERVER['SERVER_ADDR'] ?? '';

        $local_ips = [
            '127.0.0.1',
            '::1',
            'localhost'
        ];

        // Private network aralıkları
        $private_ranges = [
            '10.0.0.0/8',
            '172.16.0.0/12',
            '192.168.0.0/16'
        ];

        // IP'yi CIDR aralığında kontrol eden fonksiyon
        $inPrivateRange = function ($ip) use ($private_ranges) {
            foreach ($private_ranges as $cidr) {
                list($subnet, $mask) = explode('/', $cidr);
                if ((ip2long($ip) & ~((1 << (32 - $mask)) - 1)) === (ip2long($subnet) & ~((1 << (32 - $mask)) - 1))) {
                    return true;
                }
            }
            return false;
        };

        if (in_array($remoteAddr, $local_ips) || in_array($serverAddr, $local_ips)) {
            return true;
        }

        if ($inPrivateRange($remoteAddr) || $inPrivateRange($serverAddr)) {
            return true;
        }

        return false;
    }
    public function is_external($url) {
        $host = parse_url($url, PHP_URL_HOST);
        $site_host = parse_url(home_url(), PHP_URL_HOST);
        return $host && $host !== $site_host;
    }

    public function duplicate_post($post_id, $override = []) {
        if (!function_exists('get_post')) return 0;

        $post = get_post($post_id);
        if (!$post || $post->post_status === 'trash') return 0;

        // Yeni post data
        $new_post = [
            'post_title'     => $post->post_title,
            'post_content'   => $post->post_content,
            'post_excerpt'   => $post->post_excerpt,
            'post_status'    => 'draft',
            'post_type'      => $post->post_type,
            'post_author'    => $post->post_author,
            'post_category'  => wp_get_post_categories($post_id),
            'post_date'      => current_time('mysql'),
            'post_date_gmt'  => current_time('mysql', 1),
            'menu_order'     => $post->menu_order,
            'comment_status' => $post->comment_status,
            'ping_status'    => $post->ping_status,
        ];

        // Override varsa uygula
        if (!empty($override)) {
            $new_post = array_merge($new_post, $override);
        }

        $new_post_id = wp_insert_post($new_post);

        if (is_wp_error($new_post_id)) return 0;

        // ✅ Tüm taxonomy'leri kopyala
        $taxonomies = get_object_taxonomies($post->post_type);
        foreach ($taxonomies as $taxonomy) {
            $terms = wp_get_object_terms($post_id, $taxonomy, ['fields' => 'ids']);
            wp_set_object_terms($new_post_id, $terms, $taxonomy);
        }



        // 1. Tüm meta verilerini SQL ile kopyala
        /*global $wpdb;
        $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value)
                 SELECT %d, meta_key, meta_value
                 FROM {$wpdb->postmeta}
                 WHERE post_id = %d",
                $new_post_id, $post_id
            )
        );

        // 2. Post Object field’larını tekrar set et (ID olarak)
        if (function_exists('get_field_objects')) {
            $fields = get_field_objects($post_id);
            if ($fields) {
                foreach ($fields as $field_key => $field) {


                    if (!empty($field['value']) && $field['type'] === 'post_object') {
                        update_field($field_key, $field['value'], $new_post_id);
                    }
                }
            }
        }*/
        global $wpdb;
        $metas = $wpdb->get_results(
            $wpdb->prepare("SELECT meta_key, meta_value FROM $wpdb->postmeta WHERE post_id = %d", $post_id)
        );
        foreach ($metas as $meta) {
            if (is_protected_meta($meta->meta_key, 'post')) continue;
            add_post_meta($new_post_id, $meta->meta_key, maybe_unserialize($meta->meta_value));
        }



        // ✅ ACF varsa field'ları da taşı
        /*if (function_exists('get_field_objects')) {

            //$current_lang = apply_filters('acf/settings/current_language', null);
            //add_filter('acf/settings/current_language', fn() => '');

            $fields = get_field_objects($post_id);
            if ($fields) {
                error_log("duplicaed acf fields");
                error_log(print_r($fields, true));
                foreach ($fields as $field_key => $field) {
                    if (!empty($field['value']) && !is_protected_meta($field_key, 'post')) {
                        update_field($field_key, $field['value'], $new_post_id);
                    }
                }
            }

            //if ($current_lang) {
            //    add_filter('acf/settings/current_language', fn() => $current_lang);
            //}

        }*/

        // ✅ Öne çıkan görsel
        $thumbnail_id = get_post_thumbnail_id($post_id);
        if ($thumbnail_id) {
            set_post_thumbnail($new_post_id, $thumbnail_id);
        }

        return $new_post_id;
    }
    public function duplicate_term($term_id, $override = []) {
        $original = get_term($term_id);
        if (!$original || is_wp_error($original)) return 0;

        $taxonomy = $original->taxonomy;
        if (!taxonomy_exists($taxonomy)) {
            $this->log("❌ Taxonomy '{$taxonomy}' does not exist.");
            return 0;
        }

        $name = $override['name'] ?? $original->name;
        if (empty($name)) {
            $this->log("❌ Term name is empty. Cannot insert term.");
            return 0;
        }

        $slug = sanitize_title_with_dashes($override['slug'] ?? $name);
        $slug = wp_unique_term_slug($slug, (object)['taxonomy' => $taxonomy]);

        // Zaten varsa atla
        $existing = get_term_by('slug', $slug, $taxonomy);
        if ($existing) {
            $this->log("❌ Term with slug '$slug' already exists in '$taxonomy'. ID: " . $existing->term_id);
            return 0;
        }

        $args = wp_parse_args($override, [
            'description' => $original->description,
            'slug'        => $slug,
            'parent'      => $original->parent,
        ]);

        $this->log("Trying to duplicate term: $name [$taxonomy]");
        $this->log($args);

        $new_term = wp_insert_term($name, $taxonomy, $args);

        if (is_wp_error($new_term)) {
            // Belki slug çakışması — mevcut term'i bul
            $existing = get_term_by('slug', $slug, $taxonomy);
            if ($existing) {
                $this->log("♻️ Using existing term ID: " . $existing->term_id);
                $new_term_id = $existing->term_id;
            } else {
                $this->log("❌ Term insert error: " . $new_term->get_error_message());
                return 0;
            }
        } elseif (!isset($new_term['term_id']) || empty($new_term['term_id'])) {
            $this->log("❌ wp_insert_term returned invalid term_id:");
            $this->log($new_term);
            return 0;
        } else {
            $new_term_id = $new_term['term_id'];
        }

        // ACF ve Meta kopyalama
        $fields = get_fields("term_$term_id");
        if ($fields && is_array($fields)) {
            foreach ($fields as $key => $value) {
                update_field($key, $value, "term_$new_term_id");
            }
        }

        $meta = get_term_meta($term_id);
        foreach ($meta as $key => $values) {
            if (strpos($key, '_') === 0 || $key === 'slug') continue;
            foreach ($values as $value) {
                add_term_meta($new_term_id, $key, maybe_unserialize($value));
            }
        }

        return $new_term_id;
    }

    function sanitize_translated_string($text) {
        $text = trim($text);

        // Boş tag formatıysa <contact></contact> → "contact" olarak döndür
        if (preg_match('/^<(\w+)><\/\1>$/', $text, $match)) {
            return $match[1]; // "contact"
        }

        // Tag içinde içerik varsa ve tag HTML tag değilse → sadece içeriği al
        if (preg_match('/^<(\w+)>(.*?)<\/\1>$/', $text, $match)) {
            $tag = strtolower($match[1]);
            $html_tags = ['div','span','p','br','b','i','strong','em','a','ul','ol','li','h1','h2','h3','h4','h5','h6'];

            // Eğer HTML tag değilse → sadece içeriği al
            if (!in_array($tag, $html_tags)) {
                return $match[2];
            }
        }

        // Normal durumda HTML tag'larını sil
        return strip_tags($text);
    }

    public function is_doing_translate() {
        return !empty($GLOBALS['salt_ai_doing_translate']);
    }

    /**
     * Çeviri sırasında QueryCache'i susturur.
     * Her update_field/wp_update_post çağrısı cache fırtınası yaratmasın.
     */
    private function suspend_query_cache(): void {
        if ( class_exists('QueryCache') && QueryCache::$initiated ) {
            QueryCache::$cache = false;
        }
    }

    /**
     * Çeviri bittikten sonra QueryCache'i yeniden aktif eder.
     */
    private function resume_query_cache(): void {
        if ( class_exists('QueryCache') && QueryCache::$initiated ) {
            QueryCache::$cache = true;
        }
    }

    /**
     * Belirli bir post'a ait tüm QueryCache girişlerini temizler.
     */
    private function flush_post_cache( int $post_id ): void {
        if ( ! class_exists('QueryCache') || ! QueryCache::$initiated ) return;
        QueryCache::on_post_change( $post_id );
    }

    /**
     * Belirli bir term'e ait tüm QueryCache girişlerini temizler.
     */
    private function flush_term_cache( int $term_id ): void {
        if ( ! class_exists('QueryCache') || ! QueryCache::$initiated ) return;
        $term = get_term( $term_id );
        $taxonomy = ( $term && ! is_wp_error($term) ) ? $term->taxonomy : '';
        QueryCache::on_term_change( $term_id, 0, $taxonomy );
    }

    public function log($message) {
        $upload_dir = wp_upload_dir();
        $log_file = trailingslashit($upload_dir['basedir']) . 'salt-translate-logs.txt';
        $timestamp = date('Y-m-d H:i:s');

        if (is_array($message) || is_object($message)) {
            $message = print_r($message, true);
        }

        $formatted = "[{$timestamp}] {$message}\n";
        file_put_contents($log_file, $formatted, FILE_APPEND);
    }

    //Pro Features
    /*public function get_seo_description($post_id = 0){
        $seo = $this->container->get('seo');
        $seo->generate_seo_description($post_id);
    }*/

}

add_action('admin_notices', function () {
    if (isset($_GET['salt_translator_done'])) {
        echo '<div class="notice notice-success is-dismissible"><p>' . __('Content translated successfully.', 'salt-ai-translator') . '</p></div>';
    } elseif (isset($_GET['salt_translator_error'])) {
        echo '<div class="notice notice-error is-dismissible"><p>' . __('An error occurred during the translation process.', 'salt-ai-translator') . '</p></div>';
    }
});

new Salt_AI_Translator_Plugin();
