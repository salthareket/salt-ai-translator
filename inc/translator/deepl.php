<?php

namespace SaltAI\Translator;

use SaltAI\Core\ServiceContainer;

//Translator name: Deepl (Free)

class Translator {
    private ServiceContainer $container;
    private $plugin;
    public $formalities;
    public $formality;
    private $api_url;

    public function __construct($container) {
        $this->container = $container;
        $this->plugin = $container->get('plugin');
        $this->formalities = [
            'default' => __('Let DeepL decide the tone (default)', 'salt-ai-translator'),
            'more' => __('Use a more formal and respectful tone (e.g. business language)', 'salt-ai-translator'),
            'less' => __('Use a more casual and friendly tone (e.g. for friends)', 'salt-ai-translator'),
            'prefer_more' => __('Prefer formal tone when ambiguous', 'salt-ai-translator'),
            'prefer_less' => __('Prefer informal tone when ambiguous', 'salt-ai-translator'),
        ];
        $this->formality = 'default';
        $this->api_url = 'https://api-free.deepl.com/v2/translate';
    }

    public function request($body=[]){
        $original_body = $body;
        foreach ($this->plugin->options["api_keys"]["deepl"] as $index => $api_key) {
            if (empty($api_key)) continue;

            $request_body = $original_body;
            $request_body["auth_key"] = $api_key;

            $response = wp_remote_post($this->api_url, [
                'body' => $request_body,
            ]);

            if (is_wp_error($response)) {
                continue; // WP error varsa diğer key'e geç
            }

            $data = json_decode(wp_remote_retrieve_body($response), true);
            if (!empty($data['translations'][0]['text'])) {
                $translated = $data['translations'][0]['text'];
                return str_replace('<x-linebreak>', PHP_EOL, $translated);
            }

        }
        return __('All API keys failed. Please check your configuration.', 'salt-ai-translator');
    }

    public function translate($text = "", $to = 'EN') {
        if (!is_string($text) || trim($text) === '' || !$this->should_translate($text) || empty($text) || empty($this->plugin->options["api_keys"]["deepl"])) return $text;
        $text = str_replace(["\r\n", "\n", "\r"], '<x-linebreak>', $text);
        $body = [
                    //'auth_key' => $key,
                    'text' => $text,
                    'target_lang' => strtoupper($to),
                    'tag_handling' => 'html',
                    'preserve_formatting' => 1,
        ];
        return $this->request($body);
    }

    public function quota_info($keys = []) {
        if (empty($keys)) return [];

        $results = [];
        foreach ($keys as $key) {
            $test = wp_remote_post('https://api-free.deepl.com/v2/usage', [
                'body' => ['auth_key' => $key],
            ]);

            if (is_wp_error($test)) {
                $results[] = __('API error – ', 'salt-ai-translator') . $test->get_error_message();
                continue;
            }

            $body = json_decode(wp_remote_retrieve_body($test), true);
            if (isset($body['character_count'])) {
                $percent = round(($body['character_count'] / $body['character_limit']) * 100, 2);
                $results[] = sprintf(
                    __('Valid – %s%% used (%d/%d)', 'salt-ai-translator'),
                    $percent,
                    $body['character_count'],
                    $body['character_limit']
                );
            } else {
                $results[] = __('API error – No response received.', 'salt-ai-translator');
            }
        }

        return $results;
    }

    private function should_translate($text) {
        return is_string($text) && trim(strip_tags($text)) !== '' && !is_numeric($text);
    }

    public function generate_alt_text(string $image_url, $to=""): string {
        // DeepL does not support vision/image analysis — return empty
        return '';
    }

    public function set_custom_prompt($prompt = ""){
        // DeepL does not use custom prompts — noop
    }
}
