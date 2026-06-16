<?php
namespace SAT\Translator;

class Claude extends AbstractTranslator {

    private string $apiUrl = 'https://api.anthropic.com/v1/messages';

    private array $staticModels = [
        'claude-3-5-sonnet-20241022' => ['name' => 'Claude 3.5 Sonnet', 'input' => 0.003,  'output' => 0.015, 'vision' => true],
        'claude-3-5-haiku-20241022'  => ['name' => 'Claude 3.5 Haiku',  'input' => 0.0008, 'output' => 0.004, 'vision' => true],
        'claude-3-opus-20240229'     => ['name' => 'Claude 3 Opus',     'input' => 0.015,  'output' => 0.075, 'vision' => true],
        'claude-3-haiku-20240307'    => ['name' => 'Claude 3 Haiku',    'input' => 0.00025,'output' => 0.00125,'vision' => true],
    ];

    public function getName(): string    { return 'claude'; }
    public function supportsVision(): bool { return true; }
    public function supportsBatch(): bool  { return false; }

    public function getModels(): array {
        $custom = $this->options['custom_models']['claude'] ?? [];
        return !empty($custom) ? $custom : $this->staticModels;
    }

    public function getLanguages(): array { return []; }

    public function translate(string $text, string $lang, string $customPrompt = ''): string {
        if (!$this->shouldTranslate($text)) return $text;

        $model       = $this->options['model'] ?: 'claude-3-5-haiku-20241022';
        $temperature = (float) ($this->options['temperature'] ?? 0.2);
        $system      = $this->buildPrompt($lang, $customPrompt);

        $response = $this->request($model, $temperature, $system, "<sat_content>{$text}</sat_content>");
        if (!$response['success']) {
            throw new \RuntimeException('Claude API error: ' . ($response['error'] ?? 'Unknown error'));
        }

        $result = preg_replace('/<\/?sat_content>/i', '', $response['content']);
        return trim($result);
    }

    public function generateAltText(string $imageUrl, string $lang): string {
        $system = "Generate a concise ALT text (max 125 chars) in language [{$lang}]. No 'image of' prefix.";
        $response = $this->request('claude-3-5-haiku-20241022', 0.4, $system, [
            ['type' => 'image', 'source' => ['type' => 'url', 'url' => $imageUrl]],
            ['type' => 'text',  'text'   => $system],
        ]);
        return $response['success'] ? trim($response['content']) : '';
    }

    public function generateMetaDescription(string $title, string $content, string $lang): string {
        $system  = "Generate an SEO meta description under 155 characters in language [{$lang}]. Return only the description.";
        $userMsg = "Title: {$title}\n\nContent:\n" . wp_trim_words(strip_tags($content), 200);
        $response = $this->request('claude-3-5-haiku-20241022', 0.5, $system, $userMsg);
        return $response['success'] ? trim($response['content']) : '';
    }

    public function estimateCost(string $text, string $model = ''): float {
        $model   = $model ?: ($this->options['model'] ?: 'claude-3-5-haiku-20241022');
        $models  = $this->getModels();
        $pricing = $models[$model] ?? ['input' => 0.0008, 'output' => 0.004];
        $tokens  = (int) ceil(strlen($text) / 4);
        return ($tokens * $pricing['input'] / 1000) + ($tokens * $pricing['output'] / 1000);
    }

    public function getCostPerToken(string $model = ''): float {
        $model   = $model ?: ($this->options['model'] ?: 'claude-3-5-haiku-20241022');
        $models  = $this->getModels();
        return ($models[$model]['input'] ?? 0.0008) / 1000;
    }

    public function getRemainingCredits(): array {
        return ['note' => 'Anthropic does not expose credit balance via API. Check console.anthropic.com'];
    }

    private function buildPrompt(string $lang, string $customPrompt = ''): string {
        $prompt = "You are a professional website content translator.
- Target language: [{$lang}]
- Input is wrapped in <sat_content> tags. Return translated content inside <sat_content> tags.
- Preserve ALL HTML, shortcodes, block comments exactly.
- Translate ONLY visible text. Never translate attributes, class names, IDs, URLs.
- Do NOT summarize or rephrase.";
        if ($this->options['prompt'] ?? '') $prompt .= "\n" . $this->options['prompt'];
        if ($customPrompt) $prompt .= "\n" . $customPrompt;
        return $this->applyGlossary($prompt);
    }

    private function request(string $model, float $temperature, string $system, mixed $userContent): array {
        $keys = $this->getApiKeys();
        if (empty($keys)) return ['success' => false];

        $key      = reset($keys);
        $messages = [['role' => 'user', 'content' => is_array($userContent) ? $userContent : $userContent]];

        $res = $this->httpPost($this->apiUrl, [
            'headers' => [
                'x-api-key'         => trim($key),
                'anthropic-version' => '2023-06-01',
                'Content-Type'      => 'application/json',
            ],
            'body'    => json_encode([
                'model'       => $model,
                'max_tokens'  => 4096,
                'temperature' => $temperature,
                'system'      => $system,
                'messages'    => $messages,
            ]),
            'timeout' => 60,
        ]);

        if ($res['error'] || $res['code'] === 0) return ['success' => false, 'error' => $res['error']];

        $data = json_decode($res['body'], true);
        if (isset($data['content'][0]['text'])) {
            return ['success' => true, 'content' => $data['content'][0]['text']];
        }
        return ['success' => false, 'error' => $data['error']['message'] ?? ('HTTP ' . $res['code'])];
    }
}
