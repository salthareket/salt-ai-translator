# Salt AI Translator — Plugin Sistem Dokümantasyonu

**Son Güncelleme:** 2026-06-16  
**Versiyon:** 3.4.0  
**Yazar:** Tolga Koçak  

---

## 📌 TEK CÜMLE ÖZET

Salt AI Translator, WordPress'te çok dilli içerik yönetimi için AI destekli çeviri sağlayan bir plugin'dir. OpenAI, DeepL, Google Translate, Claude ve Azure OpenAI destekler. Polylang, WPML ve qTranslate-XT ile entegre çalışır. Post, term, ACF alanları, SEO meta verileri ve görsel alt metinlerini otomatik çevirir.

---

## 🗂️ KLASÖR YAPISI

```
salt-ai-translator/
├── salt-ai-translator.php          ← Plugin entry point, defines, bootstrap
├── composer.json
├── assets/
│   ├── css/admin.css               ← Tüm admin UI stilleri
│   └── js/admin.js                 ← Global admin JS (tab, chip, toast, queue)
├── admin/
│   └── views/                      ← Her admin sayfasının PHP/HTML+JS view'ı
│       ├── dashboard.php           ← Ana dashboard (istatistikler)
│       ├── posts.php               ← Post çevirisi
│       ├── terms.php               ← Taxonomy/term çevirisi
│       ├── media.php               ← Görsel alt text çevirisi
│       ├── others.php              ← Menü ve string çevirisi
│       ├── credits.php             ← API kredi ve kullanım istatistikleri
│       ├── logs.php                ← Çeviri geçmişi ve hatalar
│       ├── export.php              ← XLSX/CSV export
│       ├── settings.php            ← Tüm ayarlar (5 sekme)
│       └── meta-box.php            ← Post editörü sidebar widget
└── inc/
    ├── Admin/
    │   ├── AdminController.php     ← Menü kaydı, asset enqueue, meta box
    │   ├── AjaxHandler.php         ← Tüm AJAX endpoint'leri
    │   └── ExportImport.php        ← XLSX/CSV export + import + XLSX parse
    ├── Content/
    │   ├── AcfTranslator.php       ← ACF field çevirisi
    │   ├── BlockTranslator.php     ← Gutenberg block çevirisi
    │   └── MediaTranslator.php     ← Görsel alt text üretimi/çevirisi
    ├── Core/
    │   ├── Autoloader.php          ← PSR-4 autoloader
    │   ├── Container.php           ← Servis container (DI)
    │   ├── CreditTracker.php       ← API kredi/kapasite hesaplama
    │   ├── Installer.php           ← DB tablo kurulumu
    │   ├── Logger.php              ← Çeviri log'larını yazar/okur
    │   ├── Plugin.php              ← Ana plugin class (singleton)
    │   └── Settings.php            ← Ayar okuma/yazma, defaults
    ├── Integration/
    │   ├── AbstractIntegration.php ← Ortak metotlar (duplicatePost, translateText vs.)
    │   ├── IntegrationInterface.php← Interface tanımı
    │   ├── Polylang.php            ← Polylang çeviri mantığı
    │   ├── WPML.php                ← WPML çeviri mantığı
    │   └── QtranslateXT.php        ← qTranslate-XT çeviri mantığı
    ├── Queue/
    │   └── QueueManager.php        ← WP Cron tabanlı kuyruklama
    ├── SEO/
    │   ├── SeoInterface.php        ← Interface tanımı
    │   ├── Yoast.php               ← Yoast SEO meta entegrasyonu
    │   └── RankMath.php            ← Rank Math SEO entegrasyonu
    └── Translator/
        ├── AbstractTranslator.php  ← Ortak çeviri mantığı, round-robin key seçimi
        ├── TranslatorInterface.php ← Interface tanımı
        ├── OpenAI.php              ← OpenAI API
        ├── DeepL.php               ← DeepL API
        ├── Google.php              ← Google Translate API
        ├── Claude.php              ← Anthropic Claude API
        ├── AzureOpenAI.php         ← Azure OpenAI API
        ├── LibreTranslate.php      ← LibreTranslate (self-hosted)
        └── ModelSync.php           ← OpenAI model listesini senkronize eder
```

---

## 🚀 BOOT AKIŞI

```
salt-ai-translator.php
  → SAT_VERSION, SAT_DIR, SAT_URL, SAT_PREFIX define'ları
  → vendor/autoload.php (Composer)
  → Plugin::getInstance()
      → Container::build()
          → Settings (DB'den oku)
          → Installer (DB tablosu kontrol)
          → Logger
          → CreditTracker
          → Translator (settings'den hangisi seçildiyse)
          → Integration (Polylang/WPML/qTranslate-XT detect)
          → Queue (QueueManager)
          → SEO (Yoast veya RankMath)
      → AdminController::register()
          → admin_menu → registerMenus()
          → admin_enqueue_scripts → enqueueAssets()
          → add_meta_boxes → registerMetaBoxes()
          → AjaxHandler::register() (tüm wp_ajax_ hook'ları)
```

---

## 🗃️ VERİTABANI TABLOLARI

### `wp_sat_translate_logs`
```sql
id, object_type, object_id, source_lang, target_lang,
translator, model, tokens_input, tokens_output,
cost_usd, status, error_msg, duration_ms, created_at
```

---

## ⚙️ SETTINGS YAPISI (`wp_options → sat_settings`)

```php
[
  'translator'         => 'openai',          // aktif translator
  'api_keys'           => ['openai' => [...], 'deepl' => [...]],
  'model'              => 'gpt-4o-mini',
  'temperature'        => '0.2',
  'prompt'             => '',                // global prompt eki
  'retranslate'        => 0,                 // mevcut çevirilerin üzerine yaz
  'auto_translate'     => 0,                 // kayıtta otomatik çeviri
  'translate_slugs'    => 0,                 // slug'ları da çevir
  'exclude_post_types' => ['attachment'],
  'exclude_taxonomies' => [],
  'exclude_posts'      => [],
  'exclude_terms'      => [],
  'glossary'           => [['source' => 'WordPress', 'target' => '']],
  'seo' => [
    'meta_desc'     => ['generate' => 0, 'translate' => 0, 'on_save' => 0, 'overwrite' => 0],
    'image_alttext' => ['generate' => 0, 'translate' => 0, 'image_size' => 'medium'],
    'seo_title'     => ['translate' => 0],
    'og_tags'       => ['translate' => 0],
  ],
  'display' => ['unpublished_languages' => []],
]
```

---

## 🔌 AJAX ENDPOINT'LERİ

| Action | Method | Açıklama |
|--------|--------|----------|
| `sat_translate_post` | POST | Tek post çevirisi |
| `sat_translate_term` | POST | Tek term çevirisi |
| `sat_translate_post_alts` | POST | Alternatif çeviri önerileri |
| `sat_get_untranslated` | POST | Çevrilmemiş post/term listesi |
| `sat_estimate_cost` | POST | Tahmini API maliyeti |
| `sat_get_credits` | POST | Kalan API kredisi |
| `sat_get_logs` | POST | Çeviri logları |
| `sat_sync_models` | POST | OpenAI model listesi güncelle |
| `sat_save_settings` | POST | Ayarları kaydet |
| `sat_export_xlsx` | POST | XLSX/CSV export |
| `sat_translate_menu` | POST | Nav menu çevirisi |
| `sat_translate_strings` | POST | Polylang string çevirisi (yakında) |
| `sat_bulk_media_alt` | POST | Toplu görsel alt text |
| `sat_autocomplete_posts` | POST | Post arama autocomplete |
| `sat_autocomplete_terms` | POST | Term arama autocomplete |
| `sat_export_full` | POST | Full XLSX export (post/term/menu/ACF/SEO) |
| `sat_import_csv` | POST | CSV batch import (skip-same, dry-run) |
| `sat_parse_xlsx` | POST | XLSX upload → rows JSON parse |
| `sat_clear_logs` | POST | Log tablosunu temizle |
| `sat_set_translation_lock` | POST | Post başına çeviri kilidi |
| `sat_get_po_files` | POST | PO dosyalarını listele |
| `sat_translate_po_file` | POST | PO dosyasını AI ile çevir |
| `sat_translate_string_alts` | POST | String alternatif çeviriler |
| `sat_get_po_strings` | POST | PO string listesi |
| `sat_save_po_string` | POST | Tek PO string kaydet |

---

## 🔄 TRANSLATOR AKIŞI

```
AbstractTranslator::translate($text, $lang, $prompt)
  → getApiKey()          // Round-robin key seçimi (birden fazla key varsa)
  → buildMessages()      // System prompt + glossary + custom prompt
  → callApi()            // HTTP isteği
  → parseResponse()      // Yanıtı temizle
  → logger->log()        // Sonucu logla
```

**Round-robin key:** Birden fazla API key varsa sırayla kullanılır. Aşırı yük dağıtımı için.

---

## 📝 POLYLANG ENTEGRASYONU

### Post Çevirisi (`Polylang::translatePost`)
1. Default dildeki post'u bul
2. Hedef dilde çeviri var mı? → varsa `wp_update_post`, yoksa `duplicatePost` + `setPostLanguage`
3. Title, content (blocks veya düz metin), excerpt çevir
4. `translate_slugs` aktifse `post_name` = `sanitize_title($translatedTitle)`
5. Taxonomileri senkronize et (hedef dildeki karşılıklarına set et)
6. ACF field'larını çevir
7. SEO meta verilerini güncelle

### Term Çevirisi (`Polylang::translateTerm`)
1. Default dildeki term'i bul
2. Hedef dilde çeviri var mı? → varsa `wp_update_term`, yoksa `duplicateTerm`
3. Name, description çevir
4. `translate_slugs` aktifse `slug` = `sanitize_title($name)`

---

## ⚠️ BİLİNEN SORUNLAR / AÇIK TODO'LAR

### Canlı Test Bekleyen
- **WPML entegrasyonu** — kod review + fix yapıldı (2.0.5), canlı WPML kurulu ortamda test edilmeli
- **qTranslate-XT entegrasyonu** — aynı şekilde canlı test bekliyor
- **Polylang string API** — `pll_save_string` Polylang Pro'da farklı davranabilir, test edilmeli

### Gelecek Özellikler
- **Action Scheduler monitor** — WP Admin → Tools → Scheduled Actions'da SAT queue'larını göster
- **Otomatik çeviri on save** — `auto_translate` ayarı var, UI feedback yok

---

## 📅 CHANGELOG

```
3.4.0 - 2026-06-16
  Add: Translation Memory prune cron — haftalık WP Cron, 90 gün eski + 0 hit kayıtları siler
  Add: sat_memory_last_prune option — son prune zamanı + silinen kayıt sayısı
  Add: Dashboard → Translation Memory card — prune bilgisi (last run / entries removed)
  Fix: retranslate açıkken transient cache da bypass edilir (önceden sadece DB memory atlanıyordu)
  Fix: getCached() refactor — retranslate kontrolü en başa, temiz akış
  Add: Terms sayfası — WC attribute info note + pa_* taxonomy badge
  Add: Settings → Content → Field-level Exclusions
       exclude_title_post_types — post title API'ye gitmez, olduğu gibi kopyalanır
       exclude_name_taxonomies  — term name API'ye gitmez, olduğu gibi kopyalanır
  Fix: Translate product attributes switch kaydedilmiyordu — sanitizeSettings woo key eklendi
  Add: samples/glossary-sample.csv — import test dosyası
  Fix: Media sayfası — default dil target listesinden kaldırıldı (posts/terms ile tutarlı)
  Add: Media sayfası — çoklu dil checkbox (posts/terms ile aynı format), All languages toggle

3.3.0 - 2026-06-16
  Add: Translation Memory — wp_sat_translation_memory tablosu
       get/set/has/clear/getStats/prune tam API
       AbstractTranslator::getCached() + setCache() entegrasyonu (runtime → transient → DB sırası)
       retranslate=1 ise memory atlanır
       Dashboard stats card: cached entries, cache hits, lang breakdown, per-lang clear butonu
  Add: WooCommerce Variation Çevirisi
       Settings → Content → WooCommerce toggle (translate_attributes)
       Polylang::isTranslatableTaxonomy() — woo.translate_attributes aktifse pa_* taxonomy'leri çevrilebilir
  Add: Glossary XLSX/CSV Import
       Settings → Glossary → Import XLSX / CSV butonu
       AjaxHandler::importGlossary() — sat_import_glossary endpoint
       append (duplicate check) veya replace mod
       .xlsx (PhpSpreadsheet) + .csv her ikisi desteklenir
       Başlık satırı auto-detect ve atlanır
  Add: AjaxHandler::getMemoryStats() + clearMemory() — sat_memory_stats / sat_memory_clear

3.2.0 - 2026-06-11
  Fix: PO export/import komple yeniden yazıldı
       Dil → locale dosya eşleştirmesi: tr → tr_TR.po, de → de_DE.po
       sat_field = comma-separated dosya adları (tr_TR.po, de_DE.po)
       sat_context = po_theme
       parsePOForExport — multiline msgid/msgstr, .pot kaynak dosya desteği
       Hash msgid bazlı — dosya adından bağımsız, import tutarlı
  Add: Dashboard Queue Status — current item: "şu an kimi çeviriyor" (title + → LANG)
  Add: Logs sayfası — Date From / Date To tarih aralığı filtresi
  Add: Logger::getLogs() + getStats() — date_from/date_to filter desteği
  Add: QueueManager::getStatus() — current_item alanı (title, object_type, target_lang)

3.1.0 - 2026-06-11
  Fix: Export — excluded post types/taxonomies listede görünmüyor
  Add: Export — Strings (Polylang groups checkbox) + PO (theme .po files checkbox) bölümleri eklendi
  Add: ExportImport — string/po_theme import: Polylang PLL_MO ve tema .po/.mo güncellemesi
  Fix: Menu export/import — location bazlı TR/DE menü tespiti (pll_get_term bağlantısı gerekmez)
  Fix: translateMenu — post/page/term item'larında önce Polylang çevirisi kullanılır (AI çağrısı yok)
       Sadece çevirisi olmayan veya custom item'lar AI ile çevrilir → para tasarrufu
  Add: translateMenu — WC endpoint URL'leri pll_translate_url() ile dil bazlı

3.1.0 - 2026-06-10
  Add: Export — Strings bölümü (Polylang group checkbox seçimi) aynı XLSX'e eklendi
  Add: Export — PO Translations bölümü (sadece tema .po dosyaları, tek checkbox)
  Fix: Export — Settings Exclusions'daki post type/taxonomy'ler artık unchecked geliyor
  Add: ExportImport::exportFull() — str_groups (Polylang PLL_MO ile çeviri okuma) + theme_po export
  Add: ExportImport::updateField() — string (PLL_MO güncelleme) + po_theme (.po/.mo güncelleme)
  Add: ExportImport — parsePOForExport() + writePOEntry() helper metodları
  Fix: importCsv — sat_context (grup/dosya bilgisi) okunup updateField'a context parametresi olarak geçiriliyor

3.0.0 - 2026-06-10
  Add: Rate limit handling — AbstractTranslator::httpPost() tüm translatorlarda ortak
       429 gelince Retry-After header'ına göre bekler, exponential backoff (2s→4s→8s)
       Max 3 deneme, tüm key'ler başarısız olursa exception fırlatır
       OpenAI, DeepL, Google, Claude, Azure, LibreTranslate hepsi kullanıyor
  Add: Strings background queue — Others/Polylang Strings sayfasına queue desteği
       posts/terms ile birebir aynı UI: driver badge, meta (dil/group/tarih), polling, cancel, retry
       QueueManager: CRON_STRINGS, AS_HOOK_STRING, addStringBatch(), asProcessString()
       ajaxStart string branch: PLL_Admin_Strings ile string listesi, group/skip_translated filtresi
       PLL_MO ile çeviriler Polylang'a kaydediliyor
  Add: Dashboard Queue Status — canlı polling (5sn), driver badge, type/dil/group meta
  Add: Terms.php — Queue Status card (posts.php ile aynı format)
       Polling, cancel, retry on error, done sonrası auto-refresh
  Add: Others.php — string queue bittikten 2sn sonra string listesi otomatik yenileniyor
  Fix: asProcessString — hata durumunda JSON data _err/_data formatıyla korunuyor
  Fix: others.php — $queue undefined hatası
  Fix: Background queue — Select all label tüm sayfalarda (posts/terms/strings) gizleniyor
  Fix: DB migration — wp_sat_queue.field_name kolonu, field_name eksikse her zaman migration çalışıyor

2.9.0 - 2026-06-10
  Fix: Pagination — posts/terms loadPage'de translatedIds korunuyor, all-done kartı doğru gizleniyor
  Fix: Polylang Strings — s.string number gelince .replace() crash düzeltildi (String() cast)
  Fix: Logs — Type filtresi artık çalışıyor (AjaxHandler object_type parametresi eksikti)
  Fix: Logger::getStats() — status/object_type/target_lang filter desteği eklendi
  Fix: QueueManager — processing'de takılı item'lar (stale recovery) 5 dk sonra pending'e döner
  Fix: QueueManager::ajaxStart — post_types/taxonomies/skip_translated parametreleri eksikti
  Add: Background queue UI — checkbox seçilince row checkbox+action kolonları thead+tbody birlikte gizlenir
  Add: Queue Status card — hangi diller/post types/başlangıç zamanı gösteriliyor
  Add: Stale recovery — ajaxStatus polling'de schedule otomatik yenileniyor
  Fix: terms.php — Cost card posts ile aynı yerleşim ve stil (sat-cost-box)

2.8.0 - 2026-06-10
  Add: Export/Import — XLSX desteği tam implement edildi
       exportFull() — PhpSpreadsheet ile .xlsx çıktısı
       Wrap text açık, frozen header, alternatif grup renkleri, sütun genişlikleri ayarlı
       setValueExplicit(TYPE_STRING) — HTML/Gutenberg comment içeren hücreler bozulmuyor
       parseXlsx() — XLSX upload → server-side parse → rows JSON → mevcut batch import
       sat_parse_xlsx AJAX endpoint — format kontrolü (sat_id/sat_type/sat_field/sat_context)
       İmport: .xlsx ve .csv her ikisi de kabul ediliyor (dropzone accept güncellemesi)
       İmport format validation — yanlış dosya yüklenince hata mesajı gösteriliyor
  Fix: importCsv — sanitize_text_field JSON üzerinde kullanılıyordu → \n karakterleri siliniyor
       wp_unslash() + json_decode() — newline'lar korunuyor
  Fix: normalizeLineEndings — karşılaştırma için tüm whitespace collapse (whitespace-insensitive)
  Fix: langKeys — import sırasında default dil baştan çıkarılıyor (EN/default = skip)
  Fix: Export — pll_get_post_language ile default dil kontrolü (Polylang dil filter bazen tüm dil postları döner)
  Fix: parseXlsx — langKeys boş/non-lang kolonları temizleniyor (/^[A-Z]{2,5}$/ regex)
  Fix: Boş CSV satırları (separator) skipped sayısına eklenmiyor
  UI:  Import done card — ✅ emoji → SVG check icon (tek renk, modern)
  UI:  posts.php + terms.php + others.php done card'ları da aynı SVG ile güncellendi
  UI:  Import file info — dosya adı + dil listesi + tip + boyut özeti (CSV içeriği artık dökülmüyor)

2.7.0 - 2026-06-10
  Add: Export/Import tab — export.php view oluşturuldu
       Export: post/term/menu seçimi, field seçimi (title/content/excerpt/slug/ACF/SEO)
       Format: sat_id|sat_type|sat_field|sat_context|EN|TR|DE — her object grubu, araya blank satır
       Default dil her zaman kaynak olarak dahil, seçilemiyor
       Import: drag-drop CSV yükleme, batch AJAX (50 satır), skip-same, dry-run
       Progress: updated/skipped/errors anlık, log, done card
       Diff log: dry-run modunda DB vs CSV farkı gösteriliyor (diff@X ile konum)
       ExportImport.php — exportFull() + importCsv() + updateField() + normalizeLineEndings()
       sat_export_full + sat_import_csv AJAX endpoint'leri

2.6.1 - 2026-06-09
  Fix: Log — string, menu, po_file tipleri artık doğru object_type ile kaydediliyor
       translateStrings — object_type: 'string', field_name: group/name
       translateMenu — object_type: 'menu', object_id: menu ID, field_name: menu_item:ID
       translatePOFile — object_type: 'po_file', field_name: filename:msgid
  Add: logs.php — Type filtresi (post/term/string/menu/po_file)
  Add: logs.php — string/menu/po_file için özel Object kolonu gösterimi (ikon + label)

2.6.0 - 2026-06-09
  Add: PO Files — 2 aşamalı sistem
       Stage 1: Dosya listesi (Load PO Files → tablo)
       Stage 2: Dosya seçilince içerik açılır (← Back to PO Files ile geri dönüş)
       Stage 2: Polylang Strings ile aynı UI — Translate, Same, Show Alternatives, Translate All/Selected
       Stage 2: Sayfalama, arama, skip translated, progress log
  Add: sat_get_po_strings AJAX endpoint — .po parse + skip/search filtresi
  Add: sat_save_po_string AJAX endpoint — tek string kaydet + .mo recompile

2.5.3 - 2026-06-09
  Fix: Strings — Translate / Same for all butonları yanyana (flex layout)
  Fix: PO Files — progress bar ayrı box yerine Translated kolonunun içinde inline gösterilir
       Translate tıklanınca kolon progress bar'a dönüşür (x/27 sayacı ile)
       Bitti — ✓ 27 gösterilir, progress kaybolur
  Fix: Strings — Same for all butonu "= Copy" yerine "Same for all" — anlam netleşti

2.5.2 - 2026-06-09
  Add: Strings — "= Copy" butonu: orijinal string'i API çağrısı olmadan tüm seçili dillere kopyalar
       "looks", "favorites" gibi evrensel kelimeler için ideal
       forced_translation ile direkt PLL_MO'ya kaydedilir, 2sn sonra "Copied ✓" → "= Copy"
  Add: Strings — Translate All/Selected: checkbox seçiliyse sadece seçilileri çevirir
       Seçili yoksa "Translate All", varsa "Translate Selected (N)" yazar
       Row'lar varsayılan seçisiz gelir
  Fix: Strings — Translate All buton başlangıçta gizli, Check Strings sonucu gelince görünür

2.5.0 - 2026-06-09
  Add: Strings — her dil için ayrı kolon (header'da dil adı + kod)
  Add: Strings — "Show alternatives" checkbox (3x kredi, Translate All'da devre dışı)
       Translate butonuna basınca her dil için 3 alternatif radio button ile gösterilir
       "Translate Again" ile exclude ederek yeni alternatifler üretilir
       "Save" butonu seçili radio'ları PLL_MO'ya kaydeder
  Add: Strings — forced_translation parametresi: alternatif seçilirse API çağrısı yapılmaz
  Add: sat_translate_string_alts AJAX endpoint — dil başına N alternatif üretir, exclude desteği
  Add: Strings — Translate All sayfalar arası batch sistemi (posts/terms ile aynı)
       strTranslateIdx ile tüm array üzerinde sırayla gider, sayfa değişince tablo otomatik güncellenir
  Fix: PLL_MO yazma — export_to_db() ile term meta'ya doğru yazılıyor
  Fix: PLL_MO okuma — import_from_db() ile term meta'dan okunuyor

2.4.0 - 2026-06-09
  Fix: Strings sayfası — PLL_Admin_Strings::get_strings() ile çalışıyor (pll_get_strings globali yok)
  Fix: Çeviriler pll_mo_{lang} WP option'a yazılıyor — Polylang Free+Pro uyumlu
  Add: Strings UI — multi-lang checkbox (birden fazla dil aynı anda), group select autocomplete
       skip translated checkbox, sayfalama (50/sayfa)
  Add: PO Files — scope: "Theme only" / "All", dil filtresi select (integration'dan otomatik)
       Manuel dil girişi kaldırıldı — dil her dosya için otomatik detect ediliyor
  Add: parsePO — msgctxt, msgid_plural, msgstr[n] plural forms tam desteği
  Add: translatePOFile — dile göre plural form sayısı otomatik (getPluralFormCount)
       tr/ja/ko = 1 form, en/de/fr = 2, ru/pl/cs = 3, sl = 4
  Add: getPOFiles — translated/untranslated ayrı kolon, matched_lang (integration ile eşleştirme)

2.3.0 - 2026-06-09
  Add: PO File Translation — Others sayfasına full PO/MO çeviri desteği
       getPOFiles() — tema/plugin/WP_LANG_DIR .po dosyalarını tarar, untranslated sayısını döndürür
       translatePOFile() — batch çeviri (20/req), .po dosyasını günceller
       compileMO() — native PHP binary MO compiler (msgfmt binary gerekmez)
       Path güvenlik kontrolü — sadece WP dizinleri içindeki dosyalara erişim
       others.php — PO Files full-width kartı: dosya listesi, progress bar, log
       others.php — Strings kartı Polylang/WPML badge'li, condition'lar düzeltildi
  Add: sat_get_po_files + sat_translate_po_file AJAX endpoint'leri

2.2.0 - 2026-06-09
  Add: Auto-translate on save — UI feedback
       meta-box.php — "Auto-translate is ON" yeşil badge (settings açıksa gösterilir, lock varsa gri gösterilir)
       meta-box.php — Gutenberg wp.data.subscribe ile save event dinleniyor
       Save başlarken: "⏳ Saving... translations will be queued."
       Save sonrası: "✅ Queued for translation — N item(s) pending. Next run ~Xs"
       8 saniye sonra otomatik kaybolur
       Lock toggle: badge gerçek zamanlı güncellenir (yeşil ↔ gri)
       Classic editor için: form submit'te processing mesajı gösterilir

2.1.0 - 2026-06-09
  Add: Queue Status card'a "Next run" countdown eklendi (posts.php + dashboard.php)
       QueueManager::getNextRunTime() — AS veya WP Cron'dan bir sonraki scheduled action zamanını döndürür
       pending item yoksa next_run = null, gösterilmez
       Countdown: "⏱ Next run in 23s" — her saniye güncellenir
       Done olunca countdown temizlenir

2.0.9 - 2026-06-09
  Fix: translateMenu() — Polylang nav_menus option'ına menu location ataması eklendi
       get_option('polylang')['nav_menus'][$theme][$location][$lang] = $targetMenuId
       Kaynak menünün location'ı tespit ediliyor, hedef dil için aynı location atanıyor

2.0.8 - 2026-06-09
  Fix: translateMenu() — çevrilen menü Polylang nav_menus option'ına kaydedildi
       get_option('polylang')['nav_menus'][$theme][$location][$lang] = $targetMenuId
       Kaynak menünün location'ı tespit ediliyor, hedef dil için aynı location atanıyor
  Fix: meta-box.php — mevcut post'un dili hedef listeden çıkarılıyor
       Default dil olmayan post editöründe "Source: TR" bilgisi gösteriliyor
       Her dil için çeviri durumu gösteriliyor (✓ / missing)
  Add: meta-box.php — Translation Lock switch: post başına "asla çevirme" koruması
       _sat_translation_lock post meta ile saklanıyor
       Polylang::translatePost() ve getUntranslatedPostIds() lock'u kontrol ediyor
  Add: AjaxHandler — sat_set_translation_lock endpoint
  Fix: posts.php — Queue Status card: pending=0 ise PHP'de gösterilmiyor
       Done bitince 2sn sonra card fadeOut + Check Untranslated otomatik yenileniyor
       Sayfa açılışında queue bitmiş olsa card gizleniyor
  Fix: Queue DB temizliği — ajaxStatus() done tespitinde done/error row'ları temizliyor
  Fix: AdminController — queue badge submenu'de doğru yerde gösteriliyor
  Add: meta-box.php — çoklu dil checkbox, All languages kaldırıldı, edit linkleri

2.0.7 - 2026-06-09
  Add: others.php — String Translation sayfası implement edildi
       pll_get_strings() ile Polylang kayıtlı string'leri listeler
       Grup filtresi, tek tek veya toplu çeviri
  Add: AjaxHandler — sat_get_untranslated_strings endpoint
  Fix: AjaxHandler — sat_translate_strings artık gerçekten çeviri yapıyor (pll_save_string)

2.0.6 - 2026-06-09
  Add: composer.json — woocommerce/action-scheduler ^3.7 eklendi (composer update ile kuruldu v3.9.3)
  Add: salt-ai-translator.php — AS bootstrap: WooCommerce yoksa vendor'dan yüklenir
  Fix: meta-box.php — tek dil select → çoklu checkbox, tüm seçili diller sırayla çevrilir
       "All languages" toggle eklendi, her dil için ayrı status satırı

2.0.5 - 2026-06-09
  Add: QueueManager — Action Scheduler entegrasyonu
       AS mevcutsa (WooCommerce veya standalone) otomatik kullanılır, yoksa WP Cron'a fallback
       useActionScheduler() — AS aktif mi kontrol
       scheduleNext() / cancelScheduled() — soyut scheduler interface
       AS modunda her queue item ayrı async action olarak schedule edilir (50 item/batch)
       AS modunda her item ayrı izlenebilir, başarısız item'lar AS'in retry mekanizmasından faydalanır
       getStatus() dönen response'a 'driver' key'i eklendi: 'action_scheduler' | 'wp_cron'
  Fix: WPML — isTranslatablePostType/Taxonomy doğru filter kullanıyor
  Fix: WPML — translatePost: exception + setContext + translate_slugs desteği
  Fix: WPML — getUntranslatedPostIds: default dil filter'ı eklendi
  Fix: QtranslateXT — buildQtranslateField: [:]  closing tag çakışması düzeltildi

2.0.4 - 2026-06-09
  Fix: BlockTranslator — innerHTML + innerContent çift çeviri sorunu giderildi (2x API call → 1x)
  Fix: acf-admin.php line 6153 — $_POST["acf"] undefined key warning fix (null coalescing)
  Add: posts.php — isTranslating flag: Translate All sayfalar arası kesintisiz devam eder
  Add: posts.php — inline row status: checkbox → –(Queued) → spinner → ✓Done / Retry
  Add: posts.php — Translate All sırasında header checkbox + pagination gizlenir
  Add: posts.php — pagination: sayfa altında Prev/Next + "Page X of Y"
  Add: posts.php — cost estimate toplam post sayısına scale edilir (sample × total/sample)
  Add: terms.php — posts.php ile birebir aynı sistem: isTranslating, inline status, batch pagination
  Fix: terms.php — skip=kapalı tüm term'leri listeler (AjaxHandler term bölümü güncellendi)
  Add: AjaxHandler — exclude_ids: post ve term batch'lerinde çevrilen ID'ler sonraki batch'te hariç tutulur

2.0.3 - 2026-06-09
  Fix: BlockTranslator::translateInnerHTML() — innerHTML ve innerContent ayrı ayrı çevriliyordu,
       her block 2x API çağrısı yapılıyordu. Artık sadece innerContent çevriliyor,
       innerHTML innerContent'ten yeniden oluşturuluyor. API maliyeti ~%50 azaldı.
  Add: posts.php — inline row status (Pending → loading spinner → ✓ Done / Retry)
       Çevrilen row'da checkbox kalkıp ✓ ikonu geliyor, row seçilemez oluyor.
       Batch bitince done row'lar kaldırılıp sonraki batch otomatik yükleniyor (exclude_ids ile).
  Add: terms.php — aynı inline status sistemi posts.php ile tutarlı
  Add: terms.php — batch sayfalama + exclude_ids desteği (posts ile aynı mantık)
  Add: AjaxHandler — post ve term getUntranslated'a exclude_ids parametresi eklendi
  Fix: salt-ai-translator.php versiyon 2.0.3

2.0.2 - 2026-06-09
  Fix: Tüm Translator sınıfları (OpenAI, DeepL, Google, Claude, AzureOpenAI, LibreTranslate)
       API başarısız olunca artık orijinal metni döndürmek yerine RuntimeException fırlatıyor.
       Bu sayede AjaxHandler doğru şekilde wp_send_json_error() döndürüyor,
       UI satırı silmiyor ve hata mesajı log'da gösteriliyor.
  Fix: Polylang::translatePost() — isTranslatablePostType false dönünce sessizce return $postId
       yapmak yerine RuntimeException fırlatıyor. duplicatePost başarısız olunca da aynı.
  Fix: posts.php — single translate handler: title fadeOut'tan önce okunuyor, hata log'a yazılıyor.
  Fix: posts.php/terms.php — missing_langs obje array badge sistemi (sat-badge-error/success ↻)
  Fix: logs.php — sat_clear_logs AJAX handler eksikti, AjaxHandler'a eklendi. confirm+reload.
  Add: logs.php — Source kolonu (field_name alias). Logger'da source→field_name mapping.
  Add: Admin menü queue badge — updateMenuBadge() + injectQueueBadgeJs() AdminController'da.
  Add: terms.php — Skip/Queue checkbox, badge fix, queue desteği.
  Add: Export CSV — exportXlsx() gerçek CSV çıktısı (form submit ile download).
  Fix: WooCommerce variable price range encoding — product-bundles.php filter.
  Fix: My Account duplicate page — MembershipHooks::preventDuplicateMyAccountPage().

2.0.1 - 2026-06-05
  Fix: Polylang::translatePost — post_name (slug) artık çevirilen title'dan üretiliyor (translate_slugs aktifse)
  Fix: Language chip checked/unchecked admin.js'de çalışmıyor — düzeltildi
  Fix: Logger — post/term/lang bilgileri log'a eksik yazılıyordu
       AbstractTranslator::setContext() eklendi, Polylang translatePost/translateTerm'de çağrılıyor
       OpenAI request() → log'a object_type, object_id, source_lang, target_lang yazılıyor
  Fix: credits.php — satConfig undefined olduğunda AJAX crash'i düzeltildi, Check All Keys butonu çalışıyor
  Fix: logs.php — satConfig undefined crash fix, sayfa açılışında auto-load, ajaxUrl düzeltildi
  Add: translate_slugs ayarı — Settings → Content → Translation Behavior
       Post ve term slug çevirisi tek switch ile kontrol edilir
  Add: Settings Exclusions → Select2 multi-select
  Add: Export Post Types → Select2 multi-select
  Add: SYSTEM.md — plugin dokümantasyonu oluşturuldu

2.0.0 - 2026-05-XX
  Add: Tam refactor — OpenAI, DeepL, Google, Claude, Azure OpenAI
  Add: Polylang, WPML, qTranslate-XT entegrasyonları
  Add: ACF field çevirisi
  Add: SEO (Yoast, RankMath) entegrasyonu
  Add: Görsel alt text üretimi (Vision AI)
  Add: Round-robin API key desteği
  Add: Background queue (WP Cron)
  Add: XLSX/CSV export (WIP)
  Add: Translation glossary
  Add: Cost estimator
  Add: Credit tracker
```

---

## 🛠️ HOW TO USE

### 1. Kurulum
1. Plugin'i aktive et
2. **Settings → Translator** sekmesinde translator seç
3. API key(ler)ini ekle (birden fazla key = round-robin)
4. **Settings → Content** → Davranış ayarlarını yap

### 2. Tek Post Çevirisi (Meta Box)
Post editörünün sağ sidebar'ında "Salt Translate" kutusu görünür.  
Dil seç → Translate butonuna bas.

### 3. Toplu Post Çevirisi
**Posts** sayfasından:
1. Dil seç
2. Post type seç
3. "Check Untranslated" → listeyi al
4. "Translate All" veya tek tek çevir

### 4. Term Çevirisi
**Terms** sayfasında aynı akış — taxonomy seç, dil seç, çevir.

### 5. Slug Çevirisi
**Settings → Content → Translation Behavior → Translate Slugs** aktif edilirse:
- Post çevirisinde `post_name` (URL slug'ı) çevirilen title'dan otomatik üretilir
- Term çevirisinde `slug` da aynı şekilde güncellenir

### 6. Yeni Çeviri Sağlayıcısı Ekle
1. `inc/Translator/` altında `AbstractTranslator`'ı extend eden yeni class oluştur
2. `translate()`, `getRemainingCredits()`, `estimateCost()`, `getCostPerToken()` metodlarını implement et
3. `inc/Core/Container.php`'de `buildTranslator()` metoduna ekle
4. `admin/views/settings.php`'deki `$translators` array'ine ekle

---

## 🗺️ ÖNEMLİ DOSYA REFERANSLARI

```
# Entry point
salt-ai-translator.php

# Polylang entegrasyonu (slug, post, term çevirisi)
inc/Integration/Polylang.php

# Tüm AJAX endpoint'leri
inc/Admin/AjaxHandler.php

# Ayarlar okuma/yazma
inc/Core/Settings.php

# Çeviri log'ları
inc/Core/Logger.php

# Admin sayfası views
admin/views/

# Global admin JS (chip, tab, toast)
assets/js/admin.js

# Admin CSS
assets/css/admin.css
```


---

## 🔧 AKTİF TODO'LAR (Yeni Chat'te Devam)

### Canlı Test Bekleyen
- [ ] **WPML entegrasyonu** — canlı WPML kurulu ortamda test edilmeli
- [ ] **qTranslate-XT entegrasyonu** — aynı şekilde canlı test
- [ ] **String/PO export/import** — gerçek sitede test edilmeli
- [ ] **Translation Memory** — ✅ Lokal test OK (transient+DB hit çalışıyor). Gerçek sitede API maliyet tasarrufu doğrulanmalı
- [ ] **WooCommerce Variation Çevirisi** — Polylang'da `pa_*` taxonomy'leri çevrilebilir olarak işaretlenince test edilmeli

### Bu Session'da Tamamlananlar (3.3.x)
- [x] Translation Memory (wp_sat_translation_memory) — DB cache, transient+memory hit, dashboard stats
- [x] Retranslate açıkken cache tamamen bypass edilir, kapalıyken okur
- [x] Dashboard → Translation Memory card — cache durumu badge (active/bypassed)
- [x] WooCommerce product attributes toggle — Settings → Content → WooCommerce
- [x] Terms sayfası — WC attribute info note (açık/kapalı durumuna göre)
- [x] Glossary XLSX/CSV Import — append/replace mod, sample dosya
- [x] Glossary tab — Import XLSX/CSV butonu + mode select
- [x] Field-level exclusions — "Keep original title for post types" + "Keep original name for taxonomies"
- [x] Settings → Content → Exclusions bölümü genişletildi (select2'li)
- [x] Translate product attributes switch kaydedilmiyor bug fix (sanitizeSettings'e woo eklendi)

### Gelecek Özellikler (Backlog)
- [ ] **PO background queue** — PO dosya çevirisi için background queue desteği
- [ ] **Scheduled auto re-translation** — WP Cron ile haftalık/günlük yeni içerik otomatik queue'ya ekle
- [ ] **Translation Memory prune cron** — 90 günden eski, sıfır hit'li kayıtları haftalık otomatik temizle
