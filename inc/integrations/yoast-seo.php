<?php

namespace SaltAI\Integrations;

use SaltAI\Core\ServiceContainer;

if (!defined('ABSPATH')) exit;

class SEOIntegration{

    private ServiceContainer $container;

    public function __construct($container) {
        $this->container = $container;
        add_filter('wpseo_metadesc', [$this, 'frontend_meta_description'], 10, 2);
        add_filter('wpseo_opengraph_desc', [$this, 'frontend_meta_description'], 10, 2);
        add_filter('wpseo_twitter_description', [$this, 'frontend_meta_description'], 10, 2);
        //add_filter('wpseo_frontend_presenters', [$this, 'frontend_schema_description'], 20);
        //add_filter('wpseo_schema_needs_rebuild', '__return_true');
        add_filter('get_post_metadata', [$this, 'frontend_image_alt'], 10, 4);
    }

    public function is_active(): bool {
        return defined('WPSEO_VERSION');
    }

    public function frontend_meta_description($desc, $post) {
        $integration = $this->container->get('integration');
        $current_lang = $integration->current_language;
        $default_lang = $integration->default_language;

        if ($current_lang === $default_lang || is_admin()) {
            return $desc;
        }

        $post_id = $post->model->object_id;
        if (!$post_id) {
            return $desc; // Güvenli fallback
        }
        $translated = get_post_meta($post_id, "_salt_metadesc_{$current_lang}", true);
        return !empty($translated) ? $translated : $desc;
    }
    public function frontend_schema_description( $presenters ) {
       
        if (is_admin()) return $presenters;

        $integration = $this->container->get('integration');
        $current_lang = $integration->current_language;
        $default_lang = $integration->default_language;

        if ($current_lang === $default_lang || is_admin()) {
            return $presenters;
        }
        foreach ($presenters as $i => $presenter) {
            if (!is_object($presenter)) continue;

            if ($presenter instanceof \Yoast\WP\SEO\Presenters\Schema_Presenter) {
                $post_id = get_the_ID();
                if (!$post_id) continue;

                $translated_desc = get_post_meta($post_id, "_yoast_wpseo_metadesc_{$current_lang}", true);
                if (empty($translated_desc)) continue;

                $original = $presenter->present();

                // JSON formatında olduğundan emin olmak için temizle
                $json = trim(str_replace(['<script type="application/ld+json" class="yoast-schema-graph">', '</script>'], '', $original));
                $schema = json_decode($json, true);

                if (!is_array($schema) || !isset($schema['@graph'])) continue;

                foreach ($schema['@graph'] as &$piece) {
                    if (!is_array($piece)) continue;

                    $types = (array) ($piece['@type'] ?? []);
                    if (in_array('WebPage', $types)) {
                        $piece['description'] = $translated_desc;
                    }
                }

                $presenters[$i] = new class($schema) extends \Yoast\WP\SEO\Presenters\Schema_Presenter {
                    private $schema;

                    public function __construct($schema) {
                        $this->schema = $schema;
                    }

                    public function present() {
                        return '<script type="application/ld+json" class="yoast-schema-graph">' .
                            wp_json_encode($this->schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) .
                            '</script>';
                    }
                };
            }
        }

        return $presenters;
    }
    public function frontend_image_alt($value, $object_id, $meta_key, $single) {
        $integration = $this->container->get('integration');
        $current_lang = $integration->current_language;
        $default_lang = $integration->default_language;

        if ($meta_key !== '_wp_attachment_image_alt' || get_post_type($object_id) !== 'attachment') {
            return $value;
        }

        if ($current_lang === $default_lang || is_admin()) {
            return $value;
        }

        $original = $value[0] ?? '';

        $translated = get_post_meta($object_id, "_salt_image_alt_{$current_lang}", true);
        $translated =  !empty($translated) ? $translated : $original;

        return [$translated];
    }


    public function get_meta_description(int $id, string $lang = "", string $type = "post"): ?string {
        $meta_key = !empty($lang) ? '_salt_metadesc_' . $lang : '_yoast_wpseo_metadesc';
        if ($type === 'term') {
            return get_term_meta($id, $meta_key, true) ?: null;
        }
        return get_post_meta($id, $meta_key, true) ?: null;
    }

    public function update_meta_description(int $id, string $meta_value, string $lang = "", string $type = "post"): void {
        $plugin = $this->container->get("plugin");
        $meta_key = !empty($lang) ? '_salt_metadesc_'.$lang : "_yoast_wpseo_metadesc";
        $plugin->log($meta_key." -> ".$meta_value);
        if ($type === 'term') {
            update_term_meta($id, $meta_key, $meta_value);
        } else {
            update_post_meta($id, $meta_key, $meta_value);
        }
    }

    public function get_meta_title(int $post_id): ?string {
        return get_post_meta($post_id, '_yoast_wpseo_title', true) ?: null;
    }

    public function update_meta_title(int $post_id, string $value): void {
        update_post_meta($post_id, '_yoast_wpseo_title', $value);
    }
    
    public function generate_seo_description($id=0, $type="post"): ?string {
        $plugin = $this->container->get("plugin");
        $ml_plugin = $plugin->ml_plugin["key"];
        $options = $plugin->options;
        $integration = $this->container->get("integration");
        $translator = $this->container->get('translator');

        if($options["seo"]["meta_desc"]["preserve"]){
            if(!empty($this->get_meta_description($id))){
                return null;
            }
        }
        
        if ($type === 'term') {
            $object = get_term($id);
            $title = $object->name;
            $content = $object->description;
        } else {
            $object = get_post($id);
            $title = $object->post_title;
            $content = $object->post_content;
        }
        
        if($ml_plugin == "qtranslate-xt"){
            $title   = qtranxf_use($integration->default_language, $title, false, false);
            $content = qtranxf_use($integration->default_language, $content, false, false);
        }
        $content = apply_filters('the_content', $content);
        $content = wp_strip_all_tags($content);
        $content = preg_replace('/\s+/', ' ', $content); // fazla boşlukları temizle
        $content = trim($content);
        $content = mb_substr($content, 0, 1000); // fazla uzamasın

        if(empty($content) && $integration->contents){
            $content = implode(" ", $integration->contents);
        }

        $plugin->log($content);

        $system = $translator->prompts["meta_desc"]["system"]();
        /*$system = "You are an assistant that generates SEO meta descriptions. Keep them under 155 characters, clear, informative, and natural. Do not return anything except the description. Do not include quote characters at the start or end. No explanations.";

        if(!empty($options["seo"]["meta_desc"]["prompt"])){
            $system .= $options["seo"]["meta_desc"]["prompt"];
        }
        $user = "Generate a meta description for the following content.";
        if ($title) {
            $user .= "\n\nTitle: " . $title;
        }
        $user .= "\n\nContent:\n" . trim($clean);*/

        $user = $translator->prompts["meta_desc"]["user"]("", $title, $content);

        $messages = [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ];
        $body = json_encode([
            'model' => $options["seo"]["meta_desc"]["model"] ?? 'gpt-4',
            'messages' => $messages,
            'temperature' => (float) $options["seo"]["meta_desc"]["temperature"] ?? $this->temperature_meta_desc,
        ]);

        $description = $translator->request($body);
        if (!empty($description) && is_string($description)) {
            $description = trim($description);
            $this->update_meta_description($id, $description);
            return $description;
        }
        return null;
    }


}
