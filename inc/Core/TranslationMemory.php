<?php
namespace SAT\Core;

/**
 * Translation Memory — DB-backed cache for translated strings.
 *
 * Aynı source_text + lang + context (post_type + field_name) kombinasyonu
 * daha önce çevrildiyse API'ye gitmeden DB'den döndürür.
 *
 * @version 1.0.0
 * @changelog
 *   1.0.0 - 2026-06-12
 *     - Add: Initial release
 *     - Add: wp_sat_translation_memory tablosu (source_hash, source_text, lang, context, translation)
 *     - Add: get() / set() / has() / clear() API
 *     - Add: createTable() — Installer üzerinden çağrılır
 *     - Add: lookup() — AbstractTranslator'da kullanım için combined get+set flow
 *
 * ─── HOW TO USE ───────────────────────────────────────────
 *
 *   $memory = $container->get('memory');
 *
 *   // Manuel arama:
 *   $cached = $memory->get($sourceText, $lang, $context);
 *   if ($cached !== null) return $cached;
 *
 *   // Kaydetme:
 *   $memory->set($sourceText, $lang, $context, $translation);
 *
 *   // AbstractTranslator'dan (otomatik): context = object_type:field_name
 *   // Memory, translator'a inject edilir — translate() içinde otomatik kontrol.
 *
 * ──────────────────────────────────────────────────────────
 */
class TranslationMemory {

    private \wpdb $wpdb;
    private string $table;

    // Minimum kayıt uzunluğu — çok kısa stringler memory'e yazılmaz (rakam, tek kelime vb.)
    private int $minLength = 3;

    // Maksimum kayıt uzunluğu — çok uzun içerik (tam page content) memory'e yazılmaz
    // 0 = sınır yok
    private int $maxLength = 0;

    public function __construct() {
        global $wpdb;
        $this->wpdb  = $wpdb;
        $this->table = $wpdb->prefix . 'sat_translation_memory';
    }

    /**
     * DB tablosunu oluştur.
     */
    public static function createTable(): void {
        global $wpdb;
        $table      = $wpdb->prefix . 'sat_translation_memory';
        $charset    = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id            bigint(20)   NOT NULL AUTO_INCREMENT,
            source_hash   char(32)     NOT NULL,
            source_text   text         NOT NULL,
            lang          varchar(10)  NOT NULL,
            context       varchar(100) NOT NULL DEFAULT '',
            translation   text         NOT NULL,
            translator    varchar(30)  NOT NULL DEFAULT '',
            model         varchar(50)  NOT NULL DEFAULT '',
            hit_count     int(11)      NOT NULL DEFAULT 0,
            created_at    datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at    datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY   (id),
            UNIQUE KEY    source_lang_ctx (source_hash, lang, context(50)),
            KEY           lang_idx (lang),
            KEY           created_idx (created_at)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Çeviri hafızasından ara.
     *
     * @param  string $sourceText  Kaynak metin
     * @param  string $lang        Hedef dil kodu
     * @param  string $context     Bağlam (object_type:field_name, boş olabilir)
     * @return string|null         Bulunan çeviri veya null
     */
    public function get(string $sourceText, string $lang, string $context = ''): ?string {
        if (!$this->shouldCache($sourceText)) return null;

        $hash = $this->hash($sourceText);

        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT id, translation FROM {$this->table}
                 WHERE source_hash = %s AND lang = %s AND context = %s
                 LIMIT 1",
                $hash, $lang, $context
            )
        );

        if (!$row) return null;

        // Hit count'u arttır (arka planda, hata önemli değil)
        $this->wpdb->query(
            $this->wpdb->prepare(
                "UPDATE {$this->table} SET hit_count = hit_count + 1 WHERE id = %d",
                $row->id
            )
        );

        return $row->translation;
    }

    /**
     * Çeviriyi hafızaya yaz.
     *
     * @param  string $sourceText   Kaynak metin
     * @param  string $lang         Hedef dil kodu
     * @param  string $context      Bağlam
     * @param  string $translation  Çevrilmiş metin
     * @param  string $translator   Kullanılan translator (openai, deepl vb.)
     * @param  string $model        Kullanılan model
     */
    public function set(
        string $sourceText,
        string $lang,
        string $context,
        string $translation,
        string $translator = '',
        string $model = ''
    ): void {
        if (!$this->shouldCache($sourceText) || empty(trim($translation))) return;

        $hash = $this->hash($sourceText);

        $result = $this->wpdb->query(
            $this->wpdb->prepare(
                "INSERT INTO {$this->table}
                    (source_hash, source_text, lang, context, translation, translator, model)
                 VALUES (%s, %s, %s, %s, %s, %s, %s)
                 ON DUPLICATE KEY UPDATE
                    translation = VALUES(translation),
                    translator  = VALUES(translator),
                    model       = VALUES(model),
                    updated_at  = NOW()",
                $hash, $sourceText, $lang, $context, $translation, $translator, $model
            )
        );

        if ($result === false) {
            error_log('[SAT] Memory::set INSERT failed — error: ' . $this->wpdb->last_error . ' | table: ' . $this->table);
        }

        // Tablo yoksa oluştur ve tekrar dene
        if ($result === false && str_contains((string)$this->wpdb->last_error, "doesn't exist")) {
            self::createTable();
            $this->wpdb->query(
                $this->wpdb->prepare(
                    "INSERT INTO {$this->table}
                        (source_hash, source_text, lang, context, translation, translator, model)
                     VALUES (%s, %s, %s, %s, %s, %s, %s)
                     ON DUPLICATE KEY UPDATE
                        translation = VALUES(translation),
                        translator  = VALUES(translator),
                        model       = VALUES(model),
                        updated_at  = NOW()",
                    $hash, $sourceText, $lang, $context, $translation, $translator, $model
                )
            );
        }
    }

    /**
     * Belirli bir metin/dil kombinasyonunun hafızada olup olmadığını kontrol eder.
     */
    public function has(string $sourceText, string $lang, string $context = ''): bool {
        return $this->get($sourceText, $lang, $context) !== null;
    }

    /**
     * Transient'tan dönerken hit count'u artır — dashboard sayacı için.
     */
    public function incrementHit(string $sourceText, string $lang, string $context = ''): void {
        if (!$this->shouldCache($sourceText)) return;
        $hash = $this->hash($sourceText);
        $this->wpdb->query(
            $this->wpdb->prepare(
                "UPDATE {$this->table} SET hit_count = hit_count + 1
                 WHERE source_hash = %s AND lang = %s AND context = %s",
                $hash, $lang, $context
            )
        );
    }

    /**
     * Context'ten bağımsız hit count artırma — transient hit için.
     * Aynı hash+lang kombinasyonundaki tüm context'leri günceller.
     */
    public function incrementHitByHash(string $sourceText, string $lang): void {
        if (!$this->shouldCache($sourceText)) return;
        $hash = $this->hash($sourceText);
        $this->wpdb->query(
            $this->wpdb->prepare(
                "UPDATE {$this->table} SET hit_count = hit_count + 1
                 WHERE source_hash = %s AND lang = %s",
                $hash, $lang
            )
        );
    }

    /**
     * Tüm hafızayı temizle (veya belirli bir dil).
     */
    public function clear(string $lang = ''): int {
        if ($lang) {
            return (int) $this->wpdb->delete($this->table, ['lang' => $lang], ['%s']);
        }
        return (int) $this->wpdb->query("TRUNCATE TABLE {$this->table}");
    }

    /**
     * Hafıza istatistikleri.
     */
    public function getStats(): array {
        $total = (int) $this->wpdb->get_var("SELECT COUNT(*) FROM {$this->table}");
        $langs = $this->wpdb->get_results(
            "SELECT lang, COUNT(*) as cnt FROM {$this->table} GROUP BY lang ORDER BY cnt DESC"
        );
        $hits  = (int) $this->wpdb->get_var("SELECT SUM(hit_count) FROM {$this->table}");

        return [
            'total_entries' => $total,
            'total_hits'    => $hits,
            'by_lang'       => $langs,
        ];
    }

    /**
     * Eski kayıtları temizle (varsayılan: 90 günden eski, sıfır hit olanlar).
     */
    public function prune(int $daysOld = 90, int $minHits = 0): int {
        return (int) $this->wpdb->query(
            $this->wpdb->prepare(
                "DELETE FROM {$this->table}
                 WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)
                   AND hit_count <= %d",
                $daysOld, $minHits
            )
        );
    }

    // ─── Private Helpers ──────────────────────────────────────────

    private function hash(string $text): string {
        return md5($text);
    }

    /**
     * Bu metin cache'e yazılmaya/okunmaya değer mi?
     */
    private function shouldCache(string $text): bool {
        $len = mb_strlen(trim($text));
        if ($len < $this->minLength) return false;
        if ($this->maxLength > 0 && $len > $this->maxLength) return false;
        return true;
    }
}
