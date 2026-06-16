<?php
namespace SAT\Integration;

use SAT\Core\Container;

class WPML extends AbstractIntegration {

    public function __construct(Container $container) {
        parent::__construct($container);
        $this->defaultLanguage = apply_filters('wpml_default_language', null) ?? 'en';
        $this->currentLanguage = apply_filters('wpml_current_language', null) ?? 'en';
    }

    public function getLanguages(): array {
        $langs  = apply_filters('wpml_active_languages', null, ['skip_missing' => 0]);
        $result = [];
        if (is_array($langs)) {
            foreach ($langs as $code => $data) {
                $result[$code] = $data['native_name'] ?? $data['translated_name'] ?? $code;
            }
        }
        return $result;
    }

    public function isTranslatablePostType(string $postType): bool {
        // wpml_is_translated_post_type filter ile kontrol et
        $translated = apply_filters('wpml_is_translated_post_type', false, $postType);
        return (bool) $translated;
    }

    public function isTranslatableTaxonomy(string $taxonomy): bool {
        $translated = apply_filters('wpml_is_translated_taxonomy', false, $taxonomy);
        return (bool) $translated;
    }

    public function getPostTranslation(int $postId, string $lang): ?int {
        $id = apply_filters('wpml_object_id', $postId, get_post_type($postId), false, $lang);
        return $id ?: null;
    }

    public function getTermTranslation(int $termId, string $lang): ?int {
        $term = get_term($termId);
        if (!$term || is_wp_error($term)) return null;
        $id = apply_filters('wpml_object_id', $termId, $term->taxonomy, false, $lang);
        return $id ?: null;
    }

    public function setPostLanguage(int $postId, string $lang): void {
        do_action('wpml_set_element_language_details', [
            'element_id'    => $postId,
            'element_type'  => 'post_' . get_post_type($postId),
            'language_code' => $lang,
        ]);
    }

    public function setTermLanguage(int $termId, string $lang): void {
        $term = get_term($termId);
        if (!$term || is_wp_error($term)) return;
        do_action('wpml_set_element_language_details', [
            'element_id'    => $termId,
            'element_type'  => 'tax_' . $term->taxonomy,
            'language_code' => $lang,
        ]);
    }

    public function savePostTranslations(int $sourceId, int $translatedId, string $lang): void {
        $trid = apply_filters('wpml_element_trid', null, $sourceId, 'post_' . get_post_type($sourceId));
        if ($trid) {
            do_action('wpml_set_element_language_details', [
                'element_id'           => $translatedId,
                'element_type'         => 'post_' . get_post_type($translatedId),
                'trid'                 => $trid,
                'language_code'        => $lang,
                'source_language_code' => $this->defaultLanguage,
            ]);
        }
    }

    public function saveTermTranslations(int $sourceId, int $translatedId, string $lang): void {
        $term = get_term($sourceId);
        if (!$term || is_wp_error($term)) return;
        $trid = apply_filters('wpml_element_trid', null, $sourceId, 'tax_' . $term->taxonomy);
        if ($trid) {
            do_action('wpml_set_element_language_details', [
                'element_id'           => $translatedId,
                'element_type'         => 'tax_' . $term->taxonomy,
                'trid'                 => $trid,
                'language_code'        => $lang,
                'source_language_code' => $this->defaultLanguage,
            ]);
        }
    }

    public function translatePost(int $postId, string $lang): int {
        if (!$this->isTranslatablePostType(get_post_type($postId))) {
            throw new \RuntimeException("Post type '" . get_post_type($postId) . "' is not translatable in WPML settings.");
        }

        $GLOBALS['sat_doing_translate'] = true;
        try {
            $translatedId = $this->getPostTranslation($postId, $lang);
            if (!$translatedId) {
                $translatedId = $this->duplicatePost($postId);
                if (!$translatedId) throw new \RuntimeException("Could not duplicate post #{$postId}.");
                $this->setPostLanguage($translatedId, $lang);
                $this->savePostTranslations($postId, $translatedId, $lang);
            }

            $source  = get_post($postId);
            if (!$source) throw new \RuntimeException("Source post #{$postId} not found.");

            // Context set et — log'a doğru bilgi yazılsın
            $translator = $this->container->get('translator');
            if ($translator && method_exists($translator, 'setContext')) {
                $translator->setContext([
                    'object_type' => $source->post_type ?: 'post',
                    'object_id'   => $postId,
                    'source_lang' => $this->defaultLanguage,
                    'target_lang' => $lang,
                    'field_name'  => 'title',
                ]);
            }

            $translator && method_exists($translator, 'setContext') && $translator->setContext(['field_name' => 'title']);
            $title = $this->translateText($source->post_title, $lang);

            $translator && method_exists($translator, 'setContext') && $translator->setContext(['field_name' => 'content']);
            $content = has_blocks($source->post_content)
                ? $this->translateBlocks($source->post_content, $lang)
                : $this->translateText($source->post_content, $lang);

            $translateSlugs = $this->container->get('settings')->get('translate_slugs', 0);
            $updateData = [
                'ID'           => $translatedId,
                'post_title'   => $title,
                'post_content' => wp_slash($content),
                'post_status'  => 'publish',
            ];
            if ($translateSlugs) $updateData['post_name'] = sanitize_title($title);
            wp_update_post($updateData);

            $this->syncTaxonomies($postId, $translatedId, $lang);
            $acfFields = $this->translateAcfFields($postId, $lang);
            foreach ($acfFields as $key => $value) update_field($key, $value, $translatedId);
        } finally {
            $GLOBALS['sat_doing_translate'] = false;
        }
        return $translatedId;
    }

    public function translateTerm(int $termId, string $taxonomy, string $lang): int {
        $GLOBALS['sat_doing_translate'] = true;
        try {
            $translatedId = $this->getTermTranslation($termId, $lang);
            $term = get_term($termId, $taxonomy);
            if (!$term || is_wp_error($term)) return 0;

            $name = $this->translateText($term->name, $lang);
            $desc = $term->description ? $this->translateText($term->description, $lang) : '';

            if (!$translatedId) {
                $translatedId = $this->duplicateTerm($termId, $taxonomy, ['name' => $name, 'description' => $desc, 'lang' => $lang]);
                if (!$translatedId) return 0;
                $this->setTermLanguage($translatedId, $lang);
                $this->saveTermTranslations($termId, $translatedId, $lang);
            } else {
                wp_update_term($translatedId, $taxonomy, ['name' => $name, 'description' => $desc]);
            }
        } finally {
            $GLOBALS['sat_doing_translate'] = false;
        }
        return $translatedId;
    }

    public function getUntranslatedPostIds(string $lang, array $postTypes = [], int $limit = -1): array {
        if (empty($postTypes)) {
            $postTypes = array_values(array_filter(
                array_keys(get_post_types(['public' => true])),
                [$this, 'isTranslatablePostType']
            ));
        }
        if (empty($postTypes)) return [];

        // WPML: default dildeki post'ları al (do_shortcode=false ile filter bypass)
        $ids = get_posts([
            'post_type'        => $postTypes,
            'post_status'      => 'publish',
            'posts_per_page'   => $limit,
            'fields'           => 'ids',
            'suppress_filters' => false,
            'lang'             => $this->defaultLanguage, // WPML lang query var
        ]);

        return array_values(array_filter($ids, fn($id) => !$this->getPostTranslation($id, $lang)));
    }

    public function getUntranslatedTermIds(string $lang, array $taxonomies = [], int $limit = -1): array {
        if (empty($taxonomies)) $taxonomies = get_taxonomies(['public' => true]);
        $terms = get_terms(['taxonomy' => array_values($taxonomies), 'hide_empty' => false, 'number' => $limit > 0 ? $limit : 0]);
        if (is_wp_error($terms)) return [];
        return array_filter(array_column($terms, 'term_id'), fn($id) => !$this->getTermTranslation($id, $lang));
    }

    public function isMediaTranslationEnabled(): bool { return false; }
}
