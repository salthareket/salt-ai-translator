<?php
namespace SAT\Frontend;

use SAT\Core\Container;

/**
 * Salt AI Translator — Admin Bar Frontend Integration
 *
 * Adds "Translate Page" dropdown + "Inline Mode" toggle to the WP admin bar.
 * Works on both frontend and admin pages.
 *
 * @version 1.0.0
 * @changelog
 *   1.0.0 - 2026-06-25
 *     - Add: "Translate Page" admin bar menu — triggers full page translation for each available lang
 *     - Add: "Inline Mode" toggle — enables/disables text-selection inline translation UI
 *     - Add: Admin bar only shown when user has 'manage_options' capability
 *     - Add: Current page post ID detection (frontend + admin)
 *     - Add: Inline mode state persisted via localStorage
 */
class AdminBar {

    private Container $container;

    public function __construct(Container $container) {
        $this->container = $container;
    }

    public function register(): void {
        // Show admin bar items to logged-in users with manage_options
        if (!is_user_logged_in() || !current_user_can('manage_options')) return;

        add_action('admin_bar_menu', [$this, 'addMenuItems'], 200);
        add_action('wp_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
    }

    /**
     * Add "Salt Translate" node + children to the admin bar.
     */
    public function addMenuItems(\WP_Admin_Bar $bar): void {
        $integration = $this->container->get('integration');
        if (!$integration) return;

        $languages = $integration->getLanguages();
        $default   = $integration->getDefaultLanguage();
        if (count($languages) < 2) return;

        // Detect current post ID
        $postId = $this->getCurrentPostId();

        // Root node
        $bar->add_node([
            'id'    => 'sat-translate',
            'title' => '<span class="ab-icon dashicons dashicons-translation" style="margin-top:2px;"></span>'
                     . '<span class="ab-label">Translate</span>',
            'href'  => false,
            'meta'  => ['class' => 'menupop'],
        ]);

        // "Translate Page" group
        $bar->add_group([
            'id'     => 'sat-translate-page-group',
            'parent' => 'sat-translate',
        ]);

        if ($postId) {
            foreach ($languages as $code => $label) {
                if ($code === $default) continue;
                $bar->add_node([
                    'id'     => 'sat-translate-to-' . $code,
                    'parent' => 'sat-translate-page-group',
                    'title'  => '🌐 Translate to ' . esc_html(strtoupper($code)),
                    'href'   => '#',
                    'meta'   => [
                        'class'           => 'sat-ab-translate-page',
                        'data-post-id'    => $postId,
                        'data-lang'       => $code,
                        'title'           => 'Translate this page to ' . esc_attr($label),
                    ],
                ]);
            }
        } else {
            $bar->add_node([
                'id'     => 'sat-translate-no-post',
                'parent' => 'sat-translate-page-group',
                'title'  => '<em style="opacity:.6;font-size:12px;">No translatable page detected</em>',
                'href'   => false,
            ]);
        }

        // Separator
        $bar->add_group([
            'id'     => 'sat-translate-tools-group',
            'parent' => 'sat-translate',
            'meta'   => ['class' => 'ab-sub-secondary'],
        ]);

        // Inline Mode toggle
        $bar->add_node([
            'id'     => 'sat-inline-toggle',
            'parent' => 'sat-translate-tools-group',
            'title'  => '<span id="sat-inline-toggle-label">✏️ Inline Mode: <strong>OFF</strong></span>',
            'href'   => '#',
            'meta'   => ['class' => 'sat-ab-inline-toggle'],
        ]);

        // Link to admin
        $bar->add_node([
            'id'     => 'sat-admin-link',
            'parent' => 'sat-translate-tools-group',
            'title'  => '⚙️ Salt AI Translator',
            'href'   => admin_url('admin.php?page=sat'),
        ]);
    }

    /**
     * Enqueue admin bar styles + inline translation bootstrap data.
     * The heavy inline-translation JS/CSS is loaded by InlineTranslation class separately.
     */
    public function enqueueAssets(): void {
        $integration = $this->container->get('integration');
        if (!$integration) return;

        $languages = $integration->getLanguages();
        $default   = $integration->getDefaultLanguage();
        $postId    = $this->getCurrentPostId();

        // Localize sat_frontend for admin bar JS (inline-translation.js uses this)
        wp_localize_script('sat-inline', 'satFrontend', [
            'nonce'       => wp_create_nonce('sat_nonce'),
            'ajaxUrl'     => admin_url('admin-ajax.php'),
            'postId'      => $postId,
            'languages'   => $languages,
            'defaultLang' => $default,
            'adminUrl'    => admin_url('admin.php?page=sat'),
            'canTranslate'=> (bool) $postId,
        ]);
    }

    /**
     * Detect the current post ID from various contexts.
     */
    private function getCurrentPostId(): int {
        // Classic admin post edit screen
        if (is_admin()) {
            $screen = function_exists('get_current_screen') ? get_current_screen() : null;
            if ($screen && in_array($screen->base, ['post', 'page'])) {
                return (int) ($_GET['post'] ?? $_POST['post_ID'] ?? 0);
            }
            return 0;
        }

        // Frontend
        $id = get_the_ID();
        if ($id) return (int) $id;

        // WooCommerce single product fallback
        if (function_exists('is_product') && is_product()) {
            global $post;
            return $post ? (int) $post->ID : 0;
        }

        return 0;
    }
}
