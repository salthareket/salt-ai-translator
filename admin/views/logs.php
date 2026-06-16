<?php if (!defined('ABSPATH')) exit; ?>
<div class="sat-wrap">

  <header class="sat-header">
    <div class="sat-logo">A</div>
    <div><h1>Translation Logs</h1><p>View history, errors and performance</p></div>
  </header>

  <!-- Filters -->
  <div class="sat-card" style="margin-bottom:16px;">
    <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;">
      <div class="sat-form-group" style="margin:0;">
        <label class="sat-label">Status</label>
        <select class="sat-select" id="sat-log-status" style="width:140px;">
          <option value="">All</option>
          <option value="success">Success</option>
          <option value="error">Error</option>
          <option value="skipped">Skipped</option>
        </select>
      </div>
      <div class="sat-form-group" style="margin:0;">
        <label class="sat-label">Type</label>
        <select class="sat-select" id="sat-log-type" style="width:140px;">
          <option value="">All</option>
          <option value="post">Post</option>
          <option value="term">Term</option>
          <option value="string">String</option>
          <option value="menu">Menu</option>
          <option value="po_file">PO File</option>
        </select>
      </div>
      <div class="sat-form-group" style="margin:0;">
        <label class="sat-label">Language</label>
        <select class="sat-select" id="sat-log-lang" style="width:140px;">
          <option value="">All</option>
          <?php foreach ($languages as $code => $label): ?>
            <option value="<?= esc_attr($code) ?>"><?= esc_html($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="sat-form-group" style="margin:0;">
        <label class="sat-label">Date From</label>
        <input type="date" class="sat-input" id="sat-log-date-from" style="width:150px;background:var(--sat-gray-50);border:1px solid var(--sat-gray-200);border-radius:6px;padding:6px 10px;font-size:13px;">
      </div>
      <div class="sat-form-group" style="margin:0;">
        <label class="sat-label">Date To</label>
        <input type="date" class="sat-input" id="sat-log-date-to" style="width:150px;background:var(--sat-gray-50);border:1px solid var(--sat-gray-200);border-radius:6px;padding:6px 10px;font-size:13px;">
      </div>
      <button class="sat-btn sat-btn-primary" id="sat-load-logs">Load Logs</button>
      <button class="sat-btn sat-btn-secondary" id="sat-export-logs">Export CSV</button>
      <button class="sat-btn sat-btn-danger" id="sat-clear-logs" style="margin-left:auto;">🗑 Clear Logs</button>
    </div>
  </div>

  <!-- Stats row -->
  <div class="sat-grid-4" style="margin-bottom:20px;" id="sat-log-stats"></div>

  <!-- Table -->
  <div class="sat-card">
    <div style="overflow-x:auto;">
      <table class="sat-table">
        <thead>
          <tr>
            <th>Date</th>
            <th>Object</th>
            <th>Lang</th>
            <th>Translator</th>
            <th>Model</th>
            <th>Source</th>
            <th>Tokens</th>
            <th>Cost</th>
            <th>Duration</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody id="sat-logs-tbody">
          <tr><td colspan="10" style="text-align:center;color:var(--sat-gray-400);padding:30px;">Click "Load Logs" to view history</td></tr>
        </tbody>
      </table>
    </div>
    <div id="sat-logs-pagination" style="margin-top:12px;display:flex;gap:8px;align-items:center;"></div>
  </div>

</div>

<script>
(function($){
  const nonce = (typeof satConfig !== 'undefined' && satConfig.nonce) ? satConfig.nonce : '<?= wp_create_nonce('sat_nonce') ?>';
  const ajaxUrl = (typeof satConfig !== 'undefined' && satConfig.ajaxUrl) ? satConfig.ajaxUrl : '<?= admin_url('admin-ajax.php') ?>';
  const limit = 50;

  function loadLogs(offset) {
    offset = offset || 0;
    $.post(ajaxUrl, {
      action: 'sat_get_logs', nonce,
      status:      $('#sat-log-status').val(),
      object_type: $('#sat-log-type').val(),
      lang:        $('#sat-log-lang').val(),
      date_from:   $('#sat-log-date-from').val(),
      date_to:     $('#sat-log-date-to').val(),
      limit:  limit,
      offset: offset
    }, function(res) {
      if (!res.success) return;
      const { logs, stats } = res.data;

      // Stats
      $('#sat-log-stats').html(`
        <div class="sat-stat"><div class="sat-stat-value">${stats.total.toLocaleString()}</div><div class="sat-stat-label">Total</div></div>
        <div class="sat-stat"><div class="sat-stat-value" style="color:var(--sat-success)">${stats.success.toLocaleString()}</div><div class="sat-stat-label">Success</div></div>
        <div class="sat-stat"><div class="sat-stat-value" style="color:var(--sat-danger)">${stats.error.toLocaleString()}</div><div class="sat-stat-label">Errors</div></div>
        <div class="sat-stat"><div class="sat-stat-value">$${parseFloat(stats.total_cost).toFixed(4)}</div><div class="sat-stat-label">Total Cost</div></div>
      `);

      // Table
      const tbody = $('#sat-logs-tbody').empty();
      if (!logs.length) {
        tbody.html('<tr><td colspan="10" style="text-align:center;color:var(--sat-gray-400);padding:20px;">No logs found</td></tr>');
        return;
      }

      logs.forEach(function(log) {
        const badge = log.status === 'success' ? 'sat-badge-success' : (log.status === 'error' ? 'sat-badge-error' : 'sat-badge-pending');

        // Object gösterimi — tipe göre farklı
        let objectHtml;
        const adminUrl = (typeof satConfig !== 'undefined' && satConfig.adminUrl) ? satConfig.adminUrl : ajaxUrl.replace('admin-ajax.php', '');
        const type = log.object_type || 'post';

        if (type === 'string') {
          objectHtml = `<span style="font-size:11px;" title="${log.source||''}">🔤 string</span>`;
        } else if (type === 'menu') {
          objectHtml = `<span style="font-size:11px;">🗂 menu #${log.object_id}</span>`;
        } else if (type === 'po_file') {
          const src = (log.source || '').split(':')[0] || 'po';
          objectHtml = `<span style="font-size:11px;" title="${log.source||''}">📄 ${src}</span>`;
        } else if (log.object_id > 0 && type === 'term') {
          objectHtml = `<span style="font-size:12px;">${type} #${log.object_id}</span>`;
        } else if (log.object_id > 0) {
          const editUrl = adminUrl + 'post.php?post=' + log.object_id + '&action=edit';
          objectHtml = `<a href="${editUrl}" target="_blank" rel="noopener" style="font-size:12px;color:var(--sat-primary);text-decoration:none;">${type} #${log.object_id} ↗</a>`;
        } else {
          objectHtml = `<span style="font-size:12px;color:var(--sat-gray-400);">${type}</span>`;
        }

        tbody.append(`
          <tr>
            <td style="white-space:nowrap;font-size:12px;">${log.created_at}</td>
            <td>${objectHtml}</td>
            <td><strong>${log.target_lang}</strong></td>
            <td>${log.translator}</td>
            <td style="font-size:12px;">${log.model}</td>
            <td style="font-size:11px;max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${log.source || ''}">${log.source || '–'}</td>
            <td style="font-size:12px;">${parseInt(log.tokens_input)+parseInt(log.tokens_output)}</td>
            <td style="font-size:12px;">$${parseFloat(log.cost_usd).toFixed(6)}</td>
            <td style="font-size:12px;">${log.duration_ms}ms</td>
            <td><span class="sat-badge ${badge}">${log.status}</span></td>
          </tr>
        `);
      });

      // Pagination
      const pages = Math.ceil(stats.total / limit);
      const currentPage = Math.floor(offset / limit) + 1;
      let pag = `<span style="font-size:13px;color:var(--sat-gray-400);">Page ${currentPage} of ${pages}</span>`;
      if (offset > 0) pag += `<button class="sat-btn sat-btn-secondary sat-btn-sm" data-offset="${offset - limit}">← Prev</button>`;
      if (offset + limit < stats.total) pag += `<button class="sat-btn sat-btn-secondary sat-btn-sm" data-offset="${offset + limit}">Next →</button>`;
      $('#sat-logs-pagination').html(pag);
    });
  }

  $('#sat-load-logs').on('click', function() { loadLogs(0); });

  // Sayfa açılınca otomatik yükle
  loadLogs(0);

  $(document).on('click', '#sat-logs-pagination button', function() {
    loadLogs(parseInt($(this).data('offset')));
  });

  $('#sat-clear-logs').on('click', function() {
    if (!confirm('All translation logs will be permanently deleted. Are you sure?')) return;
    const $btn = $(this).prop('disabled', true).html('<span class="sat-spinner"></span> Clearing...');
    $.post(ajaxUrl, { action: 'sat_clear_logs', nonce }, function(res) {
      $btn.prop('disabled', false).html('🗑 Clear Logs');
      if (res.success) {
        loadLogs(0); // Logları yeniden yükle (boş gelecek)
      } else {
        alert('Error: ' + (res.data || 'Could not clear logs'));
      }
    }).fail(function() {
      $btn.prop('disabled', false).html('🗑 Clear Logs');
      alert('AJAX request failed. Check console.');
    });
  });

})(jQuery);
</script>
