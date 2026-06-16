<?php
namespace SAT\Translator;

class DeepL extends AbstractTranslator {

    private string $apiUrl     = 'https://api.deepl.com/v2/translate';
    private string $freeApiUrl = 'https://api-free.deepl.com/v2/translate';

    private array $supportedLangs = [
        'BG'=>'Bulgarian','CS'=>'Czech','DA'=>'Danish','DE'=>'German',
        'EL'=>'Greek','EN-GB'=>'English (UK)','EN-US'=>'English (US)',
        'ES'=>'Spanish','ET'=>'Estonian','FI'=>'Finnish','FR'=>'French',
        'HU'=>'Hungarian','ID'=>'Indonesian','IT'=>'Italian','JA'=>'Japanese',
        'KO'=>'Korean','LT'=>'Lithuanian','LV'=>'Latvian','NB'=>'Norwegian',
        'NL'=>'Dutch','PL'=>'Polish','PT-BR'=>'Portuguese (Brazil)',
        'PT-PT'=>'Portuguese (Portugal)','RO'=>'Romanian','RU'=>'Russian',
        'SK'=>'Slovak','SL'=>'Slovenian','SV'=>'Swedish','TR'=>'Turkish',
        'UK'=>'Ukrainian','ZH'=>'Chinese',
    ];

    public function getName(): string    { return 'deepl'; }
    public function supportsVision(): bool { return false; }
    public function supportsBatch(): bool  { return true; }

    public function getModels(): array {
        return [
            'default' => ['name' => 'DeepL Default', 'input' => 0.000025, 'output' => 0],
        ];
    }

    public function getLanguages(): array {
        return $this->supportedLangs;
    }

    public function translate(string $text, string $lang, string $customPrompt = ''): string {
        if (!$this->shouldTranslate($text)) return $text;

        $keys = $this->getApiKeys();
        if (empty($keys)) throw new \RuntimeException('DeepL: No API keys configured');

        $targetLang = strtoupper($lang);
        $formality  = $this->options['deepl_formality'] ?? 'default';

        $body = [
            'text'        => [$text],
            'target_lang' => $targetLang,
            'tag_handling'=> 'html',
        ];

        if (in_array($targetLang, ['DE','FR','ES','IT','NL','PL','PT-BR','PT-PT','RU'])) {
            $body['formality'] = $formality;
        }

        $lastError = 'Unknown error';
        foreach ($keys as $key) {
            $url = str_ends_with(trim($key), ':fx') ? $this->freeApiUrl : $this->apiUrl;
            $res = $this->httpPost($url, [
                'headers' => [
                    'Authorization' => 'DeepL-Auth-Key ' . trim($key),
                    'Content-Type'  => 'application/json',
                ],
                'body'    => json_encode($body),
                'timeout' => 30,
            ]);

            if ($res['error'] || $res['code'] === 0) {
                $lastError = $res['error'] ?? 'Network error';
                continue;
            }

            $code = $res['code'];
            $data = json_decode($res['body'], true);

            if ($code === 200 && isset($data['translations'][0]['text'])) {
                return $data['translations'][0]['text'];
            }

            $lastError = 'HTTP ' . $code . ': ' . ($data['message'] ?? 'Unknown error');
        }

        throw new \RuntimeException('DeepL API error: ' . $lastError);
    }

    public function translateBatch(array $texts, string $lang): array {
        $keys = $this->getApiKeys();
        if (empty($keys)) return $texts;

        $targetLang = strtoupper($lang);
        $key        = reset($keys);
        $url        = str_ends_with(trim($key), ':fx') ? $this->freeApiUrl : $this->apiUrl;

        $response = wp_remote_post($url, [
            'headers' => [
                'Authorization' => 'DeepL-Auth-Key ' . trim($key),
                'Content-Type'  => 'application/json',
            ],
            'body'    => json_encode(['text' => array_values($texts), 'target_lang' => $targetLang, 'tag_handling' => 'html']),
            'timeout' => 60,
        ]);

        if (is_wp_error($response)) return $texts;

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($data['translations'])) return $texts;

        $results = [];
        $keys_arr = array_keys($texts);
        foreach ($data['translations'] as $i => $t) {
            $results[$keys_arr[$i]] = $t['text'];
        }
        return $results;
    }

    public function generateAltText(string $imageUrl, string $lang): string {
        return ''; // DeepL cannot analyze images
    }

    public function generateMetaDescription(string $title, string $content, string $lang): string {
        return ''; // DeepL cannot generate content, only translate
    }

    public function estimateCost(string $text, string $model = ''): float {
        $chars = strlen(strip_tags($text));
        return $chars * 0.000025; // $25 per 1M chars
    }

    public function getCostPerToken(string $model = ''): float {
        return 0.000025; // per character
    }

    public function getRemainingCredits(): array {
        $keys = $this->getApiKeys();
        if (empty($keys)) return ['error' => 'No API keys'];

        $key = reset($keys);
        $url = str_ends_with(trim($key), ':fx')
            ? 'https://api-free.deepl.com/v2/usage'
            : 'https://api.deepl.com/v2/usage';

        $response = wp_remote_get($url, [
            'headers' => ['Authorization' => 'DeepL-Auth-Key ' . trim($key)],
            'timeout' => 10,
        ]);

        if (is_wp_error($response)) return ['error' => $response->get_error_message()];

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($data)) return ['error' => 'Invalid response'];

        $used      = $data['character_count'] ?? 0;
        $limit     = $data['character_limit'] ?? 0;
        $remaining = max(0, $limit - $used);

        return [
            'characters_used'      => $used,
            'characters_limit'     => $limit,
            'characters_remaining' => $remaining,
            'balance_usd'          => $remaining * 0.000025,
            'percent_used'         => $limit > 0 ? round(($used / $limit) * 100, 1) : 0,
        ];
    }
}
