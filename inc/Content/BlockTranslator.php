<?php
namespace SAT\Content;

use SAT\Core\Container;

class BlockTranslator {

    private Container $container;
    private array $attachments = [];

    public function __construct(Container $container) {
        $this->container = $container;
    }

    public function translate(string $content, string $lang): string {
        if (!has_blocks($content)) return $content;

        $this->attachments = [];
        $blocks    = parse_blocks($content);
        $blocks    = $this->processBlocks($blocks, $lang);
        return serialize_blocks($blocks);
    }

    public function getCollectedAttachments(): array {
        return $this->attachments;
    }

    private function processBlocks(array $blocks, string $lang): array {
        $result = [];
        foreach ($blocks as $block) {
            $result[] = $this->processBlock($block, $lang);
        }
        return $result;
    }

    private function processBlock(array $block, string $lang): array {
        $name = $block['blockName'] ?? null;

        // Reusable block — translate the referenced post
        if ($name === 'core/block' && !empty($block['attrs']['ref'])) {
            $integration = $this->container->get('integration');
            if ($integration) {
                $translatedRef = $integration->translatePost((int)$block['attrs']['ref'], $lang);
                $block['attrs']['ref'] = $translatedRef ?: $block['attrs']['ref'];
            }
            return $block;
        }

        // Collect images for alt text generation
        $this->collectImages($block);

        // Translate innerBlocks recursively
        if (!empty($block['innerBlocks'])) {
            $block['innerBlocks'] = $this->processBlocks($block['innerBlocks'], $lang);
        }

        // Null block (raw HTML/text)
        if ($name === null) {
            if (!empty($block['innerHTML']) && $this->shouldTranslate($block['innerHTML'])) {
                $block['innerHTML'] = $this->translateText($block['innerHTML'], $lang, 'block:raw');
                // innerContent'i innerHTML ile senkronize et (double translate önleme)
                $block['innerContent'] = [$block['innerHTML']];
            }
            return $block;
        }

        // ACF block
        if (str_starts_with($name, 'acf/')) {
            return $this->processAcfBlock($block, $lang);
        }

        // Core paragraph — translate innerHTML + innerContent
        if ($name === 'core/paragraph' || $name === 'core/heading' || $name === 'core/list-item') {
            $block = $this->translateInnerHTML($block, $lang, $name);
            return $block;
        }

        // Core button
        if ($name === 'core/button') {
            if (!empty($block['attrs']['text'])) {
                $block['attrs']['text'] = $this->translateText($block['attrs']['text'], $lang, 'block:button');
            }
            $block = $this->translateInnerHTML($block, $lang, $name);
            return $block;
        }

        // Core image — translate alt, caption
        if ($name === 'core/image') {
            if (!empty($block['attrs']['alt'])) {
                $block['attrs']['alt'] = $this->translateText($block['attrs']['alt'], $lang, 'block:image:alt');
            }
            if (!empty($block['attrs']['caption'])) {
                $block['attrs']['caption'] = $this->translateText($block['attrs']['caption'], $lang, 'block:image:caption');
            }
            $block = $this->translateInnerHTML($block, $lang, $name);
            return $block;
        }

        // Core quote, pullquote, verse
        if (in_array($name, ['core/quote', 'core/pullquote', 'core/verse'])) {
            if (!empty($block['attrs']['citation'])) {
                $block['attrs']['citation'] = $this->translateText($block['attrs']['citation'], $lang, 'block:citation');
            }
            $block = $this->translateInnerHTML($block, $lang, $name);
            return $block;
        }

        // Default: translate innerHTML
        $block = $this->translateInnerHTML($block, $lang, $name);
        return $block;
    }

    private function processAcfBlock(array $block, string $lang): array {
        if (empty($block['attrs']['data']) || !is_array($block['attrs']['data'])) {
            return $block;
        }

        foreach ($block['attrs']['data'] as $key => $val) {
            if (str_starts_with($key, '_')) continue; // field key meta, skip
            if (!is_string($val) || empty(trim($val))) continue;
            if (is_numeric($val)) continue;

            $fieldKey = $block['attrs']['data']['_' . $key] ?? null;
            if ($fieldKey) {
                $fieldObj = function_exists('get_field_object') ? get_field_object($fieldKey) : null;
                $fieldType = $fieldObj['type'] ?? 'text';

                // Collect images
                if ($fieldType === 'image' && is_numeric($val)) {
                    $url = wp_get_attachment_image_src((int)$val, 'full')[0] ?? '';
                    if ($url) $this->attachments[] = ['id' => (int)$val, 'url' => $url];
                    continue;
                }

                // Skip non-translatable types
                if (!in_array($fieldType, ['text', 'textarea', 'wysiwyg'])) continue;

                // Skip if ACF field has translations=copy or similar
                if (isset($fieldObj['translations']) && $fieldObj['translations'] !== 'translate') continue;
            }

            $block['attrs']['data'][$key] = $this->translateText($val, $lang);
        }

        return $block;
    }

    private function translateInnerHTML(array $block, string $lang, string $source = ''): array {
        $blockSource = $source ?: 'block:html';

        // Sadece innerContent çevir — innerHTML innerContent'in birleşimidir.
        // İkisini ayrı çevirmek double API çağrısına yol açar.
        if (!empty($block['innerContent']) && is_array($block['innerContent'])) {
            $block['innerContent'] = array_map(function ($item) use ($lang, $blockSource) {
                return (is_string($item) && $this->shouldTranslate($item))
                    ? $this->translateText($item, $lang, $blockSource)
                    : $item;
            }, $block['innerContent']);

            // innerHTML'yi çevirilen innerContent'ten yeniden oluştur
            $block['innerHTML'] = implode('', array_map(fn($c) => is_string($c) ? $c : '', $block['innerContent']));
        } elseif (!empty($block['innerHTML']) && $this->shouldTranslate($block['innerHTML'])) {
            // innerContent yoksa fallback: sadece innerHTML çevir
            $block['innerHTML'] = $this->translateText($block['innerHTML'], $lang, $blockSource);
        }

        return $block;
    }

    private function collectImages(array $block): void {
        $settings = $this->container->get('settings');
        if (!$settings->get('seo')['image_alttext']['generate'] ?? false) return;

        if (!empty($block['innerHTML'])) {
            preg_match_all('/<img[^>]+src=[\'"]([^\'"]+)[\'"]/i', $block['innerHTML'], $matches);
            foreach ($matches[1] ?? [] as $src) {
                $id = attachment_url_to_postid($src);
                $this->attachments[] = ['id' => $id ?: null, 'url' => $src];
            }
        }
    }

    private function translateText(string $text, string $lang, string $source = ''): string {
        $translator = $this->container->get('translator');
        if (!$translator || !$this->shouldTranslate($text)) return $text;
        if ($source && method_exists($translator, 'setContext')) {
            $translator->setContext(['field_name' => $source]);
        }
        return $translator->translate($text, $lang);
    }

    private function shouldTranslate(mixed $text): bool {
        if (!is_string($text) || trim($text) === '') return false;
        if (preg_match('/<!--\s*wp:/i', $text)) return false;
        return trim(strip_tags($text)) !== '';
    }
}
