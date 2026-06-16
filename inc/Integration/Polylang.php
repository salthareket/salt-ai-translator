<?php
namespace SAT\Integration;

use SAT\Core\Container;

/**
 * Polylang Integration
 *
 * Polylang ile post, term ve medya çevirilerini yönetir.
 * translatePost() ve translateTerm() ana giriş noktaları.
 *
 * @version 1.1.0
 * @changelog
 *   1.1.0 - 2026-06-05
 *     - Add: translate_slugs settings desteği — aktifse post_name ve term slug
 *            çevirilen title'dan sanitize_title() ile üretilir
 *     - Change: translateTerm() — slug artık settings'e göre koşullu set ediliyor
 *   1.0.0 - 2026-05-XX
 *     - Add: Initial release — translatePost(), translateTerm()
 *     - Add: ACF field sync, taxonomy sync, SEO meta sync
 *     - Add: duplicatePost() ile yeni çeviri oluşturma
 *
 * ─── HOW TO USE ───────────────────────────────────────────
 *
 * // Post çevir:
 * $integration = $container->get('integration'); // Polylang instance
 * $translatedId = $integration->translatePost(123, 'de');
 *
 * // Term çevir:
 * $translatedId = $integration->translateTerm(45, 'product_cat', 'tr');
 *
 * // Slug çevirisi aktif mi kontrol et:
 * $translateSlugs = $container->get('settings')->get('translate_slugs', 0);
 *
 * ──────────────────────────────────────────────────────────
 *
 * @example
 *   // Admin sayfasından (Posts view):
 *   $.post(ajaxurl, { action: 'sat_translate_post', nonce, post_id: 123, lang: 'de' })
 *
 * @example
 *   // Translate slugs aktifken:
 *   // EN "My Product" → DE slug: "mein-produkt"
 *   // EN "My Product" → TR slug: "urunum"
 *
 * @example
 *   // Translate slugs pasifken (default):
 *   // Term slug: "kategori-name-de" (orijinal slug + "-" + lang kodu)
 */
class Polylang extends AbstractIntegration {

    public function __construct(Container $container) {
        parent::__construct($container);
        $this->defaultLanguage = function_exists('pll_default_language') ? pll_default_language() : 'en';
        $this->currentLanguage = function_exists('pll_current_language') ? pll_current_language() : 'en';
    }

    public function getLanguages(): array {
        if (!function_exists('pll_the_languages')) return [];
        $langs = pll_the_languages(['raw' => 1]);
        $result = [];
        foreach ($langs as $code => $data) {
            $result[$code] = $data['name'];
        }
        return $result;
    }

    public function isTranslatablePostType(string $postType): bool {
        return function_exists('pll_is_translated_post_type') && pll_is_translated_post_type($postType);
    }

    public function isTranslatableTaxonomy(string $taxonomy): bool {
        // WooCommerce product attribute taxonomy'leri (pa_*) — Polylang'da çevrilmese de çevirilebilir say
        if (function_exists('wc_attribute_taxonomy_name') && str_starts_with($taxonomy, 'pa_')) {
            $settings = $this->container->get('settings');
            if ($settings && $settings->get('woo.translate_attributes', 0)) {
                return true;
            }
        }
        return function_exists('pll_is_translated_taxonomy') && pll_is_translated_taxonomy($taxonomy);
    }

    public function getPostTranslation(int $postId, string $lang): ?int {
        if (!function_exists('pll_get_post')) return null;
        $id = pll_get_post($postId, $lang);
        return $id ?: null;
    }

    public function getTermTranslation(int $termId, string $lang): ?int {
        if (!function_exists('pll_get_term')) return null;
        $id = pll_get_term($termId, $lang);
        return $id ?: null;
    }

    public function setPostLanguage(int $postId, string $lang): void {
        if (function_exists('pll_set_post_language')) pll_set_post_language($postId, $lang);
    }

    public function setTermLanguage(int $termId, string $lang): void {
        if (function_exists('pll_set_term_language')) pll_set_term_language($termId, $lang);
    }

    public function savePostTranslations(int $sourceId, int $translatedId, string $lang): void {
        if (!function_exists('pll_save_post_translations')) return;
        $translations = function_exists('pll_get_post_translations') ? pll_get_post_translations($sourceId) : [];
        $translations[$lang] = $translatedId;
        pll_save_post_translations($translations);
    }

    public function saveTermTranslations(int $sourceId, int $translatedId, string $lang): void {
        if (!function_exists('pll_save_term_translations')) return;
        $translations = function_exists('pll_get_term_translations') ? pll_get_term_translations($sourceId) : [];
        $translations[$lang] = $translatedId;
        pll_save_term_translations($translations);
    }

    public function isMediaTranslationEnabled(): bool {
        if (!function_exists('pll_is_translated_post_type')) return false;
        return pll_is_translated_post_type('attachment');
    }

    public function translatePost(int $postId, string $lang): int {
        if (!$this->isTranslatablePostType(get_post_type($postId))) {
            throw new \RuntimeException(
                "Post type '" . get_post_type($postId) . "' is not translatable in Polylang settings."
            );
        }

        // Translation Lock kontrolü
        if (get_post_meta($postId, '_sat_translation_lock', true)) {
            throw new \RuntimeException("Post #{$postId} is locked for translation.");
        }

        $GLOBALS['sat_doing_translate'] = true;

        try {
            // Ensure we're translating from default language
            $sourceLang = function_exists('pll_get_post_language') ? pll_get_post_language($postId) : '';
            if ($sourceLang && $sourceLang !== $this->defaultLanguage) {
                $defaultId = $this->getPostTranslation($postId, $this->defaultLanguage);
                if ($defaultId) $postId = $defaultId;
            }

            // Get or create translated post
            $translatedId = $this->getPostTranslation($postId, $lang);
            if (!$translatedId) {
                $translatedId = $this->duplicatePost($postId);
                if (!$translatedId) {
                    throw new \RuntimeException("Could not duplicate post #{$postId} for language '{$lang}'.");
                }
                $this->setPostLanguage($translatedId, $lang);
                $this->savePostTranslations($postId, $translatedId, $lang);
            }

            $source = get_post($postId);
            if (!$source) {
                throw new \RuntimeException("Source post #{$postId} not found.");
            }

            // Translator'a context set et — log'a doğru post_id/lang yazılsın
            $translator = $this->container->get('translator');
            if ( $translator && method_exists($translator, 'setContext') ) {
                $translator->setContext([
                    'object_type' => $source->post_type ?: 'post',
                    'object_id'   => $postId,
                    'source_lang' => $this->defaultLanguage,
                    'target_lang' => $lang,
                ]);
            }

            // Translate title — field exclusion kontrolü
            $excludeTitleTypes = $this->container->get('settings')->get('exclude_title_post_types', []);
            $skipTitle         = in_array($source->post_type, $excludeTitleTypes, true);
            $translator && method_exists($translator, 'setContext') && $translator->setContext(['field_name' => 'title']);
            $title = $skipTitle
                ? $source->post_title  // API çağrısı yok — olduğu gibi kopyala
                : $this->translateText($source->post_title, $lang,
                    "This is a page/post title for post type '{$source->post_type}'. Translate naturally.");

            // Translate content
            $translator && method_exists($translator, 'setContext') && $translator->setContext(['field_name' => 'content']);
            $content = has_blocks($source->post_content)
                ? $this->translateBlocks($source->post_content, $lang)
                : $this->translateText($source->post_content, $lang);

            // Translate excerpt
            $excerpt = '';
            if (post_type_supports($source->post_type, 'excerpt') && $source->post_excerpt) {
                $translator && method_exists($translator, 'setContext') && $translator->setContext(['field_name' => 'excerpt']);
                $excerpt = $this->translateText($source->post_excerpt, $lang);
            }

            // translate_slugs ayarı aktifse slug'ı da çevir
            $translateSlugs = $this->container->get('settings')->get('translate_slugs', 0);
            $updateData = [
                'ID'           => $translatedId,
                'post_title'   => $title,
                'post_content' => $content, // wp_update_post zaten wp_slash çağırıyor
                'post_excerpt' => $excerpt,
                'post_status'  => 'publish',
            ];
            if ( $translateSlugs ) {
                $updateData['post_name'] = sanitize_title($title);
            }
            wp_update_post($updateData);
            // Sync taxonomies
            $this->syncTaxonomies($postId, $translatedId, $lang);

            // Translate ACF fields
            $acfFields = $this->translateAcfFields($postId, $lang);
            foreach ($acfFields as $key => $value) {
                update_field($key, $value, $translatedId);
            }

            // SEO
            $this->handleSeo($postId, $translatedId, $source, $lang);

        } finally {
            $GLOBALS['sat_doing_translate'] = false;
        }

        return $translatedId;
    }

    public function translateTerm(int $termId, string $taxonomy, string $lang): int {
        if (!$this->isTranslatableTaxonomy($taxonomy)) return $termId;

        $GLOBALS['sat_doing_translate'] = true;

        try {
            $sourceLang = function_exists('pll_get_term_language') ? pll_get_term_language($termId) : '';
            if ($sourceLang && $sourceLang !== $this->defaultLanguage) {
                $defaultId = $this->getTermTranslation($termId, $this->defaultLanguage);
                if ($defaultId) $termId = $defaultId;
            }

            $translatedId = $this->getTermTranslation($termId, $lang);
            $term = get_term($termId, $taxonomy);
            if (!$term || is_wp_error($term)) return 0;

            // Translator'a context set et
            $translator = $this->container->get('translator');
            if ( $translator && method_exists($translator, 'setContext') ) {
                $translator->setContext([
                    'object_type' => 'term',
                    'object_id'   => $termId,
                    'source_lang' => $this->defaultLanguage,
                    'target_lang' => $lang,
                ]);
            }

            $translator && method_exists($translator, 'setContext') && $translator->setContext(['field_name' => 'term:name']);
            $excludeNameTaxes = $this->container->get('settings')->get('exclude_name_taxonomies', []);
            $skipName         = in_array($taxonomy, $excludeNameTaxes, true);
            $name        = $skipName
                ? $term->name  // API çağrısı yok — olduğu gibi kopyala
                : $this->translateText($term->name, $lang, "Taxonomy term name for '{$taxonomy}'. Short, no HTML.");
            $translator && method_exists($translator, 'setContext') && $translator->setContext(['field_name' => 'term:description']);
            $description = $term->description ? $this->translateText($term->description, $lang) : '';
            $translateSlugs = $this->container->get('settings')->get('translate_slugs', 0);
            // translate_slugs aktifse slug'ı çeviriden üret, değilse lang suffix'li eski slug
            $slug = $translateSlugs
                ? sanitize_title($name)
                : ( $term->slug . '-' . $lang );

            if (!$translatedId) {
                $translatedId = $this->duplicateTerm($termId, $taxonomy, [
                    'name'        => $name,
                    'description' => $description,
                    'slug'        => $slug,
                    'lang'        => $lang,
                ]);
                if (!$translatedId) return 0;
                $this->setTermLanguage($translatedId, $lang);
                $this->saveTermTranslations($termId, $translatedId, $lang);
            } else {
                $termUpdate = [
                    'name'        => $name,
                    'description' => $description,
                ];
                if ( $translateSlugs ) {
                    $termUpdate['slug'] = $slug;
                }
                wp_update_term($translatedId, $taxonomy, $termUpdate);
            }

        } finally {
            $GLOBALS['sat_doing_translate'] = false;
        }

        return $translatedId;
    }

    public function getUntranslatedPostIds(string $lang, array $postTypes = [], int $limit = -1): array {
        global $wpdb;

        if (empty($postTypes)) {
            $postTypes = get_post_types(['public' => true], 'names');
            $postTypes = array_filter($postTypes, [$this, 'isTranslatablePostType']);
        }

        if (empty($postTypes)) return [];
        $args = [
            'post_type'      => array_values($postTypes),
            'post_status'    => 'publish',
            'posts_per_page' => $limit,
            'fields'         => 'ids',
            'lang'           => $this->defaultLanguage,
        ];

        $allIds = get_posts($args);
        $untranslated = [];

        foreach ($allIds as $id) {
            // Translation Lock kontrolü — locked post'ları atla
            if (get_post_meta($id, '_sat_translation_lock', true)) continue;
            if (!$this->getPostTranslation($id, $lang)) {
                $untranslated[] = $id;
            }
        }

        return $untranslated;
    }

    public function getUntranslatedTermIds(string $lang, array $taxonomies = [], int $limit = -1): array {
        if (empty($taxonomies)) {
            $taxonomies = get_taxonomies(['public' => true]);
            $taxonomies = array_filter($taxonomies, [$this, 'isTranslatableTaxonomy']);
        }

        if (empty($taxonomies)) return [];

        $terms = get_terms([
            'taxonomy'   => array_values($taxonomies),
            'hide_empty' => false,
            'lang'       => $this->defaultLanguage,
            'number'     => $limit > 0 ? $limit : 0,
        ]);

        if (is_wp_error($terms)) return [];

        $untranslated = [];
        foreach ($terms as $term) {
            if (!$this->getTermTranslation($term->term_id, $lang)) {
                $untranslated[] = $term->term_id;
            }
        }

        return $untranslated;
    }

    private function handleSeo(int $sourceId, int $translatedId, \WP_Post $source, string $lang): void {
        $seo = $this->container->get('seo');
        if (!$seo) return;

        $settings   = $this->container->get('settings')->get('seo', []);
        $translator = $this->container->get('translator');

        // Meta description
        if (!empty($settings['meta_desc']['generate']) && $translator && method_exists($translator, 'generateMetaDescription')) {
            $translator->setContext(['field_name' => 'seo:meta_desc']);
            $desc = $translator->generateMetaDescription($source->post_title, $source->post_content, $lang);
            if ($desc) $seo->updateMetaDescription($translatedId, $desc);
        } elseif (!empty($settings['meta_desc']['translate'])) {
            $existing = $seo->getMetaDescription($sourceId);
            if ($existing) {
                $translator && method_exists($translator, 'setContext') && $translator->setContext(['field_name' => 'seo:meta_desc']);
                $translated = $this->translateText($existing, $lang);
                $seo->updateMetaDescription($translatedId, $translated);
            }
        }

        // SEO title
        if (!empty($settings['seo_title']['translate'])) {
            $seoTitle = $seo->getSeoTitle($sourceId);
            if ($seoTitle) {
                $translator && method_exists($translator, 'setContext') && $translator->setContext(['field_name' => 'seo:title']);
                $seo->updateSeoTitle($translatedId, $this->translateText($seoTitle, $lang));
            }
        }
    }
}
