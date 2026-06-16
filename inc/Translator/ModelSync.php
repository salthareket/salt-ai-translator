<?php
namespace SAT\Translator;

/**
 * ModelSync — Haftalık cron ile AI sağlayıcılarından güncel model listesini çeker.
 * OpenAI: /v1/models endpoint'i
 * Anthropic: Statik liste (API yok, GitHub release'lerden parse edilir)
 * DeepL: Sabit (model yok, sadece API versiyonu)
 */
class ModelSync {

    public static function sync(): void {
        $settings = sat_plugin()->getContainer()->get('settings');
        $apiKeys  = $settings->getAll()['api_keys'] ?? [];

        $customModels = $settings->get('custom_models', []);

        // OpenAI model sync
        $openaiKeys = $apiKeys['openai'] ?? [];
        if (!empty($openaiKeys)) {
            $synced = self::syncOpenAI(reset($openaiKeys));
            if (!empty($synced)) {
                $customModels['openai'] = $synced;
            }
        }

        // Claude model sync (statik liste güncelleme)
        $claudeKeys = $apiKeys['claude'] ?? [];
        if (!empty($claudeKeys)) {
            $synced = self::syncClaude(reset($claudeKeys));
            if (!empty($synced)) {
                $customModels['claude'] = $synced;
            }
        }

        $settings->save([
            'custom_models'    => $customModels,
            'models_last_sync' => time(),
        ]);
    }

    private static function syncOpenAI(string $apiKey): array {
        $response = wp_remote_get('https://api.openai.com/v1/models', [
            'headers' => ['Authorization' => 'Bearer ' . trim($apiKey)],
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) return [];

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($data['data'])) return [];

        // Sadece GPT ve o-serisi modelleri al, embedding/whisper vs. atla
        $translationModels = [];
        $knownPricing = [
            'gpt-4o'          => ['input' => 0.005,   'output' => 0.015,  'vision' => true],
            'gpt-4o-mini'     => ['input' => 0.00015, 'output' => 0.0006, 'vision' => true],
            'gpt-4.1'         => ['input' => 0.002,   'output' => 0.008,  'vision' => true],
            'gpt-4.1-mini'    => ['input' => 0.0004,  'output' => 0.0016, 'vision' => true],
            'gpt-4.1-nano'    => ['input' => 0.0001,  'output' => 0.0004, 'vision' => false],
            'gpt-4-turbo'     => ['input' => 0.01,    'output' => 0.03,   'vision' => true],
            'gpt-3.5-turbo'   => ['input' => 0.0005,  'output' => 0.0015, 'vision' => false],
            'o3-mini'         => ['input' => 0.0011,  'output' => 0.0044, 'vision' => false],
            'o1-mini'         => ['input' => 0.003,   'output' => 0.012,  'vision' => false],
        ];

        foreach ($data['data'] as $model) {
            $id = $model['id'] ?? '';
            // Sadece chat/completion modelleri
            if (!preg_match('/^(gpt-|o[0-9])/i', $id)) continue;
            // Embedding, instruct, vision-preview gibi özel versiyonları atla
            if (preg_match('/(embed|instruct|vision-preview|audio|realtime)/i', $id)) continue;

            $pricing = $knownPricing[$id] ?? ['input' => 0.001, 'output' => 0.003, 'vision' => false];
            $translationModels[$id] = [
                'name'   => strtoupper($id),
                'input'  => $pricing['input'],
                'output' => $pricing['output'],
                'vision' => $pricing['vision'],
                'batch'  => true,
                'synced' => true,
            ];
        }

        return $translationModels;
    }

    private static function syncClaude(string $apiKey): array {
        // Anthropic'in model list endpoint'i: /v1/models (beta)
        $response = wp_remote_get('https://api.anthropic.com/v1/models', [
            'headers' => [
                'x-api-key'         => trim($apiKey),
                'anthropic-version' => '2023-06-01',
            ],
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) return [];

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($data['data'])) return [];

        $knownPricing = [
            'claude-3-5-sonnet' => ['input' => 0.003,   'output' => 0.015],
            'claude-3-5-haiku'  => ['input' => 0.0008,  'output' => 0.004],
            'claude-3-opus'     => ['input' => 0.015,   'output' => 0.075],
            'claude-3-haiku'    => ['input' => 0.00025, 'output' => 0.00125],
            'claude-3-sonnet'   => ['input' => 0.003,   'output' => 0.015],
        ];

        $models = [];
        foreach ($data['data'] as $model) {
            $id = $model['id'] ?? '';
            if (!str_starts_with($id, 'claude-')) continue;

            // Pricing lookup — model ID'nin başına göre eşleştir
            $pricing = ['input' => 0.001, 'output' => 0.005];
            foreach ($knownPricing as $prefix => $p) {
                if (str_starts_with($id, $prefix)) {
                    $pricing = $p;
                    break;
                }
            }

            $models[$id] = [
                'name'   => $model['display_name'] ?? strtoupper($id),
                'input'  => $pricing['input'],
                'output' => $pricing['output'],
                'vision' => true,
                'synced' => true,
            ];
        }

        return $models;
    }
}
