<?php
namespace SAT\Content;

use SAT\Core\Container;

/**
 * Page Builder Translator
 *
 * Elementor, Bricks, WPBakery, Divi, Beaver Builder, Oxygen
 * page builder içeriklerini AI ile çevirir.
 *
 * @package    SAT\Content
 * @version    1.0.0
 * @since      3.5.0
 *
 * @changelog
 *   1.0.0 - 2026-06-25
 *     - Add: Elementor _elementor_data JSON parse + translate (widget settings)
 *     - Add: Bricks _bricks_page_content_2 JSON parse + translate
 *     - Add: WPBakery shortcode content translate
 *     - Add: Divi et_pb_* shortcode content translate
 *     - Add: Beaver Builder _fl_builder_data serialized translate
 *     - Add: Oxygen ct_builder_shortcodes translate
 *     - Add: detect() static method for builder detection
 *
 * HOW TO USE:
 *   $detector = PageBuilderTranslator::detect($postId);
 *   // $detector: 'elementor' | 'bricks' | 'wpbakery' | 'divi' | 'beaver' | 'oxygen' | null
 *
 *   $translator = new PageBuilderTranslator($container);
 *   $translator->translate($postId, $translatedPostId, $lang);
 */
class PageBuilderTranslator {

    private Container $container;

    // ── Elementor — hangi widget type'larının hangi settings key'leri çevrilecek ──
    // Format: 'widget_type' => ['key1', 'key2', ...]
    // Boş array = tüm text-like key'leri otomatik tara
    private const ELEMENTOR_WIDGET_KEYS = [
        'heading'              => ['title'],
        'text-editor'          => ['editor'],
        'button'               => ['text', 'button_text'],
        'image'                => ['caption', 'alt'],
        'image-box'            => ['title', 'description'],
        'icon-box'             => ['title', 'description'],
        'counter'              => ['title', 'suffix', 'prefix'],
        'progress-bar'         => ['title', 'inner_text'],
        'testimonial'          => ['content', 'name', 'job'],
        'testimonial-carousel' => ['content', 'name', 'job'],
        'accordion'            => ['tab_title', 'tab_content'],
        'toggle'               => ['tab_title', 'tab_content'],
        'tabs'                 => ['tab_title', 'tab_content'],
        'alert'                => ['alert_title', 'alert_description'],
        'call-to-action'       => ['title', 'description', 'btn_text'],
        'divider'              => ['text'],
        'spacer'               => [],
        'icon'                 => ['title'],
        'social-icons'         => [],
        'nav-menu'             => [],
        'sidebar'              => [],
        'wp-widget-wpforms'    => [],
        'shortcode'            => [],
        'html'                 => ['html'],
        'text'                 => ['text'],
        'icon-list'            => ['text'],
        'price-list'           => ['title', 'description'],
        'pricing'              => ['title', 'price', 'period', 'description', 'button_text', 'ribbon_title'],
        'star-rating'          => ['title'],
        'slides'               => ['heading', 'description', 'button_text'],
        'video'                => ['caption'],
        'audio'                => [],
        'map'                  => [],
        'flip-box'             => [
            'title_text_a', 'description_text_a', 'title_text_b', 'description_text_b', 'button_text'
        ],
        'team-member'          => ['name', 'job', 'description'],
        'blockquote'           => ['blockquote_content', 'author_name', 'tweet_button_label'],
        'table-of-contents'    => ['title'],
        'woocommerce-product-title'  => [],
        'woocommerce-product-price'  => [],
        'woocommerce-product-rating' => [],
        'woocommerce-add-to-cart'    => [],
    ];

    // ── Bricks — hangi element type'larının hangi settings key'leri çevrilecek ──
    private const BRICKS_ELEMENT_KEYS = [
        'heading'       => ['text'],
        'text'          => ['text'],
        'text-basic'    => ['text'],
        'button'        => ['text'],
        'icon-box'      => ['title', 'text'],
        'image-box'     => ['title', 'text'],
        'pricing-table' => ['title', 'price', 'billing', 'description'],
        'testimonial'   => ['content', 'name', 'bio'],
        'accordion'     => ['title', 'content'],
        'tabs'          => ['title', 'content'],
        'alert'         => ['title', 'text'],
        'list'          => ['items'],  // array içinde 'text'
        'countdown'     => ['text'],
        'progress-bar'  => ['title'],
        'counter'       => ['title'],
        'blockquote'    => ['quote', 'author', 'bio'],
        'nav-menu'      => [],
        'form'          => [],
    ];

    public function __construct(Container $container) {
        $this->container = $container;
    }

    /**
     * Hangi page builder kullanılıyor detect et.
     *
     * @param  int $postId
     * @return string|null  'elementor'|'bricks'|'wpbakery'|'divi'|'beaver'|'oxygen'|null
     *
     * @example
     *   $builder = PageBuilderTranslator::detect($postId);
     *   if ($builder) { // page builder içeriği var }
     */
    public static function detect(int $postId): ?string {
        // Elementor
        if (get_post_meta($postId, '_elementor_edit_mode', true) === 'builder') {
            return 'elementor';
        }

        // Bricks
        if (get_post_meta($postId, '_bricks_editor_mode', true)
            || get_post_meta($postId, '_bricks_page_content_2', true)) {
            return 'bricks';
        }

        // Divi
        if (get_post_meta($postId, '_et_pb_use_builder', true) === 'on') {
            return 'divi';
        }

        // Beaver Builder
        if (get_post_meta($postId, '_fl_builder_enabled', true)) {
            return 'beaver';
        }

        // Oxygen
        if (get_post_meta($postId, 'ct_builder_shortcodes', true)) {
            return 'oxygen';
        }

        // WPBakery — post_content içinde vc_row shortcode'u var mı
        $content = get_post_field('post_content', $postId);
        if ($content && strpos($content, '[vc_row') !== false) {
            return 'wpbakery';
        }

        return null;
    }

    /**
     * Page builder içeriğini çevir.
     * Translated post'un meta'sına doğrudan yazar.
     *
     * @param  int    $sourceId      Kaynak post ID
     * @param  int    $translatedId  Hedef (çevrilmiş) post ID
     * @param  string $lang          Hedef dil kodu
     * @return bool   Çeviri yapıldı mı
     *
     * @example
     *   $builder = new PageBuilderTranslator($container);
     *   $builder->translate(123, 456, 'de');
     */
    public function translate(int $sourceId, int $translatedId, string $lang): bool {
        $builder = self::detect($sourceId);
        if (!$builder) return false;

        switch ($builder) {
            case 'elementor': return $this->translateElementor($sourceId, $translatedId, $lang);
            case 'bricks':    return $this->translateBricks($sourceId, $translatedId, $lang);
            case 'divi':      return $this->translateDivi($sourceId, $translatedId, $lang);
            case 'beaver':    return $this->translateBeaver($sourceId, $translatedId, $lang);
            case 'oxygen':    return $this->translateOxygen($sourceId, $translatedId, $lang);
            case 'wpbakery':  return $this->translateWPBakery($sourceId, $translatedId, $lang);
        }

        return false;
    }

    // =========================================================
    //  ELEMENTOR
    // =========================================================

    private function translateElementor(int $sourceId, int $translatedId, string $lang): bool {
        $raw = get_post_meta($sourceId, '_elementor_data', true);
        if (empty($raw)) return false;

        // DB'de escaped JSON olabilir — wp_unslash zorunlu
        $elements = json_decode(wp_unslash($raw), true);
        if (!is_array($elements)) return false;

        $elements = $this->processElementorElements($elements, $lang);

        $json = wp_slash(wp_json_encode($elements));
        update_post_meta($translatedId, '_elementor_data', $json);

        // Elementor cache'ini temizle
        if (class_exists('\Elementor\Plugin')) {
            \Elementor\Plugin::$instance->files_manager->clear_cache();
        }

        return true;
    }

    private function processElementorElements(array $elements, string $lang): array {
        foreach ($elements as &$element) {
            $type    = $element['elType']     ?? null;  // 'section'|'column'|'container'|'widget'
            $widgetType = $element['widgetType'] ?? null;

            // Widget — metin alanlarını çevir
            if ($type === 'widget' && $widgetType) {
                $element['settings'] = $this->translateElementorWidgetSettings(
                    $element['settings'] ?? [],
                    $widgetType,
                    $lang
                );
            }

            // Alt elementleri recursive işle
            if (!empty($element['elements'])) {
                $element['elements'] = $this->processElementorElements($element['elements'], $lang);
            }
        }
        unset($element);
        return $elements;
    }

    private function translateElementorWidgetSettings(array $settings, string $widgetType, string $lang): array {
        $knownKeys = self::ELEMENTOR_WIDGET_KEYS[$widgetType] ?? null;

        if ($knownKeys === null) {
            // Bilinmeyen widget — tüm string value'ları otomatik tara
            return $this->translateUnknownWidgetSettings($settings, $lang);
        }

        if (empty($knownKeys)) {
            // Boş array = çevirme (layout/functional widget'lar)
            return $settings;
        }

        foreach ($knownKeys as $key) {
            if (!isset($settings[$key])) continue;

            // Repeater field (array of arrays)
            if (is_array($settings[$key])) {
                $settings[$key] = $this->translateElementorRepeater($settings[$key], $key, $lang);
                continue;
            }

            if (is_string($settings[$key]) && $this->shouldTranslate($settings[$key])) {
                $settings[$key] = $this->translateText($settings[$key], $lang, "elementor:{$widgetType}:{$key}");
            }
        }

        return $settings;
    }

    private function translateElementorRepeater(array $items, string $parentKey, string $lang): array {
        foreach ($items as &$item) {
            if (!is_array($item)) continue;
            foreach ($item as $k => &$v) {
                if (is_string($v) && $this->shouldTranslate($v)) {
                    $v = $this->translateText($v, $lang, "elementor:repeater:{$parentKey}:{$k}");
                }
            }
            unset($v);
        }
        unset($item);
        return $items;
    }

    private function translateUnknownWidgetSettings(array $settings, string $lang): array {
        // Sadece text/editor/html türü string value'ları çevir
        // Sayısal, URL, class, ID gibi şeyleri atla
        $skip_patterns = [
            '/^https?:\/\//i',   // URL
            '/^\d+$/',            // Sadece sayı
            '/^#[0-9a-f]{3,8}$/i', // Hex renk
            '/^rgba?\(/i',        // RGB renk
            '/^[a-z0-9_-]+$/i',  // slug/class
        ];

        foreach ($settings as $key => &$value) {
            if (!is_string($value) || !$this->shouldTranslate($value)) continue;

            // Skip key'ler — görsel, renk, boyut, URL vs.
            $skip_keys = ['url', 'id', 'size', 'color', 'background', 'border', 'margin',
                          'padding', 'font', 'css_id', 'css_classes', 'custom_id', 'class',
                          'link', 'video_url', 'image', 'icon'];
            if (in_array($key, $skip_keys, true)) continue;
            if (str_ends_with($key, '_color') || str_ends_with($key, '_size')
                || str_ends_with($key, '_id') || str_ends_with($key, '_url')) continue;

            // Pattern skip
            $skip = false;
            foreach ($skip_patterns as $pattern) {
                if (preg_match($pattern, $value)) { $skip = true; break; }
            }
            if ($skip) continue;

            $value = $this->translateText($value, $lang, "elementor:auto:{$key}");
        }
        unset($value);

        return $settings;
    }

    // =========================================================
    //  BRICKS BUILDER
    // =========================================================

    private function translateBricks(int $sourceId, int $translatedId, string $lang): bool {
        // Bricks 3 ayrı meta key kullanıyor: header, content, footer
        $metaKeys = [
            '_bricks_page_header_2',
            '_bricks_page_content_2',
            '_bricks_page_footer_2',
        ];

        $translated = false;

        foreach ($metaKeys as $metaKey) {
            $raw = get_post_meta($sourceId, $metaKey, true);
            if (empty($raw)) continue;

            $elements = is_string($raw) ? json_decode(wp_unslash($raw), true) : $raw;
            if (!is_array($elements)) continue;

            $elements = $this->processBricksElements($elements, $lang);

            update_post_meta($translatedId, $metaKey, $elements);
            $translated = true;
        }

        return $translated;
    }

    private function processBricksElements(array $elements, string $lang): array {
        foreach ($elements as &$element) {
            $name     = $element['name']     ?? null;
            $settings = $element['settings'] ?? [];

            if (!empty($settings) && is_array($settings)) {
                $element['settings'] = $this->translateBricksElementSettings($settings, $name, $lang);
            }
        }
        unset($element);
        return $elements;
    }

    private function translateBricksElementSettings(array $settings, ?string $elementName, string $lang): array {
        $knownKeys = $elementName ? (self::BRICKS_ELEMENT_KEYS[$elementName] ?? null) : null;

        if ($knownKeys === null) {
            // Bilinmeyen element — string value'ları tara
            return $this->translateBricksAutoSettings($settings, $lang);
        }

        foreach ($knownKeys as $key) {
            if (!isset($settings[$key])) continue;

            // items gibi nested array'ler
            if (is_array($settings[$key])) {
                foreach ($settings[$key] as &$item) {
                    if (is_array($item)) {
                        foreach ($item as $k => &$v) {
                            if (is_string($v) && $this->shouldTranslate($v)) {
                                $v = $this->translateText($v, $lang, "bricks:{$elementName}:{$key}:{$k}");
                            }
                        }
                        unset($v);
                    }
                }
                unset($item);
                continue;
            }

            if (is_string($settings[$key]) && $this->shouldTranslate($settings[$key])) {
                $settings[$key] = $this->translateText($settings[$key], $lang, "bricks:{$elementName}:{$key}");
            }
        }

        return $settings;
    }

    private function translateBricksAutoSettings(array $settings, string $lang): array {
        foreach ($settings as $key => &$value) {
            if (!is_string($value) || !$this->shouldTranslate($value)) continue;
            if (in_array($key, ['tag', 'icon', 'id', 'class', 'link', 'href', 'src'], true)) continue;
            if (str_ends_with($key, '_id') || str_ends_with($key, '_url') || str_ends_with($key, '_color')) continue;
            $value = $this->translateText($value, $lang, "bricks:auto:{$key}");
        }
        unset($value);
        return $settings;
    }

    // =========================================================
    //  DIVI
    // =========================================================

    private function translateDivi(int $sourceId, int $translatedId, string $lang): bool {
        $content = get_post_field('post_content', $sourceId);
        if (empty($content) || strpos($content, 'et_pb_') === false) return false;

        $content = $this->translateShortcodeContent($content, $lang, 'divi');

        wp_update_post([
            'ID'           => $translatedId,
            'post_content' => $content,
        ]);

        // Divi cache temizle
        if (function_exists('et_pb_remove_global_module_cache')) {
            et_pb_remove_global_module_cache();
        }

        return true;
    }

    // =========================================================
    //  WPBAKERY
    // =========================================================

    private function translateWPBakery(int $sourceId, int $translatedId, string $lang): bool {
        $content = get_post_field('post_content', $sourceId);
        if (empty($content) || strpos($content, '[vc_row') === false) return false;

        $content = $this->translateShortcodeContent($content, $lang, 'wpbakery');

        wp_update_post([
            'ID'           => $translatedId,
            'post_content' => $content,
        ]);

        return true;
    }

    // =========================================================
    //  BEAVER BUILDER
    // =========================================================

    private function translateBeaver(int $sourceId, int $translatedId, string $lang): bool {
        $data = get_post_meta($sourceId, '_fl_builder_data', true);
        if (empty($data)) return false;

        // Beaver data serialized PHP object'tir
        if (is_string($data)) {
            $data = maybe_unserialize($data);
        }

        if (!is_array($data) && !is_object($data)) return false;

        $data = $this->processBeaverNodes((array) $data, $lang);

        update_post_meta($translatedId, '_fl_builder_data', $data);

        // Beaver draft data da güncelle
        $draft = get_post_meta($sourceId, '_fl_builder_draft', true);
        if ($draft) {
            update_post_meta($translatedId, '_fl_builder_draft', $data);
        }

        return true;
    }

    private function processBeaverNodes(array $nodes, string $lang): array {
        // Beaver Builder translatable module settings
        $translatableModules = [
            'rich-text', 'html', 'heading', 'cta', 'button',
            'callout', 'testimonial', 'accordion', 'tabs',
            'pricing-table', 'icon-group', 'icon', 'text',
        ];

        foreach ($nodes as $nodeId => &$node) {
            $node = (array) $node;

            $type   = $node['type']   ?? null; // 'row'|'column'|'module'
            $module = $node['settings']->type ?? ($node['settings']['type'] ?? null);

            if ($type === 'module' && $module && in_array($module, $translatableModules, true)) {
                $settings = (array) ($node['settings'] ?? []);
                $settings = $this->translateBeaverModuleSettings($settings, $module, $lang);
                $node['settings'] = (object) $settings;
            }
        }
        unset($node);

        return $nodes;
    }

    private function translateBeaverModuleSettings(array $settings, string $module, string $lang): array {
        $keys = match($module) {
            'heading'       => ['heading'],
            'rich-text',
            'text',
            'html'          => ['text'],
            'button'        => ['text'],
            'cta'           => ['title', 'btn_text'],
            'callout'       => ['title', 'text', 'cta_text'],
            'testimonial'   => ['text', 'name', 'company'],
            default         => [],
        };

        if (empty($keys)) return $settings;

        foreach ($keys as $key) {
            if (empty($settings[$key]) || !is_string($settings[$key])) continue;
            if (!$this->shouldTranslate($settings[$key])) continue;
            $settings[$key] = $this->translateText($settings[$key], $lang, "beaver:{$module}:{$key}");
        }

        return $settings;
    }

    // =========================================================
    //  OXYGEN BUILDER
    // =========================================================

    private function translateOxygen(int $sourceId, int $translatedId, string $lang): bool {
        $shortcodes = get_post_meta($sourceId, 'ct_builder_shortcodes', true);
        if (empty($shortcodes)) return false;

        $translated = $this->translateShortcodeContent($shortcodes, $lang, 'oxygen');

        update_post_meta($translatedId, 'ct_builder_shortcodes', $translated);

        // Oxygen JSON data da var mı kontrol et
        $json = get_post_meta($sourceId, 'ct_builder_json', true);
        if ($json) {
            $data = json_decode($json, true);
            if (is_array($data)) {
                $data = $this->translateOxygenJson($data, $lang);
                update_post_meta($translatedId, 'ct_builder_json', wp_json_encode($data));
            }
        }

        return true;
    }

    private function translateOxygenJson(array $data, string $lang): array {
        array_walk_recursive($data, function (&$value, $key) use ($lang) {
            if (!is_string($value) || !$this->shouldTranslate($value)) return;
            if (in_array($key, ['id', 'class', 'tag', 'selector', 'icon', 'url', 'href'], true)) return;
            if (str_ends_with($key, '_id') || str_ends_with($key, '_color') || str_ends_with($key, '_url')) return;
            $value = $this->translateText($value, $lang, "oxygen:json:{$key}");
        });
        return $data;
    }

    // =========================================================
    //  SHARED: Shortcode İçerik Çevirisi (Divi / WPBakery / Oxygen)
    // =========================================================

    /**
     * Shortcode bazlı builder içeriklerini çevirir.
     * Shortcode tag ve attribute'larına dokunmadan sadece text content'i çevirir.
     *
     * Strateji:
     * 1. Shortcode'ları placeholder ile değiştir
     * 2. Kalan düz metni çevir
     * 3. Placeholder'ları geri koy
     * 4. Shortcode attribute değerlerini ayrıca çevir
     */
    private function translateShortcodeContent(string $content, string $lang, string $source): string {
        // Shortcode tag'leri ve HTML tag'lerini koru
        // Sadece visible text content'i çevir

        // Regex: closing tag olmayan self-closing shortcode'lar ve açma/kapama çiftleri
        // İçerik: shortcode'lar arasındaki düz metin
        $translated = preg_replace_callback(
            '/(\[[\w\/][^\]]*\])([^\[]*)/s',
            function (array $m) use ($lang, $source): string {
                $tag  = $m[1]; // shortcode tag
                $text = $m[2]; // tag sonrası düz metin

                $translatedTag = $this->translateShortcodeAttributes($tag, $lang, $source);

                if ($text && $this->shouldTranslate($text)) {
                    $text = $this->translateText(trim($text), $lang, "{$source}:text");
                }

                return $translatedTag . ($text ? ' ' . $text : '');
            },
            $content
        ) ?: $content;

        return $translated;
    }

    /**
     * Shortcode attribute'larından text değerleri çevirir.
     * [module_text text="Hello World" id="123"] → text attribute çevrilir, id atlanır
     */
    private function translateShortcodeAttributes(string $tag, string $lang, string $source): string {
        // Çevrilecek attribute adları (text içerikler)
        $textAttributes = [
            'text', 'title', 'heading', 'content', 'description', 'label',
            'button_text', 'btn_text', 'caption', 'summary', 'excerpt',
            'admin_label', // Divi builder label
        ];

        foreach ($textAttributes as $attr) {
            // Hem tırnak hem çift tırnak desteği
            $tag = preg_replace_callback(
                '/(' . preg_quote($attr, '/') . '=")([^"]+)(")/i',
                function (array $m) use ($lang, $source, $attr): string {
                    $value = $m[2];
                    if (!$this->shouldTranslate($value)) return $m[0];
                    $translated = $this->translateText($value, $lang, "{$source}:attr:{$attr}");
                    return $m[1] . $translated . $m[3];
                },
                $tag
            ) ?: $tag;
        }

        return $tag;
    }

    // =========================================================
    //  YARDIMCI METODLAR
    // =========================================================

    private function translateText(string $text, string $lang, string $source = ''): string {
        $translator = $this->container->get('translator');
        if (!$translator || !$this->shouldTranslate($text)) return $text;

        if ($source && method_exists($translator, 'setContext')) {
            $translator->setContext(['field_name' => $source]);
        }

        try {
            return $translator->translate($text, $lang);
        } catch (\Throwable $e) {
            // Log hatayı, orijinal metni döndür
            $logger = $this->container->get('logger');
            if ($logger) {
                $logger->log([
                    'object_type' => 'page_builder',
                    'field_name'  => $source,
                    'target_lang' => $lang,
                    'status'      => 'error',
                    'error_msg'   => $e->getMessage(),
                ]);
            }
            return $text;
        }
    }

    private function shouldTranslate(mixed $text): bool {
        if (!is_string($text) || trim($text) === '') return false;
        if (strlen($text) < 2) return false;

        // WP block comment'leri çevirme
        if (preg_match('/<!--\s*wp:/i', $text)) return false;
        // CSS/JS içerik çevirme
        if (preg_match('/^\s*[\{\[\.]/', $text)) return false;
        // Sadece HTML tag'i çevirme
        if (preg_match('/^<[^>]+>$/', trim($text))) return false;

        return trim(strip_tags($text)) !== '';
    }
}
