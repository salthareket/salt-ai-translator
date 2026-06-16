<?php
namespace SAT\Core;

class CreditTracker {
    private Container $container;

    public function __construct(Container $container) {
        $this->container = $container;
    }

    /**
     * Kalan krediyi döndürür (translator'a göre farklı API çağrısı)
     */
    public function getRemainingCredits(): array {
        $translator = $this->container->get('translator');
        if (!$translator) return ['error' => 'No translator configured'];

        try {
            return $translator->getRemainingCredits();
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Verilen metin için tahmini maliyet hesaplar
     */
    public function estimateCost(string $text, string $model = ''): array {
        $translator = $this->container->get('translator');
        if (!$translator) return ['tokens' => 0, 'cost_usd' => 0, 'words' => 0];

        $clean  = strip_tags($text);
        // str_word_count Unicode'u desteklemez — preg_match_all ile unicode word boundary kullan
        preg_match_all('/\S+/u', $clean, $matches);
        $words  = count($matches[0]);
        $tokens = (int) ceil(mb_strlen($clean) / 4); // ~4 char per token

        try {
            $cost = $translator->estimateCost($text, $model);
        } catch (\Throwable $e) {
            $cost = 0;
        }

        return [
            'words'    => $words,
            'tokens'   => $tokens,
            'cost_usd' => round($cost, 6),
            'cost_try' => round($cost * 32, 4), // yaklaşık TL
        ];
    }

    /**
     * Kalan kredi ile kaç kelime çevrilebilir?
     */
    public function estimateCapacity(): array {
        $credits = $this->getRemainingCredits();
        if (isset($credits['error'])) return $credits;

        $translator = $this->container->get('translator');
        $settings   = $this->container->get('settings');
        $model      = $settings->get('model', '');

        $remaining_usd = $credits['balance_usd'] ?? 0;
        if ($remaining_usd <= 0) return ['words' => 0, 'characters' => 0];

        // Ortalama 1000 token = $0.005 (gpt-4o-mini baz alınarak)
        $cost_per_token = $translator ? $translator->getCostPerToken($model) : 0.000005;
        $tokens_available = $cost_per_token > 0 ? (int) ($remaining_usd / $cost_per_token) : 0;
        $words_available  = (int) ($tokens_available * 0.75); // ~0.75 word per token

        return [
            'balance_usd'      => $remaining_usd,
            'tokens_available' => $tokens_available,
            'words_available'  => $words_available,
            'chars_available'  => $tokens_available * 4,
        ];
    }
}
