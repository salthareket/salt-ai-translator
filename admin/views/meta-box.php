<?php if (!defined('ABSPATH')) exit;

$postId      = $post->ID;
$postLang    = function_exists('pll_get_post_language') ? pll_get_post_language($postId) : $default;
$defaultLang = $default;
$isLocked    = (bool) get_post_meta($postId, '_sat_translation_lock', true);
$autoTranslate = (bool) $container->get('settings')->get('auto_translate', false);

// Bu post default dil mi yoksa çeviri mi?
$isDefaultLangPost = ($postLang === $defaultLang);

// Default dil post'unu bul (çeviri sayfasındaysak kaynak post'u göstereceğiz)
$sourcePostId = $postId;
if (!$isDefaultLangPost && function_exists('pll_get_post')) {
    $src = pll_get_post($postId, $defaultLang);
    if ($src) $sourcePostId = $src;
}

?>
<div id="sat-meta-box" data-post-id="<?= $postId ?>" data-auto-translate="<?= $autoTranslate ? '1' : '0' ?>">

  <!-- Auto-translate badge -->
  <?php if ($autoTranslate && !$isLocked): ?>
  <div id="sat-auto-translate-badge" style="display:flex;align-items:center;gap:6px;padding:7px 10px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:6px;margin-bottom:10px;font-size:12px;color:#15803d;">
    <span style="font-size:14px;">🌐</span>
    <div>
      <strong>Auto-translate is ON</strong>
      <div style="font-size:11px;color:#166534;margin-top:1px;">This post will be queued for translation when saved.</div>
    </div>
  </div>
  <?php elseif ($autoTranslate && $isLocked): ?>
  <div style="display:flex;align-items:center;gap:6px;padding:7px 10px;background:#fafafa;border:1px solid #e5e7eb;border-radius:6px;margin-bottom:10px;font-size:12px;color:#9ca3af;">
    <span style="font-size:14px;">🔒</span>
    <div>Auto-translate is ON — but this post is <strong>locked</strong>.<br><span style="font-size:11px;">Unlock to enable auto-translation.</span></div>
  </div>
  <?php endif; ?>

  <!-- Save feedback — Gutenberg/Classic'te save sonrası buraya yazılır -->
  <div id="sat-auto-save-feedback" style="display:none;padding:7px 10px;border-radius:6px;margin-bottom:10px;font-size:12px;"></div>

  <!-- Translation Lock -->
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;padding-bottom:10px;border-bottom:1px solid #eee;">
    <label style="display:flex;align-items:center;gap:6px;font-size:12px;cursor:pointer;font-weight:600;">
      <input type="checkbox" id="sat-mb-lock" <?= $isLocked ? 'checked' : '' ?> style="margin:0;">
      🔒 Lock (never auto-translate)
    </label>
    <span id="sat-mb-lock-status" style="font-size:11px;color:<?= $isLocked ? '#dc2626' : '#999' ?>;">
      <?= $isLocked ? 'Locked' : '' ?>
    </span>
  </div>

  <?php if ($isLocked): ?>
    <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:4px;padding:8px;font-size:12px;color:#991b1b;margin-bottom:10px;">
      🔒 This post is locked. It will be skipped in bulk translations.
    </div>
  <?php endif; ?>

  <?php if (!$isDefaultLangPost): ?>
    <!-- Default dil olmayan post editöründeyiz — kaynak bilgisini göster -->
    <div style="background:#f0f4ff;border:1px solid #c7d2fe;border-radius:4px;padding:8px;font-size:12px;color:#3730a3;margin-bottom:12px;">
      ℹ️ Source language: <strong><?= esc_html(strtoupper($defaultLang)) ?></strong>
      <?php if ($sourcePostId !== $postId): ?>
        — <a href="<?= get_edit_post_link($sourcePostId) ?>" style="color:#6366f1;">Edit source post →</a>
      <?php endif; ?>
      <br><small style="color:#6b7280;">Translations are always generated from the source (<?= esc_html($defaultLang) ?>) post.</small>
    </div>
  <?php endif; ?>

  <!-- Target Languages — mevcut post dili HARİÇ, default dil HARİÇ (default dil post'undaysak), çevirilerin durumu ile -->
  <div class="sat-form-group" style="margin-bottom:10px;">
    <div style="font-size:11px;color:#666;margin-bottom:6px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;">
      Target Languages
      <?php if (!$isDefaultLangPost): ?>
        <span style="font-size:10px;font-weight:400;color:#999;">(from <?= esc_html(strtoupper($defaultLang)) ?>)</span>
      <?php endif; ?>
    </div>

    <?php
    $hasTargetLangs = false;
    foreach ($languages as $code => $label):
      // Mevcut post'un dilini atla
      if ($code === $postLang) continue;
      // Default dil post'undaysak default dili atla
      if ($isDefaultLangPost && $code === $defaultLang) continue;
      // Default dil değilsek default dili de atla (zaten kaynak)
      if (!$isDefaultLangPost && $code === $defaultLang) continue;

      $hasTargetLangs = true;

      // Bu dilde çeviri var mı?
      $translatedId = null;
      if (function_exists('pll_get_post')) {
          $translatedId = pll_get_post($sourcePostId, $code) ?: null;
      }
      $hasTranslation = !empty($translatedId);
      $editUrl = $hasTranslation ? get_edit_post_link($translatedId) : null;
    ?>
      <div style="display:flex;align-items:center;justify-content:space-between;padding:5px 0;border-bottom:1px solid #f5f5f5;">
        <label style="display:flex;align-items:center;gap:6px;font-size:12px;cursor:pointer;flex:1;">
          <input type="checkbox" class="sat-mb-lang-check" value="<?= esc_attr($code) ?>" style="margin:0;">
          <span><?= esc_html($label) ?> <span style="color:#999;font-size:11px;">(<?= esc_attr($code) ?>)</span></span>
        </label>
        <span style="font-size:11px;white-space:nowrap;margin-left:4px;">
          <?php if ($hasTranslation): ?>
            <span style="color:#16a34a;">✓</span>
            <?php if ($editUrl): ?>
              <a href="<?= esc_url($editUrl) ?>" target="_blank" style="color:#6366f1;font-size:10px;text-decoration:none;" title="Edit translation">↗</a>
            <?php endif; ?>
          <?php else: ?>
            <span style="color:#dc2626;font-size:10px;">missing</span>
          <?php endif; ?>
        </span>
      </div>
    <?php endforeach; ?>

    <?php if (!$hasTargetLangs): ?>
      <div style="font-size:12px;color:#999;padding:8px 0;">
        No other languages available.
      </div>
    <?php endif; ?>
  </div>

  <?php if ($hasTargetLangs && !$isLocked): ?>
    <?php if ($translator === 'openai'): ?>
    <div class="sat-form-group" style="margin-bottom:10px;">
      <textarea id="sat-mb-prompt" style="width:100%;padding:6px;border:1px solid #ddd;border-radius:4px;font-size:11px;resize:vertical;" rows="2" placeholder="Custom prompt (optional)..."></textarea>
    </div>
    <?php endif; ?>

    <button type="button" id="sat-mb-translate" class="button button-primary" style="width:100%;margin-bottom:8px;">
      Translate
    </button>

    <button type="button" id="sat-mb-alternatives" class="button" style="width:100%;margin-bottom:8px;">
      Get Title Alternatives
    </button>
  <?php endif; ?>

  <div id="sat-mb-status" style="font-size:12px;margin-top:8px;max-height:100px;overflow-y:auto;"></div>

  <!-- Alternatives panel -->
  <div id="sat-mb-alts-panel" style="display:none;margin-top:10px;">
    <div style="font-size:12px;font-weight:600;margin-bottom:6px;">Choose a translation:</div>
    <div id="sat-mb-alts-list"></div>
    <button type="button" id="sat-mb-use-alt" class="button button-primary" style="width:100%;margin-top:8px;display:none;">Use Selected</button>
  </div>

</div>

<script>
(function($){
  const nonce   = '<?= wp_create_nonce('sat_nonce') ?>';
  const postId  = <?= $postId ?>;
  const autoTranslate = <?= $autoTranslate ? 'true' : 'false' ?>;
  const isLocked = <?= $isLocked ? 'true' : 'false' ?>;
  let selectedAlt = null;

  // ── Auto-translate save feedback ────────────────────────────────────────────
  function showSaveFeedback(type, msg) {
    const $fb = $('#sat-auto-save-feedback');
    const styles = {
      queued:     { bg: '#f0fdf4', border: '#bbf7d0', color: '#15803d' },
      processing: { bg: '#eff6ff', border: '#bfdbfe', color: '#1d4ed8' },
      error:      { bg: '#fef2f2', border: '#fecaca', color: '#dc2626' },
    };
    const s = styles[type] || styles.queued;
    $fb.css({ background: s.bg, border: '1px solid ' + s.border, color: s.color })
       .html(msg).show();
  }

  function hideSaveFeedback() {
    $('#sat-auto-save-feedback').hide().html('');
  }

  if (autoTranslate && !isLocked) {
    // ── Gutenberg: wp.data subscribe ile save durumunu dinle ────────────────
    if (typeof wp !== 'undefined' && wp.data) {
      let wasSaving = false;
      let wasAutosaving = false;

      wp.data.subscribe(function() {
        const editor = wp.data.select('core/editor');
        if (!editor) return;

        const isSaving     = editor.isSavingPost();
        const isAutosaving = editor.isAutosavingPost();
        const didSave      = editor.didPostSaveRequestSucceed();

        // Autosave'i atla — sadece gerçek save
        if (isAutosaving) { wasAutosaving = true; return; }

        if (isSaving && !wasSaving && !wasAutosaving) {
          // Save başladı
          wasSaving = true;
          showSaveFeedback('processing', '⏳ Saving... translations will be queued.');
        }

        if (!isSaving && wasSaving) {
          wasSaving    = false;
          wasAutosaving = false;

          if (didSave) {
            // Queue status'u kontrol et — kaç dil kuyruğa girdi?
            $.post(ajaxurl, { action: 'sat_queue_status', nonce, type: 'post' }, function(res) {
              if (res.success && res.data.pending > 0) {
                const pending = res.data.pending;
                showSaveFeedback('queued',
                  '✅ Queued for translation — <strong>' + pending + ' item(s)</strong> pending.' +
                  (res.data.next_run ? ' <span style="opacity:.7;font-size:11px;">Next run ~' + Math.max(0, Math.round(res.data.next_run - Date.now()/1000)) + 's</span>' : '')
                );
                // 8 saniye sonra kaybolsun
                setTimeout(hideSaveFeedback, 8000);
              } else {
                hideSaveFeedback();
              }
            });
          } else {
            hideSaveFeedback();
          }
        }
      });
    }

    // ── Classic editor: form submit hook ────────────────────────────────────
    // Classic editörde #post form'a submit listener ekle
    if ($('#post').length && typeof wp === 'undefined') {
      $('#post').on('submit', function() {
        showSaveFeedback('processing', '⏳ Saving... translations will be queued automatically.');
      });
    }
  }

  // ── Translation Lock ────────────────────────────────────────────────────────
  $('#sat-mb-lock').on('change', function() {
    const locked = this.checked;
    $.post(ajaxurl, {
      action:  'sat_set_translation_lock',
      nonce:   nonce,
      post_id: postId,
      locked:  locked ? 1 : 0
    }, function(res) {
      if (res.success) {
        $('#sat-mb-lock-status').text(locked ? 'Locked' : '').css('color', locked ? '#dc2626' : '#999');
        if (locked) {
          if (!$('#sat-mb-lock-notice').length) {
            $('#sat-meta-box').prepend('<div id="sat-mb-lock-notice" style="background:#fef2f2;border:1px solid #fecaca;border-radius:4px;padding:8px;font-size:12px;color:#991b1b;margin-bottom:10px;">🔒 This post is locked. It will be skipped in bulk translations.</div>');
          }
          // Auto-translate badge'ini güncelle
          if (autoTranslate) {
            $('#sat-auto-translate-badge').replaceWith(
              '<div id="sat-auto-translate-badge" style="display:flex;align-items:center;gap:6px;padding:7px 10px;background:#fafafa;border:1px solid #e5e7eb;border-radius:6px;margin-bottom:10px;font-size:12px;color:#9ca3af;">' +
              '<span style="font-size:14px;">🔒</span>' +
              '<div>Auto-translate is ON — but this post is <strong>locked</strong>.<br><span style="font-size:11px;">Unlock to enable auto-translation.</span></div></div>'
            );
          }
        } else {
          $('#sat-mb-lock-notice').remove();
          // Auto-translate badge'ini geri getir
          if (autoTranslate) {
            $('#sat-auto-translate-badge').replaceWith(
              '<div id="sat-auto-translate-badge" style="display:flex;align-items:center;gap:6px;padding:7px 10px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:6px;margin-bottom:10px;font-size:12px;color:#15803d;">' +
              '<span style="font-size:14px;">🌐</span>' +
              '<div><strong>Auto-translate is ON</strong><div style="font-size:11px;color:#166534;margin-top:1px;">This post will be queued for translation when saved.</div></div></div>'
            );
          }
        }
      }
    });
  });

  // ── Translate ───────────────────────────────────────────────────────────────
  function getSelectedLangs() {
    return $('.sat-mb-lang-check:checked').map(function(){ return this.value; }).get();
  }

  $('#sat-mb-translate').on('click', function() {
    const langs = getSelectedLangs();
    if (!langs.length) { alert('Select at least one language.'); return; }
    const btn = $(this).prop('disabled', true).text('Translating...');
    $('#sat-mb-status').html('<span style="color:#666;">⏳ Translating ' + langs.length + ' language(s)...</span>');

    let i = 0;
    const results = [];

    function next() {
      if (i >= langs.length) {
        btn.prop('disabled', false).text('Translate');
        const msgs = results.map(r => r.ok
          ? `✅ ${r.lang} — <a href="${r.edit_url}" target="_blank" style="color:#6366f1;">Edit ↗</a>`
          : `❌ ${r.lang}: ${r.error}`
        ).join('<br>');
        $('#sat-mb-status').html(msgs);

        // Çeviri durumu ikonlarını güncelle (yeni çevrilen dil varsa ✓ yap)
        results.filter(r => r.ok).forEach(r => {
          $('.sat-mb-lang-check[value="' + r.lang + '"]').closest('div')
            .find('span:last')
            .html('<span style="color:#16a34a;">✓</span> <a href="' + r.edit_url + '" target="_blank" style="color:#6366f1;font-size:10px;text-decoration:none;" title="Edit translation">↗</a>');
        });
        return;
      }

      const lang = langs[i++];
      $('#sat-mb-status').html('<span style="color:#666;">⏳ [' + i + '/' + langs.length + '] → ' + lang + '...</span>');

      $.post(ajaxurl, {
        action: 'sat_translate_post', nonce,
        post_id: postId, lang: lang,
        custom_prompt: $('#sat-mb-prompt').val()
      }, function(res) {
        if (res.success) {
          results.push({ lang, ok: true, edit_url: res.data.edit_url });
        } else {
          results.push({ lang, ok: false, error: (typeof res.data === 'string' ? res.data : 'Error') });
        }
        next();
      }).fail(function() {
        results.push({ lang, ok: false, error: 'AJAX failed' });
        next();
      });
    }
    next();
  });

  // ── Alternatives ────────────────────────────────────────────────────────────
  $('#sat-mb-alternatives').on('click', function() {
    const langs = getSelectedLangs();
    if (!langs.length) { alert('Select a language.'); return; }
    const btn = $(this).prop('disabled', true).text('Loading...');

    $.post(ajaxurl, {
      action: 'sat_translate_post_alts', nonce,
      post_id: postId, lang: langs[0], field: 'title', count: 3
    }, function(res) {
      btn.prop('disabled', false).text('Get Title Alternatives');
      if (!res.success) return;
      const list = $('#sat-mb-alts-list').empty();
      res.data.alternatives.forEach(function(alt, i) {
        list.append(`<div class="sat-alt-item" data-alt="${alt}" style="padding:6px 8px;border:1px solid #ddd;border-radius:4px;margin-bottom:4px;cursor:pointer;font-size:12px;line-height:1.4;"><strong>${i+1}.</strong> ${alt}</div>`);
      });
      $('#sat-mb-alts-panel').show();
    });
  });

  $(document).on('click', '.sat-alt-item', function() {
    $('.sat-alt-item').css({'background':'','border-color':'#ddd'});
    $(this).css({'background':'#f0f4ff','border-color':'#6366f1'});
    selectedAlt = $(this).data('alt');
    $('#sat-mb-use-alt').show();
  });

  $('#sat-mb-use-alt').on('click', function() {
    if (!selectedAlt) return;
    $('#sat-mb-status').html('<span style="color:#666;font-size:11px;">📋 ' + selectedAlt + '</span>');
    $('#sat-mb-alts-panel').hide();
  });

})(jQuery);
</script>
