<?php if (!defined('ABSPATH')) exit; ?>
<div class="sat-wrap">

  <header class="sat-header">
    <div class="sat-logo">A</div>
    <div>
      <h1>Salt AI Translator</h1>
      <p>AI-Powered Multilingual Translation System</p>
    </div>
  </header>

  <?php
  $logger   = $container->get('logger');
  $queue    = $container->get('queue');
  $stats    = $logger ? $logger->getStats() : [];
  $qStatus  = $queue ? $queue->getStatus() : [];
  $mlPlugin = $container->get('ml_plugin') ?? '';
  $hasTranslator = (bool) $translator;
  ?>

  <?php if (!$integration): ?>
  <div class="sat-alert sat-alert-danger">
    <strong>No multilanguage plugin detected.</strong>
    Please install and activate Polylang, WPML, or qTranslate-XT.
  </div>
  <?php endif; ?>

  <?php if (!$hasTranslator): ?>
  <div class="sat-alert sat-alert-warning">
    <strong>No translator configured.</strong>
    <a href="<?= admin_url('admin.php?page=sat-settings') ?>">Go to Settings</a> to add your API key.
  </div>
  <?php endif; ?>

  <!-- Stats -->
  <div class="sat-grid-4" style="margin-bottom:24px;">
    <div class="sat-stat">
      <div class="sat-stat-value"><?= number_format($stats['total'] ?? 0) ?></div>
      <div class="sat-stat-label">Total Translations</div>
    </div>
    <div class="sat-stat">
      <div class="sat-stat-value" style="color:var(--sat-success)"><?= number_format($stats['success'] ?? 0) ?></div>
      <div class="sat-stat-label">Successful</div>
    </div>
    <div class="sat-stat">
      <div class="sat-stat-value" style="color:var(--sat-danger)"><?= number_format($stats['error'] ?? 0) ?></div>
      <div class="sat-stat-label">Errors</div>
    </div>
    <div class="sat-stat">
      <div class="sat-stat-value">$<?= number_format($stats['total_cost'] ?? 0, 4) ?></div>
      <div class="sat-stat-label">Total Cost (USD)</div>
    </div>
  </div>

  <div class="sat-grid-2">

    <!-- Queue Status -->
    <div class="sat-card" id="sat-dash-queue-card">
      <div class="sat-card-title">
        Queue Status
        <?php
          $driver = $qStatus['driver'] ?? '';
          $driverColor = $driver === 'action_scheduler' ? '#dcfce7' : '#fef9c3';
          $driverText  = $driver === 'action_scheduler' ? '#16a34a' : '#854d0e';
          $driverLabel = $driver === 'action_scheduler' ? 'Action Scheduler' : 'WP Cron';
          if ($driver):
        ?>
        <span id="sat-dash-driver-badge" style="font-size:10px;font-weight:500;margin-left:8px;padding:2px 7px;border-radius:10px;background:<?= $driverColor ?>;color:<?= $driverText ?>;"><?= $driverLabel ?></span>
        <?php endif; ?>
      </div>

      <?php
        // Tüm type'lardan meta bilgisi topla
        $allMeta = [];
        foreach (['post', 'term', 'string'] as $t) {
          $m = get_option('sat_queue_meta_' . $t, []);
          if (!empty($m['langs']) && ($qStatus['pending'] > 0 || $qStatus['processing'] > 0 || $qStatus['done'] > 0)) {
            $allMeta[] = strtoupper($t) . ': ' . implode(', ', array_map('strtoupper', $m['langs']));
          }
        }
      ?>
      <?php if (!empty($allMeta)): ?>
      <div id="sat-dash-meta" style="font-size:12px;color:var(--sat-gray-500);margin-bottom:8px;"><?= esc_html(implode(' | ', $allMeta)) ?></div>
      <?php else: ?>
      <div id="sat-dash-meta" style="font-size:12px;color:var(--sat-gray-500);margin-bottom:8px;display:none;"></div>
      <?php endif; ?>

      <?php if (!empty($qStatus['total'])): ?>
        <div style="margin-bottom:12px;">
          <div class="sat-credit-bar">
            <div class="label"><span id="sat-dash-status-text">Processing...</span><span id="sat-dash-count"><?= $qStatus['done'] ?>/<?= $qStatus['total'] ?></span></div>
            <div class="sat-progress"><div class="sat-progress-bar" id="sat-dash-bar" style="width:<?= $qStatus['percent'] ?>%"><?= $qStatus['percent'] ?>%</div></div>
          </div>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:8px;" id="sat-dash-badges">
          <span class="sat-badge sat-badge-pending" id="sat-dash-pending"><?= $qStatus['pending'] ?> pending</span>
          <span class="sat-badge sat-badge-processing" id="sat-dash-processing"><?= $qStatus['processing'] ?> processing</span>
          <span class="sat-badge sat-badge-success" id="sat-dash-done"><?= $qStatus['done'] ?> done</span>
          <?php if ($qStatus['error']): ?>
          <span class="sat-badge sat-badge-error" id="sat-dash-error"><?= $qStatus['error'] ?> errors</span>
          <?php else: ?>
          <span class="sat-badge sat-badge-error" id="sat-dash-error" style="display:none;">0 errors</span>
          <?php endif; ?>
        </div>
        <div id="sat-dash-next-run" style="font-size:12px;color:var(--sat-gray-400);min-height:16px;" data-next-run="<?= (int)($qStatus['next_run'] ?? 0) ?>"></div>
        <?php if (!empty($qStatus['current_item'])): ?>
        <div id="sat-dash-current" style="font-size:11px;color:var(--sat-gray-500);margin-top:6px;
          padding:6px 10px;background:rgba(37,99,235,0.06);border:1px solid rgba(37,99,235,0.12);border-radius:6px;">
          ⚙ <span id="sat-dash-current-text">
            <?= esc_html($qStatus['current_item']['title'] ?? '') ?> 
            <span style="color:var(--sat-primary);font-weight:600;">→ <?= strtoupper(esc_html($qStatus['current_item']['target_lang'] ?? '')) ?></span>
          </span>
        </div>
        <?php else: ?>
        <div id="sat-dash-current" style="font-size:11px;color:var(--sat-gray-500);margin-top:6px;
          padding:6px 10px;background:rgba(37,99,235,0.06);border:1px solid rgba(37,99,235,0.12);border-radius:6px;display:none;">
          ⚙ <span id="sat-dash-current-text"></span>
        </div>
        <?php endif; ?>
      <?php else: ?>
        <p id="sat-dash-no-queue" style="color:var(--sat-gray-400);margin:0;">No active queue.</p>
      <?php endif; ?>
    </div>

    <!-- Quick Actions -->
    <div class="sat-card">
      <div class="sat-card-title">Quick Actions</div>
      <div style="display:flex;flex-direction:column;gap:10px;">
        <a href="<?= admin_url('admin.php?page=sat-posts') ?>" class="sat-btn sat-btn-primary">
          📝 Translate Posts
        </a>
        <a href="<?= admin_url('admin.php?page=sat-terms') ?>" class="sat-btn sat-btn-secondary">
          🏷️ Translate Terms
        </a>
        <a href="<?= admin_url('admin.php?page=sat-media') ?>" class="sat-btn sat-btn-secondary">
          🖼️ Generate Alt Texts
        </a>
        <a href="<?= admin_url('admin.php?page=sat-credits') ?>" class="sat-btn sat-btn-secondary">
          💳 Check Credits
        </a>
      </div>
    </div>

  </div>

  <!-- Languages overview -->
  <?php if ($integration && $languages): ?>
  <div class="sat-card">
    <div class="sat-card-title">Languages <small style="font-weight:400;color:var(--sat-gray-400);font-size:12px;">(configured via Polylang/WPML)</small></div>
    <div class="sat-lang-grid">
      <?php foreach ($languages as $code => $label): ?>
        <div class="sat-lang-badge" style="display:inline-flex;align-items:center;gap:6px;padding:6px 12px;background:var(--sat-gray-50);border:1px solid var(--sat-gray-200);border-radius:20px;font-size:13px;cursor:default;">
          <?= esc_html($label) ?> <small style="color:var(--sat-gray-400);font-size:11px;"><?= esc_html($code) ?></small>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- Translation Memory stats -->
  <?php
  $memory      = $container->get('memory');
  $memStats    = $memory ? $memory->getStats() : null;
  $retranslate = (bool) $container->get('settings')->get('retranslate', 0);
  if ($memStats):
  ?>
  <div class="sat-card">
    <div class="sat-card-title" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
      <span>🧠 Translation Memory</span>
      <div style="display:flex;align-items:center;gap:8px;">
        <?php if ($retranslate): ?>
        <span style="font-size:11px;font-weight:500;padding:3px 10px;border-radius:10px;background:#fef3c7;color:#92400e;">
          ⚠ Cache bypassed — Retranslate is ON
        </span>
        <?php else: ?>
        <span style="font-size:11px;font-weight:500;padding:3px 10px;border-radius:10px;background:#dcfce7;color:#166534;">
          ✓ Cache active
        </span>
        <?php endif; ?>
        <button type="button" class="sat-btn sat-btn-danger sat-btn-sm" id="sat-memory-clear-btn"
          style="font-size:11px;padding:3px 10px;" title="Clear all cached translations">
          🗑 Clear All
        </button>
      </div>
    </div>
    <?php if ($retranslate): ?>
    <div style="font-size:12px;color:#92400e;background:#fef9c3;border:1px solid #fde68a;border-radius:6px;padding:8px 12px;margin-bottom:12px;">
      <strong>Settings → Content → Retranslate existing content</strong> is ON.<br>
      Translation Memory is being written but <strong>not read</strong> — every translation calls the API.
      <a href="<?= admin_url('admin.php?page=sat-settings') ?>" style="color:#92400e;text-decoration:underline;margin-left:4px;">Turn it off</a> to enable cache reads.
    </div>
    <?php endif; ?>
    <div class="sat-grid-3" style="margin-bottom:12px;">
      <div class="sat-stat" style="padding:12px;">
        <div class="sat-stat-value" id="sat-mem-total"><?= number_format($memStats['total_entries']) ?></div>
        <div class="sat-stat-label">Cached Entries</div>
      </div>
      <div class="sat-stat" style="padding:12px;">
        <div class="sat-stat-value" style="color:var(--sat-success)" id="sat-mem-hits"><?= number_format($memStats['total_hits']) ?></div>
        <div class="sat-stat-label">Cache Hits (API calls saved)</div>
      </div>
      <div class="sat-stat" style="padding:12px;">
        <div class="sat-stat-value" id="sat-mem-langs"><?= count($memStats['by_lang']) ?></div>
        <div class="sat-stat-label">Languages</div>
      </div>
    </div>
    <?php if (!empty($memStats['by_lang'])): ?>
    <div style="display:flex;flex-wrap:wrap;gap:6px;">
      <?php foreach ($memStats['by_lang'] as $langRow): ?>
      <span style="font-size:12px;padding:3px 10px;background:var(--sat-gray-100);border-radius:12px;display:inline-flex;align-items:center;gap:6px;">
        <strong><?= esc_html(strtoupper($langRow->lang)) ?></strong>
        <span style="color:var(--sat-gray-400);"><?= number_format($langRow->cnt) ?> entries</span>
        <button type="button" class="sat-memory-clear-lang" data-lang="<?= esc_attr($langRow->lang) ?>"
          style="background:none;border:none;cursor:pointer;color:var(--sat-gray-400);font-size:11px;padding:0;line-height:1;" title="Clear <?= esc_attr(strtoupper($langRow->lang)) ?> memory">✕</button>
      </span>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <?php
    $lastPrune = get_option('sat_memory_last_prune', null);
    ?>
    <div style="margin-top:10px;padding-top:10px;border-top:1px solid var(--sat-gray-100);font-size:11px;color:var(--sat-gray-400);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:6px;">
      <span>
        🗑 Auto-prune: weekly — removes entries older than 90 days with 0 hits.
        <?php if ($lastPrune): ?>
          Last run: <strong><?= human_time_diff($lastPrune['time']) ?> ago</strong>
          <?php if ($lastPrune['deleted'] > 0): ?>
            — <?= number_format($lastPrune['deleted']) ?> entries removed
          <?php else: ?>
            — nothing to remove
          <?php endif; ?>
        <?php else: ?>
          <em>Not run yet.</em>
        <?php endif; ?>
      </span>
    </div>
  </div>
  <?php endif; ?>

</div>

<script>
(function($){
  const nonce   = (typeof satConfig !== 'undefined' && satConfig.nonce) ? satConfig.nonce : '';
  let nextRunTimer = null;

  function renderNextRun(ts) {
    const $el = $('#sat-dash-next-run');
    if (nextRunTimer) { clearInterval(nextRunTimer); nextRunTimer = null; }
    if (!ts) { $el.text(''); return; }
    function update() {
      const diff = Math.round(ts - (Date.now() / 1000));
      if (diff <= 0)      $el.text('⏳ Starting now...');
      else if (diff < 60) $el.text('⏱ Next run in ' + diff + 's');
      else { const m = Math.floor(diff/60), s = diff%60; $el.text('⏱ Next run in ' + m + 'm ' + (s<10?'0':'') + s + 's'); }
    }
    update();
    nextRunTimer = setInterval(update, 1000);
  }

  // Init next run countdown from PHP data
  const initNextRun = parseInt($('#sat-dash-next-run').data('next-run') || 0, 10);
  if (initNextRun) renderNextRun(initNextRun);

  // Polling — 5 saniyede bir tüm queue'yu kontrol et
  <?php if (($qStatus['pending'] ?? 0) + ($qStatus['processing'] ?? 0) > 0): ?>
  const pollInterval = setInterval(function() {
    $.post(ajaxurl, { action: 'sat_queue_status', nonce }, function(res) {
      if (!res.success) return;
      const d = res.data;

      // Progress bar
      $('#sat-dash-bar').css('width', d.percent + '%').text(d.percent + '%');
      $('#sat-dash-count').text(d.done + '/' + d.total);

      // Badges
      $('#sat-dash-pending').text(d.pending + ' pending');
      $('#sat-dash-processing').text(d.processing + ' processing');
      $('#sat-dash-done').text(d.done + ' done');
      if (d.error > 0) $('#sat-dash-error').show().text(d.error + ' errors');
      else             $('#sat-dash-error').hide();

      // Driver badge
      if (d.driver) {
        const isAS = d.driver === 'action_scheduler';
        $('#sat-dash-driver-badge')
          .text(isAS ? 'Action Scheduler' : 'WP Cron')
          .css({'background': isAS ? '#dcfce7':'#fef9c3', 'color': isAS ? '#16a34a':'#854d0e'});
      }

      // Current item göster
        if (d.current_item) {
          const title = d.current_item.title || '';
          const lang  = (d.current_item.target_lang || '').toUpperCase();
          $('#sat-dash-current').show();
          $('#sat-dash-current-text').html('⚙ ' + title + ' <span style="color:#3B82F6;font-weight:600">→ ' + lang + '</span>');
        } else {
          $('#sat-dash-current').hide();
        }
      const metaParts = [];
      if (d.langs && d.langs.length) metaParts.push(d.langs.map(l => l.toUpperCase()).join(', '));
      if (d.post_types && d.post_types.length) metaParts.push(d.post_types.join(', '));
      if (d.group) metaParts.push('Group: ' + d.group);
      if (metaParts.length) $('#sat-dash-meta').show().text(metaParts.join(' · '));

      renderNextRun(d.next_run || 0);

      // Bitti
      if (d.pending === 0 && d.processing === 0) {
        clearInterval(pollInterval);
        if (nextRunTimer) { clearInterval(nextRunTimer); nextRunTimer = null; }
        $('#sat-dash-next-run').text('');
        $('#sat-dash-status-text').text('✅ Done!');
      }
    });
  }, 5000);
  <?php endif; ?>

  // Translation Memory clear handlers
  $('#sat-memory-clear-btn').on('click', function() {
    if (!confirm('Clear ALL translation memory? This cannot be undone.')) return;
    const btn = $(this).prop('disabled', true).text('Clearing...');
    $.post(ajaxurl, { action: 'sat_memory_clear', nonce, lang: '' }, function(res) {
      btn.prop('disabled', false).text('🗑 Clear All');
      if (res.success) {
        $('#sat-mem-total').text('0');
        $('#sat-mem-hits').text('0');
        $('#sat-mem-langs').text('0');
        $(btn).closest('.sat-card').find('.sat-memory-clear-lang').closest('span').remove();
      }
    });
  });

  $(document).on('click', '.sat-memory-clear-lang', function() {
    const lang = $(this).data('lang');
    const $span = $(this).closest('span');
    $.post(ajaxurl, { action: 'sat_memory_clear', nonce, lang }, function(res) {
      if (res.success) {
        $span.remove();
        // Total güncelle
        $.post(ajaxurl, { action: 'sat_memory_stats', nonce }, function(r) {
          if (r.success) {
            $('#sat-mem-total').text(r.data.total_entries.toLocaleString());
            $('#sat-mem-hits').text(r.data.total_hits.toLocaleString());
            $('#sat-mem-langs').text(r.data.by_lang.length);
          }
        });
      }
    });
  });

})(jQuery);
</script>
