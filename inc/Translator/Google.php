<?php
namespace SAT\Translator;

class Google extends AbstractTranslator {

    private string $apiUrl = 'https://translation.googleapis.com/language/translate/v2';

    public function getName(): string    { return 'google'; }
    public function supportsVision(): bool { return false; }
    public function supportsBatch(): bool  { return true; }

    public function getModels(): array {
        return [
            'nmt' => ['name' => 'Google NMT (Neural)', 'input' => 0.00002, 'output' => 0],
        ];
    }

    public function getLanguages(): array {
        return [
            'af'=>'Afrikaans','sq'=>'Albanian','am'=>'Amharic','ar'=>'Arabic',
            'az'=>'Azerbaijani','eu'=>'Basque','be'=>'Belarusian','bn'=>'Bengali',
            'bs'=>'Bosnian','bg'=>'Bulgarian','ca'=>'Catalan','ceb'=>'Cebuano',
            'zh-CN'=>'Chinese (Simplified)','zh-TW'=>'Chinese (Traditional)',
            'co'=>'Corsican','hr'=>'Croatian','cs'=>'Czech','da'=>'Danish',
            'nl'=>'Dutch','en'=>'English','eo'=>'Esperanto','et'=>'Estonian',
            'fi'=>'Finnish','fr'=>'French','fy'=>'Frisian','gl'=>'Galician',
            'ka'=>'Georgian','de'=>'German','el'=>'Greek','gu'=>'Gujarati',
            'ht'=>'Haitian Creole','ha'=>'Hausa','haw'=>'Hawaiian','he'=>'Hebrew',
            'hi'=>'Hindi','hmn'=>'Hmong','hu'=>'Hungarian','is'=>'Icelandic',
            'ig'=>'Igbo','id'=>'Indonesian','ga'=>'Irish','it'=>'Italian',
            'ja'=>'Japanese','jv'=>'Javanese','kn'=>'Kannada','kk'=>'Kazakh',
            'km'=>'Khmer','rw'=>'Kinyarwanda','ko'=>'Korean','ku'=>'Kurdish',
            'ky'=>'Kyrgyz','lo'=>'Lao','la'=>'Latin','lv'=>'Latvian',
            'lt'=>'Lithuanian','lb'=>'Luxembourgish','mk'=>'Macedonian',
            'mg'=>'Malagasy','ms'=>'Malay','ml'=>'Malayalam','mt'=>'Maltese',
            'mi'=>'Maori','mr'=>'Marathi','mn'=>'Mongolian','my'=>'Myanmar',
            'ne'=>'Nepali','no'=>'Norwegian','ny'=>'Nyanja','or'=>'Odia',
            'ps'=>'Pashto','fa'=>'Persian','pl'=>'Polish','pt'=>'Portuguese',
            'pa'=>'Punjabi','ro'=>'Romanian','ru'=>'Russian','sm'=>'Samoan',
            'gd'=>'Scots Gaelic','sr'=>'Serbian','st'=>'Sesotho','sn'=>'Shona',
            'sd'=>'Sindhi','si'=>'Sinhala','sk'=>'Slovak','sl'=>'Slovenian',
            'so'=>'Somali','es'=>'Spanish','su'=>'Sundanese','sw'=>'Swahili',
            'sv'=>'Swedish','tl'=>'Tagalog','tg'=>'Tajik','ta'=>'Tamil',
            'tt'=>'Tatar','te'=>'Telugu','th'=>'Thai','tr'=>'Turkish',
            'tk'=>'Turkmen','uk'=>'Ukrainian','ur'=>'Urdu','ug'=>'Uyghur',
            'uz'=>'Uzbek','vi'=>'Vietnamese','cy'=>'Welsh','xh'=>'Xhosa',
            'yi'=>'Yiddish','yo'=>'Yoruba','zu'=>'Zulu',
        ];
    }

    public function translate(string $text, string $lang, string $customPrompt = ''): string {
        if (!$this->shouldTranslate($text)) return $text;

        $keys = $this->getApiKeys();
        if (empty($keys)) throw new \RuntimeException('Google Translate: No API keys configured');

        $key = reset($keys);
        $res = $this->httpPost($this->apiUrl . '?key=' . trim($key), [
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => json_encode([
                'q'      => $text,
                'target' => $lang,
                'format' => 'html',
            ]),
            'timeout' => 30,
        ]);

        if ($res['error'] || $res['code'] === 0) {
            throw new \RuntimeException('Google Translate API error: ' . ($res['error'] ?? 'Network error'));
        }

        $code = $res['code'];
        $data = json_decode($res['body'], true);

        if ($code !== 200 || !isset($data['data']['translations'][0]['translatedText'])) {
            $msg = $data['error']['message'] ?? ('HTTP ' . $code);
            throw new \RuntimeException('Google Translate API error: ' . $msg);
        }

        return $data['data']['translations'][0]['translatedText'];
    }

    public function translateBatch(array $texts, string $lang): array {
        $keys = $this->getApiKeys();
        if (empty($keys)) return $texts;

        $key      = reset($keys);
        $response = wp_remote_post($this->apiUrl . '?key=' . trim($key), [
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => json_encode([
                'q'      => array_values($texts),
                'target' => $lang,
                'format' => 'html',
            ]),
            'timeout' => 60,
        ]);

        if (is_wp_error($response)) return $texts;

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($data['data']['translations'])) return $texts;

        $results  = [];
        $keysArr  = array_keys($texts);
        foreach ($data['data']['translations'] as $i => $t) {
            $results[$keysArr[$i]] = $t['translatedText'];
        }
        return $results;
    }

    public function generateAltText(string $imageUrl, string $lang): string { return ''; }
    public function generateMetaDescription(string $title, string $content, string $lang): string { return ''; }

    public function estimateCost(string $text, string $model = ''): float {
        return strlen(strip_tags($text)) * 0.00002;
    }

    public function getCostPerToken(string $model = ''): float { return 0.00002; }

    public function getRemainingCredits(): array {
        return ['note' => 'Google Translate uses pay-per-use. Check Google Cloud Console for billing.'];
    }
}
