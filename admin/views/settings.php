<?php if (!defined('ABSPATH')) exit;
$opts        = $settings->getAll();
$translatorKey = $opts['translator'] ?? '';
$apiKeys     = $opts['api_keys'] ?? [];
$glossary    = $opts['glossary'] ?? [];
$seo         = $opts['seo'] ?? [];
$allPostTypes= get_post_types(['public' => true], 'objects');
$allTaxes    = get_taxonomies(['public' => true], 'objects');

$translators = [
  'openai'         => ['name' => 'OpenAI',          'icon' => '🤖', 'vision' => true],
  'deepl'          => ['name' => 'DeepL',            'icon' => '🔵', 'vision' => false],
  'google'         => ['name' => 'Google Translate', 'icon' => '🌐', 'vision' => false],
  'claude'         => ['name' => 'Anthropic Claude', 'icon' => '🧠', 'vision' => true],
  'azure_openai'   => ['name' => 'Azure OpenAI',     'icon' => '☁️', 'vision' => true],
  'libretranslate' => ['name' => 'LibreTranslate',   'icon' => '🔓', 'vision' => false],
];

$translator = $container->get('translator');
$models = $translator && method_exists($translator, 'getModels') ? $translator->getModels() : [];
$lastSync = $opts['models_last_sync'] ?? 0;
?>
<div class="sat-wrap">

  <header class="sat-header">
    <div class="sat-logo">A</div>
    <div><h1>Settings</h1><p>Configure translators, API keys, and behavior</p></div>
  </header>

  <form method="post" action="options.php">
    <?php settings_fields('sat_options'); ?>

    <nav class="sat-tabs">
      <button type="button" class="sat-tab is-active" data-tab="translator">Translator</button>
      <button type="button" class="sat-tab" data-tab="content">Content</button>
      <button type="button" class="sat-tab" data-tab="seo">SEO & Media</button>
      <button type="button" class="sat-tab" data-tab="glossary">Glossary</button>
      <button type="button" class="sat-tab" data-tab="display">Display</button>
    </nav>

    <!-- TRANSLATOR TAB -->
    <div class="sat-tab-content" id="tab-translator">

      <div class="sat-card">
        <div class="sat-card-title">Select Translator</div>
        <div class="sat-grid-3">
          <?php foreach ($translators as $key => $t): ?>
          <label style="display:flex;align-items:center;gap:10px;padding:14px;border:2px solid var(--sat-gray-200);border-radius:8px;cursor:pointer;transition:all .15s;" class="sat-translator-option <?= $translatorKey === $key ? 'selected' : '' ?>" data-key="<?= $key ?>">
            <input type="radio" name="sat_settings[translator]" value="<?= $key ?>" <?= checked($translatorKey, $key, false) ?> style="display:none;">
            <span style="font-size:24px;"><?= $t['icon'] ?></span>
            <div>
              <div style="font-weight:600;font-size:14px;"><?= $t['name'] ?></div>
              <div style="font-size:11px;color:var(--sat-gray-400);"><?= $t['vision'] ? '✓ Vision' : '' ?></div>
            </div>
          </label>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- API Keys -->
      <?php foreach ($translators as $key => $t): ?>
      <div class="sat-card sat-api-key-section" data-translator="<?= $key ?>" style="<?= $translatorKey !== $key ? 'display:none' : '' ?>">
        <div class="sat-card-title"><?= $t['icon'] ?> <?= $t['name'] ?> — API Key</div>
        <div class="sat-form-group">
          <label class="sat-label">API Keys <small style="font-weight:400;color:var(--sat-gray-400)">(one per line — multiple keys for round-robin)</small></label>
          <textarea name="sat_settings[api_keys][<?= $key ?>]" class="sat-textarea" rows="4" placeholder="Paste your API key(s) here..."><?= esc_textarea(implode("\n", $apiKeys[$key] ?? [])) ?></textarea>
        </div>
        <?php if ($key === 'libretranslate'): ?>
        <div class="sat-form-group">
          <label class="sat-label">LibreTranslate URL</label>
          <input type="url" name="sat_settings[libretranslate_url]" class="sat-input" value="<?= esc_attr($opts['libretranslate_url'] ?? 'https://libretranslate.com') ?>">
        </div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>

      <!-- OpenAI Model Settings -->
      <div class="sat-card sat-api-key-section" data-translator="openai" style="<?= $translatorKey !== 'openai' ? 'display:none' : '' ?>">
        <div class="sat-card-title">🤖 OpenAI Model Settings</div>
        <div class="sat-grid-2">
          <div class="sat-form-group">
            <label class="sat-label">Model</label>
            <select name="sat_settings[model]" class="sat-select">
              <?php foreach ($models as $mKey => $mData): ?>
                <option value="<?= esc_attr($mKey) ?>" <?= selected($opts['model'] ?? '', $mKey, false) ?>>
                  <?= esc_html($mData['name']) ?> — $<?= $mData['input'] ?>/1K in, $<?= $mData['output'] ?>/1K out
                  <?= !empty($mData['synced']) ? ' ✓' : '' ?>
                </option>
              <?php endforeach; ?>
            </select>
            <small style="color:var(--sat-gray-400);display:block;margin-top:4px;">
              Last synced: <?= $lastSync ? date('d.m.Y H:i', $lastSync) : 'Never' ?>
              <button type="button" class="sat-btn sat-btn-secondary sat-btn-sm" id="sat-sync-models" style="margin-left:8px;">↻ Sync Models</button>
            </small>
          </div>
          <div class="sat-form-group">
            <label class="sat-label">Temperature</label>
            <select name="sat_settings[temperature]" class="sat-select">
              <?php
              $temps = ['0.0'=>'Exact Copycat','0.1'=>'Literal Bot','0.2'=>'Cautious Thinker (Recommended)','0.3'=>'Professional','0.4'=>'Curious','0.5'=>'Balanced','0.6'=>'Creative','0.7'=>'Idea Generator','0.8'=>'Dreamer','1.0'=>'Chaos Mode'];
              foreach ($temps as $v => $l): ?>
                <option value="<?= $v ?>" <?= selected($opts['temperature'] ?? '0.2', $v, false) ?>><?= $v ?> — <?= $l ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="sat-form-group">
          <label class="sat-label">Global Prompt <small style="font-weight:400;color:var(--sat-gray-400)">(appended to all translation requests)</small></label>
          <textarea name="sat_settings[prompt]" class="sat-textarea" rows="3" placeholder="e.g. This is an e-commerce site selling outdoor gear. Use professional tone."><?= esc_textarea($opts['prompt'] ?? '') ?></textarea>
        </div>
      </div>

    </div>

    <!-- CONTENT TAB -->
    <div class="sat-tab-content" id="tab-content" style="display:none;">
      <div class="sat-card">
        <div class="sat-card-title">Translation Behavior</div>
        <div class="sat-form-group">
          <label style="display:flex;align-items:center;gap:10px;">
            <label class="sat-switch"><input type="checkbox" name="sat_settings[retranslate]" value="1" <?= checked($opts['retranslate'] ?? 0, 1, false) ?>><span class="sat-switch-slider"></span></label>
            <div><strong>Retranslate existing content</strong><div style="font-size:12px;color:var(--sat-gray-400);">Overwrite already translated posts/terms</div></div>
          </label>
        </div>
        <div class="sat-form-group">
          <label style="display:flex;align-items:center;gap:10px;">
            <label class="sat-switch"><input type="checkbox" name="sat_settings[auto_translate]" value="1" <?= checked($opts['auto_translate'] ?? 0, 1, false) ?>><span class="sat-switch-slider"></span></label>
            <div><strong>Auto-translate on save</strong><div style="font-size:12px;color:var(--sat-gray-400);">Automatically queue translation when a post is saved</div></div>
          </label>
        </div>
        <div class="sat-form-group">
          <label style="display:flex;align-items:center;gap:10px;">
            <label class="sat-switch"><input type="checkbox" name="sat_settings[translate_slugs]" value="1" <?= checked($opts['translate_slugs'] ?? 0, 1, false) ?>><span class="sat-switch-slider"></span></label>
            <div><strong>Translate slugs</strong><div style="font-size:12px;color:var(--sat-gray-400);">Post and term URL slugs are generated from the translated title (applies to both posts and terms)</div></div>
          </label>
        </div>
      </div>

      <?php if (class_exists('WooCommerce')): ?>
      <div class="sat-card">
        <div class="sat-card-title">WooCommerce</div>
        <div class="sat-form-group">
          <label style="display:flex;align-items:center;gap:10px;">
            <label class="sat-switch">
              <input type="checkbox" name="sat_settings[woo][translate_attributes]" value="1"
                <?= checked($opts['woo']['translate_attributes'] ?? 0, 1, false) ?>>
              <span class="sat-switch-slider"></span>
            </label>
            <div>
              <strong>Translate product attributes</strong>
              <div style="font-size:12px;color:var(--sat-gray-400);">
                Enable translation of WooCommerce attribute taxonomies (<code>pa_color</code>, <code>pa_size</code> etc.)
                in the Terms page. Polylang must be configured to sync these taxonomies.
              </div>
            </div>
          </label>
        </div>
      </div>
      <?php endif; ?>

      <div class="sat-card">
        <div class="sat-card-title">Exclusions</div>
        <div class="sat-grid-2">
          <div class="sat-form-group">
            <label class="sat-label">Exclude Post Types <small style="font-weight:400;color:var(--sat-gray-400)">(skip entirely)</small></label>
            <select name="sat_settings[exclude_post_types][]" class="sat-select2-multi" multiple style="width:100%;">
              <?php foreach ($allPostTypes as $pt): ?>
                <option value="<?= $pt->name ?>" <?= in_array($pt->name, $opts['exclude_post_types'] ?? []) ? 'selected' : '' ?>><?= $pt->label ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="sat-form-group">
            <label class="sat-label">Exclude Taxonomies <small style="font-weight:400;color:var(--sat-gray-400)">(skip entirely)</small></label>
            <select name="sat_settings[exclude_taxonomies][]" class="sat-select2-multi" multiple style="width:100%;">
              <?php foreach ($allTaxes as $tax): ?>
                <option value="<?= $tax->name ?>" <?= in_array($tax->name, $opts['exclude_taxonomies'] ?? []) ? 'selected' : '' ?>><?= $tax->label ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div style="margin-top:16px;padding-top:16px;border-top:1px solid var(--sat-gray-200);">
          <div style="font-size:13px;font-weight:600;margin-bottom:4px;">Field-level Exclusions</div>
          <p style="font-size:12px;color:var(--sat-gray-500);margin-bottom:12px;">
            Post type / taxonomy is still translated — but the <strong>title / term name</strong> field is copied as-is without calling the API.
            Useful for product names, brand names, or any content that is global across languages.
          </p>
          <div class="sat-grid-2">
            <div class="sat-form-group">
              <label class="sat-label">Keep original title for post types</label>
              <select name="sat_settings[exclude_title_post_types][]" class="sat-select2-multi" multiple style="width:100%;">
                <?php foreach ($allPostTypes as $pt): ?>
                  <option value="<?= $pt->name ?>" <?= in_array($pt->name, $opts['exclude_title_post_types'] ?? []) ? 'selected' : '' ?>><?= $pt->label ?></option>
                <?php endforeach; ?>
              </select>
              <small style="color:var(--sat-gray-400);display:block;margin-top:4px;">e.g. products — title stays in original language</small>
            </div>
            <div class="sat-form-group">
              <label class="sat-label">Keep original name for taxonomies</label>
              <select name="sat_settings[exclude_name_taxonomies][]" class="sat-select2-multi" multiple style="width:100%;">
                <?php foreach ($allTaxes as $tax): ?>
                  <option value="<?= $tax->name ?>" <?= in_array($tax->name, $opts['exclude_name_taxonomies'] ?? []) ? 'selected' : '' ?>><?= $tax->label ?></option>
                <?php endforeach; ?>
              </select>
              <small style="color:var(--sat-gray-400);display:block;margin-top:4px;">e.g. product_cat, pa_color — term name stays in original language</small>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- SEO TAB -->
    <div class="sat-tab-content" id="tab-seo" style="display:none;">
      <div class="sat-card">
        <div class="sat-card-title">Meta Description</div>
        <div class="sat-form-group">
          <label style="display:flex;align-items:center;gap:10px;">
            <label class="sat-switch"><input type="checkbox" name="sat_settings[seo][meta_desc][generate]" value="1" <?= checked($seo['meta_desc']['generate'] ?? 0, 1, false) ?>><span class="sat-switch-slider"></span></label>
            <strong>Generate meta description (AI)</strong>
          </label>
        </div>
        <div class="sat-form-group">
          <label style="display:flex;align-items:center;gap:10px;">
            <label class="sat-switch"><input type="checkbox" name="sat_settings[seo][meta_desc][translate]" value="1" <?= checked($seo['meta_desc']['translate'] ?? 0, 1, false) ?>><span class="sat-switch-slider"></span></label>
            <strong>Translate existing meta descriptions</strong>
          </label>
        </div>
        <div class="sat-form-group">
          <label style="display:flex;align-items:center;gap:10px;">
            <label class="sat-switch"><input type="checkbox" name="sat_settings[seo][meta_desc][on_save]" value="1" <?= checked($seo['meta_desc']['on_save'] ?? 0, 1, false) ?>><span class="sat-switch-slider"></span></label>
            <strong>Generate on post save</strong>
          </label>
        </div>
        <div class="sat-form-group">
          <label style="display:flex;align-items:center;gap:10px;">
            <label class="sat-switch"><input type="checkbox" name="sat_settings[seo][meta_desc][overwrite]" value="1" <?= checked($seo['meta_desc']['overwrite'] ?? 0, 1, false) ?>><span class="sat-switch-slider"></span></label>
            <strong>Overwrite existing meta descriptions</strong>
          </label>
        </div>
      </div>

      <div class="sat-card">
        <div class="sat-card-title">Image Alt Text</div>
        <?php if (sat_plugin()->isLocalhost()): ?>
        <div class="sat-alert sat-alert-warning">Alt text generation is disabled on localhost (AI cannot access local images). Use on staging/production.</div>
        <?php endif; ?>
        <div class="sat-form-group">
          <label style="display:flex;align-items:center;gap:10px;">
            <label class="sat-switch"><input type="checkbox" name="sat_settings[seo][image_alttext][generate]" value="1" <?= checked($seo['image_alttext']['generate'] ?? 0, 1, false) ?> <?= sat_plugin()->isLocalhost() ? 'disabled' : '' ?>><span class="sat-switch-slider"></span></label>
            <strong>Generate alt text from image (Vision AI)</strong>
          </label>
        </div>
        <div class="sat-form-group">
          <label style="display:flex;align-items:center;gap:10px;">
            <label class="sat-switch"><input type="checkbox" name="sat_settings[seo][image_alttext][translate]" value="1" <?= checked($seo['image_alttext']['translate'] ?? 0, 1, false) ?>><span class="sat-switch-slider"></span></label>
            <strong>Translate alt text to other languages</strong>
          </label>
        </div>
        <div class="sat-form-group">
          <label class="sat-label">Image Size for Analysis</label>
          <select name="sat_settings[seo][image_alttext][image_size]" class="sat-select" style="max-width:200px;">
            <?php foreach (get_intermediate_image_sizes() as $size): ?>
              <option value="<?= $size ?>" <?= selected($seo['image_alttext']['image_size'] ?? 'medium', $size, false) ?>><?= $size ?></option>
            <?php endforeach; ?>
          </select>
          <small style="color:var(--sat-gray-400);display:block;margin-top:4px;">Smaller = cheaper API cost</small>
        </div>
      </div>

      <div class="sat-card">
        <div class="sat-card-title">SEO Title & OG Tags</div>
        <div class="sat-form-group">
          <label style="display:flex;align-items:center;gap:10px;">
            <label class="sat-switch"><input type="checkbox" name="sat_settings[seo][seo_title][translate]" value="1" <?= checked($seo['seo_title']['translate'] ?? 0, 1, false) ?>><span class="sat-switch-slider"></span></label>
            <strong>Translate SEO title</strong>
          </label>
        </div>
        <div class="sat-form-group">
          <label style="display:flex;align-items:center;gap:10px;">
            <label class="sat-switch"><input type="checkbox" name="sat_settings[seo][og_tags][translate]" value="1" <?= checked($seo['og_tags']['translate'] ?? 0, 1, false) ?>><span class="sat-switch-slider"></span></label>
            <strong>Translate Open Graph tags (og:title, og:description)</strong>
          </label>
        </div>
      </div>
    </div>

    <!-- GLOSSARY TAB -->
    <div class="sat-tab-content" id="tab-glossary" style="display:none;">
      <div class="sat-card">
        <div class="sat-card-title">Translation Glossary</div>
        <p style="font-size:13px;color:var(--sat-gray-600);margin-bottom:16px;">
          Define terms that should never be translated or should always use a specific translation.
          These rules are automatically added to every translation prompt.
        </p>
        <div id="sat-glossary-list">
          <?php foreach ($glossary as $i => $item): ?>
          <div class="sat-glossary-row">
            <input type="text" name="sat_settings[glossary][<?= $i ?>][source]" class="sat-input" placeholder="Source term (e.g. WordPress)" value="<?= esc_attr($item['source']) ?>">
            <span style="color:var(--sat-gray-400);">→</span>
            <input type="text" name="sat_settings[glossary][<?= $i ?>][target]" class="sat-input" placeholder="Always translate as (leave empty = do not translate)" value="<?= esc_attr($item['target'] ?? '') ?>">
            <button type="button" class="sat-btn sat-btn-danger sat-btn-sm sat-remove-glossary">✕</button>
          </div>
          <?php endforeach; ?>
        </div>
        <div style="display:flex;gap:8px;align-items:center;margin-top:10px;flex-wrap:wrap;">
          <button type="button" class="sat-btn sat-btn-secondary sat-btn-sm" id="sat-add-glossary">+ Add Term</button>
          <label class="sat-btn sat-btn-secondary sat-btn-sm" for="sat-glossary-import-file" style="cursor:pointer;margin:0;">
            ↑ Import XLSX / CSV
          </label>
          <input type="file" id="sat-glossary-import-file" accept=".xlsx,.csv" style="display:none;">
          <select id="sat-glossary-import-mode" class="sat-select" style="width:auto;font-size:12px;padding:4px 8px;">
            <option value="append">Append (keep existing)</option>
            <option value="replace">Replace all</option>
          </select>
          <span id="sat-glossary-import-status" style="font-size:12px;color:var(--sat-gray-400);"></span>
        </div>
        <div style="margin-top:8px;font-size:11px;color:var(--sat-gray-400);">
          Expected format: <code>source | target | notes (optional)</code> — first row can be a header (auto-skipped)
        </div>
      </div>
    </div>

    <!-- DISPLAY TAB -->
    <div class="sat-tab-content" id="tab-display" style="display:none;">
      <div class="sat-card">
        <div class="sat-card-title">Unpublished Languages</div>
        <p style="font-size:13px;color:var(--sat-gray-600);margin-bottom:16px;">
          Hide these languages from the frontend (useful while translations are in progress).
        </p>
        <?php foreach ($languages as $code => $label): ?>
        <label style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
          <input type="checkbox" name="sat_settings[display][unpublished_languages][]" value="<?= esc_attr($code) ?>"
            <?= in_array($code, $opts['display']['unpublished_languages'] ?? []) ? 'checked' : '' ?>>
          <?= esc_html($label) ?> <small style="color:var(--sat-gray-400)">(<?= esc_html($code) ?>)</small>
        </label>
        <?php endforeach; ?>
      </div>
    </div>

    <div style="margin-top:20px;">
      <?php submit_button('Save Settings', 'primary', 'submit', false, ['class' => 'sat-btn sat-btn-primary']); ?>
    </div>

  </form>
</div>

<script>
(function($){
  // Tabs
  $('.sat-tab').on('click', function() {
    $('.sat-tab').removeClass('is-active');
    $(this).addClass('is-active');
    $('.sat-tab-content').hide();
    $('#tab-' + $(this).data('tab')).show();
  });

  // Translator selector
  $('.sat-translator-option').on('click', function() {
    $('.sat-translator-option').removeClass('selected').css('border-color', 'var(--sat-gray-200)');
    $(this).addClass('selected').css('border-color', 'var(--sat-primary)');
    $(this).find('input[type=radio]').prop('checked', true);
    const key = $(this).data('key');
    $('.sat-api-key-section').hide();
    $(`.sat-api-key-section[data-translator="${key}"]`).show();
  });

  // Glossary
  let glossaryIndex = <?= count($glossary) ?>;
  $('#sat-add-glossary').on('click', function() {
    $('#sat-glossary-list').append(`
      <div class="sat-glossary-row">
        <input type="text" name="sat_settings[glossary][${glossaryIndex}][source]" class="sat-input" placeholder="Source term">
        <span style="color:var(--sat-gray-400);">→</span>
        <input type="text" name="sat_settings[glossary][${glossaryIndex}][target]" class="sat-input" placeholder="Always translate as (leave empty = do not translate)">
        <button type="button" class="sat-btn sat-btn-danger sat-btn-sm sat-remove-glossary">✕</button>
      </div>
    `);
    glossaryIndex++;
  });
  $(document).on('click', '.sat-remove-glossary', function() { $(this).closest('.sat-glossary-row').remove(); });

  // Glossary XLSX/CSV import
  $('#sat-glossary-import-file').on('change', function() {
    const file = this.files[0];
    if (!file) return;
    const status = $('#sat-glossary-import-status');
    const mode   = $('#sat-glossary-import-mode').val();
    status.text('⏳ Importing...').css('color', 'var(--sat-gray-400)');

    const formData = new FormData();
    formData.append('action', 'sat_import_glossary');
    formData.append('nonce', satConfig.nonce);
    formData.append('file', file);
    formData.append('mode', mode);

    $.ajax({
      url: ajaxurl,
      type: 'POST',
      data: formData,
      processData: false,
      contentType: false,
      success: function(res) {
        if (res.success) {
          status.text('✅ ' + res.data.message).css('color', '#166534');
          // Sayfayı yenile — glossary listesi güncellensin
          setTimeout(() => location.reload(), 1500);
        } else {
          status.text('❌ ' + (res.data || 'Import failed')).css('color', '#dc2626');
        }
      },
      error: function() {
        status.text('❌ Upload error').css('color', '#dc2626');
      }
    });

    // Reset input — aynı dosya tekrar seçilebilsin
    this.value = '';
  });

  // Sync models
  $('#sat-sync-models').on('click', function() {
    const btn = $(this).prop('disabled', true).text('Syncing...');
    $.post(ajaxurl, { action: 'sat_sync_models', nonce: satConfig.nonce }, function(res) {
      btn.prop('disabled', false).text('↻ Sync Models');
      if (res.success) {
        alert('Models synced! Last sync: ' + res.data.synced_at);
        location.reload();
      }
    });
  });

  // ── Select2 init — ACF veya WC'nin yüklediği Select2'yi kullan ──
  function initSelect2() {
    if (typeof $.fn.select2 === 'undefined') {
      // Select2 yok — native multiselect'i güzelleştir
      $('.sat-select2-multi').css({
        'min-height': '100px',
        'width': '100%',
        'border': '1px solid #ddd',
        'border-radius': '4px',
        'padding': '4px'
      });
      return;
    }

    $('.sat-select2-multi').each(function() {
      if ($(this).hasClass('select2-hidden-accessible')) return; // zaten init edilmiş

      $(this).select2({
        placeholder: 'Select items...',
        allowClear: true,
        closeOnSelect: false,
        width: '100%',
        dropdownAutoWidth: false,
      }).on('select2:select select2:unselect', function() {
        var $el = $(this);
        setTimeout(function() { $el.select2('open'); }, 50);
      });
    });
  }

  // İlk init: sayfa yüklenince
  $(function() { initSelect2(); });

  // Content tab'a tıklayınca da init et (display:none'dan çıkınca boyut hesabı yapılır)
  $('.sat-tab[data-tab="content"]').on('click', function() {
    setTimeout(initSelect2, 100);
  });

})(jQuery);
</script>

