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

        error_log("generate_seo_description -> title: ".$title);
        error_log("generate_seo_description -> content: ".$content);


        $content = apply_filters('the_content', $content);
        $content = wp_strip_all_tags($content);
        $content = preg_replace('/\s+/', ' ', $content); // fazla boşlukları temizle
        $content = trim($content);
        $content = mb_substr($content, 0, 1000); // fazla uzamasın

        if(empty($content) && $integration->contents){
            $content = implode(" ", $integration->contents);
        }

        error_log("generate_seo_description -> content filtered: ".$content);

        $plugin->log($content);

        $system = $translator->prompts["meta_desc"]["system"]();
        $user = $translator->prompts["meta_desc"]["user"]($integration->default_language, $title, $content);

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

        error_log("generate_seo_description -> content openai reponse: ".$description);

        if (!empty($description) && is_string($description)) {
            $description = trim($description);
            error_log("generate_seo_description -> update_meta_description(".$id.", ".$description);
            $this->update_meta_description($id, $description);
            return $description;
        }
        return null;
    }

    public function get_sitemap_urls($sitemap_url = null, $urls = []) {
        if ($sitemap_url === null) {
            $sitemap_url = function_exists('site_url') ? site_url('/sitemap_index.xml') : '/sitemap_index.xml';
        }

        $sitemap_content = @file_get_contents($sitemap_url);
        if (!$sitemap_content) { return []; }

        $xml = @simplexml_load_string($sitemap_content);
        if(!$xml){ return []; }

        $namespaces = $xml->getDocNamespaces(true);
        if (isset($namespaces[''])) {
            $xml->registerXPathNamespace('ns', $namespaces['']);
        } else {
            $xml->registerXPathNamespace('ns', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        }

        $sitemap_path      = parse_url($sitemap_url, PHP_URL_PATH) ?: '';
        $sitemap_file_name = preg_replace('/-sitemap\.xml$/', '', basename($sitemap_path));
        $roles             = method_exists($this, 'get_roles') ? (array) $this->get_roles() : [];

        if ($xml->xpath('//ns:sitemap')) {
            foreach ($xml->xpath('//ns:sitemap/ns:loc') as $sitemap_loc) {
                $sub_sitemap_url = (string)$sitemap_loc;
                $urls = $this->get_sitemap_urls($sub_sitemap_url, $urls);
            }
            return $urls;
        }

        foreach ($xml->xpath('//ns:url/ns:loc') as $url_loc) {
            $url_string = (string)$url_loc;

            //if (in_array($sitemap_file_name, $this->excluded_post_types, true)) continue;
            //if (in_array($sitemap_file_name, $this->excluded_taxonomies, true)) continue;

            // === (A) ROLE-BAZLI USER SİTEMAPLERİ ===
            // Örn: /artist-sitemap.xml, /editor-sitemap.xml, projendeki özel roller...
            if (!empty($roles) && in_array($sitemap_file_name, $roles, true)) {
                // Senin eski mantığınla birebir:
                $author_name = basename($url_string);
                $author = function_exists('get_user_by') ? get_user_by('slug', $author_name) : null;
                if ($author) {
                    $urls[] = [
                        "id"        => $author->ID,
                        "type"      => "user",
                        "post_type" => $sitemap_file_name, // role adı
                        "url"       => $url_string
                    ];
                }
                continue;
            }

            // === (B) COMMENT SİTEMAP ===
            if ($sitemap_file_name === 'comment') {
                $author_name = basename($url_string);
                $author = function_exists('get_user_by') ? get_user_by('slug', $author_name) : null;
                if ($author) {
                    $urls[] = [
                        "id"        => $author->ID,
                        "type"      => "comment",
                        "post_type" => "comment",
                        "url"       => $url_string
                    ];
                }
                continue;
            }

            // === (C) AUTHOR SİTEMAP (standart) ===
            if ($sitemap_file_name === 'author') {
                if (preg_match('~/author/([^/]+)/?~i', $url_string, $m)) {
                    $user = function_exists('get_user_by') ? get_user_by('slug', sanitize_title($m[1])) : null;
                    if ($user) {
                        $urls[] = [
                            "id"        => $user->ID,
                            "type"      => "user",
                            "post_type" => "author",
                            "url"       => $url_string
                        ];
                    }
                }
                continue;
            }

            // === (D) POST / PAGE / CPT ===
            if ($sitemap_file_name === 'post' || $sitemap_file_name === 'page' || (function_exists('post_type_exists') && post_type_exists($sitemap_file_name))) {

                $post_id = function_exists('url_to_postid') ? url_to_postid($url_string) : 0;

                // CPT arşiv: senin yeni mantığını koruyoruz
                if (!$post_id) {
                    $url_path = parse_url($url_string, PHP_URL_PATH);
                    $url_segments = array_filter(explode('/', $url_path));
                    $url_endpoint = end($url_segments);
                    if ($url_endpoint == $sitemap_file_name && $this->is_default_lang_url($url_string)) {
                        $urls[] = [
                            "id"        => $sitemap_file_name,
                            "type"      => "archive",
                            "post_type" => $sitemap_file_name,
                            "url"       => $url_string
                        ];
                        continue;
                    }
                }

                // Fallback: slug'tan CPT objesi
                if (!$post_id && function_exists('get_page_by_path')) {
                    $slug = sanitize_title(basename(rtrim($url_string, '/')));
                    $obj  = get_page_by_path($slug, OBJECT, $sitemap_file_name);
                    if ($obj) { $post_id = (int) $obj->ID; }
                }

                if ($post_id) {
                    $urls[] = [
                        "id"        => $post_id,
                        "type"      => "post",
                        "post_type" => function_exists('get_post_type') ? get_post_type($post_id) : $sitemap_file_name,
                        "url"       => $url_string
                    ];
                }
                continue;
            }

            // === (E) TAXONOMY ===
            $tax_alias = ['format' => 'post_format'];
            $tax_name  = $tax_alias[$sitemap_file_name] ?? $sitemap_file_name;

            if (in_array($sitemap_file_name, ['category','post_tag','post_format'], true) ||
                (function_exists('taxonomy_exists') && taxonomy_exists($tax_name))) {

                $term_slug = sanitize_title(basename(rtrim($url_string, '/')));
                $term      = function_exists('get_term_by') ? get_term_by('slug', $term_slug, $tax_name) : null;

                if ($term && !is_wp_error($term)) {
                    $urls[] = [
                        "id"        => $term->term_id,
                        "type"      => "term",
                        "post_type" => $tax_name,
                        "url"       => $url_string
                    ];
                }
                continue;
            }

            // === (F) DİĞERLERİ: gerçekten archive/özel sitemap ===
            $urls[] = [
                "id"        => $sitemap_file_name,
                "type"      => "archive",
                "post_type" => $sitemap_file_name,
                "url"       => $url_string
            ];
        }

        return $urls;
    }

    public function get_roles() {
        global $wp_roles;
        $roles = [];
        if (!isset($wp_roles)) {
            $wp_roles = new WP_Roles();
        }
        foreach ($wp_roles->roles as $role_key => $role_details) {
            $name = $role_details['name'];
            $roles[] = $role_key;
        }
        return $roles;
    }

    private function is_default_lang_url(string $url): bool {
        $integration = $this->container->get('integration');
        return $def && (strtolower($this->lang_from_url($url)) === $integration->default_language);
    }
    /** URL’den dil çıkar (path’in her segmentinde ara) */
    private function lang_from_url(string $url): string {
        $integration = $this->container->get('integration');
        $default = strtolower($integration->default_language);
        $langs   = array_map('strtolower', $this->lang_list());
        if (!$langs) return $default ?: '';

        $clean = strtok($url, '?#');
        $base  = rtrim(home_url('/'), '/');
        $path  = (stripos($clean, $base) === 0)
            ? ltrim(substr($clean, strlen($base)), '/')
            : ltrim((wp_parse_url($clean)['path'] ?? ''), '/');

        foreach (array_values(array_filter(explode('/', $path), 'strlen')) as $seg) {
            $seg = strtolower($seg);
            if (ctype_digit($seg)) continue;
            if (in_array($seg, $langs, true)) return $seg;
        }
        return $default ?: '';
    }
    private function lang_list(): array {
        $integration = $this->container->get('integration');
        return array_keys($integration->get_languages());
        /*if (isset($GLOBALS['languages']) && is_array($GLOBALS['languages'])) {
            $names = array_column($GLOBALS['languages'], 'name');
            return array_values(array_filter(array_map('strval', $names)));
        }
        return $this->lang_default() ? [$this->lang_default()] : [];*/
    }

}
