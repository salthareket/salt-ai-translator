<?php
namespace SAT\Core;

class Settings {
    private array $data;

    private array $defaults = [
        'translator'             => '',
        'api_keys'               => [],
        'model'                  => '',
        'temperature'            => '0.2',
        'prompt'                 => '',
        'retranslate'            => 0,
        'auto_translate'         => 0,
        'translate_slugs'        => 0,
        'exclude_post_types'     => [],
        'exclude_taxonomies'     => [],
        'exclude_posts'          => [],
        'exclude_terms'          => [],
        // Field-level exclusions — başlık/name alanı çevrilmeyecek post_type ve taxonomy'ler
        'exclude_title_post_types'  => [],  // bu post_type'ların title'ı çevrilmez
        'exclude_name_taxonomies'   => [],  // bu taxonomy'lerin term name'i çevrilmez
        'glossary'               => [],
        'seo' => [
            'meta_desc'     => ['generate' => 0, 'translate' => 0, 'on_save' => 0, 'overwrite' => 0, 'model' => '', 'temperature' => '0.5', 'prompt' => ''],
            'image_alttext' => ['generate' => 0, 'translate' => 0, 'on_save' => 0, 'overwrite' => 0, 'model' => '', 'temperature' => '0.4', 'prompt' => '', 'image_size' => 'medium'],
            'image_caption' => ['generate' => 0, 'translate' => 0],
            'seo_title'     => ['translate' => 0],
            'og_tags'       => ['translate' => 0],
        ],
        'display' => ['unpublished_languages' => []],
        'woo'     => ['translate_products' => 0, 'translate_attributes' => 0],
        'models_last_sync' => 0,
        'custom_models'    => [],
    ];

    public function __construct() {
        $saved = get_option('sat_settings', []);
        if (!is_array($saved)) $saved = [];

        // array_replace_recursive numeric-keyed array'lerde (glossary gibi) merge yerine
        // append yapabilir. Önce associative sub-array'leri merge et, numerically-keyed'ları override et.
        $this->data = array_replace_recursive($this->defaults, $saved);

        // Numerically-keyed array'leri kayıttan doğrudan al — merge değil override
        foreach (['glossary', 'exclude_post_types', 'exclude_taxonomies', 'exclude_posts', 'exclude_terms',
                  'exclude_title_post_types', 'exclude_name_taxonomies'] as $key) {
            if (array_key_exists($key, $saved)) {
                $this->data[$key] = $saved[$key];
            }
        }
    }

    public function get(string $key, mixed $default = null): mixed {
        return $this->data[$key] ?? $default;
    }

    public function getAll(): array {
        return $this->data;
    }

    public function getApiKeys(string $translator = ''): array {
        $translator = $translator ?: ($this->data['translator'] ?? '');
        $keys = $this->data['api_keys'][$translator] ?? [];
        return is_array($keys) ? array_filter($keys) : [];
    }

    public function save(array $data): void {
        $this->data = array_replace_recursive($this->data, $data);
        // Numerically-keyed array'leri doğrudan override et
        foreach (['glossary', 'exclude_post_types', 'exclude_taxonomies', 'exclude_posts', 'exclude_terms',
                  'exclude_title_post_types', 'exclude_name_taxonomies'] as $key) {
            if (array_key_exists($key, $data)) {
                $this->data[$key] = $data[$key];
            }
        }

        // woo nested array — array_replace_recursive ile merge et
        if (isset($data['woo']) && is_array($data['woo'])) {
            $this->data['woo'] = array_replace($this->data['woo'] ?? [], $data['woo']);
        }
        update_option('sat_settings', $this->data);
    }
}
