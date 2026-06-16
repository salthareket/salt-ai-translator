<?php if (!defined('ABSPATH')) exit;
$isLocalhost = (in_array($_SERVER['HTTP_HOST'] ?? '', ['localhost', '127.0.0.1']) || str_contains($_SERVER['HTTP_HOST'] ?? '', '.local'));
$default = $integration ? $integration->getDefaultLanguage() : '';
?>
<div class="sat-wrap">
  <header class="sat-header">
    <div class="sat-logo">A</div>
    <div><h1>Media Translation</h1><p>Generate and translate image alt texts, captions</p></div>
  </header>

  <?php if ($isLocalhost): ?>
  <div class="sat-alert sat-alert-warning">
    <strong>Localhost detected.</strong> AI vision cannot access local images. Alt text generation is disabled.
    Use this feature on staging or production.
  </div>
  <?php endif; ?>

  <div class="sat-grid-2">
    <div class="sat-card">
      <div class="sat-card-title">Bulk Alt Text Generation</div>

      <div class="sat-form-group">
        <label class="sat-label">Target Languages</label>
        <div class="sat-lang-grid" id="sat-media-lang-selector">
          <?php foreach ($languages as $code => $label): ?>
            <?php if ($code === $default) continue; ?>
            <label class="sat-lang-chip">
              <input type="checkbox" name="langs[]" value="<?= esc_attr($code) ?>" checked>
              <?= esc_html($label) ?> <small><?= esc_html($code) ?></small>
            </label>
          <?php endforeach; ?>
        </div>
        <div style="margin-top:8px;">
          <label><input type="checkbox" id="sat-media-all-langs"> <strong>All languages</strong></label>
        </div>
        <small style="color:var(--sat-gray-400);display:block;margin-top:4px;">
          Source language: <strong><?= esc_html(strtoupper($default)) ?></strong> (not translatable — it's the default)
        </small>
      </div>

      <div class="sat-form-group">
        <label class="sat-label">Batch Size</label>
        <input type="number" class="sat-input" id="sat-media-limit" value="20" min="1" max="100" style="max-width:100px;">
        <small style="color:var(--sat-gray-400);display:block;margin-top:4px;">Images to process per run</small>
      </div>
      <button class="sat-btn sat-btn-primary" id="sat-gen-alt-btn" <?= $isLocalhost ? 'disabled' : '' ?>>
        Generate Alt Texts
      </button>
    </div>

    <div class="sat-card">
      <div class="sat-card-title">Results</div>
      <div id="sat-media-result" style="color:var(--sat-gray-400);">Click "Generate Alt Texts" to start.</div>
    </div>
  </div>
</div>

<script>
(function($){
  const nonce = (typeof satConfig !== 'undefined' && satConfig.nonce) ? satConfig.nonce : '<?= wp_create_nonce('sat_nonce') ?>';

  // All languages toggle
  $('#sat-media-all-langs').on('change', function() {
    $('#sat-media-lang-selector input[type=checkbox]').prop('checked', this.checked);
  });
  $('#sat-media-lang-selector input[type=checkbox]').on('change', function() {
    if (!$(this).is(':checked')) $('#sat-media-all-langs').prop('checked', false);
  });

  $('#sat-gen-alt-btn').on('click', function() {
    const langs = [];
    $('#sat-media-lang-selector input[type=checkbox]:checked').each(function() {
      langs.push($(this).val());
    });
    if (!langs.length) {
      $('#sat-media-result').html('<div class="sat-alert sat-alert-warning">⚠ Select at least one language.</div>');
      return;
    }

    const btn = $(this).prop('disabled', true).html('<span class="sat-spinner"></span> Processing...');
    let processed = 0;
    let remaining = langs.length;

    $('#sat-media-result').html('<div style="color:var(--sat-gray-400);">Processing ' + langs.length + ' language(s)...</div>');

    langs.forEach(function(lang) {
      $.post(ajaxurl, {
        action: 'sat_bulk_media_alt', nonce,
        lang: lang,
        limit: $('#sat-media-limit').val()
      }, function(res) {
        remaining--;
        if (res.success) processed += (res.data.processed || 0);
        if (remaining === 0) {
          btn.prop('disabled', false).text('Generate Alt Texts');
          $('#sat-media-result').html('<div class="sat-alert sat-alert-success">✅ Processed <strong>' + processed + '</strong> images across <strong>' + langs.length + '</strong> language(s).</div>');
        }
      });
    });
  });
})(jQuery);
</script>

