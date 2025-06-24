<?php

namespace SaltAI\Integrations;

use SaltAI\Core\ServiceContainer;

if (!defined('ABSPATH')) exit;

if (!function_exists('get_post_type_supports')) {
    require_once ABSPATH . WPINC . '/post.php';
}

class Integration {
    private ServiceContainer $container;
    private $plugin;
    public $default_language;
    public $current_language;
    public $attachments = [];

    public function __construct($container) {
        $this->container = $container;
        $this->plugin = $container->get('plugin');
    	$this->default_language = pll_default_language() ?? 'en';
    	$this->current_language = pll_current_language() ?? 'en';
    }

	public function translate_text($text = '', $lang = 'en', $custom_prompt = "") {
		$translator = $this->container->get('translator');
		if(!$translator){
			return $text;
		}
		if(!empty($custom_prompt)){
			$translator->set_custom_prompt($custom_prompt);
		}

		$this->container->get("plugin")->log("input: ".$text);

		$parsed = $this->plugin->cleanHTML($text);
		$this->container->get("plugin")->log("Parsed segments");
		$this->container->get("plugin")->log($parsed['segments']);
        $translated_segments = [];
        foreach ($parsed['segments'] as $segment) {
            $translated_segments[] = $translator->translate($segment, $lang);
        }
        $this->container->get("plugin")->log("Translated_segments");
        $this->container->get("plugin")->log($translated_segments);
        $output = $this->plugin->replaceHTML(
            $parsed['document'],
            $parsed['text_nodes'],
            $translated_segments,
            $parsed['plain']
        );

        $this->container->get("plugin")->log("output: ".$output);
        $this->container->get("plugin")->log("----------------------------");

        return $output;
        //return $translator->translate($text, $lang);
    }


	public function translate_blocks($post_content = "", $lang = "en") {
	    if (!is_string($post_content) || trim($post_content) === '') {
	        return '';
	    }

	    // 💡 Tüm bloklardaki json key'leri yakala
		$raw_block_json_keys = [];
		if (preg_match_all('/<!--\s*wp:([^\s{]+)\s+({.*?})\s*-->/', $post_content, $matches, PREG_SET_ORDER)) {
		    foreach ($matches as $m) {
		        $block_name = $m[1];
		        
		        // 💡 cover → core/cover gibi normalize et
		        if (strpos($block_name, '/') === false) {
		            $block_name = 'core/' . $block_name;
		        }

		        $json_raw = $m[2];
		        $decoded = json_decode($json_raw, true);
		        if (is_array($decoded)) {
		            $raw_block_json_keys[$block_name][] = array_keys($decoded);
		        } elseif ($json_raw !== '') {
				    error_log("SaltAI: JSON decode hatası: $json_raw");
				}
			}
		}

		$plugin = $this->container->get("plugin");

	    $blocks = parse_blocks($post_content);
	    $new_blocks = [];

		$blocks = $this->resolve_and_translate_reusable_blocks($blocks, $lang);

	    foreach ($blocks as $block) {
	        $skip_keys = [];
            
            // skip resusable blocxk
	        if(isset($block['blockName']) && $block['blockName'] == "core/block" && isset($block['attrs']["ref"]) && !empty($block['attrs']["ref"])){
	        	$new_blocks[] = $block;
	        	continue;
	        }

			// json'dan gelen key'leri al
			if (isset($block['blockName'], $raw_block_json_keys[$block['blockName']])) {
			    foreach ($raw_block_json_keys[$block['blockName']] as $keys) {
			        $skip_keys = array_merge($skip_keys, $keys);
			    }
			}

	        // attrs içinden geçici olarak çıkarılacaklar
	        $preserved_attrs = [];
	        if (!empty($skip_keys) && isset($block['attrs']) && is_array($block['attrs'])) {
	            foreach ($skip_keys as $key) {
	                if (array_key_exists($key, $block['attrs'])) {
	                    $preserved_attrs[$key] = $block['attrs'][$key];
	                    unset($block['attrs'][$key]);
	                }
	            }
	        }

	        // Recursive innerBlocks çevirisi
	        if (!empty($block['innerBlocks']) && is_array($block['innerBlocks'])) {
	            $block['innerBlocks'] = array_map(function ($innerBlock) use ($lang) {
	                $translated = parse_blocks($this->translate_blocks( serialize_blocks([$innerBlock]), $lang));
	                return is_array($translated) && isset($translated[0]) ? $translated[0] : $innerBlock;
	            }, $block['innerBlocks']);
	        }

	        // 🔥💾 GÖRSELLERİ BURADA YAKALIYORUZ (innerHTML içinde <img src=...>)
	        if($plugin->options["seo"]["image_alttext"]["generate"]){
			    if (!empty($block['innerHTML'])) {
			        preg_match_all('/<img[^>]+src=[\'"]([^\'"]+)[\'"]/i', $block['innerHTML'], $matches);
			        foreach ($matches[1] as $src) {
			            $this->attachments[] = [
			                'url' => $src,
			                'id'  => attachment_url_to_postid($src) ?: null,
			            ];
			        }
			    }	        	
	        }


	        // Comment/boş block
	        if ($block['blockName'] === null) {
	            if (isset($block['innerHTML']) && $this->should_translate($block['innerHTML'])) {
	                $block['innerHTML'] = $this->translate_text($block['innerHTML'], $lang);
	            }
	        }

	        // ACF Timber block
	        elseif (strpos($block['blockName'], 'acf/') === 0) {

	        	if (isset($block['attrs']['data']) && is_array($block['attrs']['data'])) {
	               foreach ($block['attrs']['data'] as $key => $val) {
	                    $field_key = isset($block['attrs']['data']["_" . $key]) ? $block['attrs']['data']["_" . $key] : null;
	                   
						//if ($field_key && (($plugin->options["seo"]["image_alttext"]["generate"] && $this->should_translate($val)) || !$plugin->options["seo"]["image_alttext"]["generate"])){
						if ($field_key && $plugin->options["seo"]["image_alttext"]["generate"] && !empty($val)){
							$acf_field_object = $this->get_acf_field_object($field_key);
						    $field_type = $acf_field_object["type"];
                            
                            if($plugin->options["seo"]["image_alttext"]["generate"]){
							    // 🎯 Alt text gerektiren görselleri yakala
					            if ($field_type === 'image' && is_numeric($val)) {
					                $url = wp_get_attachment_image_src($val, 'full')[0] ?? '';
					                if ($url) {
					                    $this->attachments[] = [
					                        'id'  => $val,
					                        'url' => $url,
					                    ];
					                }
					            }

					            // 🎯 Gallery tipi çoklu görsel desteği
					            if ($field_type === 'gallery' && is_array($val)) {
					                foreach ($val as $gallery_id) {
					                    if (!is_numeric($gallery_id)) continue;

					                    $url = wp_get_attachment_image_src($gallery_id, 'full')[0] ?? '';
					                    if ($url) {
					                        $this->attachments[] = [
					                            'id'  => $gallery_id,
					                            'url' => $url,
					                        ];
					                    }
					                }
					            }
                            }

				            if(is_numeric($val) || is_array($val) || (isset($acf_field_object["translation"]) && $acf_field_object["translation"] !== 'translate') || $acf_field_object["translation"] == "class"){
				            	continue;
				            }

						    if (in_array($field_type, ['text', 'textarea', 'wysiwyg'])) {
						    	$block['attrs']['data'][$key] = $this->translate_text($val, $lang);
						    }

						}
		            } 
	            }
	        }

	        // Core paragraph
	        elseif ($block['blockName'] === 'core/paragraph') {
	            if (isset($block['innerHTML']) && $this->should_translate($block['innerHTML'])) {
	                $block['innerHTML'] = $this->translate_text($block['innerHTML'], $lang);
	            }

	            if (!empty($block['innerContent']) && is_array($block['innerContent'])) {
	                $block['innerContent'] = array_map(function ($item) use ($lang) {
	                    return $this->should_translate($item)
	                        ? $this->translate_text($item, $lang)
	                        : $item;
	                }, $block['innerContent']);
	            }
	        }

	        // Diğer bloklar
	        else {

	            if (isset($block['attrs']) && is_array($block['attrs'])) {
	                foreach ($block['attrs'] as $key => $val) {
	                    if (in_array($key, $skip_keys)) continue;

	                    if ($this->should_translate($val)) {
	                        $block['attrs'][$key] = $this->translate_text($val, $lang);
	                    }
	                }
	            }

	            if (isset($block['innerHTML']) && $this->should_translate($block['innerHTML'])) {
	                $block['innerHTML'] = $this->translate_text($block['innerHTML'], $lang);
	            }

	            if (!empty($block['innerContent']) && is_array($block['innerContent'])) {
	                $block['innerContent'] = array_map(function ($item) use ($lang) {
	                    return $this->should_translate($item)
	                        ? $this->translate_text($item, $lang)
	                        : $item;
	                }, $block['innerContent']);
	            }
	        }

	        // 💾 Çıkardığımız attr’ları geri ekle
	        if (!empty($preserved_attrs)) {
	            //$block['attrs'] = array_merge($block['attrs'] ?? [], $preserved_attrs);
	            foreach ($preserved_attrs as $k => $v) {
				    $block['attrs'][$k] = $v;
				}
	        }

	        $new_blocks[] = $block;
	    }

	    return serialize_blocks($new_blocks);
	}
	public function resolve_and_translate_reusable_blocks(array $blocks, string $lang): array {
		foreach ($blocks as &$block) {
			if ($block['blockName'] === 'core/block' && !empty($block['attrs']['ref'])) {
				$original_id = $block['attrs']['ref'];
				$original_content = get_post_field('post_content', $original_id);

				if (empty($original_content)) {
					continue;
				}

				$translated_id = $this->translate_post($original_id, $lang);
				$block['attrs']['ref'] = $translated_id;
			}
		}

		return $blocks;
	}
	private function should_translate($text) {
	    if (!is_string($text) || trim($text) === '' || is_numeric($text)) return false;
	    if(is_numeric($text) && !$accept_numeric) return false;
	    if (preg_match('/<!--\s+wp:[^{}]+\{.*\}.*-->/', $text)) return false;
	    if (strpos($text, '<!-- wp:') !== false && strpos($text, '-->') !== false) return false;
	    return trim(strip_tags($text)) !== '';
	}
	public function translate_acf_fields($post_id = 0, $lang = "en") {
	    $fields = get_field_objects($post_id);
	    $fields = unserialize(serialize($fields)); // deep copy
	    if (!$fields) return [];
	    $translated_data = [];
	    foreach ($fields as $field_key => $field) {
	        if (strpos($field_key, '_') === 0) continue;
	        $translated_data[$field_key] = $this->translate_acf_field($field, $field['value'], $lang, $post_id);
	    }
	    return $translated_data;
	}
	public function translate_acf_field($field, $value, $lang, $post_id = 0) {
	    if (!$field || !isset($field['type'])) return $value;

	    $plugin = $this->container->get("plugin");

	    $type = $field['type'];
        
        if(isset($plugin->options["seo"]["image_alttext"]["generate"]) && $plugin->options["seo"]["image_alttext"]["generate"]){
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
	    if (in_array($type, ['text', 'textarea', 'wysiwyg']) && is_string($value) && strlen($value) > 0) {
	        return $this->translate_text($value, $lang);
	    }

	    $default_language = $this->default_language;

		if (in_array($type, ['post_object', 'relationship'])) {
		    if (is_array($value)) {
		        $field_value = array_map(function($id) use ($lang, $default_language) {
		            $translated_id = pll_get_post($id, $lang);
		            if (!$translated_id) {
		                $original_id = pll_get_post($id, $default_language);
		                $translated_id = $original_id ? $this->translate_post($original_id, $lang) : $id;
		            }
		            return $translated_id ?: $id;
		        }, $value);
		    } else {
		        $translated_id = pll_get_post($value, $lang);
		        if (!$translated_id) {
		            $original_id = pll_get_post($value, $default_language);
		            $translated_id = $original_id ? $this->translate_post($original_id, $lang) : $value;
		        }
		        $field_value = $translated_id ?: $value;
		    }
		    return isset($field_value) ? $field_value : $value;
		}
		elseif ($type === 'taxonomy') {
		    if (is_array($value)) {
		        $field_value = array_map(function($id) use ($lang, $default_language) {
				    $translated_id = pll_get_term($id, $lang);
				    if (!$translated_id) {
				        $original_id = pll_get_term($id, $default_language);
				        if ($original_id) {
				            $term = get_term($original_id);
				            $translated_id = ($term && $term->taxonomy) ? $this->translate_term($original_id, $term->taxonomy, $lang) : $id;
				        }
				    }
				    return $translated_id ?: $id;
				}, $value);
		    } else {
		        $translated_id = pll_get_term($value, $lang);
				if (!$translated_id) {
				    $original_id = pll_get_term($value, $default_language);
				    if ($original_id) {
				        $term = get_term($original_id);
				        $translated_id = ($term && $term->taxonomy) ? $this->translate_term($original_id, $term->taxonomy, $lang) : $value;
				    }
				}
				$field_value = $translated_id ?: $value;
		    }
		    return isset($field_value) ? $field_value : $value;
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
	public function get_acf_field_object($field_key) {
	    if (!is_string($field_key) || strpos($field_key, 'field_') !== 0) {
	        return null;
	    }
	    preg_match_all('/field_[a-z0-9]+/', $field_key, $matches);
	    if (!empty($matches[0])) {
	        $real_key = end($matches[0]);
	        $field_object = get_field_object($real_key);
	        if (is_array($field_object) && isset($field_object['type'])) {
	            return $field_object;//['type'];
	        }
	    }
	    return null;
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



	public function translate_post($post_id = 0, $lang = "en"){

		$GLOBALS['salt_ai_doing_translate'] = true;

		$plugin = $this->container->get("plugin");
		$options = $plugin->options;
        
		$source_lang = pll_get_post_language($post_id);

		if($source_lang != $this->default_language){
            $post_id = pll_get_post($post_id, $this->default_language);
	    }

	    $lang_post_id = pll_get_post($post_id, $lang);
	    if(!$lang_post_id ){
	    	$lang_post_id = $plugin->duplicate_post($post_id);
	    	if(!$lang_post_id){
	    		$GLOBALS['salt_ai_doing_translate'] = false;
	    		return 0;
	    	} 
	    	pll_set_post_language($lang_post_id, $lang);
		    $translations = pll_get_post_translations( $post_id );
            $translations[ $lang ] = $lang_post_id;
            pll_save_post_translations( $translations );
	    }

	    $post = get_post($post_id);

	    if (!$post || $post->post_status === 'trash') {
	    	$GLOBALS['salt_ai_doing_translate'] = false;
		    return 0;
		}

		$prompt_title = "You are translating a string that will be used as a **web page title**. The page represents a WordPress post of the post type: '{$post->post_type}'.
		Please follow these rules:
		- This is not just a word or sentence — it is the official **title of a page**, often seen in browser tabs, menus, or SEO titles.
		- Translate it **contextually** to sound natural and professional in the target language.
		- Do NOT translate literally if it doesn’t make sense — adjust the wording to reflect how a native speaker would title a similar page.
		- Keep it **short and meaningful**, avoid unnecessary filler words.
		- Do not include any formatting, tags, or symbols.";

	    $title = $this->translate_text($post->post_title, $lang, $prompt_title);
	    $title = $plugin->sanitize_translated_string($title);

        $content_changed = $plugin->is_content_changed($post);

	    if (has_blocks($post->post_content)) {
	        $content = $this->translate_blocks($post->post_content, $lang);
	    }else{
	        if($plugin->options["seo"]["image_alttext"]["generate"]){
		        $this->extract_images_from_html($post->post_content);
		    }
	        $content = $this->translate_text($post->post_content, $lang);
	    }

	    $excerpt = '';
		if (post_type_supports($post->post_type, 'excerpt') && $post->post_excerpt) {
		    $excerpt = $this->translate_text($post->post_excerpt, $lang);
		}

	    $args = [
		    "ID" => $lang_post_id,
		    "post_status"  => "publish", 
            "post_title"   => $title,
	        "post_content" => wp_slash($content),
		];
		if(!empty($excerpt)){
	        $args["post_excerpt"] = $excerpt;
		}
		wp_update_post($args);

        $plugin->log($this->attachments); 

        if($post->post_type != "wp_block"){
                
            $acf_fields = $this->translate_acf_fields($post_id, $lang);
            if (!empty($acf_fields)) {
				foreach ($acf_fields as $field_key => $field_value) {
				    update_field($field_key, $field_value, $lang_post_id);
				}
			}
 
	        $description = "";
	        if (
			    $options["seo"]["meta_desc"]["generate"]
			    && (
			        ($options["seo"]["meta_desc"]["on_content_changed"] && $content_changed)
			        || !$options["seo"]["meta_desc"]["on_content_changed"]
			    )
			) {
			    $seo = $this->container->get('seo');
		        if($options["translator"] == "openai"){
				    $description = $seo->generate_seo_description($lang_post_id);
				    //$plugin->log("Generated meta description for: ".$post->post_title." -> ".$description);  	
			    }
			    if($options["seo"]["meta_desc"]["translate"]){	
			        if(empty($description)){
			            $description = $seo->get_meta_description($lang_post_id);
			        }
			        if(!empty($description)){
		            	$description = $this->translate_text($description, $lang);
		            	$seo->update_meta_description($lang_post_id, $description);
		            	//$plugin->log("Translated meta description [".$lang."] for: ".$post->post_title." -> ".$description); 
			        }
				} 
		    }

		    if($this->attachments){
				$image = $this->container->get("image");
				$image->generate_alt_text($this->attachments, $lang);            	
		    }

		    $GLOBALS['salt_ai_doing_translate'] = false;            	
	    }

		return $lang_post_id;
	}
	public function translate_term($term_id = 0, $taxonomy = "", $lang = "en") {
		$GLOBALS['salt_ai_doing_translate'] = true;

		$plugin = $this->container->get("plugin");
		$options = $plugin->options;

		$source_lang = pll_get_term_language($term_id);




		if($source_lang != $this->default_language){
            $term_id = pll_get_term($term_id, $this->default_language);
	    }

	    $term = get_term($term_id);
	    if (!$term) {
	    	$GLOBALS['salt_ai_doing_translate'] = false;
		    return 0;
		}

		$name = $this->translate_text($term->name, $lang, "These are taxonomy terms from a WordPress site under the '{$taxonomy}' taxonomy. Translate accordingly. Dont add html tags.");
		$name = $plugin->sanitize_translated_string($name);
		$description = $this->translate_text($term->description, $lang, "These are taxonomy terms from a WordPress site under the '{$taxonomy}' taxonomy. Translate accordingly.");
        $slug = sanitize_title($name);
		$existing_term = get_term_by('slug', $slug, $taxonomy);
		if ($existing_term && $existing_term->term_id != $term_id) {
		    $slug .= '-' . $lang;
		}

	    $lang_term_id = pll_get_term($term_id, $lang);
	    if(!$lang_term_id ){
	    	$override = ["name" => $name, "slug" => $slug."-".$lang, "description" => $description];
	    	$lang_term_id = $plugin->duplicate_term($term_id, $override);
	    	if(!$lang_term_id){
	    		$GLOBALS['salt_ai_doing_translate'] = false;
	    		return 0;
	    	} 
	    	pll_set_term_language($lang_term_id, $lang);
			$translations = pll_get_term_translations($term_id);
			$translations[$lang] = $lang_term_id;
			pll_save_term_translations($translations);
	    }

	    



        /*

		if ($source_lang !== $this->default_language) {
			$source_term_id = pll_get_term($term_id, $this->default_language);
			if (!$source_term_id || $source_term_id == $term_id) {
				$source_term_id = $term_id;
			}
		} else {
			$source_term_id = $term_id;
		}

		$term = get_term($source_term_id, $taxonomy);
		if (!$term || is_wp_error($term)) {
			$GLOBALS['salt_ai_doing_translate'] = false;
			return 0;
		}

		$name = $this->translate_text($term->name, $lang, "These are taxonomy terms from a WordPress site under the '{$taxonomy}' taxonomy. Translate accordingly. Dont add html tags.");
		$name = $plugin->sanitize_translated_string($name);
		$description = $this->translate_text($term->description, $lang, "These are taxonomy terms from a WordPress site under the '{$taxonomy}' taxonomy. Translate accordingly.");

        $slug = sanitize_title($name);
		$existing_term = get_term_by('slug', $slug, $taxonomy);
		if ($existing_term && $existing_term->term_id != $source_term_id) {
		    $slug .= '-' . $lang;
		}

		$lang_term_id = pll_get_term($source_term_id, $lang);
		$plugin->log("lang_term_id : ".$lang_term_id);
		if (!$lang_term_id || $lang_term_id == $source_term_id) {
			$plugin->log("Term in (".$lang.") versiyonu yok");

		    $override = ["name" => $name, "slug" => $slug."-".$lang, "description" => $description];
			$lang_term_id = $plugin->duplicate_term($source_term_id, $override);

			if (!$lang_term_id) {
				$GLOBALS['salt_ai_doing_translate'] = false;
				return 0;
			}
			pll_set_term_language($lang_term_id, $lang);
			$translations = pll_get_term_translations($source_term_id);
			$translations[$lang] = $lang_term_id;
			pll_save_term_translations($translations);
		}*/

		if ($plugin->options["seo"]["image_alttext"]["generate"]) {
			$this->extract_images_from_html($term->description);
		}

		$args = [
			"name" => $name,
			"slug" => sanitize_title($name),
			"description" => wp_slash($description),
		];
		wp_update_term($lang_term_id, $taxonomy, $args);

		$acf_fields = $this->translate_acf_fields("term_$lang_term_id", $lang);
		foreach ($acf_fields as $field_key => $field_value) {
			update_field($field_key, $field_value, "term_$lang_term_id");
		}

		if (
			$options["seo"]["meta_desc"]["generate"]
			&& (
				($options["seo"]["meta_desc"]["on_content_changed"] && true) ||
				!$options["seo"]["meta_desc"]["on_content_changed"]
			)
		) {
			$seo = $this->container->get("seo");
			$description = "";

			if ($options["translator"] === "openai") {
				$description = $seo->generate_seo_description($lang_term_id, "term");
				//$plugin->log("Generated meta description for: ".$name." -> ".$description);
			}
			if ($options["seo"]["meta_desc"]["translate"]) {
				if (empty($description)) {
					$description = $seo->get_meta_description($lang_term_id, "term");
				}
				if (!empty($description)) {
					$description = $this->translate_text($description, $lang);
					$seo->update_meta_description($lang_term_id, $description, "", "term");
					//$plugin->log("Translated meta description [{$lang}] for: ".$name." -> ".$description);
				}
			}
			$plugin->log("Meta Description: ".$description);
		}

		$plugin->log($this->attachments);

		// ALT TEXT
		if ($this->attachments) {
			$image = $this->container->get("image");
			$image->generate_alt_text($this->attachments, $lang);
		}

		$GLOBALS['salt_ai_doing_translate'] = false;

		return $lang_term_id;
	}

	

	public function get_untranslated_posts($lang_slug = "en", $retranslate = "") {
	    $options = $this->plugin->options;
		$excluded_posts = $options['exclude_posts'] ?? [];
		$excluded_post_types = $options['exclude_post_types'] ?? [];
		$retranslate = is_bool($retranslate) ? $retranslate : ($options['retranslate_existing'] ?? false);

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
	    $post_types = array_filter($post_types, function ($post_type) {
	        return pll_is_translated_post_type($post_type);
	    });
    	$post_types = array_values($post_types);
        
        $total_index = 0;
        $need_translate_index = 0;
	    foreach ($post_types as $post_type) {

	        $posts = get_posts([
	            'post_type'        => $post_type,
	            'post_status'      => 'publish',
	            'numberposts'      => -1,
	            'suppress_filters' => false,
	            'lang'             => $this->default_language,
	            'post__not_in'     => $excluded_posts,
	        ]);

	        foreach ($posts as $post) {
	            $translated_id = pll_get_post($post->ID, $lang_slug);
	            if (!$translated_id || $retranslate) {
	                $results["posts"][] = [
	                    'post_type' => $post_type,
	                    'ID'        => $post->ID,
	                    'title'     => get_the_title($post->ID),
	                ];
	                $results["need_translate"] = ++$need_translate_index;
	            }
	            $results["total"] = ++$total_index;
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
	public function get_untranslated_terms($lang_slug = "en", $retranslate = "") {
	    $options = $this->plugin->options;
	    $excluded_terms = $options['exclude_terms'] ?? [];
	    $excluded_taxonomies = $options['exclude_taxonomies'] ?? [];
	    $retranslate = is_bool($retranslate) ? $retranslate : ($options['retranslate_existing'] ?? false);

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
	    $taxonomies = array_filter($taxonomies, function ($taxonomy) {
	        return pll_is_translated_taxonomy($taxonomy);
	    });
    	$taxonomies = array_values($taxonomies);
        $total_index = 0;
        $need_translate_index = 0;
	    foreach ($taxonomies as $taxonomy) {
	        $terms = get_terms([
	            'taxonomy'   => $taxonomy,
	            'hide_empty' => false,
	            'lang'       => $this->default_language,
	        ]);
	        foreach ($terms as $term) {
	            $translated_id = pll_get_term($term->term_id, $lang_slug);
	            if (!$translated_id || $retranslate) {
	                $results["terms"][] = [
	                    'taxonomy' => $taxonomy,
	                    'term_id'  => $term->term_id,
	                    'name'     => $term->name,
	                ];
	                $results["need_translate"] = ++$need_translate_index;
	            }
	            $results["total"] = ++$total_index;
	        }
	    }

	    $total = $results["total"];
	    $need = $results["need_translate"];
	    $translated = $total - $need;

	    if ($need > 0) {
	        if ($retranslate) {
	            if ($translated > 0) {
	                $results["status_text"] = sprintf(
	                    __('%1$d translated, total %2$d terms will be retranslated to "%3$s".', 'salt-ai-translator'),
	                    $translated,
	                    $total,
	                    $this->get_language_label($lang_slug)
	                );
	            } else {
	                $results["status_text"] = sprintf(
	                    __('%1$d terms will be translated to "%2$s".', 'salt-ai-translator'),
	                    $total,
	                    $this->get_language_label($lang_slug)
	                );
	            }
	        } else {
	            $results["status_text"] = sprintf(
	                __('%1$d out of %2$d terms not translated to "%3$s".', 'salt-ai-translator'),
	                $need,
	                $total,
	                $this->get_language_label($lang_slug)
	            );
	        }
	    } else {
	        if ($retranslate) {
	            $results["status_text"] = sprintf(
	                __('All %1$d terms is already translated to "%2$s". They will be retranslated.', 'salt-ai-translator'),
	                $total,
	                $this->get_language_label($lang_slug)
	            );
	        } else {
	            $results["status_text"] = sprintf(
	                __('All %1$d terms is already translated to "%2$s".', 'salt-ai-translator'),
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


	/*public function translate_menu($lang = 'en', $retranslate = false) {
		if ($lang === $this->default_language) return;

		$results = [
			"status" => true,
			"status_text" => __("Menu created and translated...", "salt-ai-translator")
		];

		$menu_ids = [];
		$theme = wp_get_theme();
		$textdomain = $theme->get('TextDomain');
		$polylang_options = get_option('polylang');
		$polylang_menus = $polylang_options['nav_menus'][$textdomain] ?? [];
		$locations = get_nav_menu_locations();

		foreach ($locations as $location => $menu_id) {
			if (!isset($polylang_menus[$location])) {
				continue;
			}

			$menu_data = $polylang_menus[$location];
			$original_menu_id = $menu_data[$this->default_language] ?? 0;

			if (!$original_menu_id || in_array($original_menu_id, $menu_ids)) {
				continue;
			}
			$menu_ids[] = $original_menu_id;

			$menu_obj = wp_get_nav_menu_object($original_menu_id);
			$menu_items = wp_get_nav_menu_items($original_menu_id);
			$menu_name = $location . '-' . $lang;

			$existing_menu = get_term_by('name', $menu_name, 'nav_menu');

			if ($existing_menu && $retranslate) {
				wp_delete_nav_menu($existing_menu->term_id);
				$existing_menu = false;
			}

			$translated_menu_id = $existing_menu ? $existing_menu->term_id : wp_create_nav_menu($menu_name);

			if (is_wp_error($translated_menu_id)) {
				$results["status"] = false;
				$results["status_text"] = __("Failed to create menu: ", "salt-ai-translator") . $translated_menu_id->get_error_message();
				continue;
			}

			$item_id_map = [];

			foreach ($menu_items as $item) {
				$args = [
					'menu-item-title'     => $this->translate_text($item->title, $lang, "Always translate everything."),
					'menu-item-url'       => '',
					'menu-item-type'      => $item->type,
					'menu-item-object'    => $item->object,
					'menu-item-object-id' => $item->object_id,
					'menu-item-status'    => 'publish',
				];

				if ($item->type === 'custom') {
					$args['menu-item-url'] = $item->url;
				} elseif (in_array($item->type, ['post_type', 'taxonomy'])) {
					$args['menu-item-object-id'] = $this->get_or_create_translation($item->object, $item->object_id, $lang);
				}

				if ($item->menu_item_parent && isset($item_id_map[$item->menu_item_parent])) {
					$args['menu-item-parent-id'] = $item_id_map[$item->menu_item_parent];
				}

				$new_item_id = wp_update_nav_menu_item($translated_menu_id, 0, $args);
				$item_id_map[$item->ID] = $new_item_id;
			}

			$locations[$location] = $translated_menu_id;
			$update_locations = $this->get_menu_locations($polylang_menus);

			foreach ($update_locations as $update_location) {
				$polylang_options['nav_menus'][$textdomain][$update_location][$lang] = $translated_menu_id;
			}
		}

		if (!$menu_ids) {
			$results["status_text"] = __("No menus found.", "salt-ai-translator");
		}

		set_theme_mod('nav_menu_locations', $locations);
		update_option('polylang', $polylang_options);

		return $results;
	}*/
	
	private function get_menu_locations($polylang_menus){
		$locations = [];
		foreach ($polylang_menus as $location => $languages) {
			if(isset($languages[$this->default_language]) && !empty($languages[$this->default_language])){
				$locations[] = $location;
			}
		}
		return $locations;
	}
	private function get_or_create_translation($object_type, $object_id, $lang) {
		// 💡 Taxonomy ise
		if (taxonomy_exists($object_type)) {
			$translated_id = pll_get_term($object_id, $lang);
			if ($translated_id) return $translated_id;

			$term = get_term($object_id, $object_type);
			if (!$term || is_wp_error($term)) return $object_id;

			return $this->translate_term($term->term_id, $term->taxonomy, $lang);
		}

		// 💡 Post/Page/Product ise
		$translated_id = pll_get_post($object_id, $lang);
		if ($translated_id) return $translated_id;

		$post = get_post($object_id);
		if (!$post || $post->post_status === 'trash') return $object_id;

		return $this->translate_post($post->ID, $lang);
	}
	public function translate_menu($lang = 'en', $retranslate = false) {
		if ($lang === $this->default_language) return;

		$results = [
			"status" => true,
			"status_text" => __("Menu created and translated...", "salt-ai-translator")
		];

		$menu_ids = [];
		$theme = wp_get_theme();
		$textdomain = $theme->get('TextDomain');
		$polylang_options = get_option('polylang');
		$polylang_menus = $polylang_options['nav_menus'][$textdomain] ?? [];
		$locations = get_nav_menu_locations();

		foreach ($locations as $location => $menu_id) {
			if (!isset($polylang_menus[$location])) continue;

			$menu_data = $polylang_menus[$location];
			$original_menu_id = $menu_data[$this->default_language] ?? 0;

			if (!$original_menu_id || in_array($original_menu_id, $menu_ids)) continue;
			$menu_ids[] = $original_menu_id;

			$menu_items = wp_get_nav_menu_items($original_menu_id);
			$menu_name = $location . '-' . $lang;

			$existing_menu = get_term_by('name', $menu_name, 'nav_menu');
			if ($existing_menu && $retranslate) {
				wp_delete_nav_menu($existing_menu->term_id);
				$existing_menu = false;
			}

			$translated_menu_id = $existing_menu ? $existing_menu->term_id : wp_create_nav_menu($menu_name);
			if (is_wp_error($translated_menu_id)) {
				$results["status"] = false;
				$results["status_text"] = __("Failed to create menu: ", "salt-ai-translator") . $translated_menu_id->get_error_message();
				continue;
			}

			// 💡 Menü term meta'larını kopyala
			$original_meta = get_term_meta($original_menu_id);
			if (!empty($original_meta)) {
				foreach ($original_meta as $meta_key => $values) {
					foreach ($values as $value) {
						update_term_meta($translated_menu_id, $meta_key, maybe_unserialize($value));
					}
				}
			}

			$item_id_map = [];

			foreach ($menu_items as $item) {
				$object = get_post_meta($item->ID, '_menu_item_object', true);
				$type = get_post_meta($item->ID, '_menu_item_type', true);
				$object_id = get_post_meta($item->ID, '_menu_item_object_id', true);

				$args = [
					'menu-item-title'     => $this->translate_text($item->title, $lang, "Always translate everything. Don't use html tags or codes. generate plain text."),
					'menu-item-url'       => '',
					'menu-item-type'      => $type,
					'menu-item-object'    => $object,
					'menu-item-object-id' => $object_id,
					'menu-item-status'    => 'publish',
				];

				error_log(print_r($args, true));

				if ($type === 'custom') {
					$args['menu-item-url'] = $item->url;
				} elseif (in_array($type, ['post_type', 'taxonomy'])) {
					$args['menu-item-object-id'] = $this->get_or_create_translation($object, $object_id, $lang);
				}

				if ($item->menu_item_parent && isset($item_id_map[$item->menu_item_parent])) {
					$args['menu-item-parent-id'] = $item_id_map[$item->menu_item_parent];
				}

				$new_item_id = wp_update_nav_menu_item($translated_menu_id, 0, $args);
				$item_id_map[$item->ID] = $new_item_id;

				// 💡 Menü item meta'larını kopyala (ACF dahil)
				$item_meta = get_metadata('post', $item->ID);
				if (!empty($item_meta)) {
					foreach ($item_meta as $meta_key => $values) {
						if (in_array($meta_key, ['_menu_item_type', '_menu_item_menu_item_parent', '_menu_item_object_id', '_menu_item_object', '_menu_item_target', '_menu_item_classes', '_menu_item_xfn', '_menu_item_url'])) {
							continue;
						}
						foreach ($values as $value) {
							update_post_meta($new_item_id, $meta_key, maybe_unserialize($value));
						}
					}
				}
			}

			$locations[$location] = $translated_menu_id;
			$update_locations = $this->get_menu_locations($polylang_menus);

			foreach ($update_locations as $update_location) {
				$polylang_options['nav_menus'][$textdomain][$update_location][$lang] = $translated_menu_id;
			}
		}

		if (!$menu_ids) {
			$results["status_text"] = __("No menus found.", "salt-ai-translator");
		}

		set_theme_mod('nav_menu_locations', $locations);
		update_option('polylang', $polylang_options);

		return $results;
	}





	private function extract_cf7_strings($text) {
	    if (!is_string($text)) return [];
	    preg_match_all('/\{([^\{\}]+)\}/', $text, $matches);
	    return $matches[1] ?? [];
	}
	private function update_cf7_strings($lang){
	    if (!class_exists('\WPCF7_ContactForm')) return;

	    $strings = [];
	    $forms = \WPCF7_ContactForm::find();

	    foreach ($forms as $form) {
	        $props = $form->get_properties();

	        foreach ($props as $field) {
	            if (is_array($field)) {
	                foreach ($field as $subfield) {
	                    $strings = array_merge($strings, $this->extract_cf7_strings($subfield));
	                }
	            } else {
	                $strings = array_merge($strings, $this->extract_cf7_strings($field));
	            }
	        }
	    }

	    if (empty($strings)) return;

	    $translations = [];
	    foreach (array_unique($strings) as $s) {
	        $translations[] = [$s, $s, 'Contact Form 7'];
	    }

	    $term = get_term_by('slug', $lang, 'language');
	    if (!$term) return;
	    $term_id = $term->term_id;

	    $existing = get_term_meta($term_id, '_pll_strings_translations', true);
	    if (!is_array($existing)) $existing = [];

	    foreach ($translations as $entry) {
	        $found = false;
	        foreach ($existing as $e) {
	            if ($e[0] === $entry[0]) {
	                $found = true;
	                break;
	            }
	        }
	        if (!$found) {
	            $existing[] = $entry;
	        }
	    }

	    update_term_meta($term_id, '_pll_strings_translations', $existing);
	}
    public function translate_strings( $lang = "en", $retranslate = false ) {

    	if ($lang === $this->default_language) return;

		$results = [
			"status" => true,
			"status_text" => __("Strings not translated...", "salt-ai-translator")
		];

	    $term = get_term_by( 'slug', $lang, 'language' );

	    if ( ! $term ) return;

	    $plugin = $this->container->get("plugin");

	    $this->update_cf7_strings($lang);

	    $term_id = $term->term_id;
	    $translations = get_term_meta( $term_id, '_pll_strings_translations', true );

	    if ( ! is_array( $translations ) ) {
	    	$term_default = get_term_by( 'slug', $this->default_language, 'language' );
	    	$translations = get_term_meta( $term_default->term_id, '_pll_strings_translations', true );
	    }
	    if ( ! is_array( $translations ) ) return;

	    $updated = false;

	    $prompt = "Translate the following strings into the target language. These strings are used in a website's interface and content.
		Rules:
		- Translate contextually — not word-for-word. These are real UI or content strings from a website, not isolated words.
		- Always translate the string. If it cannot be translated, return it exactly as-is.
		- Preserve the original casing (uppercase/lowercase).
		- Do NOT add or modify formatting: no HTML, no tags, no symbols, no code, no explanations.
		- Only return the plain translation — no extra characters or wrappers.
		- For slugs or URLs like kebab-case, translate them accurately but never use special characters, accents, or spaces in the result.\n\n";

	    foreach ( $translations as &$pair ) {
	        $source = $pair[0] ?? '';
	        $target = $pair[1] ?? '';

	        if(empty($target)){
	        	continue;
	        }

	        if ( (empty( $target) || $target === $source) || ($retranslate && (empty($target) || $target === $source))) {
                $translated = $source;
	        	if(!preg_match('/%%[^%\s]+%%/', $source )){
					$translated = $this->translate_text( $source, $lang, $prompt);
	        	}
	        	$translated = $plugin->sanitize_translated_string($translated);
	            if ( ! empty( $translated ) && is_string( $translated ) ) {
	                $pair[1] = $translated;
	                $updated = true;
	            }
	        }
	    }

	    if ( $updated ) {
	    	update_term_meta( $term_id, '_pll_strings_translations', $translations );
	    	$results["status_text"] = __("All strings translated.", "salt-ai-translator");
	    }
	    return $results;
	}




    public function get_languages($ignore_default = true){
	    $languages = [];
    	foreach (pll_the_languages(['raw' => 1]) as $language) {
    		if($language['slug'] == $this->default_language && $ignore_default){
	    		continue;	
    		}
    		$languages[$language['slug']] = $language['name'];    	
    	};
    	return $languages;
    }
    public function get_language_label($lang="en") {
	    return $this->get_languages()[$lang];
	}
    
    public function is_media_translation_enabled() {
	    $options = get_option('polylang');
	    $this->container->get("plugin")->log("Media support is ".$options['media_support']); 
	    return isset($options['media_support']) && (bool) $options['media_support'];
	}

}