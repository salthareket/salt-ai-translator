<?php
namespace SAT\Translator;

use SAT\Core\Container;

abstract class AbstractTranslator implements TranslatorInterface {
    protected Container $container;
    protected array $options;

    // Context: hangi post/term/lang çeviriliyor — log'a yazılır
    protected array $translateContext = [
        'object_type' => 'post',
        'object_id'   => 0,
        'source_lang' => '',
        'target_lang' => '',
        'field_name'  => '',
    ];

    // Translation cache — aynı oturumda aynı string+lang tekrar çevrilmez
    private array $runtimeCache = [];

    // Transient cache TTL (saniye) — varsayılan 7 gün
    private int $cacheTtl = 604800;

    // Translation Memory enabled flag
    private bool $memoryEnabled = true;

    public function setContext(array $context): void {
        $this->translateContext = array_merge($this->translateContext, $context);
    }

    public function __construct(Container $container) {
        $this->container = $container;
        $this->options   = $container->get('settings')->getAll();
    }

    protected function getApiKeys(): array {
        return $this->container->get('settings')->getApiKeys($this->getName());
    }

    protected function shouldTranslate(mixed $text): bool {
        if (!is_string($text) || trim($text) === '') return false;
        $stripped = trim(strip_tags($text));
        if ($stripped === '' || is_numeric($stripped)) return false;
        return true;
    }

    /**
     * Translation cache key oluştur.
     * Metin + dil + translator + model kombinasyonu — benzersiz cache anahtarı.
     */
    protected function getCacheKey(string $text, string $lang): string {
        $model = $this->options['model'] ?? '';
        return 'sat_tr_' . md5($this->getName() . '|' . $model . '|' . $lang . '|' . $text);
    }

    /**
     * Cache'den çeviri al (runtime → transient → Translation Memory sırasıyla).
     */
    protected function getCached(string $text, string $lang): ?string {
        $retranslate = $this->container->get('settings')->get('retranslate', 0);

        // Retranslate açıksa hiçbir cache'e bakma — her zaman API'ye git
        if ($retranslate) return null;

        $key = $this->getCacheKey($text, $lang);

        // 1. Runtime cache (aynı request içinde)
        if (isset($this->runtimeCache[$key])) {
            return $this->runtimeCache[$key];
        }

        // 2. Transient cache (çapraz request, kısa metinler)
        if (strlen($text) <= 500) {
            $cached = get_transient($key);
            if ($cached !== false) {
                $this->runtimeCache[$key] = $cached;
                // Memory hit count'u artır
                $memory = $this->container->get('memory');
                if ($memory) {
                    $memory->incrementHitByHash($text, $lang);
                }
                return $cached;
            }
        }

        // 3. Translation Memory (DB)
        if ($this->memoryEnabled) {
            $memory  = $this->container->get('memory');
            $context = $this->getMemoryContext();
            if ($memory) {
                $fromMemory = $memory->get($text, $lang, $context);
                if ($fromMemory !== null) {
                    $this->runtimeCache[$key] = $fromMemory;
                    return $fromMemory;
                }
            }
        }

        return null;
    }

    /**
     * Çeviriyi cache'e ve Translation Memory'e yaz.
     */
    protected function setCache(string $text, string $lang, string $translation): void {
        $key = $this->getCacheKey($text, $lang);
        $this->runtimeCache[$key] = $translation;

        // Transient cache (kısa metinler)
        if (strlen($text) <= 500) {
            set_transient($key, $translation, $this->cacheTtl);
        }

        // Translation Memory'e yaz
        if ($this->memoryEnabled) {
            $memory = $this->container->get('memory');
            if ($memory) {
                $memory->set(
                    $text,
                    $lang,
                    $this->getMemoryContext(),
                    $translation,
                    $this->getName(),
                    $this->options['model'] ?? ''
                );
            }
        }
    }

    /**
     * Translation Memory için context key üret.
     * Format: "object_type:field_name" (boşsa boş string)
     */
    private function getMemoryContext(): string {
        $type  = $this->translateContext['object_type'] ?? '';
        $field = $this->translateContext['field_name'] ?? '';
        if ($type && $field) return $type . ':' . $field;
        if ($type) return $type;
        return '';
    }

    /**
     * Belirli bir dil için tüm cache'i temizle.
     */
    public function clearCache(string $lang = ''): void {
        $this->runtimeCache = [];
        // Transient cache WP'de toplu silinemez — pattern-based delete gerektirir
        // global $wpdb; $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_sat_tr_%'");

        // Translation Memory temizliği (lang belirtilmişse sadece o dil)
        $memory = $this->container->get('memory');
        if ($memory && $lang) {
            $memory->clear($lang);
        }
    }

    protected function applyGlossary(string $prompt): string {
        $glossary = $this->options['glossary'] ?? [];
        if (empty($glossary)) return $prompt;

        $lines = [];
        foreach ($glossary as $item) {
            if (!empty($item['source'])) {
                $line = "- Do NOT translate \"{$item['source']}\"";
                if (!empty($item['target'])) {
                    $line .= ", always use \"{$item['target']}\"";
                }
                $lines[] = $line;
            }
        }
        if ($lines) {
            $prompt .= "\n\nGlossary rules:\n" . implode("\n", $lines);
        }
        return $prompt;
    }

    public function generateCaption(string $imageUrl, string $lang): string {
        return ''; // Override in vision-capable translators
    }

    public function translateBatch(array $texts, string $lang): array {
        // Default: translate one by one, use cache where possible
        $results = [];
        foreach ($texts as $key => $text) {
            $cached = $this->getCached($text, $lang);
            if ($cached !== null) {
                $results[$key] = $cached;
                continue;
            }
            $translated    = $this->translate($text, $lang);
            $results[$key] = $translated;
        }
        return $results;
    }

    public function getRemainingCredits(): array {
        return ['balance_usd' => null, 'note' => 'Not supported by ' . $this->getName()];
    }

    public function getCostPerToken(string $model = ''): float {
        return 0.0;
    }

    public function supportsVision(): bool { return false; }
    public function supportsBatch(): bool  { return false; }

    /**
     * Rate-limit aware HTTP POST helper.
     * 429 gelince Retry-After header'ına göre bekler ve tekrar dener (max 3 attempt, exponential backoff).
     * Tüm translatorlar bu metodu kullanmalı — kendi wp_remote_post çağrıları yerine.
     *
     * @param  string $url
     * @param  array  $args  wp_remote_post args (headers, body, timeout vb.)
     * @return array{code: int, body: string, error: string|null}
     */
    protected function httpPost(string $url, array $args): array {
        $maxAttempts = 3;
        $attempt     = 0;

        while ($attempt < $maxAttempts) {
            $attempt++;
            $response = wp_remote_post($url, $args);

            if (is_wp_error($response)) {
                return ['code' => 0, 'body' => '', 'error' => $response->get_error_message()];
            }

            $code = wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);

            // Rate limit — bekle ve tekrar dene
            if ($code === 429 && $attempt < $maxAttempts) {
                $retryAfter = (int) wp_remote_retrieve_header($response, 'retry-after');
                $waitSecs   = $retryAfter > 0 ? min($retryAfter, 30) : (2 ** ($attempt - 1));
                sleep($waitSecs);
                continue;
            }

            return ['code' => $code, 'body' => $body, 'error' => null];
        }

        return ['code' => 429, 'body' => '', 'error' => 'Rate limit exceeded after ' . $maxAttempts . ' attempts'];
    }
}
