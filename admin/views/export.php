<?php if (!defined('ABSPATH')) exit;
$default    = $integration ? $integration->getDefaultLanguage() : '';
$defaultLabel = $languages[$default] ?? strtoupper($default);
$postTypes  = get_post_types(['public' => true], 'objects');
$taxonomies = get_taxonomies(['public' => true], 'objects');
// Settings'deki exclusion'lar — bunlar export listesinde unchecked gelecek
$excludedPT  = $settings->get('exclude_post_types', []);
$excludedTax = $settings->get('exclude_taxonomies', []);
// Polylang string groups
$stringGroups = [];
if (class_exists('\PLL_Admin_Strings')) {
    $pllStrings = \PLL_Admin_Strings::get_strings();
    if (is_array($pllStrings)) {
        $stringGroups = array_values(array_unique(array_filter(array_column($pllStrings, 'context'))));
        sort($stringGroups);
    }
}
// Tema .po dosyaları (sadece tema)
$themePoFiles = [];
$themeLangDir = get_template_directory() . '/languages';
if (is_dir($themeLangDir)) {
    foreach (glob($themeLangDir . '/*.po') as $poFile) {
        $themePoFiles[] = basename($poFile);
    }
}
?>
<div class="sat-wrap">
  <header class="sat-header">
    <div class="sat-logo">A</div>
    <div><h1>Export / Import</h1><p>Export all translations to CSV — edit offline — import back</p></div>
  </header>

  <!-- Tabs -->
  <div class="sat-tabs" role="tablist">
    <button class="sat-tab is-active" data-tab="export" role="tab">📥 Export</button>
    <button class="sat-tab" data-tab="import" role="tab">📤 Import</button>
  </div>

  <!-- ── EXPORT TAB ─────────────────────────────────────────────────────────── -->
  <div class="sat-tab-panel" id="tab-export">
    <div class="sat-grid-2">

      <!-- Left: Controls -->
      <div>

        <!-- Target Languages -->
        <div class="sat-card">
          <div class="sat-card-title">1. Target Languages</div>
          <div style="background:var(--sat-gray-50);border:1px solid var(--sat-gray-200);border-radius:6px;padding:8px 12px;margin-bottom:12px;font-size:12px;color:var(--sat-gray-600);">
            🔒 <strong><?= esc_html($defaultLabel) ?> (<?= esc_html($default) ?>)</strong> — default language, always included as source
          </div>
          <div style="display:flex;flex-direction:column;gap:6px;">
            <?php foreach ($languages as $code => $label):
              if ($code === $default) continue; ?>
              <label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer;">
                <input type="checkbox" class="sat-exp-lang" value="<?= esc_attr($code) ?>" checked>
                <?= esc_html($label) ?> <span style="color:var(--sat-gray-400);font-size:11px;">(<?= esc_attr($code) ?>)</span>
              </label>
            <?php endforeach; ?>
          </div>
        </div>

        <!-- Content Types -->
        <div class="sat-card">
          <div class="sat-card-title">2. Content to Export</div>

          <div style="margin-bottom:14px;">
            <div style="font-size:12px;font-weight:600;color:var(--sat-gray-600);margin-bottom:8px;text-transform:uppercase;letter-spacing:.5px;">Posts & Pages</div>
            <?php foreach ($postTypes as $pt):
              if (in_array($pt->name, ['attachment', 'wp_block', 'wp_template', 'wp_template_part', 'wp_navigation'])) continue;
              if (in_array($pt->name, $excludedPT)) continue; ?>
              <label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer;margin-bottom:6px;">
                <input type="checkbox" class="sat-exp-pt" value="<?= esc_attr($pt->name) ?>" checked>
                <?= esc_html($pt->label) ?> <span style="color:var(--sat-gray-400);font-size:11px;"><?= esc_attr($pt->name) ?></span>
              </label>
            <?php endforeach; ?>
          </div>

          <div style="margin-bottom:14px;">
            <div style="font-size:12px;font-weight:600;color:var(--sat-gray-600);margin-bottom:8px;text-transform:uppercase;letter-spacing:.5px;">Taxonomies / Terms</div>
            <?php foreach ($taxonomies as $tax):
              if (in_array($tax->name, ['post_format', 'nav_menu', 'language', 'post_translations', 'term_language', 'term_translations', 'wp_theme', 'wp_template_part_area'])) continue;
              if (in_array($tax->name, $excludedTax)) continue; ?>
              <label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer;margin-bottom:6px;">
                <input type="checkbox" class="sat-exp-tax" value="<?= esc_attr($tax->name) ?>" checked>
                <?= esc_html($tax->label) ?> <span style="color:var(--sat-gray-400);font-size:11px;"><?= esc_attr($tax->name) ?></span>
              </label>
            <?php endforeach; ?>
          </div>

          <div style="margin-bottom:14px;">
            <div style="font-size:12px;font-weight:600;color:var(--sat-gray-600);margin-bottom:8px;text-transform:uppercase;letter-spacing:.5px;">Other</div>
            <label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer;margin-bottom:6px;">
              <input type="checkbox" class="sat-exp-other" value="menus" checked>
              Navigation Menus
            </label>
          </div>

          <?php if (!empty($stringGroups)): ?>
          <div style="margin-bottom:14px;">
            <div style="font-size:12px;font-weight:600;color:var(--sat-gray-600);margin-bottom:8px;text-transform:uppercase;letter-spacing:.5px;">Strings (Polylang)</div>
            <?php foreach ($stringGroups as $g): ?>
              <label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer;margin-bottom:6px;">
                <input type="checkbox" class="sat-exp-strgroup" value="<?= esc_attr($g) ?>">
                <?= esc_html($g) ?>
              </label>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>

          <?php if (!empty($themePoFiles)): ?>
          <div>
            <div style="font-size:12px;font-weight:600;color:var(--sat-gray-600);margin-bottom:8px;text-transform:uppercase;letter-spacing:.5px;">PO Translations (Theme)</div>
            <label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer;margin-bottom:6px;">
              <input type="checkbox" class="sat-exp-other" value="theme_po">
              Theme .po files (<?= count($themePoFiles) ?> locales: <?= esc_html(implode(', ', $themePoFiles)) ?>)
            </label>
          </div>
          <?php endif; ?>
        </div>

        <!-- Fields -->
        <div class="sat-card">
          <div class="sat-card-title">3. Fields to Include</div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;">
            <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer;">
              <input type="checkbox" class="sat-exp-field" value="title" checked> Title
            </label>
            <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer;">
              <input type="checkbox" class="sat-exp-field" value="content" checked> Content (HTML)
            </label>
            <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer;">
              <input type="checkbox" class="sat-exp-field" value="excerpt" checked> Excerpt
            </label>
            <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer;">
              <input type="checkbox" class="sat-exp-field" value="slug"> Slug
            </label>
            <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer;">
              <input type="checkbox" class="sat-exp-field" value="acf" checked> ACF Fields
            </label>
            <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer;">
              <input type="checkbox" class="sat-exp-field" value="seo" checked> SEO (Yoast/RankMath)
            </label>
          </div>
        </div>

        <button class="sat-btn sat-btn-primary" id="sat-export-btn" style="width:100%;font-size:15px;padding:12px;">
          📥 Generate &amp; Download XLSX
        </button>
        <div id="sat-export-status" style="margin-top:10px;"></div>
      </div>

      <!-- Right: Info -->
      <div>
        <div class="sat-card">
          <div class="sat-card-title">File Format</div>
          <p style="font-size:13px;color:var(--sat-gray-600);line-height:1.7;margin:0 0 12px;">
            Exports a single <strong>.csv</strong> file with UTF-8 BOM (Excel compatible).<br>
            Rows are grouped by object — all fields of a post together, separated by blank lines for readability.
          </p>
          <div style="background:var(--sat-gray-50);border:1px solid var(--sat-gray-200);border-radius:6px;padding:12px;font-size:11px;font-family:monospace;overflow-x:auto;">
            <div style="color:var(--sat-gray-400);margin-bottom:4px;"># Header row:</div>
            <div>sat_id | sat_type | sat_field | sat_context | EN | TR | DE</div>
            <div style="margin-top:8px;color:var(--sat-gray-400);"># Post example:</div>
            <div>1661 | post | title | looks | Be Cool! | Cool ol! | Sei cool!</div>
            <div>1661 | post | content | looks | &lt;p&gt;...&lt;/p&gt; | &lt;p&gt;...&lt;/p&gt; | ...</div>
            <div>1661 | post | seo:meta_desc | looks | Meta EN | Meta TR | Meta DE</div>
            <div style="margin-top:8px;color:var(--sat-gray-400);"># Term example:</div>
            <div>45 | term | name | category | Newsletter | Bülten | Newsletter</div>
            <div style="margin-top:8px;color:var(--sat-gray-400);"># String example:</div>
            <div>3690739305 | string | search_placeholder | ACF | Search... | Ara... | Suche...</div>
            <div style="margin-top:8px;color:var(--sat-gray-400);"># PO example:</div>
            <div>1234567 | po_theme | salthareket-tr_TR.po | salthareket-tr_TR.po | Search | Ara | Suche</div>
          </div>
        </div>

        <div class="sat-card" style="margin-top:0;">
          <div class="sat-card-title">Import tip</div>
          <p style="font-size:13px;color:var(--sat-gray-600);line-height:1.7;margin:0;">
            1. Export the file<br>
            2. Share with translator — they edit only the language columns<br>
            3. <strong>Do NOT change</strong> sat_id, sat_type, sat_field columns<br>
            4. Import the edited file — only changed cells will be updated
          </p>
        </div>
      </div>

    </div>
  </div><!-- /tab-export -->

  <!-- ── IMPORT TAB ─────────────────────────────────────────────────────────── -->
  <div class="sat-tab-panel" id="tab-import" style="display:none;">
    <div class="sat-grid-2">

      <!-- Left: Upload -->
      <div>
        <div class="sat-card">
          <div class="sat-card-title">Import Translations from XLSX / CSV</div>

          <!-- Drop zone -->
          <div id="sat-import-dropzone" style="border:2px dashed var(--sat-gray-200);border-radius:8px;padding:40px 20px;text-align:center;cursor:pointer;transition:all .2s;margin-bottom:16px;position:relative;">
            <div style="font-size:36px;margin-bottom:8px;">📤</div>
            <div style="font-weight:600;font-size:14px;color:var(--sat-gray-800);margin-bottom:4px;">Drop XLSX or CSV file here or click to browse</div>
            <div style="font-size:12px;color:var(--sat-gray-400);">.xlsx or .csv files exported from this plugin</div>
            <input type="file" id="sat-import-file" accept=".xlsx,.csv"
              style="position:absolute;inset:0;width:100%;height:100%;opacity:0;cursor:pointer;z-index:2;">
          </div>

          <div id="sat-import-file-info" style="display:none;background:var(--sat-gray-50);border:1px solid var(--sat-gray-200);border-radius:6px;padding:12px;margin-bottom:16px;">
            <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;">
              <div style="overflow:hidden;">
                <div style="display:flex;align-items:center;gap:6px;margin-bottom:4px;">
                  <span style="font-size:16px;">📄</span>
                  <span style="font-weight:600;font-size:13px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" id="sat-import-filename"></span>
                </div>
                <div style="font-size:12px;color:var(--sat-gray-500);line-height:1.6;" id="sat-import-stats"></div>
              </div>
              <button class="sat-btn sat-btn-secondary sat-btn-sm" id="sat-import-clear" style="white-space:nowrap;flex-shrink:0;">✕ Remove</button>
            </div>
          </div>

          <div style="margin-bottom:16px;">
            <label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer;">
              <input type="checkbox" id="sat-import-skip-same" checked>
              Skip unchanged (only update if translation differs)
            </label>
            <label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer;margin-top:8px;">
              <input type="checkbox" id="sat-import-dry-run">
              Dry run (preview changes without saving)
            </label>
          </div>

          <button class="sat-btn sat-btn-primary" id="sat-import-btn" disabled style="width:100%;font-size:15px;padding:12px;">
            📤 Start Import
          </button>
        </div>
      </div>

      <!-- Right: Progress + Results -->
      <div>
        <!-- Progress card -->
        <div class="sat-card" id="sat-import-progress-card" style="display:none;">
          <div class="sat-card-title" id="sat-import-progress-title">Importing...</div>
          <div class="sat-credit-bar" style="margin-bottom:12px;">
            <div class="label">
              <span id="sat-import-progress-text">Processing...</span>
              <span id="sat-import-progress-count">0/0</span>
            </div>
            <div class="sat-progress">
              <div class="sat-progress-bar" id="sat-import-progress-bar" style="width:0%">0%</div>
            </div>
          </div>
          <div style="display:flex;gap:16px;flex-wrap:wrap;font-size:13px;">
            <span>✅ Updated: <strong id="sat-import-updated">0</strong></span>
            <span>⏭ Skipped: <strong id="sat-import-skipped">0</strong></span>
            <span>❌ Errors: <strong id="sat-import-errors">0</strong></span>
          </div>
        </div>

        <!-- Result log -->
        <div class="sat-card" id="sat-import-result-card" style="display:none;">
          <div class="sat-card-title">Import Results</div>
          <div id="sat-import-log" style="max-height:300px;overflow-y:auto;font-size:11px;font-family:monospace;background:var(--sat-gray-50);padding:10px;border-radius:6px;border:1px solid var(--sat-gray-200);"></div>
        </div>

        <!-- Done card -->
        <div class="sat-card" id="sat-import-done-card" style="display:none;text-align:center;padding:48px 40px;">
          <div style="margin-bottom:16px;">
            <svg width="56" height="56" viewBox="0 0 56 56" fill="none" xmlns="http://www.w3.org/2000/svg">
              <circle cx="28" cy="28" r="28" fill="#22C55E" fill-opacity="0.12"/>
              <circle cx="28" cy="28" r="20" fill="#22C55E"/>
              <path d="M19 28.5L24.5 34L37 22" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
          </div>
          <div style="font-size:20px;font-weight:700;color:var(--sat-gray-800);margin-bottom:10px;" id="sat-import-done-title">Import Complete!</div>
          <div id="sat-import-done-summary" style="font-size:13px;color:var(--sat-gray-500);line-height:1.7;"></div>
          <button class="sat-btn sat-btn-secondary" style="margin-top:20px;" id="sat-import-reset">Import another file</button>
        </div>
      </div>
    </div>
  </div><!-- /tab-import -->

</div><!-- /sat-wrap -->

<script>
(function($){
  const nonce    = (typeof satConfig !== 'undefined' && satConfig.nonce)    ? satConfig.nonce    : '<?= wp_create_nonce('sat_nonce') ?>';
  const ajaxUrl  = (typeof satConfig !== 'undefined' && satConfig.ajaxUrl)  ? satConfig.ajaxUrl  : '<?= admin_url('admin-ajax.php') ?>';

  // ── Tabs ─────────────────────────────────────────────────────────────────────
  $('.sat-tab').on('click', function() {
    const tab = $(this).data('tab');
    $('.sat-tab').removeClass('is-active');
    $(this).addClass('is-active');
    $('.sat-tab-panel').hide();
    $('#tab-' + tab).show();
    if (history.replaceState) history.replaceState(null,'','#tab-'+tab);
  });
  const hash = location.hash.replace('#tab-','');
  if (hash && $('#tab-'+hash).length) $('[data-tab="'+hash+'"]').trigger('click');

  // ── EXPORT ───────────────────────────────────────────────────────────────────
  $('#sat-export-btn').on('click', function() {
    const langs      = $('.sat-exp-lang:checked').map(function(){ return this.value; }).get();
    const ptypes     = $('.sat-exp-pt:checked').map(function(){ return this.value; }).get();
    const taxes      = $('.sat-exp-tax:checked').map(function(){ return this.value; }).get();
    const others     = $('.sat-exp-other:checked').map(function(){ return this.value; }).get();
    const fields     = $('.sat-exp-field:checked').map(function(){ return this.value; }).get();
    const strGroups  = $('.sat-exp-strgroup:checked').map(function(){ return this.value; }).get();

    if (!langs.length) { alert('Select at least one target language.'); return; }
    if (!ptypes.length && !taxes.length && !others.length && !strGroups.length) {
      alert('Select at least one content type.'); return;
    }

    $('#sat-export-status').html('<div class="sat-alert sat-alert-info">⏳ Generating file, please wait...</div>');

    const $form = $('<form>', { method:'POST', action:ajaxUrl, style:'display:none' });
    const add   = (name, val) => $form.append($('<input>',{type:'hidden',name:name,value:val}));

    add('action', 'sat_export_full');
    add('nonce',  nonce);
    langs.forEach(l      => add('langs[]',      l));
    ptypes.forEach(p     => add('ptypes[]',     p));
    taxes.forEach(t      => add('taxes[]',      t));
    others.forEach(o     => add('others[]',     o));
    fields.forEach(f     => add('fields[]',     f));
    strGroups.forEach(g  => add('str_groups[]', g));

    $('body').append($form);
    $form.submit();
    setTimeout(() => { $form.remove(); $('#sat-export-status').empty(); }, 5000);
  });

  // ── IMPORT ───────────────────────────────────────────────────────────────────
  let importRows = [], importFile = null;

  // Drop zone
  $('#sat-import-dropzone').on('dragover', function(e) {
    e.preventDefault();
    $(this).css({'border-color':'var(--sat-primary)','background':'#f8f7ff'});
  }).on('dragleave drop', function(e) {
    e.preventDefault();
    $(this).css({'border-color':'var(--sat-gray-200)','background':''});
    if (e.type === 'drop') handleFile(e.originalEvent.dataTransfer.files[0]);
  });
  $('#sat-import-file').on('change', function() { handleFile(this.files[0]); });

  function handleFile(file) {
    if (!file) return;
    const isXlsx = file.name.endsWith('.xlsx');
    const isCsv  = file.name.endsWith('.csv');
    if (!isXlsx && !isCsv) { alert('Please select a .xlsx or .csv file.'); return; }
    importFile = file;

    if (isXlsx) {
      // XLSX: server-side parse
      $('#sat-import-dropzone').hide();
      $('#sat-import-file-info').show();
      $('#sat-import-filename').text(file.name);
      $('#sat-import-stats').text('⏳ Parsing XLSX...');
      $('#sat-import-btn').prop('disabled', true);

      const fd = new FormData();
      fd.append('action', 'sat_parse_xlsx');
      fd.append('nonce',  nonce);
      fd.append('file',   file);

      $.ajax({ url: ajaxUrl, type: 'POST', data: fd, processData: false, contentType: false,
        success: function(res) {
          if (res.success) {
            importRows = res.data.rows;
            const langCols  = res.data.langs  || [];
            const types     = res.data.types  || [];
            const fileSize  = file.size > 1024*1024 ? (file.size/1024/1024).toFixed(1)+' MB' : Math.round(file.size/1024)+' KB';
            $('#sat-import-stats').html(
              importRows.length + ' rows &nbsp;·&nbsp; ' +
              langCols.length + ' languages: <strong>' + langCols.join(', ') + '</strong>' +
              (types.length ? ' &nbsp;·&nbsp; types: ' + types.join(', ') : '') +
              ' &nbsp;·&nbsp; ' + fileSize
            );
            $('#sat-import-btn').prop('disabled', importRows.length === 0);
          } else {
            const errMsg = res.data || 'Unknown error';
            $('#sat-import-stats').html('<span style="color:var(--sat-error);">❌ ' + errMsg + '</span>');
            $('#sat-import-btn').prop('disabled', true);
          }
        },
        error: function() {
          $('#sat-import-stats').html('<span style="color:var(--sat-error);">❌ Upload failed. Try again.</span>');
        }
      });
    } else {
      // CSV: client-side parse
      const reader = new FileReader();
      reader.onload = function(e) { parseCSV(e.target.result); };
      reader.readAsText(file, 'UTF-8');
    }
  }

  function parseCSV(text) {
    // Remove BOM
    text = text.replace(/^\uFEFF/, '');

    // Multiline-aware tokenizer — tırnak içindeki \n'leri korur
    const tokens = [];
    let cur = '', inQuote = false, i = 0, rowStart = true;
    const rows = [];
    let currentRow = [];

    while (i < text.length) {
      const ch = text[i];
      if (ch === '"') {
        if (inQuote && text[i+1] === '"') { cur += '"'; i += 2; continue; }
        inQuote = !inQuote; i++; continue;
      }
      if (ch === ',' && !inQuote) {
        currentRow.push(cur); cur = ''; i++; continue;
      }
      if ((ch === '\n' || (ch === '\r' && text[i+1] === '\n')) && !inQuote) {
        currentRow.push(cur); cur = '';
        rows.push(currentRow); currentRow = [];
        if (ch === '\r') i++;
        i++; continue;
      }
      cur += ch; i++;
    }
    if (cur || currentRow.length) { currentRow.push(cur); rows.push(currentRow); }

    const result = [];
    let header = null;
    for (const cols of rows) {
      if (!cols.some(c => c.trim())) continue; // boş satır
      if (!header) {
        header = cols.map(c => c.trim());
        // Format kontrolü
        const required = ['sat_id','sat_type','sat_field','sat_context'];
        const missing  = required.filter(r => !header.includes(r));
        if (missing.length) {
          alert('❌ Invalid file format. This file was not exported from Salt AI Translator.\nMissing columns: ' + missing.join(', '));
          importRows = []; importFile = null;
          $('#sat-import-file').val('');
          $('#sat-import-dropzone').show();
          $('#sat-import-file-info').hide();
          $('#sat-import-btn').prop('disabled', true);
          return;
        }
        continue;
      }
      if (cols.length < 5) continue;
      const row = {};
      header.forEach((h, j) => { row[h] = (cols[j] ?? '').replace(/\r/g, ''); });
      if (row.sat_id && row.sat_type && row.sat_field) result.push(row);
    }

    importRows = result;

    // Stats — sadece header'dan gelen dil kodları (EN, TR, DE vs — kısa, büyük harf)
    const langCols  = header ? header.filter(h => !['sat_id','sat_type','sat_field','sat_context'].includes(h)) : [];
    const validTypes = ['post','term','menu_item'];
    const types     = [...new Set(result.map(r => r.sat_type).filter(t => validTypes.includes(t)))];

    const fileSize  = importFile.size > 1024*1024
      ? (importFile.size / 1024 / 1024).toFixed(1) + ' MB'
      : Math.round(importFile.size / 1024) + ' KB';

    $('#sat-import-filename').text(importFile.name);
    $('#sat-import-stats').html(
      result.length + ' rows &nbsp;·&nbsp; ' +
      langCols.length + ' languages: <strong>' + langCols.join(', ') + '</strong>' +
      (types.length ? ' &nbsp;·&nbsp; types: ' + types.join(', ') : '') +
      ' &nbsp;·&nbsp; ' + fileSize
    );
    $('#sat-import-dropzone').hide();
    $('#sat-import-file-info').show();
    $('#sat-import-btn').prop('disabled', result.length === 0);
  }

  function csvParseLine(line) {
    const result = [], len = line.length;
    let cur = '', inQuote = false, i = 0;
    while (i < len) {
      const ch = line[i];
      if (ch === '"') {
        if (inQuote && line[i+1] === '"') { cur += '"'; i += 2; continue; }
        inQuote = !inQuote;
      } else if (ch === ',' && !inQuote) {
        result.push(cur); cur = ''; i++; continue;
      } else {
        cur += ch;
      }
      i++;
    }
    result.push(cur);
    return result;
  }

  $('#sat-import-clear').on('click', function() {
    importRows = []; importFile = null;
    $('#sat-import-file').val('');
    $('#sat-import-file-info').hide();
    $('#sat-import-dropzone').show();
    $('#sat-import-btn').prop('disabled', true);
  });

  // Import start
  $('#sat-import-btn').on('click', function() {
    if (!importRows.length) return;
    const skipSame = $('#sat-import-skip-same').is(':checked') ? 1 : 0;
    const dryRun   = $('#sat-import-dry-run').is(':checked') ? 1 : 0;
    const total    = importRows.length;
    const BATCH    = 50;

    $('#sat-import-progress-card').show();
    $('#sat-import-result-card').show();
    $('#sat-import-done-card').hide();
    $('#sat-import-btn').prop('disabled', true);
    $('#sat-import-log').empty();
    $('#sat-import-updated').text(0);
    $('#sat-import-skipped').text(0);
    $('#sat-import-errors').text(0);

    let offset = 0, totalUpdated = 0, totalSkipped = 0, totalErrors = 0;

    function nextBatch() {
      const batch = importRows.slice(offset, offset + BATCH);
      if (!batch.length) {
        // Done
        const title = dryRun ? '🔍 Dry Run Complete' : '✅ Import Complete';
        $('#sat-import-progress-title').text(title);
        $('#sat-import-progress-bar').css('width','100%').text('100%');
        $('#sat-import-progress-text').text('Done!');
        $('#sat-import-done-title').text(dryRun ? 'Dry Run Complete' : 'Import Complete!');
        $('#sat-import-done-summary').html(
          `Updated: <strong>${totalUpdated}</strong> &nbsp; Skipped: <strong>${totalSkipped}</strong> &nbsp; Errors: <strong>${totalErrors}</strong>` +
          (dryRun ? '<br><em>Dry run — no changes were saved</em>' : '')
        );
        $('#sat-import-done-card').show();
        return;
      }

      $.post(ajaxUrl, {
        action:    'sat_import_csv',
        nonce:     nonce,
        rows:      JSON.stringify(batch),
        skip_same: skipSame,
        dry_run:   dryRun,
      }, function(res) {
        offset += BATCH;
        if (res.success) {
          totalUpdated += res.data.updated || 0;
          totalSkipped += res.data.skipped || 0;
          totalErrors  += res.data.errors  || 0;

          const pct = Math.min(100, Math.round((offset / total) * 100));
          $('#sat-import-progress-bar').css('width', pct + '%').text(pct + '%');
          $('#sat-import-progress-count').text(Math.min(offset, total) + '/' + total);
          $('#sat-import-updated').text(totalUpdated);
          $('#sat-import-skipped').text(totalSkipped);
          $('#sat-import-errors').text(totalErrors);

          // Log
          (res.data.log || []).forEach(function(msg) {
            const out = $('#sat-import-log');
            // PHP json_encode \n → \\n olarak encode eder, ikisini de handle et
            const lines = msg.split(/\\n|\n/);
            const div = $('<div style="margin-bottom:4px;border-bottom:1px solid var(--sat-gray-200);padding-bottom:4px;">');
            lines.forEach(function(line, i) {
              if (!line.trim()) return;
              if (i === 0) {
                div.append($('<div>').text(line));
              } else {
                div.append($('<div style="padding-left:12px;color:var(--sat-gray-500);font-size:10px;word-break:break-all;">').text(line));
              }
            });
            out.append(div);
            out.scrollTop(out[0].scrollHeight);
          });
        }
        nextBatch();
      }).fail(function() {
        totalErrors++;
        importLog('❌ AJAX fail at offset ' + offset);
        offset += BATCH;
        nextBatch();
      });
    }

    nextBatch();
  });

  $('#sat-import-reset').on('click', function() {
    $('#sat-import-done-card').hide();
    $('#sat-import-progress-card').hide();
    $('#sat-import-result-card').hide();
    $('#sat-import-clear').trigger('click');
  });

  function importLog(msg) {
    const out = $('#sat-import-log');
    out.append('<div>' + msg + '</div>');
    out.scrollTop(out[0].scrollHeight);
  }

})(jQuery);
</script>
