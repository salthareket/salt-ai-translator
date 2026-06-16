/**
 * Salt AI Translator — Admin JS
 * Global utilities used across all admin pages
 */
(function ($) {
  'use strict';

  // ── Tab switching ──────────────────────────────────────────
  $(document).on('click', '.sat-tab', function () {
    const $tabs = $(this).closest('.sat-tabs, nav').find('.sat-tab');
    $tabs.removeClass('is-active');
    $(this).addClass('is-active');

    const target = $(this).data('tab');
    if (target) {
      $('.sat-tab-content').hide();
      $('#tab-' + target).show();
    }
  });

  // ── Translator option card selection ──────────────────────
  $(document).on('click', '.sat-translator-option', function () {
    $('.sat-translator-option').removeClass('selected').css('border-color', 'var(--sat-gray-200)');
    $(this).addClass('selected').css('border-color', 'var(--sat-primary)');
    $(this).find('input[type=radio]').prop('checked', true).trigger('change');
  });

  // ── Language chip toggle ───────────────────────────────────
  $(document).on('click', '.sat-lang-chip', function (e) {
    // Native checkbox click'ini engelle — biz manuel kontrol ediyoruz
    if ($(e.target).is('input[type=checkbox]')) return; // checkbox'a direkt tıklandıysa native davranış
    e.preventDefault();
    const $input  = $(this).find('input[type=checkbox]');
    const checked = !$input.prop('checked');
    $input.prop('checked', checked);
    $(this).toggleClass('selected', checked);
  });

  // Chip içindeki checkbox değişince chip'i senkronize et
  $(document).on('change', '.sat-lang-chip input[type=checkbox]', function () {
    $(this).closest('.sat-lang-chip').toggleClass('selected', $(this).prop('checked'));
  });

  // Sayfa yüklenince mevcut durumu yansıt
  $(function () {
    $('.sat-lang-chip input[type=checkbox]').each(function () {
      $(this).closest('.sat-lang-chip').toggleClass('selected', $(this).prop('checked'));
    });
  });

  // ── "All languages" checkbox ───────────────────────────────
  $(document).on('change', '#sat-all-langs', function () {
    const checked = $(this).prop('checked');
    $('input[name="langs[]"]').prop('checked', checked);
    $('.sat-lang-chip').toggleClass('selected', checked);
  });

  // ── Auto-show/hide sections based on data-sat-toggle ──────
  $(document).on('change', 'input[type=checkbox][id]', function () {
    const id      = $(this).attr('id');
    const checked = $(this).prop('checked');
    $('[data-sat-toggle="' + id + '"]').toggle(checked);
  });

  // Init toggles on page load
  $('input[type=checkbox][id]').each(function () {
    const id      = $(this).attr('id');
    const checked = $(this).prop('checked');
    $('[data-sat-toggle="' + id + '"]').toggle(checked);
  });

  // ── Queue status polling (global) ─────────────────────────
  window.satPollQueue = function (type, onUpdate, onDone) {
    const interval = setInterval(function () {
      $.post(ajaxurl, {
        action: 'sat_queue_status',
        nonce:  satConfig.nonce,
        type:   type,
      }, function (res) {
        if (!res.success) return;
        const d = res.data;
        if (typeof onUpdate === 'function') onUpdate(d);
        if (d.pending === 0 && d.processing === 0) {
          clearInterval(interval);
          if (typeof onDone === 'function') onDone(d);
        }
      });
    }, 3000);
    return interval;
  };

  // ── Toast notifications ───────────────────────────────────
  window.satToast = function (msg, type) {
    type = type || 'info';
    const colors = {
      info:    '#6366f1',
      success: '#22c55e',
      error:   '#ef4444',
      warning: '#f59e0b',
    };
    const $toast = $('<div>')
      .css({
        position:     'fixed',
        bottom:       '24px',
        right:        '24px',
        background:   colors[type] || colors.info,
        color:        '#fff',
        padding:      '12px 20px',
        borderRadius: '8px',
        fontSize:     '14px',
        fontWeight:   '500',
        zIndex:       99999,
        boxShadow:    '0 4px 12px rgba(0,0,0,.15)',
        maxWidth:     '360px',
      })
      .text(msg)
      .appendTo('body');

    setTimeout(function () { $toast.fadeOut(300, function () { $(this).remove(); }); }, 3500);
  };

  // ── Confirm dangerous actions ─────────────────────────────
  $(document).on('click', '[data-sat-confirm]', function (e) {
    const msg = $(this).data('sat-confirm') || 'Are you sure?';
    if (!confirm(msg)) e.preventDefault();
  });

  // ── Select2 multi-select (Exclusions, Export post types) ──
  $(function () {
    // Select2 var mı kontrol et (WC veya ACF üzerinden yükleniyor)
    if ( typeof $.fn.select2 === 'undefined' ) {
      // Select2 yok — native multiselect'i biraz güzelleştir
      $('.sat-select2-multi').css({'min-height': '80px', 'border-radius': '6px', 'border-color': '#ddd'});
      return;
    }

    $('.sat-select2-multi').select2({
      placeholder:   'Select items...',
      allowClear:    true,
      closeOnSelect: false,
      width:         '100%',
    }).on('select2:select select2:unselect', function () {
      // Multi-select'te dropdown açık kalsın
      var $el = $(this);
      setTimeout(function () { $el.select2('open'); }, 10);
    });
  });

})(jQuery);
