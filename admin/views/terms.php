<?php 

if (!defined('ABSPATH')) exit;

$default    = $integration ? $integration->getDefaultLanguage() : '';
$taxonomies = get_taxonomies(['public' => true], 'objects');
$excluded   = $settings->get('exclude_taxonomies', []);
$queue      = $container->get('queue');
$tqStatus   = $queue ? $queue->getStatus('term') : [];
$tqMeta     = get_option('sat_queue_meta_term', []);

?>
<div class="sat-wrap">
  <header class="sat-header">
    <div class="sat-logo">A</div>
    <div><h1>Term Translation</h1><p>Translate categories, tags and custom taxonomies</p></div>
  </header>

  <div class="sat-grid-2">
    <div>
      <div class="sat-card">
        <div class="sat-card-title">1. Select Languages</div>
        <div class="sat-lang-grid" id="sat-lang-selector">
          <?php foreach ($languages as $code => $label):
            if ($code === $default) continue; ?>
            <label class="sat-lang-chip">
              <input type="checkbox" name="langs[]" value="<?= esc_attr($code) ?>">
              <?= esc_html($label) ?> <small><?= esc_html($code) ?></small>
            </label>
          <?php endforeach; ?>
        </div>
        <div style="margin-top:10px;">
          <label><input type="checkbox" id="sat-all-langs"> <strong>All languages</strong></label>
        </div>
      </div>

      <div class="sat-card">
        <div class="sat-card-title">2. Select Taxonomies</div>
        <?php foreach ($taxonomies as $tax):
          if (in_array($tax->name, $excluded)) continue;
          if (!$integration || !$integration->isTranslatableTaxonomy($tax->name)) continue; ?>
          <label style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
            <input type="checkbox" name="taxonomies[]" value="<?= esc_attr($tax->name) ?>" checked>
            <?= esc_html($tax->label) ?>
            <?php if (str_starts_with($tax->name, 'pa_')): ?>
              <span style="font-size:10px;padding:1px 6px;background:#ede9fe;color:#7c3aed;border-radius:8px;">WC attribute</span>
            <?php endif; ?>
          </label>
        <?php endforeach; ?>

        <?php if (class_exists('WooCommerce')): ?>
        <?php $translateAttrs = $settings->get('woo', [])['translate_attributes'] ?? 0; ?>
        <?php if (!$translateAttrs): ?>
        <div style="margin-top:10px;font-size:12px;color:#92400e;background:#fef9c3;border:1px solid #fde68a;border-radius:6px;padding:8px 10px;">
          💡 WooCommerce product attribute taxonomies (<code>pa_color</code>, <code>pa_size</code> etc.) are not shown.
          <a href="<?= admin_url('admin.php?page=sat-settings#tab-content') ?>" style="color:#92400e;font-weight:600;text-decoration:underline;">
            Enable in Settings → Content → WooCommerce
          </a>
        </div>
        <?php else: ?>
        <div style="margin-top:10px;font-size:12px;color:#166534;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:6px;padding:8px 10px;">
          ✓ WooCommerce product attributes enabled — <code>pa_*</code> taxonomies are listed above.
        </div>
        <?php endif; ?>
        <?php endif; ?>
      </div>

      <div class="sat-card">
        <div class="sat-card-title">3. Options</div>
        <label style="display:flex;align-items:center;gap:8px;margin-bottom:10px;">
          <input type="checkbox" id="sat-skip-terms" checked>
          Skip already translated terms
        </label>
        <label style="display:flex;align-items:center;gap:8px;">
          <input type="checkbox" id="sat-use-terms-queue">
          Use background queue (recommended for large sites)
        </label>
      </div>

      <!-- Estimated Cost — butonlardan ÖNCE, posts ile aynı yerleşim -->
      <div class="sat-card" id="sat-terms-cost-card" style="display:none;">
        <div class="sat-card-title">Estimated Cost</div>
        <div class="sat-cost-box">
          <div>
            <div class="cost-value" id="sat-terms-cost-val">~$0.0000</div>
            <div class="cost-label" id="sat-terms-cost-meta">Select terms to estimate</div>
          </div>
          <div style="text-align:right;">
            <div style="font-size:13px;color:var(--sat-gray-600);" id="sat-terms-cost-tokens"></div>
          </div>
        </div>
      </div>

      <div style="display:flex;gap:10px;">
        <button class="sat-btn sat-btn-primary" id="sat-check-terms-btn">Check Untranslated</button>
        <button class="sat-btn sat-btn-success" id="sat-translate-main-terms-btn" style="display:none;">Translate All</button>
      </div>
    </div>

    <div>
      <!-- Terms Queue Status card -->
      <div class="sat-card" id="sat-terms-queue-card" style="<?= (($tqStatus['pending'] ?? 0) + ($tqStatus['processing'] ?? 0)) > 0 ? '' : 'display:none' ?>">
        <div class="sat-card-title">
          Queue Status
          <span id="sat-tq-driver-badge" style="font-size:10px;font-weight:500;margin-left:8px;padding:2px 7px;border-radius:10px;background:<?= ($tqStatus['driver'] ?? '') === 'action_scheduler' ? '#dcfce7' : '#fef9c3' ?>;color:<?= ($tqStatus['driver'] ?? '') === 'action_scheduler' ? '#16a34a' : '#854d0e' ?>;"><?= ($tqStatus['driver'] ?? '') === 'action_scheduler' ? 'Action Scheduler' : 'WP Cron' ?></span>
        </div>
        <div id="sat-tq-meta" style="font-size:12px;color:var(--sat-gray-500);margin-bottom:8px;min-height:16px;"><?php
          if (!empty($tqMeta['langs'])) {
            echo esc_html(implode(', ', array_map('strtoupper', $tqMeta['langs'])));
            if (!empty($tqMeta['taxonomies'])) echo ' &middot; ' . esc_html(implode(', ', $tqMeta['taxonomies']));
            if (!empty($tqMeta['started_at'])) echo ' &middot; Started: ' . esc_html($tqMeta['started_at']);
          }
        ?></div>
        <div class="sat-credit-bar">
          <div class="label">
            <span id="sat-tq-status-text">Processing...</span>
            <span id="sat-tq-count"><?= ($tqStatus['done'] ?? 0) ?>/<?= ($tqStatus['total'] ?? 0) ?></span>
          </div>
          <div class="sat-progress">
            <div class="sat-progress-bar" id="sat-tq-bar" style="width:<?= ($tqStatus['percent'] ?? 0) ?>%">
              <?= ($tqStatus['percent'] ?? 0) ?>%
            </div>
          </div>
        </div>
        <div id="sat-tq-next-run" style="margin-top:8px;font-size:12px;color:var(--sat-gray-400);min-height:18px;"></div>
        <div id="sat-tq-errors" style="display:none;margin-top:6px;font-size:12px;color:var(--sat-danger);"></div>
        <div style="display:flex;gap:8px;margin-top:10px;">
          <button class="sat-btn sat-btn-secondary sat-btn-sm" id="sat-tq-cancel">Cancel Queue</button>
          <button class="sat-btn sat-btn-danger sat-btn-sm" id="sat-tq-retry" style="display:none;">Retry Errors</button>
        </div>
      </div>

      <div class="sat-card" id="sat-terms-card" style="display:none;">
        <div class="sat-card-title">Terms <span id="sat-terms-count" style="font-weight:400;color:var(--sat-gray-400);font-size:13px;"></span></div>
        <table class="sat-table">
          <thead>
            <tr>
              <th><input type="checkbox" id="sat-select-all-terms"></th>
              <th>Name</th><th>Taxonomy</th><th>Missing</th><th>Action</th>
            </tr>
          </thead>
          <tbody id="sat-terms-tbody"></tbody>
        </table>
        <div id="sat-terms-pagination" style="margin-top:12px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;"></div>
      </div>

      <!-- All done -->
      <div class="sat-card" id="sat-terms-all-done" style="display:none;">
        <div style="text-align:center;padding:40px 16px;">
          <div style="margin-bottom:16px;">
            <svg width="56" height="56" viewBox="0 0 56 56" fill="none" xmlns="http://www.w3.org/2000/svg">
              <circle cx="28" cy="28" r="28" fill="#22C55E" fill-opacity="0.12"/>
              <circle cx="28" cy="28" r="20" fill="#22C55E"/>
              <path d="M19 28.5L24.5 34L37 22" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
          </div>
          <div style="font-size:16px;font-weight:600;color:var(--sat-gray-800);margin-bottom:6px;">All translations complete!</div>
          <div id="sat-terms-all-done-msg" style="font-size:13px;color:var(--sat-gray-500);"></div>
        </div>
      </div>

      <div class="sat-card" id="sat-term-log-card" style="display:none;">
        <div class="sat-card-title">Log</div>
        <div id="sat-term-log" style="max-height:200px;overflow-y:auto;font-size:12px;font-family:monospace;background:var(--sat-gray-50);padding:12px;border-radius:6px;"></div>
      </div>
    </div>
  </div>
</div>

<script>
(function($){
  const nonce         = (typeof satConfig !== 'undefined' && satConfig.nonce) ? satConfig.nonce : '<?= wp_create_nonce('sat_nonce') ?>';
  const translatedTermIds = new Set();
  let currentTermLangs    = [];
  let totalTermCount      = 0;
  let currentTermPage     = 1;
  let isTranslatingTerms  = false;
  const PAGE_LIMIT        = 50;

  // ── Buton güncelle ──────────────────────────────────────────────────────────
  function updateMainBtn() {
    const $btn     = $('#sat-translate-main-terms-btn');
    const useQueue = $('#sat-use-terms-queue').is(':checked');
    const total    = $('input.sat-term-check').length;

    if (total === 0) { $btn.hide(); return; }

    if (useQueue) {
      $btn.show().text('Queue All for Translation');
    } else {
      const count = $('input.sat-term-check:checked').length;
      $btn.show().text(count > 0 ? 'Translate Selected (' + count + ')' : 'Translate All');
    }
  }

  // ── Background queue checkbox değişince tablo UI'ını güncelle ──────────────
  function applyTermQueueMode() {
    const useQueue = $('#sat-use-terms-queue').is(':checked');
    if (useQueue) {
      // Tüm seçimleri kaldır
      $('input.sat-term-check').prop('checked', false);
      // Thead: 1. kolon (checkbox) ve son kolon (action) gizle
      $('#sat-terms-card thead th:first-child').hide();
      $('#sat-terms-card thead th:last-child').hide();
      // Tbody: her row'da 1. td ve son td gizle
      $('#sat-terms-tbody tr').each(function() {
        $(this).find('td:first-child').hide();
        $(this).find('td:last-child').hide();
      });
    } else {
      // Thead geri getir
      $('#sat-terms-card thead th:first-child').show();
      $('#sat-terms-card thead th:last-child').show();
      // Tbody geri getir
      $('#sat-terms-tbody tr').each(function() {
        $(this).find('td:first-child').show();
        $(this).find('td:last-child').show();
      });
    }
    updateMainBtn();
  }

  $(document).on('change', '#sat-use-terms-queue', applyTermQueueMode);

  // ── Row inline status ───────────────────────────────────────────────────────
  function setTermRowStatus($row, status) {
    const $btn      = $row.find('.sat-translate-term');
    const $checkbox = $row.find('input.sat-term-check');

    if (status === 'pending') {
      $checkbox.prop('checked', false).replaceWith('<span style="color:var(--sat-gray-400);font-size:16px;">–</span>');
      $btn.prop('disabled', true)
          .attr('class', 'sat-btn sat-btn-secondary sat-btn-sm sat-translate-term')
          .css({'cursor':'default'}).text('Queued');
      $row.css({'opacity':'0.7'});
    } else if (status === 'loading') {
      $btn.prop('disabled', true)
          .attr('class', 'sat-btn sat-btn-secondary sat-btn-sm sat-translate-term')
          .html('<span class="sat-spinner"></span>');
    } else if (status === 'done') {
      $checkbox.prop('checked', false).replaceWith('<span style="color:var(--sat-success);font-size:16px;">✓</span>');
      $btn.prop('disabled', true)
          .attr('class', 'sat-btn sat-btn-sm sat-translate-term')
          .css({'background':'var(--sat-success)','color':'#fff','cursor':'default','border':'none'})
          .text('Done');
      $row.css({'opacity':'0.55','pointer-events':'none'});
      updateMainBtn();
    } else if (status === 'error') {
      $btn.prop('disabled', false)
          .attr('class', 'sat-btn sat-btn-danger sat-btn-sm sat-translate-term')
          .text('Retry');
      $row.css({'opacity':'1','pointer-events':''});
    }
  }

  // ── Batch done kontrolü ─────────────────────────────────────────────────────
  function checkTermBatchDone(langs) {
    const $rows      = $('#sat-terms-tbody tr');
    const $doneRows  = $rows.filter(function() { return $(this).find('.sat-translate-term').text().trim() === 'Done'; });
    const $errorRows = $rows.filter(function() { return $(this).find('.sat-translate-term').text().trim() === 'Retry'; });

    if ($doneRows.length + $errorRows.length === $rows.length && $doneRows.length > 0) {
      setTimeout(function() {
        $doneRows.fadeOut(300, function() {
          $(this).remove();
          if ($('#sat-terms-tbody tr').length === 0) {
            fetchNextTermBatch(langs);
          }
        });
      }, 800);
    }
  }

  // ── Sonraki batch ───────────────────────────────────────────────────────────
  function fetchNextTermBatch(langs) {
    const excludeIds = Array.from(translatedTermIds);
    $.post(ajaxurl, {
      action:          'sat_get_untranslated', nonce, type: 'term',
      langs:           langs, lang: langs[0], page: 1, limit: PAGE_LIMIT,
      skip_translated: $('#sat-skip-terms').is(':checked') ? 1 : 0,
      taxonomies:      $('input[name="taxonomies[]"]:checked').map(function(){ return this.value; }).get(),
      exclude_ids:     excludeIds
    }, function(res) {
      if (!res.success || !res.data.items.length) {
        isTranslatingTerms = false;
        $('#sat-translate-main-terms-btn').prop('disabled', false).text('Translate All');
        showTermsAllDone(langs);
        return;
      }
      currentTermPage  = 1;
      totalTermCount   = res.data.total;
      renderTermList(res.data.items, res.data.total, langs, false);
      if (isTranslatingTerms) {
        startTermTranslateAll(langs, false);
      }
    });
  }

  // ── All done ────────────────────────────────────────────────────────────────
  function showTermsAllDone(langs) {
    $('#sat-terms-card').hide();
    $('#sat-translate-main-terms-btn').hide();
    $('#sat-select-all-terms').closest('th').css('visibility', '');
    $('#sat-terms-pagination').show();
    const langLabels = $('input[name="langs[]"]:checked').map(function(){
      return $(this).closest('label').text().trim();
    }).get().join(', ');
    $('#sat-terms-all-done-msg').text('All terms translated for: ' + (langLabels || langs.join(', ')));
    $('#sat-terms-all-done').show();
  }

  // ── All languages toggle ────────────────────────────────────────────────────
  $(document).on('change', '#sat-all-langs', function() {
    $('input[name="langs[]"]').prop('checked', this.checked);
    $('.sat-lang-chip').toggleClass('selected', this.checked);
  });

  // ── Select all ──────────────────────────────────────────────────────────────
  $(document).on('change', '#sat-select-all-terms', function() {
    $('input.sat-term-check').prop('checked', this.checked);
    updateMainBtn();
  });
  $(document).on('change', '.sat-term-check', function() { updateMainBtn(); });

  // ── Check Untranslated ──────────────────────────────────────────────────────
  $('#sat-check-terms-btn').on('click', function() {
    const langs = $('input[name="langs[]"]:checked').map(function(){ return this.value; }).get();
    if (!langs.length) { alert('Select a language.'); return; }
    currentTermLangs = langs;
    translatedTermIds.clear();
    isTranslatingTerms = false;
    $(this).prop('disabled', true).html('<span class="sat-spinner"></span>');

    $.post(ajaxurl, {
      action:          'sat_get_untranslated', nonce, type: 'term',
      langs:           langs, lang: langs[0], page: 1, limit: PAGE_LIMIT,
      skip_translated: $('#sat-skip-terms').is(':checked') ? 1 : 0,
      taxonomies:      $('input[name="taxonomies[]"]:checked').map(function(){ return this.value; }).get()
    }, function(res) {
      $('#sat-check-terms-btn').prop('disabled', false).text('Check Untranslated');
      if (!res.success) return;
      currentTermPage  = 1;
      totalTermCount   = res.data.total;
      renderTermList(res.data.items, res.data.total, langs, true);
      // Cost estimate — term listesi geldikten sonra hesapla
      updateTermsCostEstimate(res.data.items, langs, res.data.total);
    });
  });

  // ── Cost Estimate ────────────────────────────────────────────────────────────
  function updateTermsCostEstimate(items, langs, total) {
    if (!items || !items.length) {
      $('#sat-terms-cost-card').hide();
      return;
    }
    // Sample üzerinden toplamı tahmin et
    const sampleIds = items.map(function(i){ return i.id; });
    $.post(ajaxurl, {
      action: 'sat_estimate_cost', nonce,
      type:   'term', ids: sampleIds, langs: langs
    }, function(res) {
      if (!res.success) return;
      const d = res.data;
      // sample * (total / sample) ile ölçekle
      const scale   = total > sampleIds.length ? (total / sampleIds.length) : 1;
      const cost    = (d.cost_usd * scale * langs.length).toFixed(4);
      const words   = Math.round(d.words * scale);
      const tokens  = Math.round(d.tokens * scale * langs.length);
      $('#sat-terms-cost-val').text('~$' + cost);
      $('#sat-terms-cost-meta').text(total + ' terms × ' + langs.length + ' lang(s) (estimated)');
      $('#sat-terms-cost-tokens').text(words.toLocaleString() + ' words / ' + tokens.toLocaleString() + ' tokens');
      $('#sat-terms-cost-card').show();
    });
  }

  // ── Render list ─────────────────────────────────────────────────────────────
  function renderTermList(items, total, langs, resetDone) {
    if (resetDone) {
      $('#sat-terms-all-done').hide();
      translatedTermIds.clear();
    }

    const tbody = $('#sat-terms-tbody').empty();
    $('#sat-terms-count').text('(' + total + ' total)');

    if (!items.length) {
      showTermsAllDone(langs);
      return;
    }

    $('#sat-terms-card').show();
    // Translate All devam ediyorsa header checkbox ve pagination gizli kalır
    if (!isTranslatingTerms) {
      $('#sat-select-all-terms').closest('th').css('visibility', '');
    }

    items.forEach(function(item) {
      const missingLangs = item.missing_langs || [];
      const missingHtml = missingLangs.length
        ? missingLangs.map(function(l) {
            if (typeof l === 'string') return `<span class="sat-badge sat-badge-error" style="margin-right:3px;">${l}</span>`;
            return l.has_translation
              ? `<span class="sat-badge sat-badge-success" style="margin-right:3px;" title="Will be re-translated">${l.lang} ↺</span>`
              : `<span class="sat-badge sat-badge-error" style="margin-right:3px;">${l.lang}</span>`;
          }).join('')
        : '–';

      tbody.append(`<tr data-id="${item.id}">
        <td><input type="checkbox" class="sat-term-check" value="${item.id}" data-taxonomy="${item.taxonomy}"></td>
        <td>${item.title}</td>
        <td><span class="sat-badge sat-badge-pending">${item.taxonomy}</span></td>
        <td>${missingHtml}</td>
        <td><button class="sat-btn sat-btn-primary sat-btn-sm sat-translate-term" data-id="${item.id}" data-taxonomy="${item.taxonomy}">Translate</button></td>
      </tr>`);
    });

    renderTermPagination(total, langs);
    updateMainBtn();
    // Queue modu aktifse tablo UI'ını güncelle
    applyTermQueueMode();
  }

  // ── Pagination render ───────────────────────────────────────────────────────
  function renderTermPagination(total, langs) {
    const $pag = $('#sat-terms-pagination').empty();
    if (isTranslatingTerms) { $pag.hide(); return; }

    const totalPages = Math.ceil(total / PAGE_LIMIT);
    if (totalPages <= 1) { $pag.hide(); return; }

    $pag.show();
    $pag.append(`<span style="font-size:13px;color:var(--sat-gray-500);">Page ${currentTermPage} of ${totalPages}</span>`);
    if (currentTermPage > 1) {
      $pag.append(`<button class="sat-btn sat-btn-secondary sat-btn-sm sat-term-page-btn" data-page="${currentTermPage - 1}" data-langs='${JSON.stringify(langs)}'>← Prev</button>`);
    }
    if (currentTermPage < totalPages) {
      $pag.append(`<button class="sat-btn sat-btn-secondary sat-btn-sm sat-term-page-btn" data-page="${currentTermPage + 1}" data-langs='${JSON.stringify(langs)}'>Next →</button>`);
    }
  }

  $(document).on('click', '.sat-term-page-btn', function() {
    const page  = parseInt($(this).data('page'));
    const langs = $(this).data('langs');
    currentTermPage = page;
    $('#sat-terms-tbody').empty();
    $('#sat-terms-pagination').empty();
    $('#sat-translate-main-terms-btn').hide();
    // Sayfa geçişlerinde all-done kartını gizle, terms kartını göster
    $('#sat-terms-all-done').hide();
    $('#sat-terms-card').show();

    $.post(ajaxurl, {
      action:          'sat_get_untranslated', nonce, type: 'term',
      langs:           langs, lang: langs[0], page: page, limit: PAGE_LIMIT,
      skip_translated: $('#sat-skip-terms').is(':checked') ? 1 : 0,
      taxonomies:      $('input[name="taxonomies[]"]:checked').map(function(){ return this.value; }).get(),
      exclude_ids:     Array.from(translatedTermIds)
    }, function(res) {
      if (!res.success) return;
      totalTermCount = res.data.total;
      // resetDone=false: translatedTermIds korunur, all-done/terms-card zaten loadTermPage başında set edildi
      renderTermList(res.data.items, res.data.total, langs, false);
    });
  });

  // ── Ana buton ───────────────────────────────────────────────────────────────
  $('#sat-translate-main-terms-btn').on('click', function() {
    const langs      = $('input[name="langs[]"]:checked').map(function(){ return this.value; }).get();
    const useQueue   = $('#sat-use-terms-queue').is(':checked');
    if (!langs.length) { alert('Select a language.'); return; }
    currentTermLangs = langs;

    const selected   = $('input.sat-term-check:checked');
    const isSelected = selected.length > 0;

    if (useQueue && !isSelected) {
      $.post(ajaxurl, {
        action:          'sat_queue_start',
        nonce,
        type:            'term',
        langs:           langs,
        all:             1,
        taxonomies:      $('input[name="taxonomies[]"]:checked').map(function(){ return this.value; }).get(),
        skip_translated: $('#sat-skip-terms').is(':checked') ? 1 : 0,
      }, function(res) {
        if (res.success) {
          $('#sat-terms-queue-card').show();
          renderTqNextRun(res.data.status && res.data.status.next_run ? res.data.status.next_run : null);
          if (res.data.driver) {
            const isAS = res.data.driver === 'action_scheduler';
            $('#sat-tq-driver-badge')
              .text(isAS ? 'Action Scheduler' : 'WP Cron')
              .css({'background': isAS ? '#dcfce7':'#fef9c3', 'color': isAS ? '#16a34a':'#854d0e'});
          }
          pollTermQueueStatus();
        } else log('❌ Queue error: ' + res.data);
      });
      return;
    }

    isTranslatingTerms = !isSelected;
    startTermTranslateAll(langs, isSelected);
  });

  function startTermTranslateAll(langs, selectedOnly) {
    const $targets = selectedOnly ? $('input.sat-term-check:checked') : $('input.sat-term-check');
    const ids = $targets.map(function(){ return $(this).val(); }).get();
    if (!ids.length) return;

    // Header checkbox ve pagination gizle
    $('#sat-select-all-terms').closest('th').css('visibility', 'hidden');
    $('#sat-terms-pagination').hide();

    ids.forEach(function(id) {
      setTermRowStatus($('#sat-terms-tbody tr[data-id="' + id + '"]'), 'pending');
    });

    const $btn = $('#sat-translate-main-terms-btn').prop('disabled', true).html('<span class="sat-spinner"></span>');
    const queue = [];
    ids.forEach(function(id) {
      const tax = $('#sat-terms-tbody tr[data-id="' + id + '"]').find('.sat-translate-term').data('taxonomy')
               || $('input.sat-term-check[value="' + id + '"]').data('taxonomy');
      langs.forEach(function(lang) { queue.push({ id, lang, tax }); });
    });

    let i = 0;
    function next() {
      if (i >= queue.length) {
        if (!isTranslatingTerms) {
          $btn.prop('disabled', false).text('Translate All');
          updateMainBtn();
        }
        return;
      }
      const { id, lang, tax } = queue[i++];
      const $row = $('#sat-terms-tbody tr[data-id="' + id + '"]');
      setTermRowStatus($row, 'loading');

      $.post(ajaxurl, { action: 'sat_translate_term', nonce, term_id: id, taxonomy: tax, lang: lang }, function(res) {
        if (res.success) {
          const doneForTerm = queue.slice(0, i).filter(q => q.id === id).length;
          if (doneForTerm === langs.length) {
            translatedTermIds.add(parseInt(id));
            setTermRowStatus($row, 'done');
            checkTermBatchDone(langs);
          }
        } else {
          setTermRowStatus($row, 'error');
        }
        next();
      });
    }
    next();
  }

  // ── Tek term translate ──────────────────────────────────────────────────────
  $(document).on('click', '.sat-translate-term', function() {
    const btn  = $(this), id = btn.data('id'), tax = btn.data('taxonomy');
    const langs = $('input[name="langs[]"]:checked').map(function(){ return this.value; }).get();
    if (!langs.length) { alert('Select a language.'); return; }

    const $row = btn.closest('tr');
    setTermRowStatus($row, 'loading');

    let i = 0, hasError = false;
    function nextLang() {
      if (i >= langs.length) {
        if (!hasError) {
          translatedTermIds.add(parseInt(id));
          setTermRowStatus($row, 'done');
          checkTermBatchDone(langs);
        } else {
          setTermRowStatus($row, 'error');
        }
        return;
      }
      const lang = langs[i++];
      $.post(ajaxurl, { action: 'sat_translate_term', nonce, term_id: id, taxonomy: tax, lang: lang }, function(res) {
        if (!res.success) hasError = true;
        nextLang();
      });
    }
    nextLang();
  });

  function log(msg) {
    $('#sat-term-log-card').show();
    const out = $('#sat-term-log');
    out.append('<div>' + msg + '</div>');
    out.scrollTop(out[0].scrollHeight);
  }

  // ── Terms Queue helpers (posts.php ile aynı format) ───────────────────────
  let tqNextRunTimer = null;

  function renderTqNextRun(nextRun) {
    const $el = $('#sat-tq-next-run');
    if (tqNextRunTimer) { clearInterval(tqNextRunTimer); tqNextRunTimer = null; }
    if (!nextRun) { $el.text(''); return; }
    function update() {
      const diff = Math.round(nextRun - (Date.now() / 1000));
      if (diff <= 0)      $el.text('⏳ Starting now...');
      else if (diff < 60) $el.text('⏱ Next run in ' + diff + 's');
      else { const m = Math.floor(diff/60), s = diff%60; $el.text('⏱ Next run in ' + m + 'm ' + s + 's'); }
    }
    update();
    tqNextRunTimer = setInterval(update, 1000);
  }

  function pollTermQueueStatus() {
    const interval = setInterval(function() {
      $.post(ajaxurl, { action: 'sat_queue_status', nonce, type: 'term' }, function(res) {
        if (!res.success) return;
        const d = res.data;
        $('#sat-tq-bar').css('width', d.percent + '%').text(d.percent + '%');
        $('#sat-tq-count').text(d.done + '/' + d.total);
        renderTqNextRun(d.next_run || null);
        // Driver badge
        if (d.driver) {
          const isAS = d.driver === 'action_scheduler';
          $('#sat-tq-driver-badge')
            .text(isAS ? 'Action Scheduler' : 'WP Cron')
            .css({'background': isAS ? '#dcfce7':'#fef9c3', 'color': isAS ? '#16a34a':'#854d0e'});
        }
        // Meta
        if (d.langs && d.langs.length) {
          const metaStr = d.langs.map(l => l.toUpperCase()).join(', ')
            + (d.taxonomies && d.taxonomies.length ? ' · ' + d.taxonomies.join(', ') : '')
            + (d.started_at ? ' · Started: ' + d.started_at : '');
          $('#sat-tq-meta').text(metaStr);
        }
        // Errors
        if (d.error > 0) {
          $('#sat-tq-errors').show().text('⚠ ' + d.error + ' item(s) failed');
          $('#sat-tq-retry').show();
        } else {
          $('#sat-tq-errors').hide();
          $('#sat-tq-retry').hide();
        }
        // Bitti
        if (d.pending === 0 && d.processing === 0) {
          clearInterval(interval);
          if (tqNextRunTimer) { clearInterval(tqNextRunTimer); tqNextRunTimer = null; }
          $('#sat-tq-next-run').text('');
          $('#sat-tq-status-text').text('✅ Done!');
          $.post(ajaxurl, { action: 'sat_queue_clear_done', nonce, type: 'term' });
          setTimeout(function() {
            $('#sat-terms-queue-card').fadeOut(400);
            const langs = $('input[name="langs[]"]:checked').map(function(){ return this.value; }).get();
            if (langs.length) $('#sat-check-terms-btn').trigger('click');
          }, 2000);
        }
      });
    }, 3000);
  }

  $('#sat-tq-cancel').on('click', function() {
    $.post(ajaxurl, { action: 'sat_queue_cancel', nonce, type: 'term' }, function() {
      $('#sat-terms-queue-card').hide();
    });
  });

  $('#sat-tq-retry').on('click', function() {
    $.post(ajaxurl, { action: 'sat_queue_retry', nonce }, function() { pollTermQueueStatus(); });
  });

  // Sayfa açılınca term queue aktifse polling başlat
  <?php if ((($tqStatus['pending'] ?? 0) + ($tqStatus['processing'] ?? 0)) > 0): ?>
  pollTermQueueStatus();
  <?php endif; ?>
})(jQuery);
</script>
