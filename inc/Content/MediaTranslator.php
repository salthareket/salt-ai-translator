<?php
namespace SAT\Content;

use SAT\Core\Container;

class MediaTranslator {

    private Container $container;

    public function __construct(Container $container) {
        $this->container = $container;
        add_filter('get_post_metadata', [$this, 'filterAltText'], 10, 4);
    }

    /**
     * Generate alt text for a list of attachments
     */
    public function generateAltTexts(array $attachments, string $lang): void {
        $settings   = $this->container->get('settings');
        $translator = $this->container->get('translator');
        $seoConfig  = $settings->get('seo')['image_alttext'] ?? [];

        if (empty($seoConfig['generate']) || !$translator) return;
        if (!$translator->supportsVision()) return;

        // Localhost check — can't access local images from OpenAI
        if ($this->isLocalhost()) return;

        foreach ($attachments as $item) {
            if (empty($item['id']) || empty($item['url'])) continue;

            $attachmentId = (int)$item['id'];
            $imageUrl     = $item['url'];

            // Preserve existing?
            if (!empty($seoConfig['overwrite']) === false) {
                $existing = get_post_meta($attachmentId, '_wp_attachment_image_alt', true);
                if (!empty($existing)) continue;
            }

            // Check URL is accessible
            $checkedUrl = $this->resolveImageUrl($imageUrl);
            if (!$checkedUrl) continue;

            // Generate alt text in default language
            $alt = $translator->generateAltText($checkedUrl, $lang);
            if (empty($alt)) continue;

            // Save default language alt
            update_post_meta($attachmentId, '_wp_attachment_image_alt', $alt);

            // Generate caption if enabled
            if (!empty($seoConfig['generate_caption'])) {
                $caption = $translator->generateCaption($checkedUrl, $lang);
                if ($caption) {
                    wp_update_post(['ID' => $attachmentId, 'post_excerpt' => $caption]);
                }
            }

            // Translate to other languages
            if (!empty($seoConfig['translate'])) {
                $integration = $this->container->get('integration');
                $languages   = $integration ? $integration->getLanguages() : [];
                $default     = $integration ? $integration->getDefaultLanguage() : '';

                foreach ($languages as $code => $label) {
                    if ($code === $default) continue;

                    $translatedAlt = $translator->translate($alt, $code);
                    if ($translatedAlt) {
                        update_post_meta($attachmentId, '_sat_alt_' . $code, $translatedAlt);
                    }
                }
            }
        }
    }

    /**
     * Filter _wp_attachment_image_alt to return language-specific alt text
     */
    public function filterAltText(mixed $value, int $objectId, string $metaKey, bool $single): mixed {
        if ($metaKey !== '_wp_attachment_image_alt') return $value;
        if (get_post_type($objectId) !== 'attachment') return $value;

        static $inProgress = false;
        if ($inProgress) return $value;
        $inProgress = true;

        $integration = $this->container->get('integration');
        if (!$integration) { $inProgress = false; return $value; }

        $default = $integration->getDefaultLanguage();
        $current = $integration->getCurrentLanguage();

        if ($current === $default) { $inProgress = false; return $value; }

        $translated = get_post_meta($objectId, '_sat_alt_' . $current, true);
        if (!empty($translated)) {
            $inProgress = false;
            return [$translated];
        }

        $inProgress = false;
        return $value;
    }

    /**
     * Bulk process all attachments without alt text
     */
    public function bulkGenerate(string $lang, int $limit = 50): array {
        $args = [
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'posts_per_page' => $limit,
            'meta_query'     => [[
                'key'     => '_wp_attachment_image_alt',
                'compare' => 'NOT EXISTS',
            ]],
            'post_mime_type' => ['image/jpeg', 'image/png', 'image/webp'],
        ];

        $attachments = get_posts($args);
        $items = [];

        foreach ($attachments as $att) {
            $url = wp_get_attachment_image_src($att->ID, 'medium')[0] ?? '';
            if ($url) $items[] = ['id' => $att->ID, 'url' => $url];
        }

        $this->generateAltTexts($items, $lang);
        return ['processed' => count($items)];
    }

    private function resolveImageUrl(string $url): ?string {
        // External URL — use as-is if accessible
        $parsed   = parse_url($url);
        $siteHost = parse_url(site_url(), PHP_URL_HOST);

        if (($parsed['host'] ?? '') !== $siteHost) {
            return $url; // External, use directly
        }

        // Local URL — check if file exists
        $path = str_replace(site_url('/'), ABSPATH, $url);
        if (file_exists($path)) return $url;

        return null;
    }

    private function isLocalhost(): bool {
        $host = $_SERVER['HTTP_HOST'] ?? '';
        return in_array($host, ['localhost', '127.0.0.1']) || str_contains($host, '.local');
    }
}
