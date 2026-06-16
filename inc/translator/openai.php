<?php
namespace SAT\Translator;

class OpenAI extends AbstractTranslator {

    private string $apiUrl = 'https://api.openai.com/v1/chat/completions';

    // Statik fallback model listesi — ModelSync ile güncellenir
    private array $staticModels = [
        'gpt-4o'          => ['name' => 'GPT-4o',           'input' => 0.005,   'output' => 0.015,  'vision' => true,  'batch' => true],
        'gpt-4o-mini'     => ['name' => 'GPT-4o Mini',      'input' => 0.00015, 'output' => 0.0006, 'vision' => true,  'batch' => true],
        'gpt-4.1'         => ['name' => 'GPT-4.1',          'input' => 0.002,   'output' => 0.008,  'vision' => true,  'batch' => true],
        'gpt-4.1-mini'    => ['name' => 'GPT-4.1 Mini',     'input' => 0.0004,  'output' => 0.0016, 'vision' => true,  'batch' => true],
        'gpt-4.1-nano'    => ['name' => 'GPT-4.1 Nano',     'input' => 0.0001,  'output' => 0.0004, 'vision' => false, 'batch' => true],
        'gpt-4-turbo'     => ['name' => 'GPT-4 Turbo',      'input' => 0.01,    'output' => 0.03,   'vision' => true,  'batch' => false],
        'o3-mini'         => ['name' => 'o3 Mini',           'input' => 0.0011,  'output' => 0.0044, 'vision' => false, 'batch' => false],
        'gpt-3.5-turbo'   => ['name' => 'GPT-3.5 Turbo',    'input' => 0.0005,  'output' => 0.0015, 'vision' => false, 'batch' => true],
    ];

    public function getName(): string { return 'openai'; }
    public function supportsVision(): bool { return true; }
    public function supportsBatch(): bool  { return true; }

    public function getModels(): array {
        // Önce custom/synced modelleri al, yoksa static fallback
        $custom = $this->options['custom_models']['openai'] ?? [];
        return !empty($custom) ? $custom : $this->staticModels;
    }

    public function getLanguages(): array {
        return []; // OpenAI tüm dilleri destekler, ML plugin'den alınır
    }

    public function translate(string $text, string $lang, string $customPrompt = ''): string {
        if (!$this->shouldTranslate($text)) return $text;

        // Cache kontrolü — aynı metin+dil daha önce çevrildiyse API çağrısı yapma
        $cached = $this->getCached($text, $lang);
        if ($cached !== null) return $cached;

        $model       = $this->options['model'] ?: 'gpt-4o-mini';
        $temperature = (float) ($this->options['temperature'] ?? 0.2);
        $system      = $this->buildTranslatePrompt($lang, $customPrompt);
        $wrapped     = "<sat_content>{$text}</sat_content>";

        $response = $this->request([
            'model'       => $model,
            'temperature' => $temperature,
            'messages'    => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user',   'content' => $wrapped],
            ],
        ]);

        if (!$response['success']) {
            // API başarısız — exception fırlat ki AjaxHandler error döndürsün
            throw new \RuntimeException('OpenAI API error: ' . ($response['error'] ?? 'Unknown error'));
        }

        $result = $response['content'];
        $result = preg_replace('/<\/?sat_content>/i', '', $result);
        $result = trim($result);

        // Başarılı çeviriyi cache'e yaz (her zaman — custom prompt context için kullanılmaz)
        $this->setCache($text, $lang, $result);

        return $result;
    }

    public function translateWithAlternatives(string $text, string $lang, int $count = 3): array {
        if (!$this->shouldTranslate($text)) return [$text];

        $model  = $this->options['model'] ?: 'gpt-4o';
        $system = "You are a professional translator. Translate the following text to [{$lang}].
Return exactly {$count} alternative translations, each on a new line, numbered like:
1. [translation]
2. [translation]
3. [translation]
Do not add any explanation.";

        $response = $this->request([
            'model'       => $model,
            'temperature' => 0.7,
            'messages'    => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user',   'content' => $text],
            ],
        ]);

        if (!$response['success']) return [$text];

        preg_match_all('/^\d+\.\s+(.+)$/m', $response['content'], $matches);
        return !empty($matches[1]) ? $matches[1] : [$response['content']];
    }

    public function generateAltText(string $imageUrl, string $lang): string {
        $system = "Generate a concise, descriptive ALT text (max 125 characters) in language [{$lang}].
Rules: accessibility-focused, no 'image of' or 'photo of', describe what's visible.";

        if (!empty($this->options['seo']['image_alttext']['prompt'])) {
            $system .= ' ' . $this->options['seo']['image_alttext']['prompt'];
        }

        $model       = $this->options['seo']['image_alttext']['model'] ?: 'gpt-4o';
        $temperature = (float) ($this->options['seo']['image_alttext']['temperature'] ?? 0.4);

        $response = $this->request([
            'model'       => $model,
            'temperature' => $temperature,
            'messages'    => [[
                'role'    => 'user',
                'content' => [
                    ['type' => 'text',      'text'      => $system],
                    ['type' => 'image_url', 'image_url' => ['url' => $imageUrl, 'detail' => 'low']],
                ],
            ]],
        ]);

        return $response['success'] ? trim($response['content']) : '';
    }

    public function generateCaption(string $imageUrl, string $lang): string {
        $system = "Generate a short, engaging image caption (1-2 sentences) in language [{$lang}]. Natural, descriptive tone.";

        $response = $this->request([
            'model'       => 'gpt-4o',
            'temperature' => 0.6,
            'messages'    => [[
                'role'    => 'user',
                'content' => [
                    ['type' => 'text',      'text'      => $system],
                    ['type' => 'image_url', 'image_url' => ['url' => $imageUrl, 'detail' => 'low']],
                ],
            ]],
        ]);

        return $response['success'] ? trim($response['content']) : '';
    }

    public function generateMetaDescription(string $title, string $content, string $lang): string {
        $model       = $this->options['seo']['meta_desc']['model'] ?: 'gpt-4o-mini';
        $temperature = (float) ($this->options['seo']['meta_desc']['temperature'] ?? 0.5);
        $system      = "Generate an SEO meta description under 155 characters in language [{$lang}]. Clear, informative, no quotes. Return only the description.";

        if (!empty($this->options['seo']['meta_desc']['prompt'])) {
            $system .= ' ' . $this->options['seo']['meta_desc']['prompt'];
        }

        $userMsg = "Title: {$title}\n\nContent:\n" . wp_trim_words(strip_tags($content), 200);

        $response = $this->request([
            'model'       => $model,
            'temperature' => $temperature,
            'messages'    => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user',   'content' => $userMsg],
            ],
        ]);

        return $response['success'] ? trim($response['content']) : '';
    }

    public function estimateCost(string $text, string $model = ''): float {
        $model   = $model ?: ($this->options['model'] ?: 'gpt-4o-mini');
        $models  = $this->getModels();
        $pricing = $models[$model] ?? ['input' => 0.00015, 'output' => 0.0006];
        $tokens  = (int) ceil(mb_strlen($text) / 4); // mb_strlen for unicode correctness
        // Input + estimated output (roughly same length)
        return ($tokens * $pricing['input'] / 1000) + ($tokens * $pricing['output'] / 1000);
    }

    public function getCostPerToken(string $model = ''): float {
        $model   = $model ?: ($this->options['model'] ?: 'gpt-4o-mini');
        $models  = $this->getModels();
        $pricing = $models[$model] ?? ['input' => 0.00015];
        return $pricing['input'] / 1000;
    }

    public function getRemainingCredits(): array {
        $keys = $this->getApiKeys();
        if (empty($keys)) return ['error' => 'No API keys configured'];

        $results = [];
        foreach ($keys as $key) {
            $response = wp_remote_get('https://api.openai.com/v1/models', [
                'headers' => ['Authorization' => 'Bearer ' . trim($key)],
                'timeout' => 10,
            ]);

            if (is_wp_error($response)) {
                $results[] = ['key_preview' => substr($key, 0, 8) . '...', 'status' => 'error', 'message' => $response->get_error_message()];
                continue;
            }

            $code = wp_remote_retrieve_response_code($response);
            $results[] = [
                'key_preview' => substr($key, 0, 8) . '...',
                'status'      => $code === 200 ? 'valid' : 'invalid',
                'http_code'   => $code,
            ];
        }

        return ['keys' => $results, 'note' => 'OpenAI does not expose credit balance via API. Use dashboard.openai.com'];
    }

    private function buildTranslatePrompt(string $lang, string $customPrompt = ''): string {
        $prompt = "You are a professional website content translator.
- Target language: [{$lang}]
- Input is wrapped in <sat_content> tags. Return the translated content inside <sat_content> tags.
- Preserve ALL HTML tags, attributes, shortcodes, and WordPress block comments exactly.
- Translate ONLY visible text between HTML tags.
- DO NOT translate: class names, IDs, href/src values, data-* attributes, shortcodes in [brackets].
- DO NOT encode HTML entities or escape characters.
- DO NOT summarize or rephrase — translate word for word.";

        $globalPrompt = $this->options['prompt'] ?? '';
        if ($globalPrompt) $prompt .= "\n" . $globalPrompt;
        if ($customPrompt) $prompt .= "\n" . $customPrompt;

        return $this->applyGlossary($prompt);
    }

    private function request(array $body): array {
        $keys = $this->getApiKeys();
        if (empty($keys)) return ['success' => false, 'error' => 'No API keys'];

        $startTime = microtime(true);

        foreach ($keys as $key) {
            $res = $this->httpPost($this->apiUrl, [
                'headers' => [
                    'Content-Type'  => 'application/json',
                    'Authorization' => 'Bearer ' . trim($key),
                ],
                'body'    => json_encode($body),
                'timeout' => 60,
            ]);

            if ($res['error'] || $res['code'] === 0) continue; // network error → sonraki key

            $code = $res['code'];
            $data = json_decode($res['body'], true);

            if ($code === 200 && isset($data['choices'][0]['message']['content'])) {
                $duration = (int) ((microtime(true) - $startTime) * 1000);
                $usage    = $data['usage'] ?? [];

                $logger = $this->container->get('logger');
                if ($logger) {
                    $model   = $body['model'] ?? '';
                    $models  = $this->getModels();
                    $pricing = $models[$model] ?? ['input' => 0, 'output' => 0];
                    $cost    = (($usage['prompt_tokens'] ?? 0) * $pricing['input'] / 1000)
                             + (($usage['completion_tokens'] ?? 0) * $pricing['output'] / 1000);

                    $logger->log([
                        'object_type'  => $this->translateContext['object_type'] ?? 'post',
                        'object_id'    => $this->translateContext['object_id']   ?? 0,
                        'source_lang'  => $this->translateContext['source_lang'] ?? '',
                        'target_lang'  => $this->translateContext['target_lang'] ?? '',
                        'field_name'   => $this->translateContext['field_name']  ?? '',
                        'translator'   => 'openai',
                        'model'        => $model,
                        'tokens_input' => $usage['prompt_tokens'] ?? 0,
                        'tokens_output'=> $usage['completion_tokens'] ?? 0,
                        'cost_usd'     => $cost,
                        'duration_ms'  => $duration,
                        'status'       => 'success',
                    ]);
                }

                return ['success' => true, 'content' => $data['choices'][0]['message']['content'], 'usage' => $usage];
            }

            // Quota veya invalid key → sonraki key
            $errorCode = $data['error']['code'] ?? '';
            if (in_array($errorCode, ['insufficient_quota', 'invalid_api_key'])) continue;

            // Rate limit 429 — httpPost zaten retry yaptı, tüm denemeler bitti → sonraki key
            if ($code === 429) continue;
        }

        return ['success' => false, 'error' => 'All API keys failed'];
    }
}
