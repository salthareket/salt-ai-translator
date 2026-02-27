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
    public $acf_fields_raw = [];
    
    public function __construct($container) {
    	global $q_config;
        $this->container = $container;
	    $this->default_language = $q_config['default_language'] ?? 'en';
	    $this->current_language = $q_config['language'] ?? 'en';
	    $this->repeater_index = "";
	}

	public function is_translatable_post_type($post_type): bool {
		return true;
	}
	public function is_translatable_taxonomy($taxonomy): bool {
		return true;
	}

    public function translate_text($text = '', $lang = 'en', $custom_prompt = "") {
    	$translator = $this->container->get('translator');
    	if(!$translator || empty($text)){
			return $text;
		}
		if(!empty($custom_prompt)){
			$translator->set_custom_prompt($custom_prompt);
		}

		$this->container->get("plugin")->log("input: ".$text);
        $output = $translator->translate($text, $lang);
        $this->container->get("plugin")->log("output: ".$output);
        $this->container->get("plugin")->log("----------------------------");
		return $output;
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

	public function extract_acf_fields($source_array = []){
	    $text_fields = [];
	    $image_ids = [];
	    $field_map = [];
	    foreach($source_array as $k => $v){
	        if(!isset($v[0])) continue;
	        $field_val = $v[0];
	        if(strpos($field_val, 'field_') !== 0) continue;
	        if(strpos($field_val, '_field_') !== false){
	            $parts = explode('_field_', $field_val);
	            $field_val = 'field_' . end($parts);
	        }
	        $field_key = ltrim($k, '_');
	        $field_map[$field_key] = $field_val;
	    }
	    foreach($field_map as $key => $field_id){
	        $field_obj = get_field_object($field_id);
	        if(!$field_obj) continue;
	        if(empty($source_array[$key][0])) continue;

	        $type = $field_obj['type'];
	        if(in_array($type, ['qtranslate_text','qtranslate_textarea','qtranslate_wysiwyg'])){
	            $text_fields[$key] = $source_array[$key][0];
	        }
	        if(in_array($type, ['qtranslate_image','gallery','image'])){
	            $image_ids[] = $source_array[$key][0];
	        }
	    }
	    return [
	        'text' => $text_fields,
	        'image' => $image_ids
	    ];
	}
	public function translate_acf_fields($fields = [], $object_id = 0, $lang = "en") {
	    if (!$object_id) return;

	    $plugin  = $this->container->get("plugin");
	    $options = $plugin->options;

	    $text_fields  = $fields["text"] ?? [];
	    $image_fields = $fields["image"] ?? [];

	    // Object type: post mu term mi?
	    $meta_type = "post";
	    if (is_string($object_id) && strpos($object_id, "term_") === 0) {
	        $meta_type = "term";
	        $object_id = (int) str_replace("term_", "", $object_id);
	    }

	    if ($text_fields) {
	        foreach ($text_fields as $meta_key => $value) {
	            if (empty($value)) continue;

	            $value_input  = qtranxf_use($this->default_language, $value, false, false);
	            $this->contents[] = $value_input;

	            $translated   = $this->translate_text($value_input, $lang);
	            $value_output = $this->append_translation($value, $translated, $lang);

	            update_metadata($meta_type, $object_id, $meta_key, $value_output);
	        }
	    }
	    if (!empty($options["seo"]["image_alttext"]["generate"])) {
			if($image_fields){
				$ids = [];
				foreach($array as $val){

					if(function_exists('qtranxf_isMultilingual') && qtranxf_isMultilingual($val)){
						$translated = qtranxf_use($lang, $val);
						if(is_numeric($translated)){
							$ids[] = (int)$translated;
						}
						continue;
					}

					if(is_string($val) && is_serialized($val)){
						$unser = maybe_unserialize($val);
						if(is_array($unser)){
							foreach($unser as $i){
								if(is_numeric($i)){
									$ids[] = (int)$i;
								}
							}
						}
						continue;
					}

					if(is_numeric($val)){
						$ids[] = (int)$val;
					}
				}

				$ids = array_values(array_unique($ids));
				foreach($ids as $id){
					$url = wp_get_attachment_url($id);
					if($url){
						$this->attachments[] = [
							'id'  => $id,
							'url' => $url
						];
					}
				}
			}
		}
	}

	public function translate_post_type_taxonomy($lang = "en"){
	    $results = [
	        "status" => true,
	        "status_text" => __("Post type & taxonomies not translated...", "salt-ai-translator")
	    ];
	    global $wpdb;
	    $posts = $wpdb->get_results( "SELECT id, post_title, post_content, post_type FROM {$wpdb->posts} WHERE post_type = 'acf-post-type' OR post_type = 'acf-taxonomy'", OBJECT );

	    if ($posts) {
	        foreach ($posts as $post) {
	            // post_content verisini serileştirilmiş string'den diziye dönüştür
	            $post_content_data = unserialize($post->post_content);
	            if ($post_content_data === false) {
	                // Eğer veri unserialize edilemiyorsa, hatayı atla veya logla
	                continue;
	            }

	            // Post başlığını çevir
	            $post_title_default = qtranxf_use($this->default_language, $post->post_title, false, false);
	            $post_title_translated = $this->translate_text($post_title_default, $lang, "Bu bir wordpress post_type yada taxonomy^sidir. BUnu dikkate alarak çeviriniz.");
	            $post_title = $this->append_translation($post->post_title, $post_title_translated, $lang);

	            // Labels dizisini kontrol et ve çevir
	            if (isset($post_content_data["labels"])) {
	                $labels = $post_content_data["labels"];

	                $prompt = "Bu bir wordpress ".($post->post_type == "acf-post-type"?"post type'ının ":"taxonomy'sinin ");

	                // 'name' etiketini çevir
	                if (isset($labels["name"])) {
	                	$prompt .= "plural versiyonudur. Buna dikkat ederk çeviri yapınız."; 
	                    $labels_name_default = qtranxf_use($this->default_language, $labels["name"], false, false);
	                    $labels_name_translated = $this->translate_text($labels_name_default, $lang, $prompt);
	                    $labels_name = $this->append_translation($labels["name"], $labels_name_translated, $lang);
	                    $post_content_data["labels"]["name"] = $labels_name;
	                    error_log($labels_name_default." - ".$labels_name_translated);
	                }

	                // 'singular_name' etiketini çevir
	                if (isset($labels["singular_name"])) {
	                	$prompt .= "singular versiyonudur. Buna dikkat ederk çeviri yapınız."; 
	                    $labels_singular_name_default = qtranxf_use($this->default_language, $labels["singular_name"], false, false);
	                    $labels_singular_name_translated = $this->translate_text($labels_singular_name_default, $lang, $prompt);
	                    $labels_singular_name = $this->append_translation($labels["singular_name"], $labels_singular_name_translated, $lang);
	                    $post_content_data["labels"]["singular_name"] = $labels_singular_name;
	                }
	            }

	            // Güncellenmiş post_content verisini tekrar serileştir
	            $updated_post_content = serialize($post_content_data);

	            // Veritabanını güncelle
	            $wpdb->update(
	                $wpdb->posts,
	                array(
	                    'post_title' => $post_title,
	                    'post_content' => $updated_post_content
	                ),
	                array('ID' => $post->id)
	            );
	            
	            // Slug işlemleri
	            if(class_exists("QTX_Module_Slugs")){
	                $meta_key = 'qtranslate_slug_' . $lang;
	                $slug_value = sanitize_title($post_title_translated);
	                $existing_meta = get_post_meta($post->id, $meta_key, true);
	                if (!empty($existing_meta)) {
	                    update_post_meta($post->id, $meta_key, $slug_value);
	                } else {
	                    add_post_meta($post->id, $meta_key, $slug_value, true);
	                }
	            }
	        }
	        flush_rewrite_rules(false);
	        $results["status_text"] = __("All post type & taxonomies translated.", "salt-ai-translator");
	    }
	    return $results;
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

	    $prompt_title = "You are translating a string that will be used as a **web page title**. The page represents a WordPress post of the post type: '{$post->post_type}'.
		Please follow these rules:
		- This is not just a word or sentence — it is the official **title of a page**, often seen in browser tabs, menus, or SEO titles.
		- Translate it **contextually** to sound natural and professional in the target language.
		- Do NOT translate literally if it doesn’t make sense — adjust the wording to reflect how a native speaker would title a similar page.
		- Keep it **short and meaningful**, avoid unnecessary filler words.
		- Do not include any formatting, tags, or symbols.";
	    $title_new = $this->translate_text($title, $lang, $prompt_title);

	    $content_changed = $this->is_post_content_changed($post);

	    error_log("content changed:".$content_changed);

	    $content_raw = $post->post_content;
	    $content = qtranxf_use($this->default_language, $content_raw, false, false);

	    if (has_blocks($post)) {
	        $content_new = $this->translate_blocks($content, $lang);
	    }else{
	        if (!empty($options['seo']['image_alttext']['generate'])){
		        $this->extract_images_from_html($content);
		    }
	        $content_new = $this->translate_text($content, $lang);
	    }

	    $excerpt_raw = $post->post_excerpt;
	    $excerpt = qtranxf_use($this->default_language, $excerpt_raw, false, false);
	    $excerpt_new = post_type_supports($post->post_type, 'excerpt') ?
	        $this->translate_text($excerpt, $lang) : '';

	    $args = [
	        'ID' => $post_id,
	        'post_title' => $this->append_translation($title_raw, $title_new, $lang),
	        'post_content' => $this->append_translation($content_raw, $content_new, $lang)
	    ];
	    if(!empty($excerpt_new)){
	        $args["post_excerpt"] = $this->append_translation($excerpt_raw, $excerpt_new, $lang);
	    }

	    $plugin->log("Translated post [".$lang."]: ".$title." to ".$title_new);

	    $acf_data = get_post_meta($post_id);

	    wp_update_post($args);

	    foreach ($acf_data as $meta_key => $value) {
		    update_post_meta($post_id, $meta_key, maybe_unserialize($value[0]));
		}

	    if(class_exists("QTX_Module_Slugs")){
	        $slug = sanitize_title($title_new);
			$slug = wp_unique_post_slug($slug, $post_id, 'publish', $post->post_type, 0);
			update_post_meta($post_id, 'qtranslate_slug_'.$lang, $slug);
	    }

	    $acf_data_translate = $this->extract_acf_fields($acf_data);
	    error_log(print_r($acf_data_translate, true));
	    $this->translate_acf_fields($acf_data_translate, $post_id, $lang);

	    $description = "";
	    if (
			    !empty($options["seo"]["meta_desc"]["generate"])
			    && (
			        (!empty($options["seo"]["meta_desc"]["on_content_changed"]) && $content_changed)
			        || !$options["seo"]["meta_desc"]["on_content_changed"]
			    )
		) {
			    $seo = $this->container->get('seo');
		        if($options["translator"] == "openai"){
				    $description = $seo->generate_seo_description($post_id);
				    error_log("1. description in default language: ".$description);
				    //$plugin->log("Generated meta description for: ".$post->post_title." -> ".$description);  	
			    }
			    if(!empty($options["seo"]["meta_desc"]["translate"])){	
			        if(empty($description)){
			            $description = $seo->get_meta_description($post_id);
			            error_log("2. description:".$description);
			        }
			        if(!empty($description)){
		            	$description = $this->translate_text($description, $lang);
		            	$seo->update_meta_description($post_id, $description, $lang);
		            	//$plugin->log("Translated meta description [".$lang."] for: ".$post->post_title." -> ".$description);
		            	error_log("3. description translated to $lang: ".$description);
			        }
				}
				error_log("4. description saved: ".$description);
		}

	    if($this->attachments){
	    	error_log("Attachments...");
	    	error_log(print_r($this->attachments, true));
		    $image = $this->container->get("image");
		    $image->generate_alt_text($this->attachments, $lang);            	
        }

	    $GLOBALS['salt_ai_doing_translate'] = false;
	}
	public function translate_term($term_id = "", $taxonomy = "", $lang = 'en') {

		$GLOBALS['salt_ai_doing_translate'] = true;

	    $term = get_term($term_id, $taxonomy);
	    error_log(print_r($term, true));
	    if (!$term || is_wp_error($term)) return;

	    $prompt_term_name = "These are taxonomy terms from a WordPress site under the '{$taxonomy}' taxonomy. Translate accordingly. Dont add html tags.";
		$prompt_term_description = "These are taxonomy terms from a WordPress site under the '{$taxonomy}' taxonomy. Translate accordingly.";
 
	    $title = $this->get_term_i18n_value($term, "name", $this->default_language);
	    $title_new = $this->translate_text($title, $lang, $prompt_term_name);
	    error_log("title:".$title." title_new:".$title_new);
	    $title = $this->append_translation($term->i18n_config["name"]["ts"], $title_new, $lang);


	    if(isset($term->i18n_config["description"])){
	    	$description_raw = $term->i18n_config["description"]["ml"];
	    }else{
	    	$description_raw = $term->description;
	    }

	    $description = qtranxf_use($this->default_language, $description_raw, false, false);
	    $description_new = $this->translate_text($description, $lang, $prompt_term_description);

	    $content_changed = $this->is_term_content_changed($term);

	    //$acf_fields = $this->translate_acf_fields("term_$term_id", $lang);

	    $acf_data = get_term_meta($term_id);

	    $args = [
	        'name' => $title,//$this->append_translation($title_raw, $title_new, $lang),
	        'description' => $this->append_translation($description_raw, $description_new, $lang),
	    ];

	    wp_update_term($term_id, $taxonomy, $args);

	    foreach ($acf_data as $meta_key => $value) {
		    if (!isset($value[0])) continue;
    		update_term_meta($term_id, $meta_key, maybe_unserialize($value[0]));
		}

	    if(class_exists("QTX_Module_Slugs")){
	    	$slug = sanitize_title($title_new);
	    	update_term_meta($term_id, 'qtranslate_slug_'.$lang, $slug);
	    	foreach($term->i18n_config["name"]["ts"] as $key => $value){
				if($key != $lang){
					update_term_meta($term_id, 'qtranslate_slug_'.$key, sanitize_title($value));
				}
			}
	    }

	    $acf_data_translate = $this->extract_acf_fields($acf_data);
	    error_log(print_r($acf_data_translate, true));
	    $this->translate_acf_fields($acf_data_translate, "term_$term_id", $lang);

	    /*foreach ($acf_fields as $field_key => $field_value) {
	        update_field($field_key, $field_value, "term_$term_id");
	    }*/

	    if(
			!empty($options["seo"]["meta_desc"]["generate"])
			&& (
			    (!empty($options["seo"]["meta_desc"]["on_content_changed"]) && $content_changed)
			    || !$options["seo"]["meta_desc"]["on_content_changed"]
			)
		) {
			$seo = $this->container->get("seo");
			$description = "";

			if ($options["translator"] === "openai") {
				$description = $seo->generate_seo_description($lang_term_id, "term");
				//$plugin->log("Generated meta description for: ".$name." -> ".$description);
			}
			if (!empty($options["seo"]["meta_desc"]["translate"])){
				if (empty($description)) {
					$description = $seo->get_meta_description($lang_term_id, "term");
				}
				if (!empty($description)) {
					$description = $this->translate_text($description, $lang);
					$seo->update_meta_description($lang_term_id, $description, $lang, "term");
					//$plugin->log("Translated meta description [{$lang}] for: ".$name." -> ".$description);
				}
			}
			$plugin->log("Meta Description: ".$description);
		}

	    if($this->attachments){
		    $image = $this->container->get("image");
		    $image->generate_alt_text($this->attachments, $lang);            	
        }

        return $term->term_id;

	    $GLOBALS['salt_ai_doing_translate'] = false;
	}



	public function get_post_translations($post_id = 0, $source_lang = "", $target_lang = "en"){

		$GLOBALS['salt_ai_doing_translate'] = true;
		$translations = [];
		$source_lang = empty($source_lang)?$this->default_language:$source_lang;

		$plugin = $this->container->get("plugin");
		$options = $plugin->options;

		$post = get_post($post_id);

		if (!$post || is_wp_error($post)) return;

	    $title_raw = $post->post_title;
	    $title_source = qtranxf_use($source_lang, $title_raw, false, false);
	    $title_target = qtranxf_use($target_lang, $title_raw, false, false);
	    if(!empty($title_target)){
		    $translations[] = [
				$source_lang => $plugin->sanitize_html_for_export($title_source),
				$target_lang  => $plugin->sanitize_html_for_export($title_target)
			];
		}

	    $content_raw = $post->post_content;
	    $content_source = qtranxf_use($source_lang, $content_raw, false, false);
	    $content_target = qtranxf_use($target_lang, $content_raw, false, false);
	    if(!empty($content_target)){
		    $translations[] = [
				$source_lang => $plugin->sanitize_html_for_export($content_source),
				$target_lang  => $plugin->sanitize_html_for_export($content_target)
			];
		}

        if(post_type_supports($post->post_type, 'excerpt')){
		    $excerpt_raw = $post->post_excerpt;
		    $excerpt_source = qtranxf_use($source_lang, $excerpt_raw, false, false);
		    $excerpt_target = qtranxf_use($target_lang, $excerpt_raw, false, false);
		    if(!empty($excerpt_target)){
			    $translations[] = [
				    $source_lang => $plugin->sanitize_html_for_export($excerpt_source),
				    $target_lang  => $plugin->sanitize_html_for_export($excerpt_target)
				];
			}
        }
        
        $acf_data = get_post_meta($post_id);
        if($acf_data){
		    $acf_data = $this->extract_acf_fields($acf_data);
		    if($acf_data){
		    	$acf_data = $acf_data["text"];
		    	if($acf_data){
			    	foreach($acf_data as $item){
			    		$source_field = qtranxf_use($source_lang, $item, false, false);
			    		$target_field = qtranxf_use($target_lang, $item, false, false);
			    		if(!empty($target_field)){
				    		$translations[] = [
				    			$source_lang => $plugin->sanitize_html_for_export($source_field),
				    			$target_lang  => $plugin->sanitize_html_for_export($target_field)
				    		];			    			
			    		}
			    	}		    		
		    	}
		    }
		}

		$GLOBALS['salt_ai_doing_translate'] = false;

	    return $translations;
	}
	public function get_term_translations($term_id = 0, $taxonomy = "", $source_lang = "", $target_lang = "en"){

		$GLOBALS['salt_ai_doing_translate'] = true;
		$translations = [];
		$source_lang = empty($source_lang)?$this->default_language:$source_lang;

		$plugin = $this->container->get("plugin");
		$options = $plugin->options;

		$term = get_term($term_id, $taxonomy);

		if (!$term || is_wp_error($term)) return;

		$title_source = $this->get_term_i18n_value($term, "name", $source_lang);
	    $title_target = $this->get_term_i18n_value($term, "name", $target_lang);
	    if(!empty($title_target)){
		    $translations[] = [
				$source_lang => $plugin->sanitize_html_for_export($title_source),
				$target_lang => $plugin->sanitize_html_for_export($title_target)
			];	    	
	    }


	    if(isset($term->i18n_config["description"])){
	    	$description_raw = $term->i18n_config["description"]["ml"];
	    }else{
	    	$description_raw = $term->description;
	    }
	    $description_source = qtranxf_use($source_lang, $description_raw, false, false);
	    $description_target = qtranxf_use($target_lang, $description_raw, false, false);
	    if(!empty($description_target)){
		    $translations[] = [
				$source_lang => $plugin->sanitize_html_for_export($description_source),
				$target_lang  => $plugin->sanitize_html_for_export($description_target)
			];
		}


        $acf_data = get_term_meta($term_id);
        if($acf_data){
		    $acf_data = $this->extract_acf_fields($acf_data);
		    if($acf_data){
		    	$acf_data = $acf_data["text"];
		    	if($acf_data){
			    	foreach($acf_data as $item){
			    		$source_field = qtranxf_use($source_lang, $item, false, false);
			    		$target_field = qtranxf_use($target_lang, $item, false, false);
			    		if(!empty($target_field)){
				    		$translations[] = [
				    			$source_lang => $plugin->sanitize_html_for_export($source_field),
				    			$target_lang  => $plugin->sanitize_html_for_export($target_field)
				    		];
				    	}
			    	}		    		
		    	}
		    }
		}

		$GLOBALS['salt_ai_doing_translate'] = false;

	    return $translations;
	}




	public function get_untranslated_posts($lang_slug = 'en') {
		$plugin = $this->container->get('plugin');
		$options = $plugin->options;

		$excluded_posts = $options['exclude_posts'] ?? [];
		$excluded_post_types = $options['exclude_post_types'] ?? [];
		$retranslate = $options['retranslate'] ?? false;

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
	    $retranslate = !empty($options['retranslate']);

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
	public function get_untranslated_posts_terms($lang_slug = "en"){
		$results = [
			"status" => true,
	        "status_text" => __("Translating menus... Please wait.", "salt-ai-translator"),
	        "info" => []
	    ];
		$posts = $this->get_untranslated_posts($lang_slug, false);
		if($posts["total"] > 0 && $posts["need_translate"] > 0){
			$results["status"] = false;
            $results["status_text"] = __("Please translate posts first to ensure menu items are correctly linked.", "salt-ai-translator");
            $results["info"] = $posts;
		}else{
			$terms = $this->get_untranslated_terms($lang_slug, false);
			if($terms["total"] > 0 && $terms["need_translate"] > 0){
				$results["status"] = false;
                $results["status_text"] = __("Please translate terms first to ensure menu items are correctly linked.", "salt-ai-translator");
                $results["info"] = $terms;
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


    

	/*public function has_translation($field_value, $lang_slug) { // qtranxf_split($text); ile split et olusan arrayi isset ile kontrol et
	   if (empty($field_value)) return false;
	   return in_array($lang_slug, qtranxf_getAvailableLanguages($field_value));
	}*/
	public function has_translation($field_value, $lang_slug) {
	    if (empty($field_value)) {
	        return false;
	    }

	    $langs = qtranxf_getAvailableLanguages($field_value);

	    if (!is_array($langs)) {
	        return false;
	    }

	    return in_array($lang_slug, $langs, true);
	}
	public function append_translation($original, $translated, $lang) { // ekleme yaptıktan sonra qtranxf_join_b(); ile birleştir arrayi
	    if(empty($original) || empty($translated)){
	        return $original;
	    }
        
        if(is_array($original)){
        	$translations = $original;
        }else{
        	$translations = qtranxf_split($original);//$this->parse_translation($original);
        }
	    
	    if(!empty($translated)){
	    	$translations[$lang] = $translated;	    	
	    }

	    if (!isset($translations[$this->default_language]) && count($translations) == 1) {
	       $translations[$this->default_language] = $original;
	    }
	    return qtranxf_join_b($translations);
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



	public function is_term_content_changed($term){
		$title = qtranxf_use($this->default_language, $term->name, false, false);
    	if(isset($term->i18n_config["description"])){
	    	$content_raw = $term->i18n_config["description"]["ml"];
	    }else{
	    	$content_raw = $term->description;
	    }
	    $content = qtranxf_use($this->default_language, $content_raw, false, false);
    	if(empty($title) && empty($content)){
    		return false;
    	}
        $current_hash = md5($title." ".$content);
        $previous_hash = get_term_meta($term->term_id, '_salt_translate_content_hash', true);
        if ($current_hash !== $previous_hash) {
            return update_term_meta($term->term_id, '_salt_translate_content_hash', $current_hash);
        }
        return false;
    }
    public function is_post_content_changed($post){
        $title = qtranxf_use($this->default_language, $post->post_title, false, false);
    	$content = qtranxf_use($this->default_language, $post->post_content, false, false);
    	if(empty($title) && empty($content)){
    		return false;
    	}
        $current_hash = md5($title." ".$content);
        $previous_hash = get_post_meta($post->ID, '_salt_translate_content_hash', true);
        if ($current_hash !== $previous_hash) {
            return update_post_meta($post->ID, '_salt_translate_content_hash', $current_hash);
        }
        return false;
    }



    public function translate_menu($lang = 'en', $retranslate = false) {
		if ($lang === $this->default_language) return;

		$menu_locations = get_nav_menu_locations();
		$results = [
			"status" => true,
			"status_text" => __("Menu items translated...", "salt-ai-translator")
		];

		foreach ($menu_locations as $location => $menu_id) {
			if (!$menu_id) continue;

			$menu_items = wp_get_nav_menu_items($menu_id);

			foreach ($menu_items as $item) {
				$titles = function_exists('qtranxf_split') ? qtranxf_split($item->title) : [$this->default_language => $item->title];

				if (!$retranslate && !empty($titles[$lang])) {
					continue; // zaten çevrilmiş
				}

				$object_type = get_post_meta($item->ID, '_menu_item_object', true);
				$item_type   = get_post_meta($item->ID, '_menu_item_type', true);
				$object_id   = get_post_meta($item->ID, '_menu_item_object_id', true);
				$translated_title = null;

				if ($item_type === 'post_type' && $object_id) {
					$translated_id = $this->get_or_create_translation($object_type, $object_id, $lang);
					$translated_post = get_post($translated_id);
					if ($translated_post) {
						$translated_title = $translated_post->post_title;
					}
				} elseif ($item_type === 'taxonomy' && $object_id) {
					$translated_id = $this->get_or_create_translation($object_type, $object_id, $lang);
					$translated_term = get_term($translated_id);
					if ($translated_term && !is_wp_error($translated_term)) {
						$translated_title = $translated_term->name;
					}
				} elseif ($item_type === 'custom') {
					$source_text = $titles[$this->default_language] ?? $item->title;
					$translated_title = $this->translate_text($source_text, $lang);
				}

				if ($translated_title) {
					$titles[$lang] = $translated_title;
					$new_title = function_exists('qtranxf_join') ? qtranxf_join($titles) : $item->title;

					wp_update_post([
						'ID'         => $item->ID,
						'post_title' => $new_title,
					]);

					// 💡 URL'yi güncelle (opsiyonel)
					if ($item_type !== 'custom') {
						$translated_url = function_exists('qtranxf_convertURL')
							? qtranxf_convertURL($item->url, $lang)
							: $item->url;

						update_post_meta($item->ID, '_menu_item_url', $translated_url);
					}
				}
			}
		}

		return $results;
	}
	private function get_or_create_translation($object_type, $object_id, $lang) {
		// 💡 Taxonomy ise
		if (taxonomy_exists($object_type)) {
			$term = get_term($object_id, $object_type);
			if (!$term || is_wp_error($term)) return $object_id;

			$translated = $this->get_translated_term_id($term->term_id, $lang);
			if ($translated) return $translated;

			return $this->translate_term($term->term_id, $lang);
		}

		// 💡 Post/Page/Product ise
		$post = get_post($object_id);
		if (!$post || $post->post_status === 'trash') return $object_id;

		$translated = $this->get_translated_post_id($post->ID, $lang);
		if ($translated) return $translated;

		return $this->translate_post($post->ID, $lang);
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
    public function unpublish_languages($languages = []){
    	if($languages){
	    	global $q_config;
		    if ( isset($q_config['enabled_languages']) && is_array($q_config['enabled_languages']) ) {
		        $q_config['enabled_languages'] = array_values(
		            array_diff($q_config['enabled_languages'], $languages)
		        );
		    }    		
    	}
    }

}