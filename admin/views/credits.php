<?php if (!defined('ABSPATH')) exit;
$credits = $container->get('credits');
$settings = $container->get('settings');
$translatorName = $settings->get('translator', '');
?>
<div class="sat-wrap">

  <header class="sat-header">
    <div class="sat-logo">A</div>
    <div><h1>Credits & Usage</h1><p>Monitor your API usage and remaining credits</p></div>
  </header>

  <div class="sat-grid-2">

    <!-- Credit Balance -->
    <div class="sat-card">
      <div class="sat-card-title">API Credit Balance</div>
      <div id="sat-credits-loading" style="text-align:center;padding:20px;">
        <span class="sat-spinner"></span> Loading...
      </div>
      <div id="sat-credits-content" style="display:none;">
        <div id="sat-credits-data"></div>
        <div style="margin-top:16px;">
          <button class="sat-btn sat-btn-secondary sat-btn-sm" id="sat-refresh-credits">
            ↻ Refresh
          </button>
        </div>
      </div>
    </div>

    <!-- Capacity Estimate -->
    <div class="sat-card">
      <div class="sat-card-title">Estimated Capacity</div>
      <div id="sat-capacity-content">
        <p style="color:var(--sat-gray-400);">Loading capacity estimate...</p>
      </div>

      <div style="margin-top:20px;border-top:1px solid var(--sat-gray-100);padding-top:16px;">
        <div class="sat-card-title" style="margin-bottom:12px;">Cost Calculator</div>
        <div class="sat-form-group">
          <label class="sat-label">Paste text to estimate cost</label>
          <textarea class="sat-textarea" id="sat-calc-text" rows="4" placeholder="Paste your content here..."></textarea>
        </div>
        <div class="sat-form-group">
          <label class="sat-label">Number of languages</label>
          <input type="number" class="sat-input" id="sat-calc-langs" value="1" min="1" max="20" style="max-width:100px;">
        </div>
        <button class="sat-btn sat-btn-primary sat-btn-sm" id="sat-calc-btn">Calculate</button>
        <div id="sat-calc-result" style="margin-top:12px;display:none;">
          <div class="sat-cost-box">
            <div>
              <div class="cost-value" id="sat-calc-cost">$0.00</div>
              <div class="cost-label" id="sat-calc-detail"></div>
            </div>
          </div>
        </div>
      </div>
    </div>

  </div>

  <!-- Usage Stats from Logger -->
  <div class="sat-card">
    <div class="sat-card-title">Usage Statistics</div>
    <div class="sat-grid-4">
      <?php
      $logger = $container->get('logger');
      $stats  = $logger ? $logger->getStats() : [];
      ?>
      <div class="sat-stat">
        <div class="sat-stat-value"><?= number_format($stats['total'] ?? 0) ?></div>
        <div class="sat-stat-label">Total Requests</div>
      </div>
      <div class="sat-stat">
        <div class="sat-stat-value"><?= number_format($stats['total_tokens'] ?? 0) ?></div>
        <div class="sat-stat-label">Total Tokens</div>
      </div>
      <div class="sat-stat">
        <div class="sat-stat-value">$<?= number_format($stats['total_cost'] ?? 0, 4) ?></div>
        <div class="sat-stat-label">Total Spent (USD)</div>
      </div>
      <div class="sat-stat">
        <div class="sat-stat-value"><?= $stats['total'] > 0 ? round(($stats['success'] / $stats['total']) * 100) : 0 ?>%</div>
        <div class="sat-stat-label">Success Rate</div>
      </div>
    </div>
  </div>

  <!-- API Keys Status -->
  <div class="sat-card">
    <div class="sat-card-title">API Keys Status</div>
    <div id="sat-keys-status">
      <button class="sat-btn sat-btn-secondary sat-btn-sm" id="sat-check-keys">Check All Keys</button>
    </div>
  </div>

</div>

<script>
(function($){
  // satConfig'den veya PHP'den nonce al
  const nonce = (typeof satConfig !== 'undefined' && satConfig.nonce) ? satConfig.nonce : '<?= wp_create_nonce('sat_nonce') ?>';

  function loadCredits() {
    $('#sat-credits-loading').show();
    $('#sat-credits-content').hide();

    $.post(ajaxurl, { action: 'sat_get_credits', nonce }, function(res) {
      $('#sat-credits-loading').hide();
      $('#sat-credits-content').show();

      if (!res.success) {
        var errMsg = (typeof res.data === 'string') ? res.data : 'Could not load credits.';
        $('#sat-credits-data').html(
          '<div class="sat-alert sat-alert-warning" style="margin:0;">' +
          '<strong>Note:</strong> ' + errMsg + '<br>' +
          '<small style="color:var(--sat-gray-400);">Make sure your API key is saved in Settings → Translator.</small>' +
          '</div>'
        );
        $('#sat-capacity-content').html('<p style="color:var(--sat-gray-400);">Not available — check API key.</p>');
        return;
      }

      const d = res.data;
      let html = '';

      // Remaining credits
      if (d.remaining) {
        const r = d.remaining;
        if (r.error) {
          html += '<div class="sat-alert sat-alert-warning">' + r.error + '</div>';
        } else if (r.characters_remaining !== undefined) {
          // DeepL
          const pct = r.percent_used || 0;
          html += '<div class="sat-credit-bar">';
          html += '<div class="label"><span>Characters Used</span><span>' + r.characters_used.toLocaleString() + ' / ' + r.characters_limit.toLocaleString() + '</span></div>';
          html += '<div class="sat-progress"><div class="sat-progress-bar" style="width:' + pct + '%">' + pct + '%</div></div>';
          html += '</div>';
          html += '<p style="margin-top:8px;font-size:13px;">Remaining: <strong>' + r.characters_remaining.toLocaleString() + ' characters</strong> (~$' + r.balance_usd.toFixed(4) + ')</p>';
        } else if (r.keys) {
          // OpenAI
          html += '<div class="sat-alert sat-alert-info">' + (r.note || '') + '</div>';
          r.keys.forEach(function(k) {
            const badge = k.status === 'valid' ? 'sat-badge-success' : 'sat-badge-error';
            html += '<div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">';
            html += '<code style="font-size:12px;">' + k.key_preview + '</code>';
            html += '<span class="sat-badge ' + badge + '">' + k.status + '</span>';
            html += '</div>';
          });
        } else {
          html += '<pre style="font-size:12px;">' + JSON.stringify(r, null, 2) + '</pre>';
        }
      }

      $('#sat-credits-data').html(html);

      // Capacity
      if (d.capacity) {
        const c = d.capacity;
        let capHtml = '';
        if (c && c.words_available && c.words_available > 0) {
          capHtml += '<div class="sat-cost-box">';
          capHtml += '<div><div class="cost-value">~' + c.words_available.toLocaleString() + '</div><div class="cost-label">words you can translate</div></div>';
          capHtml += '<div style="text-align:right;"><div style="font-size:13px;color:var(--sat-gray-600);">' + c.tokens_available.toLocaleString() + ' tokens</div></div>';
          capHtml += '</div>';
        } else {
          capHtml = '<div class="sat-alert sat-alert-info" style="margin:0;">OpenAI does not expose credit balance via API. Check your usage at <a href="https://platform.openai.com/usage" target="_blank">platform.openai.com/usage</a></div>';
        }
        $('#sat-capacity-content').html(capHtml);
      }
    });
  }

  loadCredits();
  $('#sat-refresh-credits').on('click', function() {
    $('#sat-credits-loading').show();
    $('#sat-credits-content').hide();
    loadCredits();
  });

  // Cost calculator
  $('#sat-calc-btn').on('click', function() {
    const text  = $('#sat-calc-text').val();
    const langs = parseInt($('#sat-calc-langs').val()) || 1;
    if (!text.trim()) return;

    const btn = $(this).prop('disabled', true).text('Calculating...');
    $.post(ajaxurl, {
      action: 'sat_estimate_cost', nonce,
      ids: [], type: 'text', langs: Array(langs).fill('en'),
      text: text
    }, function(res) {
      btn.prop('disabled', false).text('Calculate');
      if (!res.success) return;
      const d = res.data;
      $('#sat-calc-result').show();
      $('#sat-calc-cost').text('$' + (d.cost_usd * langs).toFixed(6));
      $('#sat-calc-detail').text(d.words + ' words × ' + langs + ' language(s) = ' + (d.tokens * langs) + ' tokens');
    }).fail(function() {
      btn.prop('disabled', false).text('Calculate');
    });
  });

  // ── Check All Keys ─────────────────────────────────────────
  $(document).on('click', '#sat-check-keys', function() {
    const $btn = $(this).prop('disabled', true).html('<span class="sat-spinner"></span> Checking...');
    const $container = $('#sat-keys-status');

    $.post(ajaxurl, { action: 'sat_get_credits', nonce }, function(res) {
      $btn.prop('disabled', false).text('Check All Keys');

      if (!res.success) {
        $container.html('<div class="sat-alert sat-alert-danger">' + (res.data || 'Error checking keys') + '</div><button class="sat-btn sat-btn-secondary sat-btn-sm" id="sat-check-keys" style="margin-top:8px;">↻ Retry</button>');
        return;
      }

      const r = res.data.remaining;
      let html = '';

      if (r && r.keys && r.keys.length) {
        html += '<p style="font-size:12px;color:var(--sat-gray-400);margin-bottom:10px;">' + (r.note || '') + '</p>';
        r.keys.forEach(function(k) {
          const isValid = k.status === 'valid';
          const icon    = isValid ? '✅' : '❌';
          const badge   = isValid ? 'sat-badge-success' : 'sat-badge-error';
          html += '<div style="display:flex;align-items:center;gap:10px;padding:8px 12px;background:var(--sat-gray-50);border-radius:6px;margin-bottom:6px;">';
          html += '<span style="font-size:16px;">' + icon + '</span>';
          html += '<code style="font-size:12px;flex:1;color:var(--sat-gray-700);">' + k.key_preview + '</code>';
          html += '<span class="sat-badge ' + badge + '">' + k.status + '</span>';
          if (k.http_code) html += '<span style="font-size:11px;color:var(--sat-gray-400);">HTTP ' + k.http_code + '</span>';
          html += '</div>';
        });
      } else if (r && r.error) {
        html = '<div class="sat-alert sat-alert-warning">' + r.error + '</div>';
      } else if (r && r.characters_limit !== undefined) {
        // DeepL — key geçerli
        html = '<div class="sat-alert sat-alert-success" style="background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;">✅ API key is valid. Characters used: ' + r.characters_used.toLocaleString() + ' / ' + r.characters_limit.toLocaleString() + '</div>';
      } else {
        html = '<div class="sat-alert sat-alert-info">Key validation not available for this provider.</div>';
      }

      html += '<button class="sat-btn sat-btn-secondary sat-btn-sm" id="sat-check-keys" style="margin-top:10px;">↻ Recheck</button>';
      $container.html(html);

    }).fail(function() {
      $btn.prop('disabled', false).text('Check All Keys');
      $container.html('<div class="sat-alert sat-alert-danger">AJAX request failed.</div>');
    });
  });

})(jQuery);
</script>
