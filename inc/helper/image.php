<?php

namespace SaltAI\Helper;

use SaltAI\Core\ServiceContainer;

class Image {

    private ServiceContainer $container;

    public function __construct($container) {
        $this->container = $container;
    }

    public function get_image_alt(int $post_id, string $lang = ""){

        $meta_key = !empty($lang) ? '_salt_image_alt_'.$lang : "_wp_attachment_image_alt";
        return get_post_meta($post_id, $meta_key, true) ?: null;
    }

    public function update_image_alt(int $post_id, string $meta_value, string $lang = ""): void {
        $plugin = $this->container->get("plugin");
        $meta_key = !empty($lang) ? '_salt_image_alt_'.$lang : "_wp_attachment_image_alt";
        $plugin->log("update_image_alt [".$lang."] : ".$meta_key." -> ".$meta_value);
        update_post_meta($post_id, $meta_key, $meta_value);
    }

    public function generate_alt_text($attachments = [], $lang="en"){
        $plugin = $this->container->get("plugin");
        $integration = $this->container->get("integration");
        $translator = $this->container->get("translator");
        foreach ($attachments as $image) {
            if (!$image["id"]){
                continue;
            }
            $alt_current = $this->get_image_alt($image["id"]);
            if($plugin->options["seo"]["image_alttext"]["preserve"] && !empty($alt_current)){
                $alt = $alt_current;
            }else{
                $image_data = $this->check_image_url($image["url"]);
                $plugin->log($image_data);
                if($image_data["success"]){
                    $alt = $translator->generate_alt_text($image_data["url"]);
                    $plugin->log("Alt text: ".$alt);
                    if($image_data["temp"]){
                        $this->delete_temp_image($image_data["path"]);                        
                    }
                }else{
                    $plugin->log("Image error: ".$image_data["error"]);
                }
            }
            
            if (!empty($alt)) {
                $this->update_image_alt($image["id"], $alt);
                $plugin->log("Alt Text generated for: ".$image["url"]." -> ".$alt); 

                if($plugin->options["seo"]["image_alttext"]["translate"]){

                    if($integration->is_media_translation_enabled()){
                        $translations = function_exists('pll_get_post_translations') ? pll_get_post_translations($image["id"]) : [];
                        if ( ! isset($translations[$lang]) || empty($translations[$lang]) ) {
                            $new_image_id = $plugin->duplicate_post($image["id"]);
                            pll_set_post_language($new_image_id, $lang);
                            $translations[$lang] = $new_image_id;
                            pll_save_post_translations($translations);
                        }else{
                            $new_image_id = $translations[$lang];
                        }
                        $translate_alt = true;
                        if($plugin->options["seo"]["image_alttext"]["preserve"]){
                            $translated_alt_current = $this->get_image_alt($new_image_id);
                            if(!empty($translated_alt_current)){
                                $translate_alt = false;
                            }
                        }
                        if($translate_alt){
                            $translated_alt = $translator->translate($alt, $lang);
                            $this->update_image_alt($new_image_id, $translated_alt);
                            $plugin->log("Alt Text translated to [".$lang."] for: ".$image["url"]." -> ".$translated_alt);  
                        }             
                    }else{

                        $translate_alt = true;
                        if($plugin->options["seo"]["image_alttext"]["preserve"]){
                            $translated_alt_current = $this->get_image_alt($image["id"], $lang);
                            if(!empty($translated_alt_current)){
                                $translate_alt = false;
                            }
                        }
                        if($translate_alt){
                            $translated_alt = $translator->translate($alt, $lang);
                            $plugin->log("Translated Alt [".$lang."]: ".$translated_alt);
                            $this->update_image_alt($image["id"], $translated_alt, $lang);                            
                        }
                        
                    }

                }
            }
        }
    }

    public function check_image_url(string $image_url, string $force_format = 'jpg'): array {
        $supported_ext = ['jpg', 'jpeg', 'png'];
        $supported_mime = ['image/jpeg', 'image/jpg', 'image/png'];

        $parsed = parse_url($image_url);
        $ext = strtolower(pathinfo($parsed['path'] ?? '', PATHINFO_EXTENSION));

        // Eğer uzantı destekleniyorsa → direkt URL döndür
        if (in_array($ext, $supported_ext)) {
            return [
                'success' => true,
                'url' => $image_url,
                'path' => null,
                'temp' => false,
                'error' => null
            ];
        }

        // URL'den dosyayı indir
        $temp_file = download_url($image_url);
        if (is_wp_error($temp_file)) {
            return ['success' => false, 'url' => null, 'path' => null, 'temp' => false, 'error' => 'Görsel indirilemedi'];
        }

        $upload_dir = wp_upload_dir();
        if (!is_dir($upload_dir['path']) || !is_writable($upload_dir['path'])) {
            @unlink($temp_file);
            return ['success' => false, 'url' => null, 'path' => null, 'temp' => false, 'error' => 'Uploads klasörüne yazılamıyor'];
        }

        $filename = 'ai-temp-' . uniqid() . '.' . $force_format;
        $final_path = trailingslashit($upload_dir['path']) . $filename;
        $final_url  = trailingslashit($upload_dir['url']) . $filename;

        // SVG ise Imagick ile özel işlem
        if ($ext === 'svg' && class_exists('Imagick')) {
            try {
                $im = new \Imagick();
                $im->setBackgroundColor(new \ImagickPixel('transparent'));
                $im->readImage($temp_file);
                $im->setImageFormat($force_format);
                $im->resizeImage(1024, 1024, \Imagick::FILTER_LANCZOS, 1, true);
                $im->writeImage($final_path);
                $im->clear();
                @unlink($temp_file);

                return [
                    'success' => true,
                    'url' => $final_url,
                    'path' => $final_path,
                    'temp' => true,
                    'error' => null
                ];
            } catch (Exception $e) {
                @unlink($temp_file);
                return ['success' => false, 'url' => null, 'path' => null, 'temp' => false, 'error' => 'SVG dönüştürme hatası: ' . $e->getMessage()];
            }
        }

        // Diğer formatları WP image editor ile çevir
        $editor = wp_get_image_editor($temp_file);
        if (is_wp_error($editor)) {
            @unlink($temp_file);
            return ['success' => false, 'url' => null, 'path' => null, 'temp' => false, 'error' => 'Image editor açamadı'];
        }

        $editor->resize(1024, 1024, false);
        $saved = $editor->save($final_path, $force_format);
        @unlink($temp_file);

        if (is_wp_error($saved)) {
            return ['success' => false, 'url' => null, 'path' => null, 'temp' => false, 'error' => 'Kaydetme hatası'];
        }

        return [
            'success' => true,
            'url' => $final_url,
            'path' => $final_path,
            'temp' => true,
            'error' => null
        ];
    }

    public function delete_temp_image(string $path): void {
        if (file_exists($path)) {
            @unlink($path);
        }
    }



}
