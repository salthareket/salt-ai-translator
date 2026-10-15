/**
 * Salt AI Translator — Inline Translation + Admin Bar
 *
 * @version 1.0.0
 * @changelog
 *   1.0.0 - 2026-06-25
 *     - Add: Text selection → floating popup with 3 AI alternatives
 *     - Add: Each alternative: copy-to-clipboard button
 *     - Add: Admin bar "Inline Mode" toggle — localStorage persisted
 *     - Add: Admin bar "Translate Page" buttons — AJAX full page translate
 *     - Add: Popup closes on outside click or Escape
 *     - Add: Loading state with spinner while waiting for AI
 *     - Add: Target lang auto-detected (satFrontend.currentLang or dropdown)
 */
(function ($) {
    'use strict';

    if (typeof satFrontend === 'undefined') return;

    var cfg      = satFrontend;
    var STORAGE_KEY = 'sat_inline_mode';
    var inlineMode  = false;
    var $popup      = null;

    /* ─── Init ───────────────────────────────────────────────────── */
    $(function () {
        buildPopup();
        initAdminBar();
        restoreInlineMode();
    });

    /* ─── Admin Bar ─────────────────────────────────────────────── */
    function initAdminBar() {
        // Inline mode toggle
        $(document).on('click', '.sat-ab-inline-toggle', function (e) {
            e.preventDefault();
            toggleInlineMode();
        });

        // Translate page buttons
        $(document).on('click', '.sat-ab-translate-page', function (e) {
            e.preventDefault();
            var $btn    = $(this);
            var postId  = $btn.data('post-id') || $btn.attr('data-post-id') || cfg.postId;
            var lang    = $btn.data('lang')    || $btn.attr('data-lang');
            if (!postId || !lang) return;
            translatePage(postId, lang, $btn);
        });
    }

    function toggleInlineMode() {
        inlineMode = !inlineMode;
        localStorage.setItem(STORAGE_KEY, inlineMode ? '1' : '0');
        updateInlineModeLabel();

        if (!inlineMode && $popup) {
            $popup.hide();
        }
    }

    function restoreInlineMode() {
        inlineMode = localStorage.getItem(STORAGE_KEY) === '1';
        updateInlineModeLabel();
    }

    function updateInlineModeLabel() {
        var $label = $('#sat-inline-toggle-label');
        if (inlineMode) {
            $label.html('✏️ Inline Mode: <strong style="color:#6ee7b7;">ON</strong>');
            $('body').addClass('sat-inline-active');
        } else {
            $label.html('✏️ Inline Mode: <strong>OFF</strong>');
            $('body').removeClass('sat-inline-active');
        }
    }

    function translatePage(postId, lang, $btn) {
        var origTitle = $btn.find('.ab-item').text() || $btn.text();
        $btn.find('.ab-item').html('<span class="sat-ab-spinner"></span> Translating…');

        $.ajax({
            url:    cfg.ajaxUrl,
            method: 'POST',
            data: {
                action:  'sat_translate_post',
                nonce:   cfg.nonce,
                post_id: postId,
                lang:    lang,
            },
            success: function (res) {
                if (res.success) {
                    $btn.find('.ab-item').html('✓ Done — <a href="' + res.data.view_url + '" style="color:#6ee7b7;" target="_blank">View</a>');
                } else {
                    $btn.find('.ab-item').html('✗ ' + (res.data || 'Error'));
                    setTimeout(function () {
                        $btn.find('.ab-item').text(origTitle);
                    }, 3000);
                }
            },
            error: function () {
                $btn.find('.ab-item').text('✗ Request failed');
                setTimeout(function () {
                    $btn.find('.ab-item').text(origTitle);
                }, 3000);
            },
        });
    }

    /* ─── Text Selection → Popup ────────────────────────────────── */
    function buildPopup() {
        $popup = $('<div id="sat-inline-popup" class="sat-inline-popup" style="display:none;" role="dialog" aria-label="Salt AI Translator">'
            + '<div class="sat-inline-popup__header">'
            +   '<span class="sat-inline-popup__icon">🌐</span>'
            +   '<span class="sat-inline-popup__title">AI Translations</span>'
            +   '<select class="sat-inline-lang-select" aria-label="Target language"></select>'
            +   '<button class="sat-inline-popup__close" aria-label="Close">×</button>'
            + '</div>'
            + '<div class="sat-inline-popup__body">'
            +   '<div class="sat-inline-loading" style="display:none;"><span class="sat-inline-spinner"></span> Translating…</div>'
            +   '<div class="sat-inline-alts"></div>'
            + '</div>'
            + '</div>');

        // Populate language select
        var $select = $popup.find('.sat-inline-lang-select');
        $.each(cfg.languages, function (code, label) {
            if (code !== cfg.defaultLang) {
                var selected = (code === cfg.currentLang) ? ' selected' : '';
                $select.append('<option value="' + code + '"' + selected + '>' + label + ' (' + code.toUpperCase() + ')</option>');
            }
        });

        $('body').append($popup);

        // Close
        $popup.on('click', '.sat-inline-popup__close', function () {
            $popup.hide();
        });

        // Outside click
        $(document).on('mousedown.sat-inline', function (e) {
            if ($popup.is(':visible') && !$(e.target).closest('#sat-inline-popup').length) {
                $popup.hide();
            }
        });

        // Escape key
        $(document).on('keydown.sat-inline', function (e) {
            if (e.key === 'Escape' && $popup.is(':visible')) {
                $popup.hide();
            }
        });
    }

    /* Text selection listener */
    $(document).on('mouseup.sat-inline touchend.sat-inline', function (e) {
        if (!inlineMode) return;

        // Don't trigger inside the popup itself
        if ($(e.target).closest('#sat-inline-popup').length) return;

        setTimeout(function () {
            var selection = window.getSelection();
            var text      = selection ? $.trim(selection.toString()) : '';

            if (!text || text.length < 2) {
                return;
            }

            showPopup(text, e);
        }, 50);
    });

    function showPopup(text, e) {
        if (!$popup) return;

        var $alts    = $popup.find('.sat-inline-alts').empty();
        var $loading = $popup.find('.sat-inline-loading').show();
        $popup.find('.sat-inline-popup__title').text('AI Translations');

        positionPopup(e);
        $popup.show();

        var lang = $popup.find('.sat-inline-lang-select').val();
        if (!lang) {
            $loading.hide();
            $alts.html('<p class="sat-inline-error">No target language available.</p>');
            return;
        }

        $.ajax({
            url:    cfg.ajaxUrl,
            method: 'POST',
            data: {
                action: 'sat_inline_translate',
                nonce:  cfg.nonce,
                text:   text,
                lang:   lang,
                count:  3,
            },
            success: function (res) {
                $loading.hide();
                if (res.success && res.data.alternatives && res.data.alternatives.length) {
                    renderAlternatives(res.data.alternatives, text);
                } else {
                    $alts.html('<p class="sat-inline-error">⚠ ' + (res.data || 'Translation failed') + '</p>');
                }
            },
            error: function () {
                $loading.hide();
                $alts.html('<p class="sat-inline-error">⚠ Request failed. Check your API settings.</p>');
            },
        });
    }

    function renderAlternatives(alts, sourceText) {
        var $alts = $popup.find('.sat-inline-alts').empty();

        $alts.append(
            '<p class="sat-inline-source"><strong>Source:</strong> <em>' + escHtml(sourceText.substring(0, 120)) + (sourceText.length > 120 ? '…' : '') + '</em></p>'
        );

        alts.forEach(function (alt, idx) {
            var $row = $('<div class="sat-inline-alt-row">'
                + '<span class="sat-inline-alt-num">' + (idx + 1) + '</span>'
                + '<span class="sat-inline-alt-text">' + escHtml(alt) + '</span>'
                + '<button class="sat-inline-copy-btn" data-text="' + escAttr(alt) + '" title="Copy to clipboard">📋</button>'
                + '</div>');
            $alts.append($row);
        });

        // Copy handler
        $alts.on('click', '.sat-inline-copy-btn', function () {
            var text = $(this).data('text');
            copyToClipboard(text, $(this));
        });
    }

    function positionPopup(e) {
        var selection = window.getSelection();
        var rect = null;

        if (selection && selection.rangeCount > 0) {
            rect = selection.getRangeAt(0).getBoundingClientRect();
        }

        var top, left;

        if (rect) {
            top  = rect.bottom + window.scrollY + 8;
            left = rect.left   + window.scrollX;
        } else if (e && e.pageX) {
            top  = e.pageY + 10;
            left = e.pageX;
        } else {
            top  = 200;
            left = 200;
        }

        // Clamp to viewport
        var popupW = 360;
        var maxLeft = $(window).width() - popupW - 20;
        if (left > maxLeft) left = maxLeft;
        if (left < 10) left = 10;

        $popup.css({ top: top, left: left });
    }

    /* ─── Helpers ───────────────────────────────────────────────── */
    function copyToClipboard(text, $btn) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(function () {
                flashCopied($btn);
            }).catch(function () {
                fallbackCopy(text, $btn);
            });
        } else {
            fallbackCopy(text, $btn);
        }
    }

    function fallbackCopy(text, $btn) {
        var $ta = $('<textarea>').css({ position: 'fixed', opacity: 0 }).val(text).appendTo('body');
        $ta[0].select();
        try {
            document.execCommand('copy');
            flashCopied($btn);
        } catch (e) {}
        $ta.remove();
    }

    function flashCopied($btn) {
        var orig = $btn.html();
        $btn.html('✓').addClass('sat-copied');
        setTimeout(function () {
            $btn.html(orig).removeClass('sat-copied');
        }, 1800);
    }

    function escHtml(str) {
        return $('<div>').text(str).html();
    }

    function escAttr(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

})(jQuery);
