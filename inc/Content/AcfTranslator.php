<?php
namespace SAT\Content;

use SAT\Core\Container;

class AcfTranslator {

    private Container $container;
    private array $attachments = [];

    public function __construct(Container $container) {
        $this->container = $container;
    }

    public function translateForPost(int $postId, string $lang): array {
        if (!function_exists('get_field_objects')) return [];

        $fields = get_field_objects($postId);
        if (!$fields) return [];

        $this->attachments = [];
        $result = [];

        foreach ($fields as $key => $field) {
            if (str_starts_with($key, '_')) continue;
            $result[$key] = $this->translateField($field, $field['value'], $lang, $postId);
        }

        return $result;
    }

    public function getCollectedAttachments(): array {
        return $this->attachments;
    }

    private function translateField(array $field, mixed $value, string $lang, int $postId): mixed {
        if ($value === null || $value === '' || $value === false) return $value;

        $type = $field['type'] ?? 'text';

        // Collect images
        $this->collectImageFromField($type, $value);

        // Translatable text types
        if (in_array($type, ['text', 'textarea', 'wysiwyg']) && is_string($value) && strlen(trim($value)) > 0) {
            $translator = $this->container->get('translator');
            if ($translator && method_exists($translator, 'setContext')) {
                $translator->setContext(['field_name' => 'acf:' . ($field['name'] ?? $field['key'] ?? 'field')]);
            }
            return $this->translateText($value, $lang);
        }

        // URL field — translate anchor text only if it's a link array
        if ($type === 'link' && is_array($value)) {
            if (!empty($value['title'])) {
                $value['title'] = $this->translateText($value['title'], $lang);
            }
            return $value; // href untouched
        }

        // Group
        if ($type === 'group' && is_array($value)) {
            $result = [];
            foreach ($field['sub_fields'] ?? [] as $sub) {
                $result[$sub['name']] = $this->translateField($sub, $value[$sub['name']] ?? null, $lang, $postId);
            }
            return $result;
        }

        // Repeater
        if ($type === 'repeater' && is_array($value)) {
            $result = [];
            foreach ($value as $row) {
                $translatedRow = [];
                foreach ($field['sub_fields'] ?? [] as $sub) {
                    $translatedRow[$sub['name']] = $this->translateField($sub, $row[$sub['name']] ?? null, $lang, $postId);
                }
                $result[] = $translatedRow;
            }
            return $result;
        }

        // Flexible content
        if ($type === 'flexible_content' && is_array($value)) {
            $result = [];
            foreach ($value as $row) {
                $layoutName = $row['acf_fc_layout'] ?? '';
                $layout = null;
                foreach ($field['layouts'] ?? [] as $l) {
                    if ($l['name'] === $layoutName) { $layout = $l; break; }
                }
                if (!$layout) { $result[] = $row; continue; }

                $translatedRow = ['acf_fc_layout' => $layoutName];
                foreach ($layout['sub_fields'] ?? [] as $sub) {
                    $translatedRow[$sub['name']] = $this->translateField($sub, $row[$sub['name']] ?? null, $lang, $postId);
                }
                $result[] = $translatedRow;
            }
            return $result;
        }

        // Post object / relationship — get translated post
        if (in_array($type, ['post_object', 'relationship'])) {
            $integration = $this->container->get('integration');
            if (!$integration) return $value;

            $translate = function($id) use ($integration, $lang) {
                if (!is_numeric($id)) return $id;
                $postType = get_post_type((int)$id);
                if (!$integration->isTranslatablePostType($postType)) return $id;
                $translated = $integration->getPostTranslation((int)$id, $lang);
                return $translated ?: $id;
            };

            return is_array($value) ? array_map($translate, $value) : $translate($value);
        }

        // Taxonomy field — get translated term
        if ($type === 'taxonomy') {
            $integration = $this->container->get('integration');
            if (!$integration) return $value;

            $translate = function($id) use ($integration, $lang) {
                if (!is_numeric($id)) return $id;
                $translated = $integration->getTermTranslation((int)$id, $lang);
                return $translated ?: $id;
            };

            return is_array($value) ? array_map($translate, $value) : $translate($value);
        }

        return $value;
    }

    private function collectImageFromField(string $type, mixed $value): void {
        $settings = $this->container->get('settings');
        if (!($settings->get('seo')['image_alttext']['generate'] ?? false)) return;

        if ($type === 'image' && is_numeric($value)) {
            $url = wp_get_attachment_image_src((int)$value, 'full')[0] ?? '';
            if ($url) $this->attachments[] = ['id' => (int)$value, 'url' => $url];
        }

        if ($type === 'gallery' && is_array($value)) {
            foreach ($value as $id) {
                if (!is_numeric($id)) continue;
                $url = wp_get_attachment_image_src((int)$id, 'full')[0] ?? '';
                if ($url) $this->attachments[] = ['id' => (int)$id, 'url' => $url];
            }
        }
    }

    private function translateText(string $text, string $lang): string {
        $translator = $this->container->get('translator');
        if (!$translator || empty(trim($text))) return $text;
        return $translator->translate($text, $lang);
    }
}
