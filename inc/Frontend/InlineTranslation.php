<?php
namespace SAT\Frontend;

use SAT\Core\Container;

/**
 * Salt AI Translator — Inline Translation
 *
 * Text selection → popup → 3 AI alternatives → clipboard / apply.
 * Frontend + admin bar toggle.
 *
 * @version 1.0.0
 * @changelog
 *   1.0.0 - 2026-06-25
 *     - Add: Frontend text selection → floating popup with 3 AI translation alternatives
 *     - Add: Each alternative has a copy-to-clipboard button
 *     - Add: Target language detected from current page (Polylang/WPML context)
 *     - Add: wp_ajax_nopriv blocked — requires manage_options
 *     - Add: AJAX endpoint: sat_inline_translate → 3 alternatives from AbstractTranslator
 *     - Add: sat-inline CSS/JS enqueued on frontend when user is logged in + manage_options
 */
class InlineTranslation {

    private Container $container;

    public function __construct(Container $container) {
        $this->container = $container;
    }

    public function register(): void {
        if (!is_user_logged_in() || !current_user_can('manage_options')) return;

        add_action('wp_enqueue_scripts',   [$this, 'enqueueAssets']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);

        // AJAX — only for logged-in users
        add_action('wp_ajax_sat_inline_translate', [$this, 'handleTranslate']);
    }

    public function enqueueAssets(): void {
        $integration = $this->container->get('integration');
        if (!$integration) return;

        wp_enqueue_style(
            'sat-inline',
            SAT_URL . 'assets/css/inline-translation.css',
            [],
            SAT_VERSION
        );

        wp_enqueue_script(
            'sat-inline',
            SAT_URL . 'assets/js/inline-translation.js',
            ['jquery'],
            SAT_VERSION,
            true
        );

        // Detect target language from current page context
        $currentLang = $this->detectCurrentLanguage($integration);
        $languages   = $integration->getLanguages();
        $default     = $integration->getDefaultLanguage();

        wp_localize_script('sat-inline', 'satFrontend', [
            'nonce'       => wp_create_nonce('sat_nonce'),
            'ajaxUrl'     => admin_url('admin-ajax.php'),
            'postId'      => (int) (get_the_ID() ?: 0),
            'languages'   => $languages,
            'defaultLang' => $default,
            'currentLang' => $currentLang,
            'adminUrl'    => admin_url('admin.php?page=sat'),
            'canTranslate'=> true,
        ]);
    }

    /**
     * AJAX handler — returns 3 translation alternatives for the selected text.
     */
    public function handleTranslate(): void {
        check_ajax_referer('sat_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized', 403);
        }

        $text   = sanitize_textarea_field(wp_unslash($_POST['text'] ?? ''));
        $lang   = sanitize_key($_POST['lang'] ?? '');
        $count  = min((int) ($_POST['count'] ?? 3), 5); // max 5

        if (!$text || !$lang) {
            wp_send_json_error('Missing text or lang');
        }

        $translator = $this->container->get('translator');
        if (!$translator) {
            wp_send_json_error('No translator configured');
        }

        $settings = $this->container->get('settings');
        $model    = $settings->get('model', 'gpt-4o-mini');
        $prompt   = $settings->get('prompt', '');

        try {
            $alternatives = [];
            $exclude      = [];

            for ($i = 0; $i < $count; $i++) {
                // Build exclusion instruction for variety
                $excludeNote = '';
                if (!empty($exclude)) {
                    $excludeNote = "\nDo NOT use these translations: " . implode(', ', array_map(fn($e) => '"' . $e . '"', $exclude));
                }

                $customPrompt = "You are a professional translator. Translate the following text to {$lang}. "
                    . "Provide a natural, idiomatic translation. Return ONLY the translated text, nothing else."
                    . $excludeNote
                    . ($prompt ? "\n" . $prompt : '');

                $result = $translator->translate($text, $lang, $customPrompt);

                if ($result && $result !== $text) {
                    $alternatives[] = $result;
                    $exclude[]      = $result;
                }
            }

            if (empty($alternatives)) {
                wp_send_json_error('Translation failed — check API key and settings');
            }

            // Deduplicate
            $alternatives = array_values(array_unique($alternatives));

            wp_send_json_success([
                'alternatives' => $alternatives,
                'source_text'  => $text,
                'lang'         => $lang,
            ]);

        } catch (\Throwable $e) {
            wp_send_json_error($e->getMessage());
        }
    }

    /**
     * Detect the current page language from Polylang/WPML.
     */
    private function detectCurrentLanguage($integration): string {
        // Polylang
        if (function_exists('pll_current_language')) {
            return pll_current_language() ?: $integration->getDefaultLanguage();
        }

        // WPML
        if (defined('ICL_LANGUAGE_CODE')) {
            return ICL_LANGUAGE_CODE ?: $integration->getDefaultLanguage();
        }

        return $integration->getDefaultLanguage();
    }
}
