<?php
namespace SAT\Translator;

class LibreTranslate extends AbstractTranslator {

    public function getName(): string    { return 'libretranslate'; }
    public function supportsVision(): bool { return false; }
    public function supportsBatch(): bool  { return true; }

    private function getApiUrl(): string {
        return rtrim($this->options['libretranslate_url'] ?? 'https://libretranslate.com', '/');
    }

    public function getModels(): array {
        return ['default' => ['name' => 'LibreTranslate', 'input' => 0, 'output' => 0]];
    }

    public function getLanguages(): array {
        $url      = $this->getApiUrl() . '/languages';
        $response = wp_remote_get($url, ['timeout' => 10]);
        if (is_wp_error($response)) return [];
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($data)) return [];
        $result = [];
        foreach ($data as $lang) {
            $result[$lang['code']] = $lang['name'];
        }
        return $result;
    }

    public function translate(string $text, string $lang, string $customPrompt = ''): string {
        if (!$this->shouldTranslate($text)) return $text;

        $keys = $this->getApiKeys();
        $key  = reset($keys) ?: '';

        $body = ['q' => $text, 'source' => 'auto', 'target' => $lang, 'format' => 'html'];
        if ($key) $body['api_key'] = $key;

        $res = $this->httpPost($this->getApiUrl() . '/translate', [
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => json_encode($body),
            'timeout' => 30,
        ]);

        if ($res['error'] || $res['code'] === 0) {
            throw new \RuntimeException('LibreTranslate API error: ' . ($res['error'] ?? 'Network error'));
        }

        $code = $res['code'];
        $data = json_decode($res['body'], true);

        if ($code !== 200 || !isset($data['translatedText'])) {
            $msg = $data['error'] ?? ('HTTP ' . $code);
            throw new \RuntimeException('LibreTranslate API error: ' . $msg);
        }

        return $data['translatedText'];
    }

    public function generateAltText(string $imageUrl, string $lang): string { return ''; }
    public function generateMetaDescription(string $title, string $content, string $lang): string { return ''; }
    public function estimateCost(string $text, string $model = ''): float { return 0.0; }
    public function getCostPerToken(string $model = ''): float { return 0.0; }

    public function getRemainingCredits(): array {
        return ['note' => 'LibreTranslate is free/self-hosted. No credit tracking needed.', 'balance_usd' => null];
    }
}
