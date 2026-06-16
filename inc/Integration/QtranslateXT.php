<?php
namespace SAT\Integration;

use SAT\Core\Container;

class QtranslateXT extends AbstractIntegration {

    public function __construct(Container $container) {
        parent::__construct($container);
        global $q_config;
        $this->defaultLanguage = $q_config['default_language'] ?? 'en';
        $this->currentLanguage = function_exists('qtranxf_getLanguage') ? qtranxf_getLanguage() : 'en';
    }

    public function getLanguages(): array {
        global $q_config;
        $langs  = $q_config['enabled_languages'] ?? [];
        $names  = $q_config['language_name'] ?? [];
        $result = [];
        foreach ($langs as $code) {
            $result[$code] = $names[$code] ?? $code;
        }
        return $result;
    }

    public function isTranslatablePostType(string $postType): bool { return true; }
    public function isTranslatableTaxonomy(string $taxonomy): bool { return true; }

    public function getPostTranslation(int $postId, string $lang): ?int {
        // qTranslate-XT stores all languages in the same post
        return $postId;
    }

    public function getTermTranslation(int $termId, string $lang): ?int {
        return $termId;
    }

    public function setPostLanguage(int $postId, string $lang): void {}
    public function setTermLanguage(int $termId, string $lang): void {}
    public function savePostTranslations(int $sourceId, int $translatedId, string $lang): void {}
    public function saveTermTranslations(int $sourceId, int $translatedId, string $lang): void {}

    public function translatePost(int $postId, string $lang): int {
        $GLOBALS['sat_doing_translate'] = true;
        try {
            $post = get_post($postId);
            if (!$post) return 0;

            // qTranslate stores content as [:en]English[:de]German[:]
            $title   = $this->buildQtranslateField($post->post_title, $lang);
            $content = $this->buildQtranslateField($post->post_content, $lang);
            $excerpt = $post->post_excerpt ? $this->buildQtranslateField($post->post_excerpt, $lang) : '';

            wp_update_post([
                'ID'           => $postId,
                'post_title'   => $title,
                'post_content' => wp_slash($content),
                'post_excerpt' => $excerpt,
            ]);
        } finally {
            $GLOBALS['sat_doing_translate'] = false;
        }
        return $postId;
    }

    public function translateTerm(int $termId, string $taxonomy, string $lang): int {
        $GLOBALS['sat_doing_translate'] = true;
        try {
            $term = get_term($termId, $taxonomy);
            if (!$term || is_wp_error($term)) return 0;

            $name = $this->buildQtranslateField($term->name, $lang);
            $desc = $term->description ? $this->buildQtranslateField($term->description, $lang) : '';

            wp_update_term($termId, $taxonomy, ['name' => $name, 'description' => $desc]);
        } finally {
            $GLOBALS['sat_doing_translate'] = false;
        }
        return $termId;
    }

    private function buildQtranslateField(string $value, string $targetLang): string {
        // Mevcut qtranslate bloklarını parse et
        $existing = [];
        // [:]  closing tag'lerini temizle, sonra parse et
        $clean = preg_replace('/\[:\]/', '', $value);
        if (preg_match_all('/\[:([a-z]{2})\](.*?)(?=\[:[a-z]{2}\]|$)/s', $clean, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $existing[$m[1]] = trim($m[2]);
            }
        }

        // Default dil metnini kaynak olarak al
        $defaultText = $existing[$this->defaultLanguage] ?? '';
        if (empty($defaultText)) {
            // Qtranslate formatı yoksa düz metin al
            $defaultText = preg_replace('/\[:[a-z]{2}\].*?\[:\]/s', '', $value);
            $defaultText = strip_tags(trim($defaultText));
        }
        if (empty($defaultText)) return $value; // Çevrilecek bir şey yok

        // Hedef dile çevir
        $translated = $this->translateText($defaultText, $targetLang);
        $existing[$targetLang] = $translated;

        // qtranslate formatında yeniden oluştur
        $result = '';
        foreach ($existing as $lang => $text) {
            if (!empty($text)) {
                $result .= "[:{$lang}]{$text}";
            }
        }
        return $result . '[:]';
    }

    public function getUntranslatedPostIds(string $lang, array $postTypes = [], int $limit = -1): array {
        // qTranslate: all posts exist, check if lang translation is empty
        $args = [
            'post_type'      => !empty($postTypes) ? $postTypes : 'any',
            'post_status'    => 'publish',
            'posts_per_page' => $limit,
            'fields'         => 'ids',
        ];
        $ids = get_posts($args);
        $untranslated = [];
        foreach ($ids as $id) {
            $post = get_post($id);
            if ($post && !preg_match('/\[:' . $lang . '\]/', $post->post_title)) {
                $untranslated[] = $id;
            }
        }
        return $untranslated;
    }

    public function getUntranslatedTermIds(string $lang, array $taxonomies = [], int $limit = -1): array {
        $terms = get_terms([
            'taxonomy'   => !empty($taxonomies) ? $taxonomies : get_taxonomies(['public' => true]),
            'hide_empty' => false,
            'number'     => $limit > 0 ? $limit : 0,
        ]);
        if (is_wp_error($terms)) return [];
        $untranslated = [];
        foreach ($terms as $term) {
            if (!preg_match('/\[:' . $lang . '\]/', $term->name)) {
                $untranslated[] = $term->term_id;
            }
        }
        return $untranslated;
    }
}
