<?php
namespace SAT\Translator;

class AzureOpenAI extends AbstractTranslator {

    public function getName(): string    { return 'azure_openai'; }
    public function supportsVision(): bool { return true; }
    public function supportsBatch(): bool  { return true; }

    private function getEndpoint(): string {
        return rtrim($this->options['azure_endpoint'] ?? '', '/');
    }

    private function getDeployment(): string {
        return $this->options['azure_deployment'] ?? 'gpt-4o';
    }

    private function getApiVersion(): string {
        return $this->options['azure_api_version'] ?? '2024-02-01';
    }

    public function getModels(): array {
        return [
            'gpt-4o'      => ['name' => 'GPT-4o (Azure)',      'input' => 0.005,   'output' => 0.015,  'vision' => true],
            'gpt-4o-mini' => ['name' => 'GPT-4o Mini (Azure)', 'input' => 0.00015, 'output' => 0.0006, 'vision' => true],
            'gpt-4'       => ['name' => 'GPT-4 (Azure)',       'input' => 0.01,    'output' => 0.03,   'vision' => false],
        ];
    }

    public function getLanguages(): array { return []; }

    public function translate(string $text, string $lang, string $customPrompt = ''): string {
        if (!$this->shouldTranslate($text)) return $text;

        $system = "You are a professional translator. Target language: [{$lang}].
Preserve all HTML, shortcodes, and block comments. Translate only visible text.";
        if ($this->options['prompt'] ?? '') $system .= "\n" . $this->options['prompt'];
        if ($customPrompt) $system .= "\n" . $customPrompt;
        $system = $this->applyGlossary($system);

        $response = $this->request([
            'messages'    => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user',   'content' => "<sat_content>{$text}</sat_content>"],
            ],
            'temperature' => (float) ($this->options['temperature'] ?? 0.2),
            'max_tokens'  => 4096,
        ]);

        if (!$response['success']) {
            throw new \RuntimeException('Azure OpenAI API error: ' . ($response['error'] ?? 'Unknown error'));
        }
        $result = preg_replace('/<\/?sat_content>/i', '', $response['content']);
        return trim($result);
    }

    public function generateAltText(string $imageUrl, string $lang): string {
        $response = $this->request([
            'messages' => [[
                'role'    => 'user',
                'content' => [
                    ['type' => 'text',      'text'      => "Generate a concise ALT text (max 125 chars) in language [{$lang}]. No 'image of' prefix."],
                    ['type' => 'image_url', 'image_url' => ['url' => $imageUrl, 'detail' => 'low']],
                ],
            ]],
            'temperature' => 0.4,
            'max_tokens'  => 200,
        ]);
        return $response['success'] ? trim($response['content']) : '';
    }

    public function generateMetaDescription(string $title, string $content, string $lang): string {
        $response = $this->request([
            'messages' => [
                ['role' => 'system', 'content' => "Generate SEO meta description under 155 chars in [{$lang}]. Return only the description."],
                ['role' => 'user',   'content' => "Title: {$title}\n\nContent:\n" . wp_trim_words(strip_tags($content), 200)],
            ],
            'temperature' => 0.5,
            'max_tokens'  => 200,
        ]);
        return $response['success'] ? trim($response['content']) : '';
    }

    public function estimateCost(string $text, string $model = ''): float {
        $model   = $model ?: $this->getDeployment();
        $models  = $this->getModels();
        $pricing = $models[$model] ?? ['input' => 0.005, 'output' => 0.015];
        $tokens  = (int) ceil(strlen($text) / 4);
        return ($tokens * $pricing['input'] / 1000) + ($tokens * $pricing['output'] / 1000);
    }

    public function getCostPerToken(string $model = ''): float {
        $model   = $model ?: $this->getDeployment();
        $models  = $this->getModels();
        return ($models[$model]['input'] ?? 0.005) / 1000;
    }

    public function getRemainingCredits(): array {
        return ['note' => 'Check Azure Portal for billing and quota information.'];
    }

    private function request(array $body): array {
        $endpoint   = $this->getEndpoint();
        $deployment = $this->getDeployment();
        $apiVersion = $this->getApiVersion();
        $keys       = $this->getApiKeys();

        if (!$endpoint || empty($keys)) return ['success' => false, 'error' => 'Azure endpoint or API key not configured'];

        $url = "{$endpoint}/openai/deployments/{$deployment}/chat/completions?api-version={$apiVersion}";
        $key = reset($keys);

        $res = $this->httpPost($url, [
            'headers' => [
                'Content-Type' => 'application/json',
                'api-key'      => trim($key),
            ],
            'body'    => json_encode($body),
            'timeout' => 60,
        ]);

        if ($res['error'] || $res['code'] === 0) return ['success' => false, 'error' => $res['error']];

        $data = json_decode($res['body'], true);
        if (isset($data['choices'][0]['message']['content'])) {
            return ['success' => true, 'content' => $data['choices'][0]['message']['content']];
        }
        return ['success' => false, 'error' => $data['error']['message'] ?? ('HTTP ' . $res['code'])];
    }
}
