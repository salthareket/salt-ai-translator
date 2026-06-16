<?php
namespace SAT\Integration;

use SAT\Core\Container;

abstract class AbstractIntegration implements IntegrationInterface {

    protected Container $container;
    protected string $defaultLanguage = 'en';
    protected string $currentLanguage = 'en';

    public function __construct(Container $container) {
        $this->container = $container;
    }

    public function getDefaultLanguage(): string { return $this->defaultLanguage; }
    public function getCurrentLanguage(): string { return $this->currentLanguage; }
    public function getLanguageLabel(string $code): string {
        return $this->getLanguages()[$code] ?? $code;
    }
    public function isMediaTranslationEnabled(): bool { return false; }

    /**
     * Post'u duplicate eder (çeviri için temel kopya)
     * Kaynak post'un status'ünü korur — hardcoded 'publish' değil.
     */
    protected function duplicatePost(int $postId): int {
        $post = get_post($postId);
        if (!$post) return 0;

        $newId = wp_insert_post([
            'post_title'   => $post->post_title,
            'post_content' => $post->post_content,
            'post_excerpt' => $post->post_excerpt,
            'post_status'  => $post->post_status, // kaynak status korunuyor
            'post_type'    => $post->post_type,
            'post_author'  => $post->post_author,
            'menu_order'   => $post->menu_order,
        ]);

        if (is_wp_error($newId)) return 0;

        // Copy post meta (ACF fields etc.)
        $metas = get_post_meta($postId);
        foreach ($metas as $key => $values) {
            if (in_array($key, ['_edit_lock', '_edit_last'])) continue;
            foreach ($values as $value) {
                add_post_meta($newId, $key, maybe_unserialize($value));
            }
        }

        return $newId;
    }

    /**
     * Term'i duplicate eder
     */
    protected function duplicateTerm(int $termId, string $taxonomy, array $override = []): int {
        $term = get_term($termId, $taxonomy);
        if (!$term || is_wp_error($term)) return 0;

        $slug = ($override['slug'] ?? $term->slug) . '-' . ($override['lang'] ?? 'copy');
        $result = wp_insert_term(
            $override['name'] ?? $term->name,
            $taxonomy,
            [
                'description' => $override['description'] ?? $term->description,
                'slug'        => $slug,
                'parent'      => $term->parent,
            ]
        );

        if (is_wp_error($result)) return 0;
        return (int) $result['term_id'];
    }

    /**
     * Translate text via configured translator
     */
    protected function translateText(string $text, string $lang, string $customPrompt = ''): string {
        $translator = $this->container->get('translator');
        if (!$translator || empty(trim($text))) return $text;
        return $translator->translate($text, $lang, $customPrompt);
    }

    /**
     * Translate Gutenberg blocks
     */
    protected function translateBlocks(string $content, string $lang): string {
        if (!has_blocks($content)) return $this->translateText($content, $lang);
        $blockTranslator = new \SAT\Content\BlockTranslator($this->container);
        return $blockTranslator->translate($content, $lang);
    }

    /**
     * Translate ACF fields
     */
    protected function translateAcfFields(int $postId, string $lang): array {
        if (!function_exists('get_field_objects')) return [];
        $acfTranslator = new \SAT\Content\AcfTranslator($this->container);
        return $acfTranslator->translateForPost($postId, $lang);
    }

    /**
     * Sync taxonomies from source to translated post.
     * Term çevirisi yoksa otomatik çevirir, sonra posta ekler.
     */
    protected function syncTaxonomies(int $sourceId, int $targetId, string $lang): void {
        $postType   = get_post_type($sourceId);
        $taxonomies = get_object_taxonomies($postType, 'names');

        foreach ($taxonomies as $tax) {
            if (in_array($tax, ['language', 'post_translations', 'term_language', 'term_translations'])) continue;

            $termIds = wp_get_object_terms($sourceId, $tax, ['fields' => 'ids']);
            if (is_wp_error($termIds) || empty($termIds)) continue;

            $translatedIds = [];
            foreach ($termIds as $termId) {
                if (!$this->isTranslatableTaxonomy($tax)) {
                    // Taxonomy çevrilmiyorsa aynı term'i kullan
                    $translatedIds[] = $termId;
                } else {
                    $translated = $this->getTermTranslation($termId, $lang);
                    if ($translated) {
                        // Çevirisi zaten var
                        $translatedIds[] = $translated;
                    } else {
                        // Çevirisi yok — otomatik çevir
                        try {
                            $newTermId = $this->translateTerm($termId, $tax, $lang);
                            if ($newTermId && $newTermId !== $termId) {
                                $translatedIds[] = $newTermId;
                            } else {
                                // Çeviri başarısız → default dildeki term'i fallback olarak ekle
                                $translatedIds[] = $termId;
                            }
                        } catch (\Throwable $e) {
                            // Hata olursa default dildeki term'i ekle — log'a yaz
                            $logger = $this->container->get('logger');
                            if ($logger) {
                                $logger->log([
                                    'object_type' => 'term',
                                    'object_id'   => $termId,
                                    'target_lang' => $lang,
                                    'status'      => 'error',
                                    'error_msg'   => 'syncTaxonomies: ' . $e->getMessage(),
                                    'field_name'  => $tax,
                                ]);
                            }
                            $translatedIds[] = $termId;
                        }
                    }
                }
            }

            if (!empty($translatedIds)) {
                wp_set_object_terms($targetId, $translatedIds, $tax);
            }
        }
    }
}
