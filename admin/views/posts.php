<?php 

if (!defined('ABSPATH')) exit;

$queue     = $container->get('queue');
$qStatus   = $queue ? $queue->getStatus('post') : [];
$postTypes = get_post_types(['public' => true], 'objects');
$excluded  = $settings->get('exclude_post_types', []);
$default   = $integration ? $integration->getDefaultLanguage() : '';

?>
<div class="sat-wrap">

  <header class="sat-header">
    <div class="sat-logo">A</div>
    <div><h1>Post Translation</h1><p>Translate posts, pages and custom post types</p></div>
  </header>

  <div class="sat-grid-2">

    <!-- Left: Controls -->
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
        <div class="sat-card-title">2. Select Post Types</div>
        <?php foreach ($postTypes as $type):
          if (in_array($type->name, $excluded)) continue;
          if (!$integration || !$integration->isTranslatablePostType($type->name)) continue; ?>
          <label style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
            <input type="checkbox" name="post_types[]" value="<?= esc_attr($type->name) ?>" checked>
            <?= esc_html($type->label) ?>
          </label>
        <?php endforeach; ?>
      </div>

      <div class="sat-card">
        <div class="sat-card-title">3. Options</div>
        <label style="display:flex;align-items:center;gap:8px;margin-bottom:10px;">
          <input type="checkbox" id="sat-skip-translated" checked>
          Skip already translated posts
        </label>
        <label style="display:flex;align-items:center;gap:8px;">
          <input type="checkbox" id="sat-use-queue">
          Use background queue (recommended for large sites)
        </label>
      </div>

      <!-- Cost estimate -->
      <div class="sat-card" id="sat-cost-card" style="display:none;">
        <div class="sat-card-title">Estimated Cost</div>
        <div class="sat-cost-box">
          <div>
            <div class="cost-value" id="sat-cost-value">$0.00</div>
            <div class="cost-label" id="sat-cost-detail">Select posts to estimate</div>
          </div>
          <div style="text-align:right;">
            <div style="font-size:13px;color:var(--sat-gray-600);" id="sat-cost-words">0 words</div>
            <div style="font-size:11px;color:var(--sat-gray-400);" id="sat-cost-tokens">0 tokens</div>
          </div>
        </div>
      </div>

      <div style="display:flex;gap:10px;flex-wrap:wrap;">
        <button class="sat-btn sat-btn-primary" id="sat-check-btn">Check Untranslated</button>
        <button class="sat-btn sat-btn-success" id="sat-translate-main-btn" style="display:none;">Translate All</button>
      </div>
    </div>

    <!-- Right: Results -->
    <div>
      <!-- Queue status -->
      <div class="sat-card" id="sat-queue-card" style="<?= (($qStatus['pending'] ?? 0) + ($qStatus['processing'] ?? 0)) > 0 ? '' : 'display:none' ?>">
        <div class="sat-card-title">
          Queue Status
          <span id="q-driver-badge" style="font-size:10px;font-weight:500;margin-left:8px;padding:2px 7px;border-radius:10px;background:<?= ($qStatus['driver'] ?? '') === 'action_scheduler' ? '#dcfce7' : '#fef9c3' ?>;color:<?= ($qStatus['driver'] ?? '') === 'action_scheduler' ? '#16a34a' : '#854d0e' ?>;"><?= ($qStatus['driver'] ?? '') === 'action_scheduler' ? 'Action Scheduler' : 'WP Cron' ?></span>
        </div>
        <div id="q-meta" style="font-size:12px;color:var(--sat-gray-500);margin-bottom:8px;min-height:16px;"><?php
          $qMeta = get_option('sat_queue_meta_post', []);
          if (!empty($qMeta['langs'])) {
            echo esc_html(implode(', ', array_map('strtoupper', $qMeta['langs'])));
            if (!empty($qMeta['post_types'])) echo ' &middot; ' . esc_html(implode(', ', $qMeta['post_types']));
            if (!empty($qMeta['started_at'])) echo ' &middot; Started: ' . esc_html($qMeta['started_at']);
          }
        ?></div>
        <div class="sat-credit-bar">
          <div class="label">
            <span id="q-status-text">Processing...</span>
            <span id="q-count"><?= ($qStatus['done'] ?? 0) ?>/<?= ($qStatus['total'] ?? 0) ?></span>
          </div>
          <div class="sat-progress">
            <div class="sat-progress-bar" id="q-bar" style="width:<?= ($qStatus['percent'] ?? 0) ?>%">
              <?= ($qStatus['percent'] ?? 0) ?>%
            </div>
          </div>
        </div>
        <div id="q-next-run" style="margin-top:8px;font-size:12px;color:var(--sat-gray-400);min-height:18px;"></div>
        <div id="q-errors" style="display:none;margin-top:6px;font-size:12px;color:var(--sat-danger);"></div>
        <div style="display:flex;gap:8px;margin-top:10px;">
          <button class="sat-btn sat-btn-secondary sat-btn-sm" id="sat-cancel-queue">Cancel Queue</button>
          <button class="sat-btn sat-btn-danger sat-btn-sm" id="sat-retry-errors" style="display:none;">Retry Errors</button>
        </div>
      </div>

      <!-- Post list -->
      <div class="sat-card" id="sat-posts-card" style="display:none;">
        <div class="sat-card-title">
          Posts
          <span id="sat-posts-count" style="font-weight:400;color:var(--sat-gray-400);font-size:13px;"></span>
        </div>
        <div style="overflow-x:auto;">
          <table class="sat-table">
            <thead>
              <tr>
                <th><input type="checkbox" id="sat-select-all-posts"></th>
                <th>Title</th><th>Type</th><th>Missing</th><th>Terms</th><th>Action</th>
              </tr>
            </thead>
            <tbody id="sat-posts-tbody"></tbody>
          </table>
        </div>
        <!-- Pagination -->
        <div id="sat-posts-pagination" style="margin-top:12px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;"></div>
      </div>

      <!-- All done -->
      <div class="sat-card" id="sat-posts-all-done" style="display:none;">
        <div style="text-align:center;padding:40px 16px;">
          <div style="margin-bottom:16px;">
            <svg width="56" height="56" viewBox="0 0 56 56" fill="none" xmlns="http://www.w3.org/2000/svg">
              <circle cx="28" cy="28" r="28" fill="#22C55E" fill-opacity="0.12"/>
              <circle cx="28" cy="28" r="20" fill="#22C55E"/>
              <path d="M19 28.5L24.5 34L37 22" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
          </div>
          <div style="font-size:16px;font-weight:600;color:var(--sat-gray-800);margin-bottom:6px;">All translations complete!</div>
          <div id="sat-posts-all-done-msg" style="font-size:13px;color:var(--sat-gray-500);"></div>
        </div>
      </div>
    </div>

  </div>
</div>

<script>
(function($){
  const nonce    = (typeof satConfig !== 'undefined' && satConfig.nonce)    ? satConfig.nonce    : '<?= wp_create_nonce('sat_nonce') ?>';
  const adminUrl = (typeof satConfig !== 'undefined' && satConfig.adminUrl) ? satConfig.adminUrl : '';

  // Tüm çevrilen ID'leri tut (sayfalama için)
  const translatedIds = new Set();
  let currentLangs    = [];
  let totalPostCount  = 0;
  let currentPage     = 1;
  let isTranslating   = false;  // Translate All devam ediyor mu?
  let activeTranslateLangs = [];
  const PAGE_LIMIT    = 50;

  // ── Buton: seçim yoksa "Translate All", varsa "Translate Selected (x)" ──
  // Background queue modunda buton metni ve tablo UI'ı farklılaşır
  function updateMainBtn() {
    const $btn     = $('#sat-translate-main-btn');
    const useQueue = $('#sat-use-queue').is(':checked');
    const total    = $('input.sat-post-check').length;

    if (total === 0) { $btn.hide(); return; }

    if (useQueue) {
      $btn.show().text('Queue All for Translation');
    } else {
      const count = $('input.sat-post-check:checked').length;
      $btn.show().text(count > 0 ? 'Translate Selected (' + count + ')' : 'Translate All');
    }
  }

  // ── Background queue checkbox değişince tablo UI'ını güncelle ──────────────
  function applyQueueMode() {
    const useQueue = $('#sat-use-queue').is(':checked');
    if (useQueue) {
      // Tüm seçimleri kaldır
      $('input.sat-post-check').prop('checked', false);
      // Thead: 1. kolon (checkbox) ve son kolon (action) gizle
      $('#sat-posts-card thead th:first-child').hide();
      $('#sat-posts-card thead th:last-child').hide();
      // Tbody: her row'da 1. td (checkbox) ve son td (action) gizle
      $('#sat-posts-tbody tr').each(function() {
        $(this).find('td:first-child').hide();
        $(this).find('td:last-child').hide();
      });
      // Select all satırı gizle
      $('#sat-posts-card .sat-card-title label').hide();
    } else {
      // Thead geri getir
      $('#sat-posts-card thead th:first-child').show();
      $('#sat-posts-card thead th:last-child').show();
      // Tbody geri getir
      $('#sat-posts-tbody tr').each(function() {
        $(this).find('td:first-child').show();
        $(this).find('td:last-child').show();
      });
      $('#sat-posts-card .sat-card-title label').show();
    }
    updateMainBtn();
  }

  $(document).on('change', '#sat-use-queue', applyQueueMode);

  // ── Row inline status güncelle ──────────────────────────────────────────────
  function setRowStatus($row, status) {
    const $btn      = $row.find('.sat-translate-single');
    const $checkbox = $row.find('input.sat-post-check');

    if (status === 'pending') {
      // Checkbox → – ikonu (işlem bekliyor)
      $checkbox.prop('checked', false).replaceWith('<span style="color:var(--sat-gray-400);font-size:16px;">–</span>');
      $btn.prop('disabled', true)
          .attr('class', 'sat-btn sat-btn-secondary sat-btn-sm sat-translate-single')
          .css({'cursor':'default'})
          .text('Queued');
      $row.css({'opacity':'0.7'});
    } else if (status === 'loading') {
      $btn.prop('disabled', true)
          .attr('class', 'sat-btn sat-btn-secondary sat-btn-sm sat-translate-single')
          .html('<span class="sat-spinner"></span>');
    } else if (status === 'done') {
      // Checkbox → ✓, row soluklaşır, buton Done olur
      $checkbox.prop('checked', false).replaceWith('<span style="color:var(--sat-success);font-size:16px;">✓</span>');
      $btn.prop('disabled', true)
          .attr('class', 'sat-btn sat-btn-sm sat-translate-single')
          .css({'background':'var(--sat-success)','color':'#fff','cursor':'default','border':'none'})
          .text('Done');
      $row.css({'opacity':'0.55','pointer-events':'none'});
      updateMainBtn(); // Checkbox kaldırıldı, sayı güncellensin
    } else if (status === 'error') {
      $btn.prop('disabled', false)
          .attr('class', 'sat-btn sat-btn-danger sat-btn-sm sat-translate-single')
          .text('Retry');
      $row.css({'opacity':'1','pointer-events':''});
    }
  }

  // ── Tüm done row'ları kaldır, eğer hepsi done/error ise sonraki batch'i çek ──
  function checkBatchDone(langs) {
    const $rows     = $('#sat-posts-tbody tr');
    const $doneRows = $rows.filter(function() {
      return $(this).find('.sat-translate-single').text().trim() === 'Done';
    });
    const $errorRows = $rows.filter(function() {
      return $(this).find('.sat-translate-single').text().trim() === 'Retry';
    });

    // Hepsi Done veya Error ise
    if ($doneRows.length + $errorRows.length === $rows.length && $doneRows.length > 0) {
      setTimeout(function() {
        $doneRows.fadeOut(300, function() {
          $(this).remove();
          // Hâlâ error var mı?
          const remaining = $('#sat-posts-tbody tr').length;
          if (remaining === 0) {
            fetchNextBatch(langs);
          }
        });
      }, 800);
    }
  }

  // ── Pagination render ───────────────────────────────────────────────────────
  function renderPagination(total, langs) {
    const $pag = $('#sat-posts-pagination').empty();
    // Translate All devam ediyorsa pagination gösterme
    if (isTranslating) { $pag.hide(); return; }

    const totalPages = Math.ceil(total / PAGE_LIMIT);
    if (totalPages <= 1) { $pag.hide(); return; }

    $pag.show();
    $pag.append(`<span style="font-size:13px;color:var(--sat-gray-500);">Page ${currentPage} of ${totalPages}</span>`);

    if (currentPage > 1) {
      $pag.append(`<button class="sat-btn sat-btn-secondary sat-btn-sm sat-page-btn" data-page="${currentPage - 1}" data-langs='${JSON.stringify(langs)}'>← Prev</button>`);
    }
    if (currentPage < totalPages) {
      $pag.append(`<button class="sat-btn sat-btn-secondary sat-btn-sm sat-page-btn" data-page="${currentPage + 1}" data-langs='${JSON.stringify(langs)}'>Next →</button>`);
    }
  }

  $(document).on('click', '.sat-page-btn', function() {
    const page  = parseInt($(this).data('page'));
    const langs = $(this).data('langs');
    currentPage = page;
    loadPage(page, langs);
  });

  function loadPage(page, langs) {
    $('#sat-posts-tbody').empty();
    $('#sat-posts-pagination').empty();
    $('#sat-translate-main-btn').hide();
    // Sayfa geçişlerinde all-done kartını gizle, posts kartını göster
    $('#sat-posts-all-done').hide();
    $('#sat-posts-card').show();

    $.post(ajaxurl, {
      action:          'sat_get_untranslated', nonce, type: 'post',
      langs:           langs, lang: langs[0], page: page, limit: PAGE_LIMIT,
      post_types:      $('input[name="post_types[]"]:checked').map(function(){ return this.value; }).get(),
      skip_translated: $('#sat-skip-translated').is(':checked') ? 1 : 0,
      exclude_ids:     Array.from(translatedIds)
    }, function(res) {
      if (!res.success) return;
      totalPostCount = res.data.total;
      // resetDone=false: translatedIds korunur, all-done/posts-card zaten loadPage başında set edildi
      renderPostList(res.data.items, res.data.total, langs, false);
    });
  }

  // ── Sonraki batch'i çek (translate all için) ───────────────────────────────
  function fetchNextBatch(langs) {
    const remaining = $('#sat-posts-tbody tr').length;
    if (remaining > 0) return;

    const excludeIds = Array.from(translatedIds);

    $.post(ajaxurl, {
      action:          'sat_get_untranslated', nonce, type: 'post',
      langs:           langs, lang: langs[0], page: 1, limit: PAGE_LIMIT,
      post_types:      $('input[name="post_types[]"]:checked').map(function(){ return this.value; }).get(),
      skip_translated: $('#sat-skip-translated').is(':checked') ? 1 : 0,
      exclude_ids:     excludeIds
    }, function(res) {
      if (!res.success || !res.data.items.length) {
        isTranslating = false;
        $('#sat-translate-main-btn').prop('disabled', false).text('Translate All');
        showAllDone(langs);
        return;
      }
      currentPage    = 1;
      totalPostCount = res.data.total;
      renderPostList(res.data.items, res.data.total, langs, false);

      // Çeviri devam ediyorsa yeni gelen sayfayı da çevir
      if (isTranslating) {
        startTranslateAll(langs, false);
      }
    });
  }

  // ── All done göster ─────────────────────────────────────────────────────────
  function showAllDone(langs) {
    $('#sat-posts-card').hide();
    $('#sat-translate-main-btn').hide();
    // Header checkbox'ı ve pagination'ı geri getir
    $('#sat-select-all-posts').closest('th').css('visibility', '');
    $('#sat-posts-pagination').show();
    const langLabels = $('input[name="langs[]"]:checked').map(function(){
      return $(this).closest('label').text().trim();
    }).get().join(', ');
    $('#sat-posts-all-done-msg').text('All posts translated for: ' + (langLabels || langs.join(', ')));
    $('#sat-posts-all-done').show();
  }

  // ── Check Untranslated ──────────────────────────────────────────────────────
  $('#sat-check-btn').on('click', function() {
    const langs = $('input[name="langs[]"]:checked').map(function(){ return this.value; }).get();
    if (!langs.length) { alert('Please select at least one language.'); return; }
    currentLangs = langs;
    translatedIds.clear();
    $(this).prop('disabled', true).html('<span class="sat-spinner"></span> Checking...');

    $.post(ajaxurl, {
      action: 'sat_get_untranslated', nonce, type: 'post',
      langs: langs, lang: langs[0], page: 1, limit: PAGE_LIMIT,
      post_types: $('input[name="post_types[]"]:checked').map(function(){ return this.value; }).get(),
      skip_translated: $('#sat-skip-translated').is(':checked') ? 1 : 0
    }, function(res) {
      $('#sat-check-btn').prop('disabled', false).text('Check Untranslated');
      if (!res.success) return;
      currentPage = 1;
      totalPostCount = res.data.total;
      renderPostList(res.data.items, res.data.total, langs, true);
      estimateCostScaled(res.data.items.map(i => i.id), langs, res.data.total);
    });
  });

  $('#sat-all-langs').on('change', function() {
    $('input[name="langs[]"]').prop('checked', this.checked);
  });

  // Sayfa açılışında queue card gösteriliyorsa durumu kontrol et
  <?php if ((($qStatus['pending'] ?? 0) + ($qStatus['processing'] ?? 0)) > 0): ?>
  (function checkInitialQueue() {
    $.post(ajaxurl, { action: 'sat_queue_status', nonce, type: 'post' }, function(res) {
      if (!res.success) return;
      const d = res.data;
      if (d.total === 0 || (d.pending === 0 && d.processing === 0 && d.done === d.total)) {
        // Queue zaten bitmiş — card'ı gizle
        $('#sat-queue-card').hide();
      } else if (d.pending > 0 || d.processing > 0) {
        // Hâlâ devam ediyor — next_run göster ve polling başlat
        renderNextRun(d.next_run || null);
        pollQueueStatus();
      }
    });
  })();
  <?php endif; ?>

  $(document).on('change', '#sat-select-all-posts', function() {
    $('input.sat-post-check').prop('checked', this.checked);
    updateMainBtn();
  });
  $(document).on('change', 'input.sat-post-check', updateMainBtn);

  // ── Render list ─────────────────────────────────────────────────────────────
  function renderPostList(items, total, langs, resetDone) {
    langs = langs || [];
    if (resetDone) {
      $('#sat-posts-all-done').hide();
      translatedIds.clear();
    }

    const tbody = $('#sat-posts-tbody').empty();
    $('#sat-posts-count').text('(' + total + ' total)');

    if (!items.length) {
      showAllDone(langs);
      return;
    }

    $('#sat-posts-card').show();
    // Translate All modunda değilse header checkbox'ı göster
    if (!isTranslating) {
      $('#sat-select-all-posts').closest('th').css('visibility', '');
    }

    items.forEach(function(item) {
      const missingLangs = item.missing_langs || [];
      const missingTerms = item.missing_terms || [];
      const missingHtml = missingLangs.length
        ? missingLangs.map(function(l) {
            const lang = (typeof l === 'object' && l.lang) ? l.lang : l;
            const hasTr = (typeof l === 'object') ? l.has_translation : false;
            return hasTr
              ? `<span class="sat-badge sat-badge-success" style="margin-right:3px;" title="Will be re-translated">${lang} ↺</span>`
              : `<span class="sat-badge sat-badge-error" style="margin-right:3px;">${lang}</span>`;
          }).join('')
        : '<span style="color:var(--sat-gray-400);font-size:11px;">–</span>';
      const termsHtml = missingTerms.length
        ? `<span style="font-size:11px;color:var(--sat-gray-500);" title="Will be auto-translated">⚠️ ${missingTerms.length}</span>`
        : '<span style="color:var(--sat-gray-300);font-size:11px;">✓</span>';

      tbody.append(`<tr data-id="${item.id}">
        <td><input type="checkbox" class="sat-post-check" value="${item.id}" data-title="${item.title}"></td>
        <td>${item.title||'(no title)'}</td>
        <td><span class="sat-badge sat-badge-pending">${item.type}</span></td>
        <td>${missingHtml}</td>
        <td>${termsHtml}</td>
        <td><button class="sat-btn sat-btn-primary sat-btn-sm sat-translate-single" data-id="${item.id}">Translate</button></td>
      </tr>`);
    });

    // Pagination
    renderPagination(total, langs);
    updateMainBtn();
    // Queue modu aktifse tablo UI'ını güncelle
    applyQueueMode();
  }

  // ── Ana buton ───────────────────────────────────────────────────────────────
  $('#sat-translate-main-btn').on('click', function() {
    const langs    = $('input[name="langs[]"]:checked').map(function(){ return this.value; }).get();
    const useQueue = $('#sat-use-queue').is(':checked');
    if (!langs.length) { alert('Select a language first.'); return; }
    currentLangs = langs;

    const selected   = $('input.sat-post-check:checked');
    const isSelected = selected.length > 0;

    if (useQueue && !isSelected) {
      $.post(ajaxurl, {
        action:          'sat_queue_start',
        nonce,
        type:            'post',
        langs:           langs,
        all:             1,
        post_types:      $('input[name="post_types[]"]:checked').map(function(){ return this.value; }).get(),
        skip_translated: $('#sat-skip-translated').is(':checked') ? 1 : 0,
      }, function(res) {
        if (res.success) {
          $('#sat-queue-card').show();
          renderNextRun(res.data.status && res.data.status.next_run ? res.data.status.next_run : null);
          pollQueueStatus();
        }
      });
      return;
    }

    // Seçili post'lar + queue aktifse → seçilileri queue'ya ekle
    if (useQueue && isSelected) {
      const ids = selected.map(function(){ return $(this).val(); }).get();
      $.post(ajaxurl, { action: 'sat_queue_start', nonce, type: 'post', langs: langs, ids: ids }, function(res) {
        if (res.success) {
          $('#sat-queue-card').show();
          renderNextRun(res.data.status && res.data.status.next_run ? res.data.status.next_run : null);
          pollQueueStatus();
          // Seçili row'ları "queued" göster
          ids.forEach(function(id) {
            setRowStatus($('#sat-posts-tbody tr[data-id="' + id + '"]'), 'pending');
          });
          selected.prop('checked', false);
          updateMainBtn();
        }
      });
      return;
    }

    isTranslating = !isSelected; // Translate All modunda true, Translate Selected'da false
    activeTranslateLangs = langs;
    startTranslateAll(langs, isSelected);
  });

  function startTranslateAll(langs, selectedOnly) {
    const $targets = selectedOnly
      ? $('input.sat-post-check:checked')
      : $('input.sat-post-check');
    const ids = $targets.map(function(){ return $(this).val(); }).get();
    if (!ids.length) return;

    // Header checkbox'ı gizle, pagination'ı gizle
    $('#sat-select-all-posts').closest('th').css('visibility', 'hidden');
    $('#sat-posts-pagination').hide();

    // Tüm row'ları "queued" yap
    ids.forEach(function(id) {
      setRowStatus($('#sat-posts-tbody tr[data-id="' + id + '"]'), 'pending');
    });

    const $btn = $('#sat-translate-main-btn').prop('disabled', true).html('<span class="sat-spinner"></span>');
    const queue = [];
    ids.forEach(function(id) { langs.forEach(function(lang) { queue.push({ id, lang }); }); });

    let i = 0;
    function next() {
      if (i >= queue.length) {
        if (!isTranslating) {
          $btn.prop('disabled', false).text('Translate All');
          updateMainBtn();
        }
        // isTranslating ise fetchNextBatch checkBatchDone üzerinden çağrılır
        return;
      }
      const { id, lang } = queue[i++];
      const $row = $('#sat-posts-tbody tr[data-id="' + id + '"]');

      setRowStatus($row, 'loading');

      $.post(ajaxurl, { action: 'sat_translate_post', nonce, post_id: id, lang: lang }, function(res) {
        if (res.success) {
          const doneForPost = queue.slice(0, i).filter(q => q.id === id).length;
          if (doneForPost === langs.length) {
            translatedIds.add(parseInt(id));
            setRowStatus($row, 'done');
            checkBatchDone(langs);
          }
        } else {
          setRowStatus($row, 'error');
        }
        next();
      }).fail(function() { setRowStatus($row, 'error'); next(); });
    }
    next();
  }

  // ── Single Translate ────────────────────────────────────────────────────────
  $(document).on('click', '.sat-translate-single', function() {
    const btn   = $(this);
    const id    = btn.data('id');
    const $row  = btn.closest('tr');
    const langs = $('input[name="langs[]"]:checked').map(function(){ return this.value; }).get();
    if (!langs.length) { alert('Select a language first.'); return; }
    currentLangs = langs;

    setRowStatus($row, 'loading');

    let i = 0, hasError = false;
    function nextLang() {
      if (i >= langs.length) {
        if (!hasError) {
          translatedIds.add(parseInt(id));
          setRowStatus($row, 'done');
          checkBatchDone(langs);
        } else {
          setRowStatus($row, 'error');
        }
        return;
      }
      const lang = langs[i++];
      $.post(ajaxurl, { action: 'sat_translate_post', nonce, post_id: id, lang: lang }, function(res) {
        if (!res.success) hasError = true;
        nextLang();
      }).fail(function() { hasError = true; nextLang(); });
    }
    nextLang();
  });

  // ── Queue helpers ───────────────────────────────────────────────────────────
  let nextRunCountdownTimer = null;

  function renderNextRun(nextRun) {
    const $el = $('#q-next-run');
    if (nextRunCountdownTimer) { clearInterval(nextRunCountdownTimer); nextRunCountdownTimer = null; }

    if (!nextRun) { $el.text(''); return; }

    function update() {
      const diff = Math.round(nextRun - (Date.now() / 1000));
      if (diff <= 0) {
        $el.text('⏳ Starting now...');
      } else if (diff < 60) {
        $el.text('⏱ Next run in ' + diff + 's');
      } else {
        const m = Math.floor(diff / 60), s = diff % 60;
        $el.text('⏱ Next run in ' + m + 'm ' + s + 's');
      }
    }
    update();
    nextRunCountdownTimer = setInterval(update, 1000);
  }

  function pollQueueStatus() {
    const interval = setInterval(function() {
      $.post(ajaxurl, { action: 'sat_queue_status', nonce, type: 'post' }, function(res) {
        if (!res.success) return;
        const d = res.data;
        $('#q-bar').css('width', d.percent + '%').text(d.percent + '%');
        $('#q-count').text(d.done + '/' + d.total);
        renderNextRun(d.next_run || null);
        // Driver badge güncelle
        if (d.driver) {
          const isAS = d.driver === 'action_scheduler';
          $('#q-driver-badge')
            .text(isAS ? 'Action Scheduler' : 'WP Cron')
            .css({'background': isAS ? '#dcfce7' : '#fef9c3', 'color': isAS ? '#16a34a' : '#854d0e'});
        }
        // Error sayısı göster + Retry butonu
        if (d.error > 0) {
          $('#q-errors').show().text('⚠ ' + d.error + ' item(s) failed');
          $('#sat-retry-errors').show();
        } else {
          $('#q-errors').hide();
          $('#sat-retry-errors').hide();
        }
        // Meta bilgisi (diller, post types)
        if (d.langs && d.langs.length) {
          const metaStr = d.langs.map(l => l.toUpperCase()).join(', ')
            + (d.post_types && d.post_types.length ? ' · ' + d.post_types.join(', ') : '')
            + (d.started_at ? ' · Started: ' + d.started_at : '');
          $('#q-meta').text(metaStr);
        }
        if (d.pending === 0 && d.processing === 0) {
          clearInterval(interval);
          if (nextRunCountdownTimer) { clearInterval(nextRunCountdownTimer); nextRunCountdownTimer = null; }
          $('#q-next-run').text('');
          $('#q-status-text').text('✅ Done!');
          // Done/error row'larını temizle (artık ajaxStatus'ta otomatik silinmiyor)
          $.post(ajaxurl, { action: 'sat_queue_clear_done', nonce: satConfig.nonce, type: 'post' });
          // 2 saniye sonra queue card'ı kapat ve listeyi yenile
          setTimeout(function() {
            $('#sat-queue-card').fadeOut(400, function() {
              $(this).hide();
            });
            // Liste açıksa ve dil seçiliyse listeyi yenile
            const langs = $('input[name="langs[]"]:checked').map(function(){ return this.value; }).get();
            if (langs.length) {
              $('#sat-check-btn').trigger('click');
            }
          }, 2000);
        }
      });
    }, 3000);
  }

  $('#sat-cancel-queue').on('click', function() {
    $.post(ajaxurl, { action: 'sat_queue_cancel', nonce, type: 'post' }, function() { $('#sat-queue-card').hide(); });
  });
  $('#sat-retry-errors').on('click', function() {
    $.post(ajaxurl, { action: 'sat_queue_retry', nonce }, function() { pollQueueStatus(); });
  });

  // ── Cost estimate — toplam post sayısına scale edilmiş ─────────────────────
  function estimateCostScaled(sampleIds, langs, total) {
    if (!sampleIds.length) return;
    $.post(ajaxurl, { action: 'sat_estimate_cost', nonce, ids: sampleIds, type: 'post', langs: langs }, function(res) {
      if (!res.success) return;
      const d = res.data;
      const sampleCount = sampleIds.length;
      const scaleFactor = total > sampleCount ? total / sampleCount : 1;

      $('#sat-cost-card').show();
      $('#sat-cost-value').text('~$' + (d.total_cost * scaleFactor).toFixed(4));
      $('#sat-cost-detail').text(total + ' posts × ' + langs.length + ' lang(s) (estimated)');
      $('#sat-cost-words').text(Math.round(d.words * scaleFactor).toLocaleString() + ' words');
      $('#sat-cost-tokens').text(Math.round(d.tokens * scaleFactor).toLocaleString() + ' tokens');
    });
  }

})(jQuery);
</script>
