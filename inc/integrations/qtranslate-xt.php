<?php

namespace SaltAI\Integrations;

use SaltAI\Core\ServiceContainer;

if (!defined('ABSPATH')) exit;

if (!function_exists('get_post_type_supports')) {
    require_once ABSPATH . WPINC . '/post.php';
}

class Integration {
	private ServiceContainer $container;
    private $repeater_index;
    public $default_language;
    public $current_language;
    public $attachments = [];
    public $contents = [];
    
    public function __construct($container) {
    	global $q_config;
        $this->container = $container;
	    $this->default_language = $q_config['default_language'] ?? 'en';
	    $this->current_language = $q_config['language'] ?? 'en';
	    $this->repeater_index = "";
	}

    public function translate_text($text = '', $lang = 'en') {
    	$translator = $this->container->get('translator');
    	if(!$translator){
			return $text;
		}
        return $translator->translate($text, $lang);
    }

	public function translate_blocks($post_content = "", $lang = "en") {
	    $blocks = parse_blocks($post_content);
	    $new_blocks = [];

	    foreach ($blocks as $block) {
	        // ACF block'lar özel olarak ele alınır
	        if (isset($block['blockName']) && strpos($block['blockName'], 'acf/') === 0) {
	            if (isset($block['attrs']['data']) && is_array($block['attrs']['data'])) {
	                foreach ($block['attrs']['data'] as $key => $val) {
	                    if (strpos($key, '_') === 0) continue;
	                    $field_object = get_field_object($key);
	                    if (!$field_object) continue;
	                    $type = $field_object['type'];
	                    if (in_array($type, ['qtranslate_text', 'qtranslate_textarea', 'qtranslate_wysiwyg']) && is_string($val)) {
	                    	$this->contents[] = $val;
	                        $block['attrs']['data'][$key] = $this->translate_text($val, $lang);
	                    }
	                }
	            }

	        // Diğer block'lar için heuristic kontrol
	        } elseif ($this->block_contains_translatable_text($block)) {
	            // innerHTML çevir
	            if (isset($block['innerHTML']) && is_string($block['innerHTML'])) {
	            	$this->contents[] = $block['innerHTML'];
	                $block['innerHTML'] = $this->translate_text($block['innerHTML'], $lang);
	            }

	            // innerContent çevir
	            if (isset($block['innerContent']) && is_array($block['innerContent'])) {
	                $block['innerContent'] = array_map(function($item) use ($lang) {
	                	$this->contents[] = $block['innerHTML'];
	                    return is_string($item) ? $this->translate_text($item, $lang) : $item;
	                }, $block['innerContent']);
	            }

	            // attrs altındaki metinsel alanlar çevir
	            if (isset($block['attrs']) && is_array($block['attrs'])) {
	                foreach (['text', 'content'] as $attr_key) {
	                    if (!empty($block['attrs'][$attr_key]) && is_string($block['attrs'][$attr_key])) {
	                    	$this->contents[] = $block['attrs'][$attr_key];
	                        $block['attrs'][$attr_key] = $this->translate_text($block['attrs'][$attr_key], $lang);
	                    }
	                }
	            }
	        }

	        $new_blocks[] = $block;
	    }

	    return serialize_blocks($new_blocks);
	}

	private function block_contains_translatable_text($block) {
	    // innerHTML varsa ve boş değilse
	    if (!empty($block['innerHTML']) && is_string($block['innerHTML']) && trim(strip_tags($block['innerHTML'])) !== '') {
	        return true;
	    }

	    // innerContent varsa ve metin içeriyorsa
	    if (!empty($block['innerContent']) && is_array($block['innerContent'])) {
	        foreach ($block['innerContent'] as $item) {
	            if (is_string($item) && trim(strip_tags($item)) !== '') {
	                return true;
	            }
	        }
	    }

	    // attrs içinde text veya content varsa
	    if (isset($block['attrs']) && is_array($block['attrs'])) {
	        foreach (['text', 'content'] as $key) {
	            if (!empty($block['attrs'][$key]) && is_string($block['attrs'][$key]) && trim(strip_tags($block['attrs'][$key])) !== '') {
	                return true;
	            }
	        }
	    }

	    return false;
	}

    public function translate_acf_fields($post_id = 0, $lang = "en") { // ok
        $fields = get_field_objects($post_id);
        if (!$fields) return [];
        $translated_data = [];
        foreach ($fields as $field_key => $field) {
            if (strpos($field_key, '_') === 0) continue;
            $translated_data[$field_key] = $this->translate_acf_field($field, $field['value'], $lang, $post_id);
        }
        return $translated_data;
    }
    public function translate_acf_field($field, $value, $lang, $post_id = 0) { // ok
        if (!$field || !isset($field['type'])) return $value;

        $plugin = $this->container->get("plugin");

        $type = $field['type'];

        if($plugin->options["seo"]["image_alttext"]["generate"]){
	        // 🎯💾 IMAGE alanı (tek görsel)
		    if ($type === 'image' && is_numeric($value)) {
		        $attachment = get_post($value);
		        if ($attachment && $attachment->post_type === 'attachment') {
		            $url = wp_get_attachment_image_src($value, 'full')[0] ?? '';
		            if ($url) {
		                $this->attachments[] = [
		                    'id'  => $value,
		                    'url' => $url,
		                ];
		            }
		        }
		    }

		    // 🎯💾 GALLERY alanı (çoklu görsel)
		    if ($type === 'gallery' && is_array($value)) {
		        foreach ($value as $gallery_item_id) {
		            if (!is_numeric($gallery_item_id)) continue;

		            $attachment = get_post($gallery_item_id);
		            if ($attachment && $attachment->post_type === 'attachment') {
		                $url = wp_get_attachment_image_src($gallery_item_id, 'full')[0] ?? '';
		                if ($url) {
		                    $this->attachments[] = [
		                        'id'  => $gallery_item_id,
		                        'url' => $url,
		                    ];
		                }
		            }
		        }
		    }
        }

        // Dil destekli alanlar
        if (in_array($type, ['qtranslate_text', 'qtranslate_textarea', 'qtranslate_wysiwyg']) && is_string($value) && strlen($value) > 0) {
            $value = get_acf_raw_field_value( $post_id, $field);
            $value_input = qtranxf_use($this->default_language, $value, false, false);
            $this->contents[] = $value_input;
            return $this->append_translation($value, $this->translate_text($value_input, $lang), $lang);
        }

        // Group
        if ($type === 'group' && is_array($value)) {
            $result = [];
            foreach ($field['sub_fields'] as $sub_field) {
                $sub_key = $sub_field['name'];
                $sub_val = $value[$sub_key] ?? null;
                $result[$sub_key] = $this->translate_acf_field($sub_field, $sub_val, $lang, $post_id);
            }
            return $result;
        }

        // Repeater
        if ($type === 'repeater' && is_array($value)) {
            $result = [];
            foreach ($value as $row_index => $row) {
                $translated_row = [];
                foreach ($field['sub_fields'] as $sub_field) {
                    $sub_key = $sub_field['name'];
                    $sub_val = $row[$sub_key] ?? null;
                    $translated_row[$sub_key] = $this->translate_acf_field($sub_field, $sub_val, $lang, $post_id);
                }
                $result[] = $translated_row;
            }
            return $result;
        }

        // Flexible Content
        if ($type === 'flexible_content' && is_array($value)) {
            $result = [];
            foreach ($value as $layout_row) {
                $layout_name = $layout_row['acf_fc_layout'];
                $translated_row = ['acf_fc_layout' => $layout_name];

                $layout = array_filter($field['layouts'], fn($l) => $l['name'] === $layout_name);
                $layout = array_values($layout)[0] ?? null;
                if (!$layout || !isset($layout['sub_fields'])) continue;

                foreach ($layout['sub_fields'] as $sub_field) {
                    $sub_key = $sub_field['name'];
                    $sub_val = $layout_row[$sub_key] ?? null;
                    $translated_row[$sub_key] = $this->translate_acf_field($sub_field, $sub_val, $lang, $post_id);
                }

                $result[] = $translated_row;
            }
            return $result;
        }

        // Diğer alan tipleri (değiştirme)
        return $value;
    }
	public function get_acf_raw_field_value( $post_id, $field ) {
	    global $wpdb;

	    $result = "";
	    $is_term = false;

	    // Eğer post_id 'term_' ile başlıyorsa term olduğunu anla
	    if ( is_string($post_id) && strpos($post_id, 'term_') === 0 ) {
	        $is_term = true;
	        $term_id = intval(str_replace('term_', '', $post_id));
	        $meta_table = $wpdb->termmeta;
	        $meta_id_col = 'term_id';
	    } else {
	        $meta_table = $wpdb->postmeta;
	        $meta_id_col = 'post_id';
	    }

	    // Field key ile meta_key'i bul
	    $results = $wpdb->get_col(
	        $wpdb->prepare(
	            "SELECT meta_key FROM {$meta_table} WHERE {$meta_id_col} = %d AND meta_value = %s",
	            $is_term ? $term_id : $post_id,
	            $field["key"]
	        )
	    );

	    // Repeater varsa index'le uğraş
	    if ( isset($field["parent_repeater"]) ) {
	        $index = $this->repeater_index === "" ? 0 : $this->repeater_index;
	        $meta_key = $results[$index] ?? null;
	        $this->repeater_index = $index + 1;
	    } else {
	        $this->repeater_index = "";
	        $meta_key = $results[0] ?? null;
	    }

	    // Son olarak raw value'yu çek
	    if ( !empty($meta_key) ) {
	        $meta_key = ltrim($meta_key, '_');
	        $result = $wpdb->get_var(
	            $wpdb->prepare(
	                "SELECT meta_value FROM {$meta_table} WHERE {$meta_id_col} = %d AND meta_key = %s",
	                $is_term ? $term_id : $post_id,
	                $meta_key
	            )
	        );
	    }

	    return $result;
	}

	public function extract_images_from_html($html) {
	    if (!is_string($html) || stripos($html, '<img') === false) {
	        return;
	    }

	    preg_match_all('/<img[^>]+src=[\'"]([^\'"]+)[\'"]/i', $html, $matches);
	    if (!empty($matches[1])) {
	        foreach ($matches[1] as $src) {
	            if (!$src) continue;

	            $id = attachment_url_to_postid($src);
	            $this->attachments[] = [
	                'id'  => $id ?: null,
	                'url' => $src,
	            ];
	        }
	    }
	}

	public function translate_post($post_id = 0, $lang = "en") {

		$GLOBALS['salt_ai_doing_translate'] = true;

		$plugin = $this->container->get("plugin");
		$options = $plugin->options;

		error_log("-*-*-*-*-**-*-*- çeviri - ".$post_id);

	    $post = get_post($post_id);

	    $title_raw = $post->post_title;
	    $title = qtranxf_use($this->default_language, $title_raw, false, false);
	    $title_new = $this->translate_text($title, $lang);

	    $content_changed = $plugin->is_content_changed($post);

	    $content_raw = $post->post_content;
	    $content = qtranxf_use($this->default_language, $content_raw, false, false);


	    if (has_blocks($post)) {
	        $content_new = $this->translate_blocks($content, $lang);
	    }else{
	        if($plugin->options["seo"]["image_alttext"]["generate"]){
		        $this->extract_images_from_html($content);
		    }
	        $content_new = $this->translate_text($content, $lang);
	    }

	    $excerpt_raw = $post->post_excerpt;
	    $excerpt = qtranxf_use($this->default_language, $excerpt_raw, false, false);
	    $excerpt_new = post_type_supports($post->post_type, 'excerpt') ?
	        $this->translate_text($excerpt, $lang) : '';

	    $acf_fields = $this->translate_acf_fields($post_id, $lang);

	    $args = [
	        'ID' => $post_id,
	        'post_title' => $this->append_translation($title_raw, $title_new, $lang),
	        'post_content' => $this->append_translation($content_raw, $content_new, $lang)
	    ];
	    if(!empty($excerpt_new)){
	        $args["post_excerpt"] = $this->append_translation($excerpt_raw, $excerpt_new, $lang);
	    }

	    $plugin->log("Translated post [".$lang."]: ".$title." to ".$title_new);

	    wp_update_post($args);

	    if(class_exists("QTX_Module_Slugs")){
	        $slug = sanitize_title($title_new);
			$slug = wp_unique_post_slug($slug, $post_id, 'publish', $post->post_type, 0);
			update_post_meta($post_id, 'qtranslate_slug_'.$lang, $slug);
	    }

	    foreach ($acf_fields as $field_key => $field_value) {
	        update_field($field_key, $field_value, $post_id);
	    }

	    $description = "";
	    if($options["seo"]["meta_desc"]["generate"]){
			if(
				($options["seo"]["meta_desc"]["on_content_changed"] && $content_changed) || 
				(!$options["seo"]["meta_desc"]["on_content_changed"])
			){
				$seo = $this->container->get('seo');
				if($options["translator"] == "openai"){
					$description = $seo->generate_seo_description($post_id);
					$plugin->log("Generated meta description for: ".$title." -> ".$description);  	
				}
				if($options["seo"]["meta_desc"]["translate"]){	
					if(empty($description)){
						$description = $seo->get_meta_description($post_id);
					}
					if(!empty($description)){
						$description = $this->translate_text($description, $lang);
						$seo->update_meta_description($post_id, $description, $lang);
						$plugin->log("Translated meta description [".$lang."] for: ".$title." -> ".$description); 
					}
				} 
			}
	    }

	    if($this->attachments){
		    $image = $this->container->get("image");
		    $image->generate_alt_text($this->attachments, $lang);            	
        }

	    $GLOBALS['salt_ai_doing_translate'] = false;
	}
	public function translate_term($term_id = "", $taxonomy = "", $lang = 'en') {

		$GLOBALS['salt_ai_doing_translate'] = true;

	    $term = get_term($term_id, $taxonomy);
	    if (!$term || is_wp_error($term)) return;
 
	    $title = $this->get_term_i18n_value($term, "name", $this->default_language);
	    $title_new = $this->translate_text($title, $lang);
	    $title = $this->append_translation($term->i18n_config["name"]["ts"], $title_new, $lang);

	    if(isset($term->i18n_config["description"])){
	    	$description_raw = $term->i18n_config["description"]["ml"];
	    }else{
	    	$description_raw = $term->description;
	    }

	    $description = qtranxf_use($this->default_language, $description_raw, false, false);
	    $description_new = $this->translate_text($description, $lang);

	    $acf_fields = $this->translate_acf_fields("term_$term_id", $lang);

	    $args = [
	        'name' => $title,//$this->append_translation($title_raw, $title_new, $lang),
	        'description' => $this->append_translation($description_raw, $description_new, $lang),
	    ];

	    wp_update_term($term_id, $taxonomy, $args);

	    if(class_exists("QTX_Module_Slugs")){
	    	$slug = sanitize_title($title_new);
	    	update_term_meta($term_id, 'qtranslate_slug_'.$lang, $slug);
	    	foreach($term->i18n_config["name"]["ts"] as $key => $value){
				if($key != $lang){
					update_term_meta($term_id, 'qtranslate_slug_'.$key, sanitize_title($value));
				}
			}
	    }

	    foreach ($acf_fields as $field_key => $field_value) {
	        update_field($field_key, $field_value, "term_$term_id");
	    }

	    /*$description = "";
	    if($options["seo"]["meta_desc"]["generate"]){
			if(
				($options["seo"]["meta_desc"]["on_content_changed"] && $content_changed) || 
				(!$options["seo"]["meta_desc"]["on_content_changed"])
			){
				$seo = $this->container->get('seo');
				if($options["translator"] == "openai"){
					$description = $seo->generate_seo_description($post_id);
					$plugin->log("Generated meta description for: ".$title." -> ".$description);  	
				}
				if($options["seo"]["meta_desc"]["translate"]){	
					if(empty($description)){
						$description = $seo->get_meta_description($post_id);
					}
					if(!empty($description)){
						$description = $this->translate_text($description, $lang);
						$seo->update_meta_description($post_id, $description, $lang);
						$plugin->log("Translated meta description [".$lang."] for: ".$title." -> ".$description); 
					}
				} 
			}
	    }*/

	    if($this->attachments){
		    $image = $this->container->get("image");
		    $image->generate_alt_text($this->attachments, $lang);            	
        }

	    $GLOBALS['salt_ai_doing_translate'] = false;
	}


	public function get_untranslated_posts($lang_slug = 'en') {
		$plugin = $this->container->get('plugin');
		$options = $plugin->options;

		$excluded_posts = $options['exclude_posts'] ?? [];
		$excluded_post_types = $options['exclude_post_types'] ?? [];
		$retranslate = $options['retranslate_existing'] ?? false;

	    $results = [
	        "total"           => 0,
	        "need_translate"  => 0,
	        "status_text"     => '',
	        "posts"           => []
	    ];

	    $post_types = get_post_types([
	        'public'   => true,
	        'show_ui'  => true,
	        '_builtin' => false
	    ], 'names');
	    $post_types = array_merge(['post', 'page'], $post_types);
	    $post_types = array_diff($post_types, $excluded_post_types);

        $total_index = 0;
        $need_translate_index = 0;
	    foreach ($post_types as $post_type) {
	        $posts = get_posts([
	            'post_type'        => $post_type,
	            'post_status'      => 'publish',
	            'numberposts'      => -1,
	            'suppress_filters' => false,
	            'post__not_in'     => $excluded_posts,
	        ]);

	        foreach ($posts as $post) {

	            $title_support   = post_type_supports($post_type, 'title');
	            $editor_support  = post_type_supports($post_type, 'editor') && get_page_template_slug($post->ID) !== 'template-layout.php';
	            $excerpt_support = post_type_supports($post_type, 'excerpt');

	            $title   = qtranxf_use($this->default_language, $post->post_title, false, false);
	            $content = qtranxf_use($this->default_language, $post->post_content, false, false);
	            $excerpt = qtranxf_use($this->default_language, $post->post_excerpt, false, false);

	            $has_trans = ($title_support && (!empty($title) && !$this->has_translation($post->post_title, $lang_slug))) ||
	                ($editor_support  && (!empty($content) && !$this->has_translation($post->post_content, $lang_slug))) ||
	                ($excerpt_support && (!empty($excerpt) && !$this->has_translation($post->post_excerpt, $lang_slug)));

	            $has_translatable = (
	            	($title_support && !empty($title)) ||
	                ($editor_support  && !empty($content)) ||
	                ($excerpt_support && !empty($excerpt))
	            );

	            if($has_translatable){
		            if ($retranslate) {
		                $results["posts"][] = [
		                    'post_type' => $post_type,
		                    'ID'        => $post->ID,
		                    'title'     => $title,
		                ];
		            }else{
		            	if ($has_trans) {
			                $results["posts"][] = [
			                    'post_type' => $post_type,
			                    'ID'        => $post->ID,
			                    'title'     => $title,
			                ];
			            }
		            }
			        $results["total"] = ++$total_index;
			        if($has_trans){
			           $results["need_translate"] = ++$need_translate_index;
			        }	            	
	            }

	        }
	    }

	    $total = $results["total"];
	    $need = $results["need_translate"];
	    $translated = $total - $need;

	    if ($need > 0) {
	        if ($retranslate) {
	            if ($translated > 0) {
	                $results["status_text"] = sprintf(
	                    __('%1$d translated, total %2$d posts will be retranslated to "%3$s".', 'salt-ai-translator'),
	                    $translated,
	                    $total,
	                    $this->get_language_label($lang_slug)
	                );
	            } else {
	                $results["status_text"] = sprintf(
	                    __('%1$d posts will be translated to "%2$s".', 'salt-ai-translator'),
	                    $total,
	                    $this->get_language_label($lang_slug)
	                );
	            }
	        } else {
	            $results["status_text"] = sprintf(
	                __('%1$d out of %2$d posts not translated to "%3$s".', 'salt-ai-translator'),
	                $need,
	                $total,
	                $this->get_language_label($lang_slug)
	            );
	        }
	    } else {
	        if ($retranslate) {
	        	$results["need_translate"] = $total;
	            $results["status_text"] = sprintf(
	                __('All %1$d posts is already translated to "%2$s". They will be retranslated.', 'salt-ai-translator'),
	                $total,
	                $this->get_language_label($lang_slug)
	            );
	        } else {
	            $results["status_text"] = sprintf(
	                __('All %1$d posts is already translated to "%2$s".', 'salt-ai-translator'),
	                $total,
	                $this->get_language_label($lang_slug)
	            );
	        }
	    }

	    return $results;
	}
	public function get_untranslated_terms($lang_slug = 'en') {
	    $plugin = $this->container->get('plugin');
		$options = $plugin->options;

	    $excluded_terms = $options['exclude_terms'] ?? [];
	    $excluded_taxonomies = $options['exclude_taxonomies'] ?? [];
	    $retranslate = !empty($options['retranslate_existing']);

	    $results = [
	        "total"           => 0,
	        "need_translate"  => 0,
	        "status_text"     => '',
	        "terms"           => []
	    ];

	    $taxonomies = get_taxonomies([
	        'public'   => true,
	        'show_ui'  => true,
	    ], 'names');
	    $taxonomies = array_diff($taxonomies, $excluded_taxonomies);

	    foreach ($taxonomies as $taxonomy) {
	        $terms = get_terms([
	            'taxonomy'   => $taxonomy,
	            'hide_empty' => false,
	            'exclude'    => $excluded_terms,
	        ]);

	        foreach ($terms as $term) {
	            $name = $this->get_term_i18n_value($term, "name", $this->default_language);
	            $name_translated = $this->get_term_i18n_value($term, "name", $lang_slug);
	            $description = $this->get_term_i18n_value($term, "description", $this->default_language);
	            $description_translated = $this->get_term_i18n_value($term, "description", $lang_slug);

	            $needs_translation =
	                (!empty($name) && empty($name_translated)) ||
	                (!empty($description) && empty($description_translated));

	            $has_translatable = !empty($name) || !empty($description);

	            if ($has_translatable) {
	                $results["total"]++;

	                if ($retranslate || $needs_translation) {
	                    $results["terms"][] = [
	                        'taxonomy' => $taxonomy,
	                        'term_id'  => $term->term_id,
	                        'name'     => $name
	                    ];
	                }

	                if ($needs_translation) {
	                    $results["need_translate"]++;
	                }
	            }
	        }
	    }

	    $total = $results["total"];
	    $need = $results["need_translate"];
	    $translated = $total - $need;

	    if ($need > 0) {
	        if ($retranslate) {
	            if ($translated > 0) {
	                $results["status_text"] = sprintf(
	                    __('%1$d translated, total %2$d posts will be retranslated to "%3$s".', 'salt-ai-translator'),
	                    $translated,
	                    $total,
	                    $this->get_language_label($lang_slug)
	                );
	            } else {
	                $results["status_text"] = sprintf(
	                    __('%1$d posts will be translated to "%2$s".', 'salt-ai-translator'),
	                    $total,
	                    $this->get_language_label($lang_slug)
	                );
	            }
	        } else {
	            $results["status_text"] = sprintf(
	                __('%1$d out of %2$d posts not translated to "%3$s".', 'salt-ai-translator'),
	                $need,
	                $total,
	                $this->get_language_label($lang_slug)
	            );
	        }
	    } else {
	        if ($retranslate) {
	        	$results["need_translate"] = $total;
	            $results["status_text"] = sprintf(
	                __('All %1$d posts is already translated to "%2$s". They will be retranslated.', 'salt-ai-translator'),
	                $total,
	                $this->get_language_label($lang_slug)
	            );
	        } else {
	            $results["status_text"] = sprintf(
	                __('All %1$d posts is already translated to "%2$s".', 'salt-ai-translator'),
	                $total,
	                $this->get_language_label($lang_slug)
	            );
	        }
	    }

	    return $results;
	}


	public function autocomplete_posts($query = "", $page = 1) {
	    global $wpdb;

	    $post_types = get_post_types([
	        'public'   => true,
	        'show_ui'  => true,
	        '_builtin' => false
	    ], 'names');
	    $post_types = array_merge(['post', 'page'], $post_types);
	    $post_types_sql = implode("','", array_map('esc_sql', $post_types));

	    $offset = ($page - 1) * 20;
	    $like_query = '%' . $wpdb->esc_like($query) . '%';

	    // Dil kontrolü
	    $default_lang = function_exists('qtranxf_getLanguage') ? qtranxf_getLanguage() : 'en';

	    // Sorgu
	    $sql = $wpdb->prepare("
	        SELECT ID, post_title 
	        FROM {$wpdb->posts}
	        WHERE post_type IN ('$post_types_sql')
	        AND post_status = 'publish'
	        AND post_title LIKE %s
	        ORDER BY post_date DESC
	        LIMIT 20 OFFSET %d
	    ", $like_query, $offset);

	    $posts = $wpdb->get_results($sql);

	    $results = [];
	    foreach ($posts as $post) {
	        $title = qtranxf_use($default_lang, $post->post_title, false, false);
	        $results[] = [
	            'id' => $post->ID,
	            'text' => $title,
	        ];
	    }

	    // Toplam eşleşen sayıyı al (sayfa başına 20, bir fazlası varsa devam var demek)
	    $sql_count = $wpdb->prepare("
	        SELECT COUNT(*) FROM {$wpdb->posts}
	        WHERE post_type IN ('$post_types_sql')
	        AND post_status = 'publish'
	        AND post_title LIKE %s
	    ", $like_query);
	    $total_count = (int) $wpdb->get_var($sql_count);

	    $has_more = ($page * 20) < $total_count;

	    return [
	        'items' => $results,
	        'has_more' => $has_more
	    ];
	}
	public function autocomplete_terms($query = "", $page = 1) {
	    global $wpdb;

	    $taxonomies = get_taxonomies([
	        'public'   => true,
	        'show_ui'  => true,
	    ], 'names');

	    $offset = ($page - 1) * 20;

	    // SQL sorgusu
	    $query_like = '%' . $wpdb->esc_like($query) . '%';
	    $tax_sql = implode("','", array_map('esc_sql', $taxonomies));

	    $sql = $wpdb->prepare("
	        SELECT t.term_id, t.name, tt.taxonomy
	        FROM {$wpdb->terms} AS t
	        INNER JOIN {$wpdb->term_taxonomy} AS tt ON t.term_id = tt.term_id
	        WHERE tt.taxonomy IN ('$tax_sql')
	        AND t.name LIKE %s
	        ORDER BY t.name ASC
	        LIMIT 20 OFFSET %d
	    ", $query_like, $offset);

	    $results_raw = $wpdb->get_results($sql);

	    $results = [];
	    foreach ($results_raw as $term) {
	        $term_id = $term->term_id;
	        $default_lang = $this->default_language ?? 'en';

	        // qTranslate XT dil kontrolü
	        $i18n_config = get_term_meta($term_id, 'i18n_config', true);
	        $translated = $i18n_config['name']['ts'][$default_lang] ?? $term->name;

	        $results[] = [
	            'id' => $term_id,
	            'text' => $translated
	        ];
	    }

	    // toplamı kontrol et (has_more için)
	    $sql_count = $wpdb->prepare("
	        SELECT COUNT(*)
	        FROM {$wpdb->terms} AS t
	        INNER JOIN {$wpdb->term_taxonomy} AS tt ON t.term_id = tt.term_id
	        WHERE tt.taxonomy IN ('$tax_sql')
	        AND t.name LIKE %s
	    ", $query_like);
	    $total_count = (int) $wpdb->get_var($sql_count);

	    $has_more = ($page * 20) < $total_count;

	    return [
	        'items' => $results,
	        'has_more' => $has_more
	    ];
	}
    

	public function has_translation($field_value, $lang_slug) { // qtranxf_split($text); ile split et olusan arrayi isset ile kontrol et
	    if (empty($field_value)) return false;

	    //qtranxf_getAvailableLanguages($field_value); string içindeki dilleri [en,tr] şeklinde dondurur.

	    // Basit kontrol: [:en] etiketiyle başlayan içerik var mı?
	    if (strpos($field_value, "[:$lang_slug]") !== false) {
	        return true;
	    }
	    return false;
	}
	public function parse_translation($str) { // qtranxf_split($text);
	    $translations = [];
	    $str = preg_replace('/\[:\]/', '', $str);
	    $parts = preg_split('/(\[:[a-z]{2}\])/', $str, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
	    $lang = null;
	    foreach ($parts as $part) {
	        if (preg_match('/\[:([a-z]{2})\]/', $part, $match)) {
	            $lang = $match[1];
	        } elseif ($lang) {
	            $translations[$lang] = $part;
	            $lang = null;
	        }
	    }
	    return $translations;
	}	
	public function append_translation($original, $translated, $lang) { // ekleme yaptıktan sonra qtranxf_join_b(); ile birleştir arrayi
	    if(empty($original) || empty($translated)){
	        return $original;
	    }
        
        if(is_array($original)){
        	$translations = $original;
        }else{
        	$translations = $this->parse_translation($original);
        }
	    
	    if(!empty($translated)){
	    	$translations[$lang] = $translated;	    	
	    }

	    if (!isset($translations[$this->default_language]) && count($translations) == 1) {
	       $translations[$this->default_language] = $original;
	    }

	    $final = '';
	    foreach ($translations as $l => $t) {
	        $final .= "[:".$l."]".$t;
	    }
	    $final .= '[:]';

	    $final = str_replace("[:][:]", "[:]", $final);
	    $final = str_replace("[:$lang][:$lang]", "[:$lang]", $final);
	    foreach ($translations as $key => $l) {
	        $final = str_replace("[:$key][:$key]", "[:$key]", $final);
	    }
	    return $final;
	}

	public function get_term_i18n_value($term, $field = 'name', $lang = null) {
	    if (!is_object($term)) {
	        $term = get_term($term); // ID verdiysek nesneye çevir
	    }

	    if (!$lang && function_exists('qtranxf_getLanguage')) {
	        $lang = qtranxf_getLanguage();
	    }

	    // fallback
	    $lang = $lang ?: 'en';

	    if (
	        isset($term->i18n_config[$field]['ts'][$lang]) &&
	        !empty($term->i18n_config[$field]['ts'][$lang])
	    ) {
	        return $term->i18n_config[$field]['ts'][$lang];
	    }

	    // fallback olarak orijinal alanı döndür
	    return $term->$field ?? '';
	}
	public function update_term_name($lang="en", $default_value="", $translated_value="") {

		error_log("update_term_name(".$lang.", ".$default_value.", ".$translated_value.")");

		 $translations = get_option("qtranslate_term_name", []);

		 $default_value = trim($default_value);
	   
	    if (!isset($translations[$default_value])) {
	        $translations[$default_value] = [];
	    }
	    $translations[$default_value][$lang] = $translated_value;

	    return update_option("qtranslate_term_name", $translations);
	}

	public function get_languages($ignore_default = true){
    	$languages = [];
    	foreach (qtranxf_getSortedLanguages() as $language) {
    		if($language == $this->default_language && $ignore_default){
	    		continue;	
    		}
	    	$languages[$language] = qtranxf_getLanguageName($language);
    	}
    	return $languages;
    }

    public function get_language_label($lang="en") {
	    return $this->get_languages()[$lang];
	}
    

    public function is_media_translation_enabled(){
    	return true;
    }

}