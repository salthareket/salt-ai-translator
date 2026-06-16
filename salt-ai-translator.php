<?php
/**
 * Plugin Name: Salt AI Translator
 * Description: Pro-level AI translation system. OpenAI, DeepL, Google, Claude. Polylang, WPML, qTranslate-XT.
 * Version: 3.4.0
 * Author: Tolga Koçak
 * Text Domain: salt-ai-translator
 * Domain Path: /languages
 *
 * @version 3.4.0
 *
 * @changelog
 *   3.4.0 - 2026-06-16
 *     - Add: Translation Memory prune cron — haftalık WP Cron, 90 gün eski + 0 hit kayıtları siler
 *     - Add: Plugin::pruneTranslationMemory() — sat_memory_prune cron hook
 *     - Add: sat_memory_last_prune WP option — son prune zamanı + silinen kayıt sayısı
 *     - Add: Dashboard → Translation Memory card — prune bilgisi (last run, entries removed)
 *     - Add: Installer::deactivate() — sat_memory_prune hook temizlendi
 *     - Fix: Translation Memory retranslate bypass — retranslate açıkken transient da atlanıyor
 *     - Fix: getCached() refactor — retranslate kontrolü en başa taşındı, temiz akış
 *     - Add: Terms sayfası — WC attribute info note (translate_attributes açık/kapalı badge)
 *     - Add: Terms sayfası — pa_* taxonomy'lere "WC attribute" badge eklendi
 *     - Add: Settings → Content → Field-level Exclusions
 *            exclude_title_post_types: bu post_type'ların title'ı API'ye gitmez, olduğu gibi kopyalanır
 *            exclude_name_taxonomies: bu taxonomy'lerin term name'i API'ye gitmez, olduğu gibi kopyalanır
 *     - Add: Polylang::translatePost() — exclude_title_post_types kontrolü
 *     - Add: Polylang::translateTerm() — exclude_name_taxonomies kontrolü
 *     - Fix: Translate product attributes switch kaydedilmiyor bug fix
 *            AdminController::sanitizeSettings() — woo key eklendi
 *     - Add: Glossary sample CSV — samples/glossary-sample.csv (target boş = çevirme, dolu = sabit kullan)
 *   3.3.0 - 2026-06-16
 *     - Add: Translation Memory (wp_sat_translation_memory tablosu) — aynı source_text+lang+context daha önce çevrildiyse API'ye gitme, DB'den getir
 *     - Add: TranslationMemory::get/set/has/clear/getStats/prune — tam API
 *     - Add: AbstractTranslator — getCached() ve setCache() Translation Memory entegrasyonu
 *     - Add: Dashboard — Translation Memory stats card (cached entries, cache hits, lang breakdown, clear butonu)
 *     - Add: WooCommerce Variation Çevirisi — Settings → Content → WooCommerce toggle
 *     - Add: Polylang::isTranslatableTaxonomy() — woo.translate_attributes aktifse pa_* taxonomy'leri çevrilebilir
 *     - Add: Glossary XLSX/CSV Import — Settings → Glossary → Import XLSX/CSV butonu
 *     - Add: AjaxHandler::importGlossary() — .xlsx ve .csv parse, append/replace mod
 *     - Add: AjaxHandler::getMemoryStats() + clearMemory() — sat_memory_stats / sat_memory_clear endpoint'leri
 *   3.2.0 - 2026-06-11
 *     - Fix: PO export/import — dil/dosya eşleştirmesi, multiline parse, hash msgid bazlı
 *     - Fix: PO export — sat_field = comma-separated dosya adları (tr_TR.po, de_DE.po)
 *     - Fix: parsePOForExport — multiline msgid/msgstr, .pot kaynak dosya desteği
 *     - Add: Dashboard Queue — current item gösterimi (şu an kimi çeviriyor: title + lang)
 *     - Add: Logs — Date From / Date To tarih aralığı filtresi
 *     - Add: Logger::getLogs/getStats — date_from/date_to filter desteği
 *     - Add: QueueManager::getStatus — current_item response alanı eklendi
 *   3.1.0 - 2026-06-11
 *     - Fix: Export — excluded post types/taxonomies listede görünmüyor (Settings exclusion'ları dikkate alınıyor)
 *     - Add: Export — Strings (Polylang groups) ve PO (theme only) export desteği
 *     - Add: ExportImport — string ve po_theme import desteği (PLL_MO + .po/.mo güncelleme)
 *     - Fix: Menu export/import — pll_get_term yerine location bazlı Polylang nav_menus option kullanılıyor
 *     - Fix: translateMenu — post/page/term bağlantılı item'larda Polylang çevirisi varsa AI çağrısı yapılmıyor
 *     - Add: translateMenu — WC endpoint URL'leri pll_translate_url() ile dil bazlı güncelleniyor
 *   3.0.0 - 2026-06-10
 *     - Add: Rate limit handling — AbstractTranslator::httpPost() helper, tüm translatorlar 429'da exponential backoff (2s→4s→8s, max 3 deneme, Retry-After header desteği)
 *     - Add: Strings background queue — Others/Polylang Strings sayfasına queue desteği (posts/terms ile birebir aynı UI format)
 *     - Add: QueueManager — string type tam desteği (CRON_STRINGS, AS_HOOK_STRING, addStringBatch, asProcessString, processBatch string branch)
 *     - Add: QueueManager::ajaxStart — string type için Polylang PLL_Admin_Strings ile string listesi toplama
 *     - Add: Dashboard — Queue Status canlı polling (5sn), driver badge, type/dil/group meta bilgisi
 *     - Add: Terms.php — Queue Status card (posts ile aynı format), canlı polling, cancel, retry, done sonrası auto-refresh
 *     - Add: Others.php — string queue done sonrası string listesi otomatik yenileniyor
 *     - Fix: asProcessString — hata durumunda JSON string data korunuyor (_err/_data format)
 *     - Fix: others.php — $queue undefined hatası düzeltildi
 *     - Fix: Background queue — tüm sayfalarda "Select all" queue modunda gizleniyor
 *     - Fix: DB migration — wp_sat_queue tablosuna field_name kolonu, field_name eksikse her zaman migration çalışıyor
 *     - Add: Plugin.php — sat_queue_strings cron hook kaydı
 *   2.9.0 - 2026-06-10
 *     - Fix: Pagination — posts/terms loadPage'de translatedIds korunuyor, all-done kartı doğru gizleniyor
 *     - Fix: Polylang Strings — s.string number gelince .replace() crash (String() cast ile fix)
 *     - Fix: Logs — Type filtresi çalışmıyordu (object_type AjaxHandler'a eklendi)
 *     - Fix: Logger::getStats() — status/object_type/target_lang filter desteği eklendi
 *     - Fix: QueueManager — processing'de takılı item'lar (stale recovery) 5dk sonra pending'e döner
 *     - Fix: QueueManager::ajaxStart — post_types/taxonomies/skip_translated parametreleri alınmıyordu, boş IDs dönüyordu
 *     - Add: Background queue UI — checkbox seçilince row checkbox+action kolonları gizlenir (thead+tbody)
 *     - Add: Background queue buton — "Queue All for Translation" metni
 *     - Add: Queue meta bilgisi — diller, post types, başlangıç zamanı Queue Status card'ında gösteriliyor
 *     - Add: Stale recovery polling — ajaxStatus'ta schedule otomatik yenileniyor
 *     - Fix: terms.php — Cost card butonlardan önce, posts ile aynı sat-cost-box style
 *   2.8.0 - 2026-06-10
 *     - Add: Export/Import — XLSX tam desteği (PhpSpreadsheet)
 *     - Add: parseXlsx() — XLSX upload → server-side parse → batch import
 *     - Add: sat_parse_xlsx AJAX endpoint + format validation
 *     - Fix: importCsv — sanitize_text_field \n siliyordu, wp_unslash+json_decode ile düzeltildi
 *     - Fix: normalizeLineEndings — whitespace-insensitive karşılaştırma
 *     - Fix: langKeys — import'ta default dil baştan çıkarılıyor
 *     - Fix: Export — pll_get_post_language ile default dil filtresi
 *     - Fix: setValueExplicit(TYPE_STRING) — HTML/Gutenberg comment'lı hücreler bozulmuyor
 *     - UI:  Done card'lar — ✅ emoji → SVG check icon (posts/terms/others/export)
 *   2.7.0 - 2026-06-10
 *     - Add: Export/Import tab — export.php, ExportImport.php
 *     - Add: exportFull() — post/term/menu, field seçimi (title/content/excerpt/slug/ACF/SEO)
 *     - Add: importCsv() — drag-drop CSV, batch 50, skip-same, dry-run, diff log
 *     - Add: updateField() — post/term/menu_item type desteği, acf/seo/wp-native field'lar
 *     - Add: sat_export_full + sat_import_csv AJAX endpoint'leri
 *   2.6.1 - 2026-06-09
 *     - Fix: Log — string/menu/po_file tipleri doğru object_type ile kaydediliyor
 *     - Add: logs.php — Type filtresi (post/term/string/menu/po_file)
 *     - Add: logs.php — string/menu/po_file için özel Object kolonu (ikon + label)
 *   2.6.0 - 2026-06-09
 *     - Add: PO Files — 2 aşamalı sistem (Stage1: liste, Stage2: içerik)
 *     - Add: Stage2: Polylang Strings ile aynı UI — Translate/Same/Alternatives/Translate All
 *     - Add: sat_get_po_strings + sat_save_po_string AJAX endpoint'leri
 *   2.5.3 - 2026-06-09
 *     - Fix: Strings — Translate/Same butonları yanyana (flex layout)
 *     - Fix: PO Files — progress bar inline, Translated kolonunun içinde
 *     - Fix: Strings — "= Copy" → "Same for all" label netleşti
 *   2.5.2 - 2026-06-09
 *     - Add: Strings — "Same for all" butonu: API çağrısı olmadan tüm dillere kopyala
 *     - Add: Strings — Translate All/Selected: checkbox seçiliyse sadece seçilileri çevir
 *   2.5.0 - 2026-06-09
 *     - Add: Strings — her dil için ayrı kolon, "Show alternatives" checkbox
 *     - Add: Strings — 3 alternatif radio button, "Translate Again" ile exclude
 *     - Add: sat_translate_string_alts AJAX endpoint
 *     - Add: Strings — Translate All sayfalar arası batch (posts/terms ile aynı mantık)
 *   2.4.0 - 2026-06-09
 *     - Fix: Strings — PLL_Admin_Strings::get_strings() kullanıyor
 *     - Fix: Çeviriler pll_mo_{lang} option'a yazılıyor (Free+Pro uyumlu)
 *     - Add: Strings UI — multi-lang checkbox, group select, skip translated, sayfalama
 *     - Add: PO Files — scope/dil filtresi, dil auto-detect
 *     - Add: parsePO — msgctxt, plural forms tam desteği
 *     - Add: translatePOFile — plural form sayısı dile göre otomatik
 *     - Add: PO File Translation — Others sayfasına tam PO/MO çeviri desteği eklendi
 *     - Add: AjaxHandler::getPOFiles() — tema, plugin, WP_LANG_DIR .po dosyalarını listeler
 *     - Add: AjaxHandler::translatePOFile() — seçilen .po'yu AI ile çevirir, .mo'yu native PHP compiler ile günceller
 *     - Add: others.php — PO Files kartı: dosya listesi, untranslated sayısı, batch çeviri + progress bar
 *     - Add: others.php — Strings kartına Polylang/WPML badge'i eklendi, conditions düzeltildi
 *     - Add: AjaxHandler::parsePO/writePO/quotePO/compileMO — native PHP .po/.mo işleme (binary bağımlılık yok)
 *   2.2.0 - 2026-06-09
 *     - Add: Auto-translate on save — UI feedback tamamlandı
 *     - Add: meta-box.php — "Auto-translate is ON" badge (ayar açıksa gösterilir)
 *     - Add: meta-box.php — Gutenberg save event'inde "Queued for translation" feedback
 *     - Add: meta-box.php — Lock toggle badge ile senkron (locked/unlocked geçişinde badge güncellenir)
 *     - Add: AdminController::renderMetaBox — $container meta-box.php'ye geçiriliyor
 *   2.1.0 - 2026-06-09
 *     - Add: Queue Status card'a "Next run" countdown eklendi (posts.php + dashboard.php)
 *     - Add: QueueManager::getNextRunTime() — AS veya WP Cron'dan bir sonraki çalışma zamanı
 *     - Add: getStatus() response'a next_run (Unix timestamp) eklendi
 *   2.0.2 - 2026-06-08
 *     - Add: syncTaxonomies() — post çevirisinde ekli term'lerin çevirisi yoksa otomatik çevrilir
 *     - Add: getUntranslated() — post_types/taxonomies filtresi, missing_langs, missing_terms bilgisi
 *     - Add: translateMenu() — Polylang'da yeni menu oluşturma + item çevirisi + dil ataması
 *     - Add: Terms sayfasına "All languages" checkbox eklendi
 *     - Add: Posts listesinde eksik diller + eksik term çevirisi gösteriliyor
 *     - Fix: Posts/Terms/Media/Others sayfalarında satConfig undefined crash fix
 *   2.0.1 - 2026-06-05
 *     - Fix: Polylang::translatePost — post_name (slug) artık çevirilen title'dan üretiliyor
 *     - Fix: Language chip checked/unchecked admin.js'de çalışmıyor — düzeltildi
 *     - Add: translate_slugs ayarı — Settings → Content → Translation Behavior
 *     - Add: Settings Exclusions → Select2 multi-select
 *     - Add: Export Post Types → Select2 multi-select
 *     - Fix: Logs sayfası → sayfa açılışında otomatik yükleme
 *     - Fix: Credits loading → hata mesajı düzgün gösteriliyor
 *   2.0.0 - 2026-05-XX
 *     - Add: Tam refactor — OpenAI, DeepL, Google, Claude, Azure OpenAI
 *     - Add: Polylang, WPML, qTranslate-XT entegrasyonları
 *     - Add: ACF field çevirisi, Gutenberg block çevirisi
 *     - Add: SEO (Yoast, RankMath) entegrasyonu
 *     - Add: Görsel alt text üretimi/çevirisi (Vision AI)
 *     - Add: Round-robin API key desteği
 *     - Add: Background queue (WP Cron)
 *     - Add: Translation glossary
 *     - Add: Cost estimator + credit tracker
 *     - Add: XLSX/CSV export (WIP)
 *
 * ─── HOW TO USE ───────────────────────────────────────────
 *
 * 1. Settings → Translator: Çeviri sağlayıcısını seç (OpenAI önerilir)
 * 2. API key(leri) gir — birden fazla key = otomatik round-robin dağıtımı
 * 3. Settings → Content: Davranış ayarlarını yap (slug çevirisi, otomatik çeviri vs.)
 * 4. Posts/Terms sayfasından toplu çeviri yap veya post editöründeki
 *    "Salt Translate" sidebar widget'ından tek post çevir
 *
 * ──────────────────────────────────────────────────────────
 *
 * @example
 *   // Tek post çevirisi (PHP'den):
 *   $integration = sat_plugin()->getContainer()->get('integration');
 *   $translatedId = $integration->translatePost(123, 'de');
 *
 * @example
 *   // Tek term çevirisi:
 *   $translatedId = $integration->translateTerm(45, 'product_cat', 'tr');
 *
 * @example
 *   // Maliyet tahmini:
 *   $credits = sat_plugin()->getContainer()->get('credits');
 *   $estimate = $credits->estimateCost('Hello world');
 *   // $estimate['cost_usd'], $estimate['words'], $estimate['tokens']
 *
 * @example
 *   // Özel translator ekle (filter ile):
 *   add_filter('sat_translators', function($translators) {
 *       $translators['myservice'] = new MyTranslator($container);
 *       return $translators;
 *   });
 */

if (!defined('ABSPATH')) exit;

define('SAT_VERSION',  '3.4.0');
define('SAT_DIR',      plugin_dir_path(__FILE__));
define('SAT_URL',      plugin_dir_url(__FILE__));
define('SAT_PREFIX',   'sat');

if (file_exists(SAT_DIR . 'vendor/autoload.php')) {
    require_once SAT_DIR . 'vendor/autoload.php';
}

// Action Scheduler bootstrap — composer vendor'dan yüklendiyse init et
// WooCommerce yoksa kendi vendor'umuzdaki AS kullanılır
if (
    file_exists(SAT_DIR . 'vendor/woocommerce/action-scheduler/action-scheduler.php') &&
    !class_exists('ActionScheduler', false)
) {
    require_once SAT_DIR . 'vendor/woocommerce/action-scheduler/action-scheduler.php';
}

require_once SAT_DIR . 'inc/Core/Autoloader.php';
\SAT\Core\Autoloader::register();

function sat_plugin(): \SAT\Core\Plugin {
    return \SAT\Core\Plugin::getInstance();
}

add_action('plugins_loaded', function () {
    sat_plugin()->boot();
});

register_activation_hook(__FILE__,   [\SAT\Core\Installer::class, 'activate']);
register_deactivation_hook(__FILE__, [\SAT\Core\Installer::class, 'deactivate']);
