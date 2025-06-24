<?php

use SaltAI\Core\ServiceContainer;

/**
 * Plugin Name: Salt AI Translator
 * Text Domain: salt-ai-translator
 * Description: Otomatik çok dilli çeviri sistemi. OpenAI, DeepL vb. destekler.
 * Version: 1.0.0
 * Author: Tolga Koçak
 */

if (!defined('ABSPATH')) exit;

define('SALT_AI_TRANSLATOR_PREFIX', 'salt_ai_translator');
define('SALT_AI_TRANSLATOR_DIR', plugin_dir_path(__FILE__));
define('SALT_AI_TRANSLATOR_URL', plugin_dir_url(__FILE__));

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

         add_action('plugins_loaded', [$this, 'initialize_services']);
         //add_action('init', [$this, 'initialize_services']);

        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);

        add_action('wp_ajax_get_untranslated_posts', [$this, 'get_untranslated_posts']);
        add_action('wp_ajax_translate_post', [$this, 'translate_post']);

        add_action('wp_ajax_get_untranslated_terms', [$this, 'get_untranslated_terms']);
        add_action('wp_ajax_translate_term', [$this, 'translate_term']);

        add_action('wp_ajax_get_untranslated_posts_terms', [$this, 'get_untranslated_posts_terms']);

        add_action('wp_ajax_translate_menu', [$this, 'translate_menu']);
        add_action('wp_ajax_translate_strings', [$this, 'translate_strings']);

        add_action('wp_ajax_salt_autocomplete_posts', [$this, 'autocomplete_posts']);
        add_action('wp_ajax_salt_autocomplete_terms', [$this, 'autocomplete_terms']);

        add_action('add_meta_boxes', [$this, 'add_translate_post_meta_box']);
        add_action('wp_ajax_salt_translate_post_manual_ajax', [$this, 'handle_translate_post_meta_box_ajax']);
        
        add_action('admin_init', [$this, 'add_translate_term_meta_box']);
        add_action('wp_ajax_salt_translate_term_manual_ajax', [$this, 'handle_translate_term_meta_box_ajax']);

        add_action('admin_head', function () {
            $pages = ["salt-ai-translator", 'salt-ai-translator-posts', 'salt-ai-translator-terms', 'salt-ai-translator-others' ];
            if (isset($_GET['page']) && in_array($_GET['page'], $pages)) {
                remove_all_actions('admin_notices');
                remove_all_actions('all_admin_notices');
            }
        });
    }

    private function get_default_options() {
        return [
            'api_keys'             => ['openai' => []],
            'translator'           => '',
            'prompt'               => '',
            'model'                => '',
            'temperature'          => '0.3',
            'retranslate_existing' => 0,
            'auto_translate'       => 0,
            'exclude_post_types'   => [],
            'exclude_taxonomies'   => [],
            'exclude_posts'        => [],
            'exclude_terms'        => [],
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
                'retranslate' => false
            ],
            'strings' => [
                'retranslate' => false
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

        if (!isset($this->container)) {
            return;
        }

        $container = $this->container;
        $plugin    = $this;

        /**
         * 1. ML Plugin (Polylang / qTranslate XT)
         */
        foreach ($this->supported_ml_plugins as $plugin_slug => $plugin_data) {
            if (is_plugin_active($plugin_slug)) {
                $this->ml_plugin = $plugin_data;
                $integration_file = SALT_AI_TRANSLATOR_DIR . 'inc/integrations/' . ($plugin_data['file'] ?? '');
                if (file_exists($integration_file)) {
                    require_once $integration_file;
                    if (class_exists('SaltAI\Integrations\Integration')) {
                        $integration = new \SaltAI\Integrations\Integration($container);
                        $container->set('integration', $integration);

                        if($this->options["seo"]["meta_desc"]["on_save"]){
                            add_action('wp_insert_post_data', [$this, 'pre_autotranslate_post'], 999, 2);
                        }

                        if($this->options["auto_translate"]){
                            add_action('save_post', [ $this, 'autotranslate_post'], 8, 3);
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
        if ($translator && file_exists(SALT_AI_TRANSLATOR_DIR . "inc/translator/{$translator}.php")) {
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
                if (file_exists($seo_file)) {
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

    public function admin_menu() {
        add_menu_page('Salt AI Translator', 'Salt AI Translator', 'manage_options', 'salt-ai-translator', [$this, 'settings_page'], 'dashicons-translation', 56);
        add_submenu_page('salt-ai-translator', 'Posts', 'Posts', 'manage_options', 'salt-ai-translator-posts', [$this, 'posts_page']);
        add_submenu_page('salt-ai-translator', 'Terms', 'Terms', 'manage_options', 'salt-ai-translator-terms', [$this, 'terms_page']);
        add_submenu_page('salt-ai-translator', 'Others', 'Others', 'manage_options', 'salt-ai-translator-others', [$this, 'others_page']);
        add_submenu_page('salt-ai-translator', 'SEO', 'SEO', 'manage_options', 'salt-ai-translator-seo', [$this, 'seo_page']);
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

        $existing['retranslate_existing'] = isset($input['retranslate_existing']) ? 1 : 0;
        $existing['auto_translate'] = isset($input['auto_translate']) ? 1 : 0;
        $existing['exclude_post_types'] = $input['exclude_post_types'] ?? [];
        $existing['exclude_taxonomies'] = $input['exclude_taxonomies'] ?? [];
        $existing['exclude_posts'] = array_filter(array_map('intval', $input['exclude_posts'] ?? []));
        $existing['exclude_terms'] = array_filter(array_map('intval', $input['exclude_terms'] ?? []));

        if (isset($input['seo']['meta_desc'])) {
            $existing['seo']['meta_desc']['on_content_changed'] = isset($input['seo']['meta_desc']['on_content_changed']) ? 1 : 0;
            $existing['seo']['meta_desc']['on_save'] = isset($input['seo']['meta_desc']['on_save']) ? 1 : 0;
            $existing['seo']['meta_desc']['generate'] = isset($input['seo']['meta_desc']['generate']) ? 1 : 0;
            $existing['seo']['meta_desc']['translate'] = isset($input['seo']['meta_desc']['translate']) ? 1 : 0;
            $existing['seo']['meta_desc']['preserve'] = isset($input['seo']['meta_desc']['preserve']) ? 1 : 0;
            $existing['seo']['meta_desc']['prompt'] = isset($input['seo']['meta_desc']['prompt']) ? $input['seo']['meta_desc']['prompt'] : "";
            $existing['seo']['meta_desc']['model'] = isset($input['seo']['meta_desc']['model']) ? $input['seo']['meta_desc']['model'] : "";
            $existing['seo']['meta_desc']['temperature'] = isset($input['seo']['meta_desc']['model']) ? $input['seo']['meta_desc']['temperature'] : "";
        }
        if (isset($input['seo']['image_alttext'])) {
            $existing["seo"]["image_alttext"]['image_size'] = isset($input['seo']['image_alttext']['image_size']) ? $input['seo']['image_alttext']['image_size'] : "medium";
            $existing['seo']['image_alttext']['on_save'] = isset($input['seo']['image_alttext']['on_save']) ? 1 : 0;
            $existing['seo']['image_alttext']['generate'] = isset($input['seo']['image_alttext']['generate']) ? 1 : 0;
            $existing['seo']['image_alttext']['translate'] = isset($input['seo']['image_alttext']['translate']) ? 1 : 0;
            $existing['seo']['image_alttext']['preserve'] = isset($input['seo']['image_alttext']['preserve']) ? 1 : 0;
            $existing['seo']['image_alttext']['prompt'] = isset($input['seo']['image_alttext']['prompt']) ? $input['seo']['image_alttext']['prompt'] : "";
            $existing['seo']['image_alttext']['model'] = isset($input['seo']['image_alttext']['model']) ? $input['seo']['image_alttext']['model'] : "";
            $existing['seo']['image_alttext']['temperature'] = isset($input['seo']['image_alttext']['model']) ? $input['seo']['image_alttext']['temperature'] : "";
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
            wp_set_script_translations('salt-ai-translator-admin', 'salt-ai-translator');
        }


        if (in_array($hook, ['edit-tags.php', 'term.php'])) {
            wp_enqueue_script('salt-ai-translator-admin', SALT_AI_TRANSLATOR_URL . 'js/admin.js', ['jquery', 'wp-i18n'], false, true);
            wp_localize_script('salt-ai-translator-admin', 'saltTranslator', [
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce'    => wp_create_nonce('salt_translate_term_manual_ajax')
            ]);
            wp_set_script_translations('salt-ai-translator-admin', 'salt-ai-translator');
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
            wp_set_script_translations('salt-ai-translator-admin', 'salt-ai-translator');
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
            wp_set_script_translations('salt-ai-translator-posts', 'salt-ai-translator');
        }

        if ($hook === 'salt-ai-translator_page_salt-ai-translator-terms') {
            wp_enqueue_script('salt-ai-translator-terms', SALT_AI_TRANSLATOR_URL . 'js/taxonomies.js', ['wp-i18n'], false, true);
            wp_localize_script('salt-ai-translator-terms', 'saltTranslator', [
                'nonce' => wp_create_nonce('salt_ai_translator_nonce'),
                'settings'  => $this->options
            ]);
            wp_set_script_translations('salt-ai-translator-terms', 'salt-ai-translator');
        }

        if ($hook === 'salt-ai-translator_page_salt-ai-translator-others') {
            wp_enqueue_script('salt-ai-translator-others', SALT_AI_TRANSLATOR_URL . 'js/others.js', ['wp-i18n'], false, true);
            wp_localize_script('salt-ai-translator-others', 'saltTranslator', [
                'nonce' => wp_create_nonce('salt_ai_translator_nonce'),
                'settings'  => $this->options,
            ]);
            wp_set_script_translations('salt-ai-translator-others', 'salt-ai-translator');
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
                __('Salt Translate', 'salt-ai'),
                [$this, 'render_post_translate_meta_box'],
                $screen,
                'side',
                'high'
            );
        }
    }
    public function render_post_translate_meta_box($post) {
        $excluded_posts = $this->options['exclude_posts'] ?? [];
        if (in_array($post->ID, $excluded_posts, true)) return;

        wp_nonce_field('salt_translate_post_manual_ajax', 'salt_translate_manual_nonce');
        
        echo '<select id="salt_translate_lang_' . $post->ID . '" class="salt-translate-lang widefat">';
        echo ' <option value="">'. __('Select a language to translate', 'salt-ai-translator').'</option>';
        foreach ($this->languages as $code => $label) {
            echo "<option value=\"$code\">$label</option>";
        }
        echo '</select><br><br>';

        if (($this->options['translator'] ?? '') === 'openai') {
            echo '<textarea id="salt_translate_prompt_' . $post->ID . '" class="widefat" rows="3" placeholder="'.__("Custom Prompt", "salt-ai-translator").' ('. __("Optional", "salt-ai-translator").')"></textarea><br>';
        }

        echo '<button type="button" class="button button-primary salt-translate-manual-submit mt-3" data-post-id="' . $post->ID . '">'.__("Translate", "salt-ai-translator").'</button>';
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
            //$this->load_translator_class();
            $integration = $this->container->get('integration');
            $translator  = $this->container->get('translator');
            if (($this->options['translator'] ?? '') === 'openai' && $prompt && method_exists($translator, 'set_custom_prompt')) {
                $translator->set_custom_prompt($prompt);
            }
            $this->log($post_id." postunun metasından ".$lang." diline ceviri isteği geldi.");
            $integration->translate_post($post_id, $lang);
            wp_send_json_success();
        } catch (Exception $e) {
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
        $excluded_terms = $this->options['exclude_terms'] ?? [];
        $excluded_taxonomies = $this->options['exclude_taxonomies'] ?? [];

        if (in_array($term->term_id, $excluded_terms, true)) return;
        if (in_array($taxonomy, $excluded_taxonomies, true)) return;

        $languages = $this->languages ?? [];
        $translator = $this->options['translator'] ?? '';

        wp_nonce_field('salt_translate_term_manual_ajax', 'salt_translate_manual_nonce');

        echo '<div class="postbox" style="margin-top:20px;">';
            echo '<div class="postbox-header"><h2 class="handle"><span>Salt Translate</span></h2></div>';
            echo '<div class="inside">';
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
            echo '</div>';
        echo '</div>';
    }
    public function handle_translate_term_meta_box_ajax() {
        check_ajax_referer('salt_translate_term_manual_ajax', 'nonce');
        $term_id = intval($_POST['term_id'] ?? 0);
        $taxonomy = sanitize_text_field($_POST['taxonomy'] ?? '');
        $lang = sanitize_text_field($_POST['language'] ?? '');
        $prompt = sanitize_textarea_field($_POST['prompt'] ?? '');
        if (!$term_id || !$taxonomy || !$lang) {
            wp_send_json_error('Eksik bilgi');
        }
        try {
            //$this->load_translator_class();
            $integration = $this->container->get('integration');
            $translator  = $this->container->get('translator');
            if (($this->options['translator'] ?? '') === 'openai' && $prompt && method_exists($translator, 'set_custom_prompt')) {
                $translator->set_custom_prompt($prompt);
            }
            $integration->translate_term($term_id, $taxonomy, $lang);
            wp_send_json_success('Başarılı');
        } catch (Exception $e) {
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
    }


    private function render_post_row_html($post_id, $post_id_translated = null) {
        $post = get_post($post_id);
        if (!$post) return '';

        $thumbnail = get_the_post_thumbnail($post_id, [60, 60]);
        $post_type = get_post_type($post);
        $title = get_the_title($post_id);
        $title_translated = $post_id_translated ? get_the_title($post_id_translated) : '—';

        ob_start();
        ?>
        <tr>
            <td style="padding: 6px; vertical-align: middle;width:60px;">
                <span style="display:inline-block;width:60px;height:60px;background:#eee;text-align:center;line-height:60px;border-radius:12px;overflow:hidden;">
                <?php 
                if (!$thumbnail) {
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
                <a href="<?php echo esc_url(get_permalink((int) $title_translated)); ?>" class="salt-button salt-primary" target="_blank">Visit</a>
            </td>
        </tr>
        <?php
        return ob_get_clean();
    }
    private function render_term_row_html($term_id, $term_id_translated = null) {
        $term = get_term($term_id);
        if (!$term || is_wp_error($term)) return '';

        $taxonomy = $term->taxonomy;
        $term_name = $term->name;
        $term_name_translated = '—';

        if ($term_id_translated) {
            $translated = get_term($term_id_translated);
            if ($translated && !is_wp_error($translated)) {
                $term_name_translated = $translated->name;
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
                <a href="<?php echo esc_url(get_term_link((int) $term_id_translated, $taxonomy)); ?>" target="_blank">Visit</a>
            </td>
        </tr>
        <?php
        return ob_get_clean();
    }



    public function get_untranslated_posts() {
        //$this->load_translator_class();
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

        // ($this->integration_instance zaten plugins_loaded sırasında set edilmiş olmalı)
        if (!$integration || !method_exists($integration, 'translate_post')) {
            wp_send_json_error('Çeviri yöntemi bulunamadı.');
        }

        try {
            $post_id_translated = $integration->translate_post($post_id, $lang);
            $html = $this->render_post_row_html($post_id, $post_id_translated);
            wp_send_json_success(["html" => $html]);
        } catch (Exception $e) {
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
        
        if (!is_admin()) return;
        if (defined('DOING_AJAX') && DOING_AJAX) return;
        if (defined('DOING_CRON') && DOING_CRON) return;
        if (wp_is_post_revision($post_id)) return;
        if (get_post_status($post_id) !== 'publish') return;

        $excluded_post_types = $this->container->get("plugin")->options['exclude_post_types'] ?? [];
        $post_types = get_post_types([
            'public'   => true,
            'show_ui'  => true,
            '_builtin' => false
        ], 'names');
        $post_types = array_merge(['post', 'page'], $post_types);
        $post_types = array_diff($post_types, $excluded_post_types);
        $post_types = array_filter($post_types, function ($post_type) {
            return function_exists('pll_is_translated_post_type') && pll_is_translated_post_type($post_type);
        });

        if (!in_array($post->post_type, $post_types)) return;

        $integration = $this->container->get("integration");
        $languages = $integration->get_languages();
        if($languages){
            foreach($languages as $key => $language){
                $integration->translate_post($post_id, $key);
            }
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
        //$this->load_translator_class();
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
            $term_id_translated = $integration->translate_term($term_id, $taxonomy, $lang);
            $html = $this->render_term_row_html($term_id, $term_id_translated);
            wp_send_json_success(["html" => $html]);
        } catch (Exception $e) {
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

        // ✅ Tüm meta'ları kopyala
        $metas = get_post_meta($post_id);
        foreach ($metas as $key => $values) {
            foreach ($values as $value) {
                add_post_meta($new_post_id, $key, maybe_unserialize($value));
            }
        }

        // ✅ Tüm taxonomy'leri kopyala
        $taxonomies = get_object_taxonomies($post->post_type);
        foreach ($taxonomies as $taxonomy) {
            $terms = wp_get_object_terms($post_id, $taxonomy, ['fields' => 'ids']);
            wp_set_object_terms($new_post_id, $terms, $taxonomy);
        }

        // ✅ ACF varsa field'ları da taşı
        if (function_exists('get_field_objects')) {
            $fields = get_field_objects($post_id);
            if ($fields) {
                foreach ($fields as $field_key => $field) {
                    if (!empty($field['value']) && !is_protected_meta($field_key, 'post')) {
                        update_field($field_key, $field['value'], $new_post_id);
                    }
                }
            }
        }

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

        if (!is_wp_error($new_term) && isset($new_term['term_id']) && $new_term['term_id'] > 0) {
            // işlem başarılı
        } else {
            $this->log("✅ DEBUG: Term belki zaten var, tekrar kontrol et...");
            $existing = get_term_by('slug', $slug, $taxonomy);
            if ($existing) {
                $lang_term_id = $existing->term_id;
                $this->log("♻️ Using existing term ID: $lang_term_id");
            } else {
                $this->log("❌ Term insert failed and no fallback found.");
                return 0;
            }
        }


        if (is_wp_error($new_term)) {
            $this->log("❌ Term insert error: " . $new_term->get_error_message());
            return 0;
        }

        if (!isset($new_term['term_id']) || empty($new_term['term_id'])) {
            $this->log("❌ wp_insert_term returned invalid term_id:");
            $this->log($new_term);
            return 0;
        }

        $new_term_id = $new_term['term_id'];

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








    public function is_content_changed($post){
        $current_hash = md5($post->post_content);
        $previous_hash = get_post_meta($post->ID, '_salt_translate_content_hash', true);
        if ($current_hash !== $previous_hash) {
            return update_post_meta($post->ID, '_salt_translate_content_hash', $current_hash);
        }
        return false;
    }
    public function is_doing_translate() {
        return !empty($GLOBALS['salt_ai_doing_translate']);
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


    public function meta_description($desc, $post) {
        $integration = $this->container->get('integration');
        $current_lang = $integration->current_language;
        $default_lang = $integration->default_language;

        if ($current_lang === $default_lang || is_admin()) {
            return $desc;
        }
        echo "xxx _yoast_wpseo_metadesc_{$current_lang}";
        $translated = get_post_meta($post->ID, "_yoast_wpseo_metadesc_{$current_lang}", true);
        return !empty($translated) ? $translated : $desc;
    }

    public function decode_html_entities($text) {
        $text = preg_replace('/u([0-9a-fA-F]{4})/', '\\\\u$1', $text);
        $text = json_decode('"' . $text . '"');
        return html_entity_decode($text);
    }
    public function encode_html_entities($text) {
        $text = json_encode($text, JSON_UNESCAPED_UNICODE);
        $text = substr($text, 1, -1);
        $text = preg_replace('/\\\\\\\\u([0-9a-fA-F]{4})/', '\\\\u$1', $text);
        return $text;
    }

    public function cleanHTML(string $html): array {
        // Düz metin kontrolü
        if (strip_tags($html) === $html) {
            return [
                'segments' => [trim($html)],
                'document' => null,
                'text_nodes' => null,
                'plain' => true,
            ];
        }

        // HTML parse et
        $doc = new \DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'), LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $xpath = new \DOMXPath($doc);
        $text_nodes = $xpath->query('//text()[normalize-space()]');

        $segments = [];

        foreach ($text_nodes as $node) {
            $text = trim($node->nodeValue);

            // 1. Tamamen boş ya da sadece &nbsp; gibi karakterlerse atla
            if ($text === '' || preg_match('/^\x{00A0}+$/u', $text)) {
                continue;
            }

            // 2. Shortcode gibi görünüyorsa atla (örn: [shortcode attr='x'])
            if (preg_match('/^\[.+?\]$/s', $text)) {
                continue;
            }

            // 3. E-posta adresleri ya da mailto: içeriyorsa atla
            if (filter_var($text, FILTER_VALIDATE_EMAIL) || stripos($text, 'mailto:') !== false) {
                continue;
            }

            $segments[] = $text;
        }

        return [
            'segments' => $segments,
            'document' => $doc,
            'text_nodes' => $text_nodes,
            'plain' => false,
        ];
    }

    public function replaceHTML($document, $text_nodes, array $translated_segments, bool $plain = false): string {
    if ($plain) {
        return $translated_segments[0] ?? '';
    }

    $i = 0;
    foreach ($text_nodes as $node) {
        $original = trim($node->nodeValue);

        // Aynı filtreleri burada da uygula
        if (
            $original === '' ||
            preg_match('/^\x{00A0}+$/u', $original) || // nbsp
            preg_match('/^\[.+?\]$/s', $original) || // shortcode
            filter_var($original, FILTER_VALIDATE_EMAIL) ||
            stripos($original, 'mailto:') !== false
        ) {
            continue;
        }

        if (isset($translated_segments[$i])) {
            $node->nodeValue = htmlspecialchars_decode($translated_segments[$i], ENT_QUOTES | ENT_HTML5);
        }

        $i++;
    }

    $html = $document->saveHTML();

    // body içinde sarılmışsa sadece içeriği al
    $body_start = stripos($html, '<body>');
    $body_end   = stripos($html, '</body>');

    if ($body_start !== false && $body_end !== false) {
        $body_start += strlen('<body>');
        return trim(substr($html, $body_start, $body_end - $body_start));
    }

    return trim($html);
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
