<?php if (!defined('ABSPATH')) exit;
$menus   = wp_get_nav_menus();
$default = $integration ? $integration->getDefaultLanguage() : '';
$hasPLL  = class_exists('\PLL_Admin_Strings') || function_exists('pll_get_strings');
$hasWPML = defined('ICL_SITEPRESS_VERSION');
$hasStrings = $hasPLL || $hasWPML;
$queue   = $container->get('queue');

// Grup listesini PHP'de hazırla
$stringGroups = [];
if ($hasPLL && class_exists('\PLL_Admin_Strings')) {
    $pllStrings = \PLL_Admin_Strings::get_strings();
    if (is_array($pllStrings)) {
        $stringGroups = array_values(array_unique(array_filter(array_column($pllStrings, 'context'))));
        sort($stringGroups);
    }
}
?>
<div class="sat-wrap">

  <header class="sat-header">
    <div class="sat-logo">A</div>
    <div><h1>Others</h1><p>Translate menus, strings and PO files</p></div>
  </header>

  <!-- Tabs -->
  <div class="sat-tabs" role="tablist">
    <button class="sat-tab is-active" data-tab="menus" role="tab">🗂 Menus</button>
    <button class="sat-tab" data-tab="strings" role="tab">🔤 Strings
      <?php if ($hasPLL): ?>
        <span class="sat-badge sat-badge-success" style="font-size:10px;margin-left:4px;vertical-align:middle;">Polylang</span>
      <?php elseif ($hasWPML): ?>
        <span class="sat-badge sat-badge-success" style="font-size:10px;margin-left:4px;vertical-align:middle;">WPML</span>
      <?php endif; ?>
    </button>
    <button class="sat-tab" data-tab="po" role="tab">📄 PO Files</button>
  </div>

  <!-- ── TAB: MENUS ─────────────────────────────────────────────────────────── -->
  <div class="sat-tab-panel" id="tab-menus">
    <div class="sat-card" style="max-width:560px;">
      <div class="sat-card-title">Navigation Menu Translation</div>
      <div class="sat-form-group">
        <label class="sat-label">Select Menu</label>
        <select class="sat-select" id="sat-menu-select">
          <option value="">— Select menu —</option>
          <?php foreach ($menus as $menu): ?>
            <option value="<?= $menu->term_id ?>"><?= esc_html($menu->name) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="sat-form-group">
        <label class="sat-label">Target Language</label>
        <select class="sat-select" id="sat-menu-lang">
          <?php foreach ($languages as $code => $label):
            if ($code === $default) continue; ?>
            <option value="<?= esc_attr($code) ?>"><?= esc_html($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button class="sat-btn sat-btn-primary" id="sat-translate-menu-btn">Translate Menu</button>
      <div id="sat-menu-result" style="margin-top:12px;"></div>
    </div>
  </div>

  <!-- ── TAB: STRINGS ──────────────────────────────────────────────────────── -->
  <div class="sat-tab-panel" id="tab-strings" style="display:none;">
    <?php if (!$hasStrings): ?>
      <div class="sat-card">
        <div class="sat-alert sat-alert-info" style="margin:0;">
          String translation requires Polylang or WPML. Install and activate one to use this feature.
        </div>
      </div>
    <?php else: ?>

      <!-- Controls bar -->
      <div class="sat-card" style="padding:16px 24px;">
        <div style="display:flex;gap:16px;flex-wrap:wrap;align-items:flex-end;">

          <!-- Target Languages -->
          <div style="min-width:180px;">
            <div class="sat-label" style="margin-bottom:6px;">Target Languages</div>
            <div style="display:flex;flex-direction:column;gap:5px;">
              <?php foreach ($languages as $code => $label):
                if ($code === $default) continue; ?>
                <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer;">
                  <input type="checkbox" class="sat-str-lang-check" value="<?= esc_attr($code) ?>" checked>
                  <?= esc_html($label) ?>
                  <span style="color:var(--sat-gray-400);font-size:11px;"><?= esc_attr($code) ?></span>
                </label>
              <?php endforeach; ?>
            </div>
          </div>

          <!-- Filters -->
          <div style="flex:1;min-width:200px;display:flex;flex-direction:column;gap:10px;">
            <div>
              <label class="sat-label">Filter by group</label>
              <select class="sat-select" id="sat-strings-group">
                <option value="">— All groups —</option>
                <?php foreach ($stringGroups as $g): ?>
                  <option value="<?= esc_attr($g) ?>"><?= esc_html($g) ?></option>
                <?php endforeach; ?>
                <?php if (empty($stringGroups) && $hasPLL): ?>
                  <option disabled style="color:#999;">No groups found yet</option>
                <?php endif; ?>
              </select>
            </div>
            <div style="display:flex;gap:16px;flex-wrap:wrap;">
              <label style="display:flex;align-items:center;gap:7px;font-size:13px;cursor:pointer;">
                <input type="checkbox" id="sat-str-skip-translated" checked>
                Skip already translated
              </label>
              <label style="display:flex;align-items:center;gap:7px;font-size:13px;cursor:pointer;" title="Shows 3 alternatives per language. Uses 3x API credits. Not available in Translate All mode.">
                <input type="checkbox" id="sat-str-show-alts">
                Show alternatives <span style="font-size:11px;color:var(--sat-gray-400);">(3x credits)</span>
              </label>
            </div>
          </div>

          <!-- Actions -->
          <div style="display:flex;flex-direction:column;gap:8px;">
            <label style="display:flex;align-items:center;gap:7px;font-size:13px;cursor:pointer;">
              <input type="checkbox" id="sat-str-use-queue">
              Use background queue
            </label>
            <button class="sat-btn sat-btn-primary" id="sat-strings-check-btn">Check Strings</button>
            <button class="sat-btn sat-btn-success" id="sat-strings-translate-btn" style="display:none;">Translate All</button>
          </div>
        </div>
      </div>

      <!-- Queue Status card — posts/terms ile aynı format -->
      <?php
        $strQStatus = $queue ? $queue->getStatus('string') : [];
        $strQMeta   = get_option('sat_queue_meta_string', []);
      ?>
      <div class="sat-card" id="sat-str-queue-card" style="<?= (($strQStatus['pending'] ?? 0) + ($strQStatus['processing'] ?? 0)) > 0 ? '' : 'display:none' ?>">
        <div class="sat-card-title">
          Queue Status
          <span id="sat-str-q-driver-badge" style="font-size:10px;font-weight:500;margin-left:8px;padding:2px 7px;border-radius:10px;background:<?= ($strQStatus['driver'] ?? '') === 'action_scheduler' ? '#dcfce7' : '#fef9c3' ?>;color:<?= ($strQStatus['driver'] ?? '') === 'action_scheduler' ? '#16a34a' : '#854d0e' ?>;"><?= ($strQStatus['driver'] ?? '') === 'action_scheduler' ? 'Action Scheduler' : 'WP Cron' ?></span>
        </div>
        <div id="sat-str-q-meta" style="font-size:12px;color:var(--sat-gray-500);margin-bottom:8px;min-height:16px;"><?php
          if (!empty($strQMeta['langs'])) {
            echo esc_html(implode(', ', array_map('strtoupper', $strQMeta['langs'])));
            if (!empty($strQMeta['group'])) echo ' &middot; Group: ' . esc_html($strQMeta['group']);
            if (!empty($strQMeta['started_at'])) echo ' &middot; Started: ' . esc_html($strQMeta['started_at']);
          }
        ?></div>
        <div class="sat-credit-bar">
          <div class="label">
            <span id="sat-str-q-status-text">Processing...</span>
            <span id="sat-str-q-count"><?= ($strQStatus['done'] ?? 0) ?>/<?= ($strQStatus['total'] ?? 0) ?></span>
          </div>
          <div class="sat-progress">
            <div class="sat-progress-bar" id="sat-str-q-bar" style="width:<?= ($strQStatus['percent'] ?? 0) ?>%">
              <?= ($strQStatus['percent'] ?? 0) ?>%
            </div>
          </div>
        </div>
        <div id="sat-str-q-next-run" style="margin-top:8px;font-size:12px;color:var(--sat-gray-400);min-height:18px;"></div>
        <div id="sat-str-q-errors" style="display:none;margin-top:6px;font-size:12px;color:var(--sat-danger);"></div>
        <div style="display:flex;gap:8px;margin-top:10px;">
          <button class="sat-btn sat-btn-secondary sat-btn-sm" id="sat-str-cancel-queue">Cancel Queue</button>
          <button class="sat-btn sat-btn-danger sat-btn-sm" id="sat-str-retry-errors" style="display:none;">Retry Errors</button>
        </div>
      </div>

      <!-- Results table -->
      <div id="sat-strings-card" style="display:none;">
        <div class="sat-card" style="padding:0;overflow:hidden;">
          <div style="padding:14px 20px;border-bottom:1px solid var(--sat-gray-200);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
            <span style="font-weight:600;font-size:14px;">Strings <span id="sat-str-count" style="font-weight:400;color:var(--sat-gray-400);font-size:13px;"></span></span>
            <label id="sat-str-select-all-label" style="display:flex;align-items:center;gap:6px;font-size:12px;cursor:pointer;">
              <input type="checkbox" id="sat-str-select-all"> Select all
            </label>
          </div>
          <div style="overflow-x:auto;">
            <table class="sat-table">
              <thead>
                <tr id="sat-str-thead-tr">
                  <th style="width:32px;"></th>
                  <th>Group</th>
                  <th>String</th>
                  <!-- Dil kolonları JS tarafından eklenir -->
                  <th style="width:110px;">Action</th>
                </tr>
              </thead>
              <tbody id="sat-strings-tbody"></tbody>
            </table>
          </div>
        </div>
        <div id="sat-strings-pagination" style="margin-top:10px;display:flex;align-items:center;gap:8px;flex-wrap:wrap;"></div>
      </div>

      <div id="sat-strings-all-done" style="display:none;">
        <div class="sat-card" style="text-align:center;padding:40px;">
          <div style="margin-bottom:16px;">
            <svg width="56" height="56" viewBox="0 0 56 56" fill="none" xmlns="http://www.w3.org/2000/svg">
              <circle cx="28" cy="28" r="28" fill="#22C55E" fill-opacity="0.12"/>
              <circle cx="28" cy="28" r="20" fill="#22C55E"/>
              <path d="M19 28.5L24.5 34L37 22" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
          </div>
          <div style="font-size:16px;font-weight:600;color:var(--sat-gray-800);">All strings translated!</div>
        </div>
      </div>

      <div id="sat-strings-log" style="display:none;margin-top:8px;max-height:120px;overflow-y:auto;font-size:11px;font-family:monospace;background:var(--sat-gray-50);padding:10px;border-radius:6px;border:1px solid var(--sat-gray-200);"></div>

    <?php endif; ?>
  </div>

  <!-- ── TAB: PO FILES ─────────────────────────────────────────────────────── -->
  <div class="sat-tab-panel" id="tab-po" style="display:none;">

    <!-- STAGE 1: PO File List -->
    <div id="sat-po-stage1">
      <!-- Toolbar -->
      <div class="sat-card" style="padding:14px 20px;">
        <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:center;">
          <div>
            <label class="sat-label" style="margin-bottom:4px;">Scope</label>
            <select class="sat-select" id="sat-po-scope" style="min-width:200px;">
              <option value="theme">Theme only</option>
              <option value="all">All (theme + plugins + WP core)</option>
            </select>
          </div>
          <div>
            <label class="sat-label" style="margin-bottom:4px;">Language filter</label>
            <select class="sat-select" id="sat-po-lang-filter" style="min-width:160px;">
              <option value="">All languages</option>
              <?php foreach ($languages as $code => $label): ?>
                <option value="<?= esc_attr($code) ?>"><?= esc_html($label) ?> (<?= esc_attr($code) ?>)</option>
              <?php endforeach; ?>
            </select>
          </div>
          <div style="display:flex;align-items:flex-end;gap:10px;padding-bottom:1px;">
            <button class="sat-btn sat-btn-primary" id="sat-po-load-btn">Load PO Files</button>
          </div>
        </div>
      </div>

      <!-- Files table -->
      <div id="sat-po-files-list" style="display:none;">
        <div class="sat-card" style="padding:0;overflow:hidden;">
          <div style="overflow-x:auto;">
            <table class="sat-table">
              <thead>
                <tr>
                  <th>File</th>
                  <th>Source</th>
                  <th>Locale</th>
                  <th style="text-align:right;">Total</th>
                  <th style="min-width:200px;">Translated</th>
                  <th style="text-align:right;">Untranslated</th>
                  <th>Action</th>
                </tr>
              </thead>
              <tbody id="sat-po-tbody"></tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <!-- STAGE 2: PO String List (Polylang gibi) -->
    <div id="sat-po-stage2" style="display:none;">

      <!-- Header bar -->
      <div class="sat-card" style="padding:12px 20px;margin-bottom:0;">
        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
          <div style="display:flex;align-items:center;gap:12px;">
            <button class="sat-btn sat-btn-secondary sat-btn-sm" id="sat-po-back-btn">← Back to PO Files</button>
            <div>
              <span id="sat-po2-filename" style="font-weight:600;font-size:14px;font-family:monospace;"></span>
              <span id="sat-po2-locale" style="font-size:12px;color:var(--sat-gray-400);margin-left:8px;"></span>
            </div>
          </div>
          <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
            <input type="text" id="sat-po2-search" class="sat-input" placeholder="Search strings..." style="width:180px;">
            <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer;white-space:nowrap;">
              <input type="checkbox" id="sat-po2-skip" checked> Skip translated
            </label>
            <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer;white-space:nowrap;" title="3x API credits">
              <input type="checkbox" id="sat-po2-show-alts"> Show alternatives
            </label>
            <button class="sat-btn sat-btn-primary sat-btn-sm" id="sat-po2-check-btn">Load</button>
            <button class="sat-btn sat-btn-success sat-btn-sm" id="sat-po2-translate-all-btn" style="display:none;">Translate All</button>
          </div>
        </div>
      </div>

      <!-- String table -->
      <div id="sat-po2-table-wrap" style="display:none;">
        <div class="sat-card" style="padding:0;overflow:hidden;margin-top:0;border-top:none;border-radius:0 0 8px 8px;">
          <div style="padding:10px 20px;border-bottom:1px solid var(--sat-gray-200);display:flex;align-items:center;justify-content:space-between;">
            <span style="font-weight:600;font-size:13px;">Strings <span id="sat-po2-count" style="font-weight:400;color:var(--sat-gray-400);"></span></span>
            <label style="display:flex;align-items:center;gap:6px;font-size:12px;cursor:pointer;">
              <input type="checkbox" id="sat-po2-select-all"> Select all
            </label>
          </div>
          <div style="overflow-x:auto;">
            <table class="sat-table">
              <thead>
                <tr>
                  <th style="width:32px;"></th>
                  <th>Context</th>
                  <th>Original (msgid)</th>
                  <th style="min-width:220px;">Translation</th>
                  <th style="width:160px;">Action</th>
                </tr>
              </thead>
              <tbody id="sat-po2-tbody"></tbody>
            </table>
          </div>
        </div>
        <div id="sat-po2-pagination" style="margin-top:10px;display:flex;align-items:center;gap:8px;flex-wrap:wrap;"></div>
      </div>

      <div id="sat-po2-all-done" style="display:none;">
        <div class="sat-card" style="text-align:center;padding:40px;">
          <div style="margin-bottom:16px;">
            <svg width="56" height="56" viewBox="0 0 56 56" fill="none" xmlns="http://www.w3.org/2000/svg">
              <circle cx="28" cy="28" r="28" fill="#22C55E" fill-opacity="0.12"/>
              <circle cx="28" cy="28" r="20" fill="#22C55E"/>
              <path d="M19 28.5L24.5 34L37 22" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
          </div>
          <div style="font-size:16px;font-weight:600;">All strings translated!</div>
          <button class="sat-btn sat-btn-secondary" style="margin-top:16px;" id="sat-po2-show-all-btn">Show all strings</button>
        </div>
      </div>

      <div id="sat-po2-log" style="display:none;margin-top:8px;max-height:100px;overflow-y:auto;font-size:11px;font-family:monospace;background:var(--sat-gray-50);padding:8px;border-radius:6px;border:1px solid var(--sat-gray-200);"></div>
    </div>

    <!-- hidden progress div (unused but kept for JS compat) -->
    <div id="sat-po-progress" style="display:none;"></div>

  </div><!-- /tab-po -->

</div><!-- /sat-wrap -->

<script>
(function($){
  const nonce = (typeof satConfig !== 'undefined' && satConfig.nonce) ? satConfig.nonce : '<?= wp_create_nonce('sat_nonce') ?>';

  // ── Tab switching ────────────────────────────────────────────────────────────
  $('.sat-tab').on('click', function() {
    const tab = $(this).data('tab');
    $('.sat-tab').removeClass('is-active');
    $(this).addClass('is-active');
    $('.sat-tab-panel').hide();
    $('#tab-' + tab).show();
    // URL hash'e yaz — yeniden açınca aynı tab
    if (history.replaceState) history.replaceState(null, '', '#tab-' + tab);
  });
  // Sayfa yüklenince hash'e göre tab seç
  const hash = location.hash.replace('#tab-', '');
  if (hash && $('#tab-' + hash).length) {
    $('[data-tab="' + hash + '"]').trigger('click');
  }

  // ── MENU ────────────────────────────────────────────────────────────────────
  $('#sat-translate-menu-btn').on('click', function() {
    const menuId = $('#sat-menu-select').val();
    const lang   = $('#sat-menu-lang').val();
    if (!menuId || !lang) { alert('Select menu and language.'); return; }
    const btn = $(this).prop('disabled', true).html('<span class="sat-spinner"></span>');
    $.post(ajaxurl, { action: 'sat_translate_menu', nonce, menu_id: menuId, lang }, function(res) {
      btn.prop('disabled', false).text('Translate Menu');
      if (res.success) {
        const link = res.data.edit_url ? ` <a href="${res.data.edit_url}" target="_blank">→ View</a>` : '';
        $('#sat-menu-result').html(`<div class="sat-alert sat-alert-success">✅ <strong>${res.data.menu_name||''}</strong> — ${res.data.translated} items translated.${link}</div>`);
      } else {
        $('#sat-menu-result').html(`<div class="sat-alert sat-alert-danger">❌ ${res.data}</div>`);
      }
    });
  });

  // ── STRINGS ─────────────────────────────────────────────────────────────────
  function getSelectedLangs() {
    return $('.sat-str-lang-check:checked').map(function(){ return this.value; }).get();
  }

  // Dil adı map'i (PHP'den)
  const langLabels = {
    <?php foreach ($languages as $code => $label): ?>
    '<?= esc_js($code) ?>': '<?= esc_js($label) ?>',
    <?php endforeach; ?>
  };

  let strAllStrings = [], strPage = 1, strTotalPages = 1, strTotal = 0, isTranslatingStr = false, strTranslateIdx = 0;
  let strCurrentLangs = [], strCurrentGroup = '', strCurrentSkip = 1;
  const STR_LIMIT = 50;

  function buildStrTableHeader(langs) {
    const $tr = $('#sat-str-thead-tr');
    $tr.find('.sat-str-lang-th').remove();
    const $actionTh = $tr.find('th:last-child');
    langs.forEach(function(l) {
      const label = langLabels[l] || l.toUpperCase();
      $(`<th class="sat-str-lang-th" style="min-width:150px;font-size:12px;">${label} <span style="color:var(--sat-gray-400);font-weight:400;">(${l})</span></th>`)
        .insertBefore($actionTh);
    });
  }

  function fetchStrPage(page) {
    $('#sat-strings-card').hide();
    $('#sat-strings-pagination').empty();
    $('#sat-strings-tbody').empty().closest('.sat-card').find('.sat-card-title').after(
      '<div id="sat-str-loading" style="text-align:center;padding:20px;"><span class="sat-spinner"></span></div>'
    );

    $.post(ajaxurl, {
      action: 'sat_get_untranslated_strings', nonce,
      langs:           strCurrentLangs,
      group:           strCurrentGroup,
      skip_translated: strCurrentSkip,
      page:            page,
      limit:           STR_LIMIT
    }, function(res) {
      $('#sat-str-loading').remove();
      if (!res.success) { alert(res.data || 'Error'); return; }
      strAllStrings  = res.data.strings || [];
      strPage        = res.data.page || page;
      strTotalPages  = res.data.pages || 1;
      strTotal       = res.data.total || 0;

      // Grupları sadece 1. sayfada güncelle
      if (page === 1 && res.data.groups && res.data.groups.length) {
        const $grp = $('#sat-strings-group');
        const current = $grp.val();
        const existing = $grp.find('option').map(function(){ return $(this).val(); }).get();
        res.data.groups.forEach(function(g) {
          if (!existing.includes(g)) $grp.append(`<option value="${g}">${g}</option>`);
        });
      }

      buildStrTableHeader(strCurrentLangs);
      renderStrPage(strPage, strCurrentLangs);
    });
  }

  $('#sat-strings-check-btn').on('click', function() {
    const langs = getSelectedLangs();
    const group = $('#sat-strings-group').val();
    const skip  = $('#sat-str-skip-translated').is(':checked') ? 1 : 0;
    if (!langs.length) { alert('Select at least one language.'); return; }

    strCurrentLangs = langs;
    strCurrentGroup = group;
    strCurrentSkip  = skip;
    strAllStrings   = [];
    strPage         = 1;
    isTranslatingStr = false;
    strTranslateIdx  = 0;

    $(this).prop('disabled', true).html('<span class="sat-spinner"></span>');
    $('#sat-strings-translate-btn').hide();

    // Önce fetch et, button'u callback'te aktifle
    const origFetch = fetchStrPage;
    $.post(ajaxurl, {
      action: 'sat_get_untranslated_strings', nonce,
      langs, group, skip_translated: skip, page: 1, limit: STR_LIMIT
    }, function(res) {
      $('#sat-strings-check-btn').prop('disabled', false).text('Check Strings');
      if (!res.success) { alert(res.data || 'Error'); return; }
      strAllStrings  = res.data.strings || [];
      strPage        = 1;
      strTotalPages  = res.data.pages || 1;
      strTotal       = res.data.total || 0;

      if (res.data.groups && res.data.groups.length) {
        const $grp = $('#sat-strings-group');
        const existing = $grp.find('option').map(function(){ return $(this).val(); }).get();
        res.data.groups.forEach(function(g) {
          if (!existing.includes(g)) $grp.append(`<option value="${g}">${g}</option>`);
        });
      }

      buildStrTableHeader(langs);
      renderStrPage(1, langs);
    });
  });

  function renderStrPage(page, langs) {
    strPage = page;
    const $tbody = $('#sat-strings-tbody').empty();
    $('#sat-strings-all-done').hide();

    if (!strAllStrings.length) {
      $('#sat-strings-card').hide();
      $('#sat-strings-all-done').show();
      $('#sat-strings-translate-btn').hide();
      return;
    }

    $('#sat-str-count').text('(' + strTotal + ' total)');

    strAllStrings.forEach(function(s, localIdx) {
      const globalIdx = (page-1)*STR_LIMIT + localIdx;
      const strVal    = String(s.string ?? '');

      // Her dil için ayrı td
      const langTds = langs.map(function(l) {
        const val = (s.translations||{})[l] || '';
        const color = val ? 'var(--sat-gray-800)' : 'var(--sat-gray-400)';
        return `<td class="sat-str-val-td" data-lang="${l}"
                    style="font-size:12px;color:${color};max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
                    title="${val}">${val || '–'}</td>`;
      }).join('');

      $tbody.append(`<tr data-global-idx="${globalIdx}"
          data-name="${s.name}"
          data-string="${strVal.replace(/"/g,'&quot;').replace(/'/g,'&#39;')}"
          data-group="${s.group||''}">
        <td><input type="checkbox" class="sat-str-check"></td>
        <td><span class="sat-badge sat-badge-pending" style="font-size:10px;">${s.group||'–'}</span></td>
        <td style="font-size:12px;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${strVal}">${strVal}</td>
        ${langTds}
        <td>
          <div style="display:flex;gap:4px;align-items:center;">
            <button class="sat-btn sat-btn-primary sat-btn-sm sat-str-single" style="white-space:nowrap;">Translate</button>
            <button class="sat-btn sat-btn-secondary sat-btn-sm sat-str-copy" style="white-space:nowrap;" title="Use original value for all languages — no translation needed">Same for all</button>
          </div>
        </td>
      </tr>`);
    });

    $('#sat-strings-card').show();

    // Pagination — server-side
    const $pag = $('#sat-strings-pagination').empty();
    if (!isTranslatingStr && strTotalPages > 1) {
      $pag.append(`<span style="font-size:12px;color:var(--sat-gray-500);">Page ${page} of ${strTotalPages} (${strTotal} strings)</span>`);
      if (page > 1) $pag.append(`<button class="sat-btn sat-btn-secondary sat-btn-sm sat-str-page" data-page="${page-1}">← Prev</button>`);
      if (page < strTotalPages) $pag.append(`<button class="sat-btn sat-btn-secondary sat-btn-sm sat-str-page" data-page="${page+1}">Next →</button>`);
    }

    if (!isTranslatingStr) {
      $('#sat-strings-translate-btn').show();
      updateTranslateBtn();
      // Queue modu aktifse tablo UI'ını güncelle
      applyStrQueueMode();
    }
  }

  $(document).on('click', '.sat-str-page', function() {
    if (isTranslatingStr) return;
    const targetPage = parseInt($(this).data('page'));
    // Sayfa geçişinde all-done kartını gizle, strings kartı görünür kalsın
    $('#sat-strings-all-done').hide();
    $('#sat-strings-card').show();
    // Server-side fetch
    strCurrentLangs = strCurrentLangs.length ? strCurrentLangs : getSelectedLangs();
    $.post(ajaxurl, {
      action: 'sat_get_untranslated_strings', nonce,
      langs:           strCurrentLangs,
      group:           strCurrentGroup,
      skip_translated: strCurrentSkip,
      page:            targetPage,
      limit:           STR_LIMIT
    }, function(res) {
      if (!res.success) return;
      strAllStrings = res.data.strings || [];
      strPage       = res.data.page || targetPage;
      strTotalPages = res.data.pages || 1;
      strTotal      = res.data.total || 0;
      renderStrPage(strPage, strCurrentLangs);
    });
  });

  $(document).on('change', '#sat-str-select-all', function() {
    $('.sat-str-check').prop('checked', this.checked);
    updateTranslateBtn();
  });

  // Checkbox değişince buton metnini güncelle
  $(document).on('change', '.sat-str-check', function() {
    // Header checkbox sync
    const total   = $('.sat-str-check').length;
    const checked = $('.sat-str-check:checked').length;
    $('#sat-str-select-all').prop('checked', total > 0 && checked === total)
                            .prop('indeterminate', checked > 0 && checked < total);
    updateTranslateBtn();
  });

  function updateTranslateBtn() {
    const count    = $('.sat-str-check:checked').length;
    const useQueue = $('#sat-str-use-queue').is(':checked');
    if (useQueue) {
      $('#sat-strings-translate-btn').text('Queue All for Translation');
    } else if (count > 0) {
      $('#sat-strings-translate-btn').text('Translate Selected (' + count + ')');
    } else {
      $('#sat-strings-translate-btn').text('Translate All (' + strAllStrings.length + ')');
    }
  }

  // ── Background queue mode — thead+tbody checkbox+action kolonları gizle ────
  function applyStrQueueMode() {
    const useQueue = $('#sat-str-use-queue').is(':checked');
    if (useQueue) {
      $('.sat-str-check').prop('checked', false);
      // Thead kolonları gizle
      $('#sat-strings-card thead th:first-child').hide();
      $('#sat-strings-card thead th:last-child').hide();
      // Tbody kolonları gizle
      $('#sat-strings-tbody tr').each(function() {
        $(this).find('td:first-child').hide();
        $(this).find('td:last-child').hide();
      });
      // "Select all" label'ını gizle
      $('#sat-str-select-all-label').hide();
    } else {
      $('#sat-strings-card thead th:first-child').show();
      $('#sat-strings-card thead th:last-child').show();
      $('#sat-strings-tbody tr').each(function() {
        $(this).find('td:first-child').show();
        $(this).find('td:last-child').show();
      });
      $('#sat-str-select-all-label').show();
    }
    updateTranslateBtn();
  }

  $(document).on('change', '#sat-str-use-queue', applyStrQueueMode);

  // ── Translate All — tüm strAllStrings üzerinden sırayla, sayfalar arası ────
  $('#sat-strings-translate-btn').on('click', function() {
    const langs = getSelectedLangs();
    if (!langs.length) { alert('Select languages.'); return; }
    if (isTranslatingStr) return;

    // Background queue modu
    if ($('#sat-str-use-queue').is(':checked')) {
      const group = strCurrentGroup;
      const $btn  = $(this).prop('disabled', true).html('<span class="sat-spinner"></span>');
      $.post(ajaxurl, {
        action:          'sat_queue_start',
        nonce,
        type:            'string',
        langs:           langs,
        all:             1,
        group:           strCurrentGroup,
        skip_translated: strCurrentSkip,
      }, function(res) {
        $btn.prop('disabled', false).text('Queue All for Translation');
        if (res.success) {
          $('#sat-str-queue-card').show();
          renderStrNextRun(res.data.status && res.data.status.next_run ? res.data.status.next_run : null);
          pollStrQueueStatus();
          // Driver badge
          const isAS = res.data.driver === 'action_scheduler';
          $('#sat-str-q-driver-badge')
            .text(isAS ? 'Action Scheduler' : 'WP Cron')
            .css({'background': isAS ? '#dcfce7' : '#fef9c3', 'color': isAS ? '#16a34a' : '#854d0e'});
        } else {
          alert('Queue error: ' + (res.data || 'Unknown'));
        }
      });
      return;
    }

    // Seçili checkbox'lar varsa sadece onları çevir
    const selectedIdxs = [];
    $('.sat-str-check:checked').each(function() {
      const idx = parseInt($(this).closest('tr').data('global-idx'));
      if (!isNaN(idx)) selectedIdxs.push(idx);
    });
    const selectedOnly = selectedIdxs.length > 0;
    const toTranslate  = selectedOnly
      ? selectedIdxs.map(i => strAllStrings[i]).filter(Boolean)
      : strAllStrings;

    if (!toTranslate.length) return;

    isTranslatingStr  = true;
    strTranslateIdx   = 0;
    const total       = toTranslate.length;
    const $btn        = $(this).hide();
    const $counter    = $('<span style="font-size:12px;color:var(--sat-gray-400);margin-left:8px;"></span>');
    $btn.after($counter);

    // Pagination gizle, select-all gizle
    $('#sat-strings-pagination').hide();
    $('#sat-str-select-all').closest('th').css('visibility','hidden');

    $('#sat-strings-log').show().empty();

    function translateNext() {
      if (strTranslateIdx >= toTranslate.length) {
        // Bitti
        isTranslatingStr = false;
        $btn.hide();
        $('#sat-str-select-all').closest('th').css('visibility','');
        strLog('🎉 ' + total + ' string' + (total > 1 ? 's' : '') + ' translated!');
        if (!selectedOnly) {
          $('#sat-strings-all-done').show();
          $('#sat-strings-card').hide();
        } else {
          // Seçili mod: sadece seçilileri çevirdi, tablo kalsın, buton güncelle
          updateTranslateBtn();
          $('#sat-strings-translate-btn').show();
        }
        return;
      }

      const s = toTranslate[strTranslateIdx];
      // Global index: selectedOnly modda s'nin orijinal global idx'i
      const globalIdx = selectedOnly
        ? selectedIdxs[strTranslateIdx]
        : strTranslateIdx;
      const currentPage = Math.floor(globalIdx / STR_LIMIT) + 1;

      if (currentPage !== strPage) {
        renderStrPage(currentPage, langs);
      }

      const $row    = $(`#sat-strings-tbody tr[data-global-idx="${globalIdx}"]`);
      const $rowBtn = $row.find('.sat-str-single').prop('disabled', true).html('<span class="sat-spinner"></span>');

      const pct = Math.round((strTranslateIdx / total) * 100);
      // Progress — buton gizli, log'da göster yeter

      $.post(ajaxurl, {
        action: 'sat_translate_strings', nonce, langs,
        strings: [{name: s.name, string: s.string, group: s.group || ''}]
      }, function(res) {
        if (res.success && res.data.translated > 0) {
          $rowBtn.prop('disabled', true).text('✓').css({'background':'var(--sat-success)','color':'#fff','border':'none'});
          $row.css({'opacity':'0.5','pointer-events':'none'});
          strLog('✅ ' + s.name);
        } else {
          $rowBtn.prop('disabled', false).text('Retry').attr('class','sat-btn sat-btn-danger sat-btn-sm sat-str-single');
          strLog('❌ ' + s.name + ': ' + (res.data?.message || 'Error'));
        }
        strTranslateIdx++;
        // Kısa delay — API rate limit koruması
        setTimeout(translateNext, 50);
      }).fail(function() {
        $rowBtn.prop('disabled', false).text('Retry').attr('class','sat-btn sat-btn-danger sat-btn-sm sat-str-single');
        strLog('❌ ' + s.name + ': AJAX fail');
        strTranslateIdx++;
        setTimeout(translateNext, 100);
      });
    }

    translateNext();
  });

  // Single row translate (normal veya alternatives modu)
  $(document).on('click', '.sat-str-single', function() {
    const $btn   = $(this);
    const $row   = $btn.closest('tr');
    const langs  = getSelectedLangs();
    const name   = $row.data('name');
    const string = $row.data('string');
    const group  = $row.data('group');
    const showAlts = $('#sat-str-show-alts').is(':checked');

    $btn.prop('disabled', true).html('<span class="sat-spinner"></span>');
    $row.find('.sat-str-alts-row').remove();
    $row.next('.sat-str-alts-row').remove();

    if (showAlts) {
      const excludes = {};
      langs.forEach(function(l) {
        const cur = $row.find(`.sat-str-val-td[data-lang="${l}"]`).attr('title') || '';
        excludes[l] = cur ? [cur] : [];
      });

      $.post(ajaxurl, {
        action: 'sat_translate_string_alts', nonce,
        langs, string, group, count: 3, excludes
      }, function(res) {
        $btn.prop('disabled', false).text('Translate Again');
        if (!res.success) { alert(res.data || 'Error'); return; }

        const alts     = res.data.alternatives || {};
        const altRowId = 'alts-' + Date.now();
        const langCount = langs.length;

        let altHtml = `<tr class="sat-str-alts-row" style="background:#f8f7ff;border-top:2px solid var(--sat-primary);">
          <td colspan="2"></td>
          <td style="font-size:11px;color:var(--sat-gray-400);padding:8px 12px;white-space:nowrap;">💡 Alternatives</td>`;

        langs.forEach(function(l) {
          const options = alts[l] || [];
          let cellHtml = `<td style="padding:8px 12px;min-width:150px;">`;
          if (options.length) {
            options.forEach(function(opt, i) {
              cellHtml += `<label style="display:flex;align-items:flex-start;gap:6px;margin-bottom:6px;font-size:12px;cursor:pointer;line-height:1.4;">
                <input type="radio" name="alt-${altRowId}-${l}" value="${opt.replace(/"/g,'&quot;')}" style="margin-top:2px;flex-shrink:0;"${i===0?' checked':''}>
                <span>${opt}</span>
              </label>`;
            });
          } else {
            cellHtml += `<span style="font-size:11px;color:var(--sat-gray-400);">No alternatives</span>`;
          }
          cellHtml += '</td>';
          altHtml  += cellHtml;
        });

        altHtml += `<td style="padding:8px 12px;">
          <button class="sat-btn sat-btn-success sat-btn-sm sat-str-alts-save" data-alt-row-id="${altRowId}" style="white-space:nowrap;margin-bottom:4px;">Save</button>
        </td></tr>`;

        $row.after(altHtml);
      }).fail(function() { $btn.prop('disabled', false).text('Translate'); });

    } else {
      $.post(ajaxurl, { action: 'sat_translate_strings', nonce, langs, strings: [{name, string, group}] }, function(res) {
        if (res.success && res.data.translated > 0) {
          $btn.prop('disabled', false).text('Re-translate');
          (res.data.results || []).forEach(function(r) {
            if (r.success) {
              $row.find(`.sat-str-val-td[data-lang="${r.lang}"]`)
                  .attr('title', r.translation).css('color','var(--sat-success)').text(r.translation);
              const gIdx = parseInt($row.data('global-idx'));
              if (strAllStrings[gIdx]) {
                if (!strAllStrings[gIdx].translations) strAllStrings[gIdx].translations = {};
                strAllStrings[gIdx].translations[r.lang] = r.translation;
              }
            }
          });
        } else {
          $btn.prop('disabled', false).text('Translate');
        }
      }).fail(function() { $btn.prop('disabled', false).text('Translate'); });
    }
  });

  // ── Copy original to all langs (no translation) ───────────────────────────
  $(document).on('click', '.sat-str-copy', function() {
    const $btn   = $(this).prop('disabled', true).html('<span class="sat-spinner"></span>');
    const $row   = $btn.closest('tr');
    const langs  = getSelectedLangs();
    const name   = $row.data('name');
    const string = $row.data('string');
    const group  = $row.data('group');

    if (!langs.length) { $btn.prop('disabled', false).text('= Copy'); alert('Select languages.'); return; }

    // forced_translation = orijinal string — API çağrısı yok
    $.post(ajaxurl, {
      action: 'sat_translate_strings', nonce, langs,
      strings: [{name, string, group, forced_translation: string}]
    }, function(res) {
      if (res.success && res.data.translated > 0) {
        langs.forEach(function(l) {
          $row.find(`.sat-str-val-td[data-lang="${l}"]`)
              .attr('title', string).css('color', 'var(--sat-gray-600)').text(string);
          const gIdx = parseInt($row.data('global-idx'));
          if (strAllStrings[gIdx]) {
            if (!strAllStrings[gIdx].translations) strAllStrings[gIdx].translations = {};
            strAllStrings[gIdx].translations[l] = string;
          }
        });
        $btn.prop('disabled', false).text('Copied ✓').css({'color':'var(--sat-success)'});
        setTimeout(function() { $btn.text('= Copy').css({'color':''}); }, 2000);
      } else {
        $btn.prop('disabled', false).text('= Copy');
      }
    }).fail(function() { $btn.prop('disabled', false).text('= Copy'); });
  });

  // Save selected alternatives
  $(document).on('click', '.sat-str-alts-save', function() {
    const $altsRow = $(this).closest('.sat-str-alts-row');
    const $mainRow = $altsRow.prev('tr');
    const altRowId = $(this).data('alt-row-id');
    const langs    = getSelectedLangs();
    const name     = $mainRow.data('name');
    const string   = $mainRow.data('string');
    const group    = $mainRow.data('group');
    const $btn     = $(this).prop('disabled', true).html('<span class="sat-spinner"></span>');

    const selected = {};
    langs.forEach(function(l) {
      const val = $altsRow.find(`input[name="alt-${altRowId}-${l}"]:checked`).val() || '';
      if (val) selected[l] = val;
    });

    if (!Object.keys(selected).length) { $btn.prop('disabled', false).text('Save'); return; }

    let saved = 0, total = Object.keys(selected).length;
    Object.entries(selected).forEach(function([l, tr]) {
      $.post(ajaxurl, {
        action: 'sat_translate_strings', nonce,
        langs: [l], strings: [{name, string, group, forced_translation: tr}]
      }, function(res) {
        saved++;
        if (res.success) {
          $mainRow.find(`.sat-str-val-td[data-lang="${l}"]`)
                  .attr('title', tr).css('color','var(--sat-success)').text(tr);
          const gIdx = parseInt($mainRow.data('global-idx'));
          if (strAllStrings[gIdx]) {
            if (!strAllStrings[gIdx].translations) strAllStrings[gIdx].translations = {};
            strAllStrings[gIdx].translations[l] = tr;
          }
        }
        if (saved === total) {
          $altsRow.fadeOut(300, function(){ $(this).remove(); });
          $mainRow.find('.sat-str-single').prop('disabled', false).text('Translate Again');
        }
      });
    });
  });

  function strLog(msg) {
    const out = $('#sat-strings-log');
    out.append(`<div>${msg}</div>`);
    out.scrollTop(out[0].scrollHeight);
  }

  // ── String Queue helpers (posts.php ile aynı format) ─────────────────────
  let strQueueNextRunTimer = null;

  function renderStrNextRun(nextRun) {
    const $el = $('#sat-str-q-next-run');
    if (strQueueNextRunTimer) { clearInterval(strQueueNextRunTimer); strQueueNextRunTimer = null; }
    if (!nextRun) { $el.text(''); return; }
    function update() {
      const diff = Math.round(nextRun - (Date.now() / 1000));
      if (diff <= 0)      $el.text('⏳ Starting now...');
      else if (diff < 60) $el.text('⏱ Next run in ' + diff + 's');
      else { const m = Math.floor(diff/60), s = diff%60; $el.text('⏱ Next run in ' + m + 'm ' + s + 's'); }
    }
    update();
    strQueueNextRunTimer = setInterval(update, 1000);
  }

  function pollStrQueueStatus() {
    const interval = setInterval(function() {
      $.post(ajaxurl, { action: 'sat_queue_status', nonce, type: 'string' }, function(res) {
        if (!res.success) return;
        const d = res.data;
        $('#sat-str-q-bar').css('width', d.percent + '%').text(d.percent + '%');
        $('#sat-str-q-count').text(d.done + '/' + d.total);
        renderStrNextRun(d.next_run || null);
        // Driver badge
        if (d.driver) {
          const isAS = d.driver === 'action_scheduler';
          $('#sat-str-q-driver-badge')
            .text(isAS ? 'Action Scheduler' : 'WP Cron')
            .css({'background': isAS ? '#dcfce7' : '#fef9c3', 'color': isAS ? '#16a34a' : '#854d0e'});
        }
        // Error göster + retry butonu
        if (d.error > 0) {
          $('#sat-str-q-errors').show().text('⚠ ' + d.error + ' item(s) failed');
          $('#sat-str-retry-errors').show();
        } else {
          $('#sat-str-q-errors').hide();
          $('#sat-str-retry-errors').hide();
        }
        // Meta (diller, group)
        if (d.langs && d.langs.length) {
          const metaStr = d.langs.map(l => l.toUpperCase()).join(', ')
            + (d.group ? ' · ' + d.group : '')
            + (d.started_at ? ' · Started: ' + d.started_at : '');
          $('#sat-str-q-meta').text(metaStr);
        }
        // Bitti
        if (d.pending === 0 && d.processing === 0) {
          clearInterval(interval);
          if (strQueueNextRunTimer) { clearInterval(strQueueNextRunTimer); strQueueNextRunTimer = null; }
          $('#sat-str-q-next-run').text('');
          $('#sat-str-q-status-text').text('✅ Done!');
          $.post(ajaxurl, { action: 'sat_queue_clear_done', nonce, type: 'string' });
          setTimeout(function() {
            $('#sat-str-queue-card').fadeOut(400, function() { $(this).hide(); });
            // String listesini yenile — dil seçili ve Check Strings yapılmışsa
            if (strCurrentLangs.length) {
              $('#sat-strings-check-btn').trigger('click');
            }
          }, 2000);
        }
      });
    }, 3000);
  }

  $('#sat-str-cancel-queue').on('click', function() {
    $.post(ajaxurl, { action: 'sat_queue_cancel', nonce, type: 'string' }, function() {
      $('#sat-str-queue-card').hide();
    });
  });

  $('#sat-str-retry-errors').on('click', function() {
    $.post(ajaxurl, { action: 'sat_queue_retry', nonce }, function() { pollStrQueueStatus(); });
  });

  // Sayfa açılınca string queue aktifse polling başlat
  <?php if ((($strQStatus['pending'] ?? 0) + ($strQStatus['processing'] ?? 0)) > 0): ?>
  pollStrQueueStatus();
  <?php endif; ?>

  // ── PO FILES — STAGE 1 ──────────────────────────────────────────────────────
  let poCurrentFile = null;
  let po2AllStrings = [], po2Page = 1, po2IsTranslating = false, po2TranslateIdx = 0;
  const PO2_LIMIT = 50;

  $('#sat-po-load-btn').on('click', function() {
    const $btn  = $(this).prop('disabled', true).html('<span class="sat-spinner"></span>');
    const scope = $('#sat-po-scope').val();
    const lang  = $('#sat-po-lang-filter').val();

    $.post(ajaxurl, { action: 'sat_get_po_files', nonce, scope, lang }, function(res) {
      $btn.prop('disabled', false).text('Load PO Files');
      if (!res.success) { alert(res.data || 'Error'); return; }

      const files  = res.data.files || [];
      const $tbody = $('#sat-po-tbody').empty();

      if (!files.length) {
        $tbody.html('<tr><td colspan="7" style="text-align:center;color:var(--sat-gray-400);padding:24px;">No .po files found.</td></tr>');
        $('#sat-po-files-list').show();
        return;
      }

      files.forEach(function(f) {
        const color = f.untranslated > 0 ? 'var(--sat-danger)' : 'var(--sat-success)';
        $tbody.append(`<tr>
          <td style="font-size:12px;font-family:monospace;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${f.path}">${f.filename}</td>
          <td><span class="sat-badge sat-badge-pending" style="font-size:10px;">${f.group}</span></td>
          <td style="font-size:12px;">${f.locale}</td>
          <td style="font-size:12px;text-align:right;">${f.total}</td>
          <td class="sat-po-translated-td" style="font-size:12px;text-align:right;color:var(--sat-success);">${f.translated}</td>
          <td style="font-size:12px;text-align:right;color:${color};">${f.untranslated}${f.untranslated>0?' ⚠️':' ✓'}</td>
          <td>
            <button class="sat-btn sat-btn-primary sat-btn-sm sat-po-open-btn"
              data-path="${f.path.replace(/"/g,'&quot;')}"
              data-filename="${f.filename}"
              data-locale="${f.locale}"
              data-lang="${f.matched_lang||f.locale}">Open →</button>
          </td>
        </tr>`);
      });

      $('#sat-po-files-list').show();
    });
  });

  // Stage 1 → Stage 2
  $(document).on('click', '.sat-po-open-btn', function() {
    poCurrentFile = {
      path:     $(this).data('path'),
      filename: $(this).data('filename'),
      locale:   $(this).data('locale'),
      lang:     $(this).data('lang'),
    };
    $('#sat-po2-filename').text(poCurrentFile.filename);
    $('#sat-po2-locale').text(poCurrentFile.locale);
    $('#sat-po-stage1').hide();
    $('#sat-po-stage2').show();
    po2AllStrings = []; po2Page = 1; po2IsTranslating = false;
    $('#sat-po2-table-wrap').hide();
    $('#sat-po2-all-done').hide();
    $('#sat-po2-translate-all-btn').hide();
    $('#sat-po2-check-btn').trigger('click');
  });

  // Stage 2 → Stage 1
  $('#sat-po-back-btn').on('click', function() {
    $('#sat-po-stage2').hide();
    $('#sat-po-stage1').show();
    po2IsTranslating = false;
  });

  // ── PO FILES — STAGE 2 ──────────────────────────────────────────────────────
  $('#sat-po2-check-btn').on('click', function() {
    if (!poCurrentFile) return;
    const $btn   = $(this).prop('disabled', true).html('<span class="sat-spinner"></span>');
    const skip   = $('#sat-po2-skip').is(':checked') ? 1 : 0;
    const search = $('#sat-po2-search').val().trim();
    po2AllStrings = []; po2Page = 1; po2IsTranslating = false;
    $('#sat-po2-all-done').hide();
    $('#sat-po2-translate-all-btn').hide();

    $.post(ajaxurl, {
      action: 'sat_get_po_strings', nonce,
      path: poCurrentFile.path, page: 1, limit: 9999,
      skip_translated: skip, search,
    }, function(res) {
      $btn.prop('disabled', false).text('Load');
      if (!res.success) { alert(res.data || 'Error'); return; }
      po2AllStrings = res.data.items || [];
      po2RenderPage(1);
    });
  });

  let po2SearchTimer;
  $('#sat-po2-search').on('input', function() {
    clearTimeout(po2SearchTimer);
    po2SearchTimer = setTimeout(function() { $('#sat-po2-check-btn').trigger('click'); }, 500);
  });

  function po2RenderPage(page) {
    po2Page = page;
    const $tbody = $('#sat-po2-tbody').empty();
    $('#sat-po2-all-done').hide();
    $('#sat-po2-table-wrap').hide();

    if (!po2AllStrings.length) {
      $('#sat-po2-all-done').show();
      $('#sat-po2-translate-all-btn').hide();
      return;
    }

    const slice = po2AllStrings.slice((page-1)*PO2_LIMIT, page*PO2_LIMIT);
    $('#sat-po2-count').text('(' + po2AllStrings.length + ' strings)');

    slice.forEach(function(s, localIdx) {
      const gIdx   = (page-1)*PO2_LIMIT + localIdx;
      const trVal  = s.msgstr || '';
      const trColor = trVal ? 'var(--sat-gray-800)' : 'var(--sat-gray-400)';
      const ctxHtml = s.msgctxt ? `<span class="sat-badge sat-badge-pending" style="font-size:10px;">${s.msgctxt}</span>` : '<span style="color:var(--sat-gray-300);">–</span>';

      $tbody.append(`<tr data-gidx="${gIdx}"
          data-msgid="${s.msgid.replace(/"/g,'&quot;').replace(/'/g,'&#39;')}"
          data-msgctxt="${(s.msgctxt||'').replace(/"/g,'&quot;')}"
          data-is-slug="${s.is_slug?'1':'0'}">
        <td><input type="checkbox" class="sat-po2-check"></td>
        <td>${ctxHtml}</td>
        <td style="font-size:12px;max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${s.msgid}">${s.msgid}</td>
        <td class="sat-po2-tr-td" style="font-size:12px;color:${trColor};" title="${trVal}">${trVal||'–'}</td>
        <td>
          <div style="display:flex;gap:4px;">
            <button class="sat-btn sat-btn-primary sat-btn-sm sat-po2-translate-btn" style="white-space:nowrap;">Translate</button>
            <button class="sat-btn sat-btn-secondary sat-btn-sm sat-po2-same-btn" style="white-space:nowrap;" title="No translation needed — same in all languages">Same</button>
          </div>
        </td>
      </tr>`);
    });

    $('#sat-po2-table-wrap').show();

    const totalPages = Math.ceil(po2AllStrings.length / PO2_LIMIT);
    const $pag = $('#sat-po2-pagination').empty();
    if (!po2IsTranslating && totalPages > 1) {
      $pag.append(`<span style="font-size:12px;color:var(--sat-gray-500);">Page ${page} of ${totalPages}</span>`);
      if (page > 1) $pag.append(`<button class="sat-btn sat-btn-secondary sat-btn-sm sat-po2-page" data-page="${page-1}">← Prev</button>`);
      if (page < totalPages) $pag.append(`<button class="sat-btn sat-btn-secondary sat-btn-sm sat-po2-page" data-page="${page+1}">Next →</button>`);
    }

    po2UpdateBtn();
  }

  function po2UpdateBtn() {
    const count = $('.sat-po2-check:checked').length;
    $('#sat-po2-translate-all-btn').show().text(
      count > 0 ? 'Translate Selected (' + count + ')' : 'Translate All (' + po2AllStrings.length + ')'
    );
  }

  $(document).on('click', '.sat-po2-page', function() {
    if (po2IsTranslating) return;
    po2RenderPage(parseInt($(this).data('page')));
  });

  $(document).on('change', '#sat-po2-select-all', function() {
    $('.sat-po2-check').prop('checked', this.checked); po2UpdateBtn();
  });
  $(document).on('change', '.sat-po2-check', function() {
    const t = $('.sat-po2-check').length, c = $('.sat-po2-check:checked').length;
    $('#sat-po2-select-all').prop('checked', t > 0 && c === t).prop('indeterminate', c > 0 && c < t);
    po2UpdateBtn();
  });

  // Stage 2: Single translate
  $(document).on('click', '.sat-po2-translate-btn', function() {
    const $btn    = $(this).prop('disabled', true).html('<span class="sat-spinner"></span>');
    const $row    = $btn.closest('tr');
    const msgid   = $row.data('msgid');
    const msgctxt = $row.data('msgctxt') || '';
    const isSlug  = $row.data('is-slug') === '1';
    const showAlts = $('#sat-po2-show-alts').is(':checked');
    const lang    = poCurrentFile.lang;

    $row.next('.sat-po2-alts-row').remove();

    if (showAlts) {
      const curTr = $row.find('.sat-po2-tr-td').attr('title') || '';
      $.post(ajaxurl, {
        action: 'sat_translate_string_alts', nonce,
        langs: [lang], string: msgid,
        group: isSlug ? 'slug' : (msgctxt || ''),
        count: 3, excludes: curTr ? {[lang]:[curTr]} : {}
      }, function(res) {
        $btn.prop('disabled', false).text('Translate Again');
        if (!res.success) return;
        const alts    = (res.data.alternatives||{})[lang] || [];
        const altId   = 'po2alt-' + Date.now();
        let html = `<tr class="sat-po2-alts-row" style="background:#f8f7ff;border-top:2px solid var(--sat-primary);">
          <td colspan="2"></td><td style="font-size:11px;color:var(--sat-gray-400);padding:8px 12px;">💡 Alternatives</td>
          <td style="padding:8px 12px;">`;
        alts.forEach(function(opt,i) {
          html += `<label style="display:flex;align-items:flex-start;gap:6px;margin-bottom:5px;font-size:12px;cursor:pointer;">
            <input type="radio" name="${altId}" value="${opt.replace(/"/g,'&quot;')}"${i===0?' checked':''} style="margin-top:2px;">
            <span>${opt}</span></label>`;
        });
        html += `</td><td style="padding:8px 12px;">
          <button class="sat-btn sat-btn-success sat-btn-sm sat-po2-alts-save" data-alt-id="${altId}">Save</button>
        </td></tr>`;
        $row.after(html);
      }).fail(function() { $btn.prop('disabled', false).text('Translate'); });
    } else {
      $.post(ajaxurl, {
        action: 'sat_translate_strings', nonce, langs: [lang],
        strings: [{name: msgid, string: msgid, group: msgctxt||''}]
      }, function(res) {
        if (res.success && res.data.translated > 0) {
          const tr = res.data.results[0]?.translation || '';
          $row.find('.sat-po2-tr-td').attr('title', tr).css('color','var(--sat-success)').text(tr);
          const gIdx = parseInt($row.data('gidx'));
          if (po2AllStrings[gIdx]) po2AllStrings[gIdx].msgstr = tr;
          $btn.prop('disabled', false).text('Re-translate');
        } else { $btn.prop('disabled', false).text('Translate'); }
      }).fail(function() { $btn.prop('disabled', false).text('Translate'); });
    }
  });

  // Stage 2: Save alternatives
  $(document).on('click', '.sat-po2-alts-save', function() {
    const $altsRow = $(this).closest('.sat-po2-alts-row');
    const $mainRow = $altsRow.prev('tr');
    const altId    = $(this).data('alt-id');
    const selected = $altsRow.find(`input[name="${altId}"]:checked`).val() || '';
    if (!selected) return;
    const $btn    = $(this).prop('disabled', true).html('<span class="sat-spinner"></span>');

    $.post(ajaxurl, {
      action: 'sat_save_po_string', nonce,
      path: poCurrentFile.path,
      msgid: $mainRow.data('msgid'),
      msgstr: selected,
      msgctxt: $mainRow.data('msgctxt') || '',
      target_lang: poCurrentFile.lang
    }, function(res) {
      if (res.success) {
        $mainRow.find('.sat-po2-tr-td').attr('title', selected).css('color','var(--sat-success)').text(selected);
        const gIdx = parseInt($mainRow.data('gidx'));
        if (po2AllStrings[gIdx]) po2AllStrings[gIdx].msgstr = selected;
        $altsRow.fadeOut(300, function(){ $(this).remove(); });
        $mainRow.find('.sat-po2-translate-btn').prop('disabled', false).text('Translate Again');
      } else { $btn.prop('disabled', false).text('Save'); }
    });
  });

  // Stage 2: Same (no translation)
  $(document).on('click', '.sat-po2-same-btn', function() {
    const $btn  = $(this).prop('disabled', true).html('<span class="sat-spinner"></span>');
    const $row  = $btn.closest('tr');
    const msgid = $row.data('msgid');

    $.post(ajaxurl, {
      action: 'sat_save_po_string', nonce,
      path: poCurrentFile.path, msgid, msgstr: msgid,
      msgctxt: $row.data('msgctxt') || '', target_lang: poCurrentFile.lang
    }, function(res) {
      if (res.success) {
        $row.find('.sat-po2-tr-td').attr('title', msgid).css('color','var(--sat-gray-600)').text(msgid);
        const gIdx = parseInt($row.data('gidx'));
        if (po2AllStrings[gIdx]) po2AllStrings[gIdx].msgstr = msgid;
        $btn.prop('disabled', false).text('Same ✓').css('color','var(--sat-success)');
        setTimeout(function(){ $btn.text('Same').css('color',''); }, 2000);
      } else { $btn.prop('disabled', false).text('Same'); }
    }).fail(function() { $btn.prop('disabled', false).text('Same'); });
  });

  // Stage 2: Translate All / Selected
  $('#sat-po2-translate-all-btn').on('click', function() {
    if (po2IsTranslating) return;
    const selectedIdxs = [];
    $('.sat-po2-check:checked').each(function() {
      const idx = parseInt($(this).closest('tr').data('gidx'));
      if (!isNaN(idx)) selectedIdxs.push(idx);
    });
    const selectedOnly = selectedIdxs.length > 0;
    const toTranslate  = selectedOnly
      ? selectedIdxs.map(function(i){ return {...po2AllStrings[i], _gidx: i}; })
      : po2AllStrings.map(function(s,i){ return {...s, _gidx: i}; });
    if (!toTranslate.length) return;

    po2IsTranslating = true;
    po2TranslateIdx  = 0;
    const total = toTranslate.length;
    const $btn  = $(this).hide();
    const lang  = poCurrentFile.lang;
    $('#sat-po2-log').show().empty();
    $('#sat-po2-pagination').hide();

    function po2Next() {
      if (po2TranslateIdx >= toTranslate.length) {
        po2IsTranslating = false;
        po2Log('🎉 ' + total + ' strings translated!');
        if (!selectedOnly) { $('#sat-po2-all-done').show(); $('#sat-po2-table-wrap').hide(); }
        else { po2UpdateBtn(); $('#sat-po2-translate-all-btn').show(); $('#sat-po2-pagination').show(); }
        return;
      }
      const s    = toTranslate[po2TranslateIdx];
      const gIdx = s._gidx;
      const pg   = Math.floor(gIdx / PO2_LIMIT) + 1;
      if (pg !== po2Page) po2RenderPage(pg);

      const $row    = $(`#sat-po2-tbody tr[data-gidx="${gIdx}"]`);
      const $rowBtn = $row.find('.sat-po2-translate-btn').prop('disabled', true).html('<span class="sat-spinner"></span>');

      $.post(ajaxurl, {
        action: 'sat_translate_strings', nonce, langs: [lang],
        strings: [{name: s.msgid, string: s.msgid, group: s.msgctxt||''}]
      }, function(res) {
        if (res.success && res.data.translated > 0) {
          const tr = res.data.results[0]?.translation || '';
          $row.find('.sat-po2-tr-td').attr('title',tr).css('color','var(--sat-success)').text(tr);
          po2AllStrings[gIdx].msgstr = tr;
          $row.css('opacity','0.5');
          $rowBtn.prop('disabled',true).text('✓').css({'background':'var(--sat-success)','color':'#fff','border':'none'});
          po2Log('✅ ' + s.msgid.substring(0,40));
        } else {
          $rowBtn.prop('disabled',false).text('Translate');
          po2Log('❌ ' + s.msgid.substring(0,40));
        }
        po2TranslateIdx++;
        setTimeout(po2Next, 50);
      }).fail(function(){
        $rowBtn.prop('disabled',false).text('Translate');
        po2Log('❌ AJAX fail');
        po2TranslateIdx++;
        setTimeout(po2Next, 100);
      });
    }
    po2Next();
  });

  $('#sat-po2-show-all-btn').on('click', function() {
    $('#sat-po2-skip').prop('checked', false);
    $('#sat-po2-check-btn').trigger('click');
  });

  function po2Log(msg) {
    const out = $('#sat-po2-log');
    out.append('<div>' + msg + '</div>');
    out.scrollTop(out[0].scrollHeight);
  }

})(jQuery);
</script>
