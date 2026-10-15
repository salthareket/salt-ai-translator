/**
 * Salt AI Translator — Frontend Inline Translation
 *
 * Admin bar üzerinden:
 * 1. "Translate Page" → tüm post tek tıkla çevrilir (singular sayfalarda)
 * 2. "Inline Mode" toggle → metin seç → popup → alternatifler → clipboard
 *
 * @version 1.2.0
 * @changelog
 *   1.2.0 - 2026-06-25
 *     - Fix: "Translate" butonuna tıklayınca popup kapanıp yeniden açılıyordu
 *            Root cause: mouseup eventi popup içinde de tetiklenip onTextSelect çalışıyordu
 *            Fix: popup.addEventListener('mouseup', stopPropagation)
 *            Fix: onTextSelect — popup içinden gelen mouseup ignore edilir
 *     - Fix: AJAX dönünce alternatifler gösterilmiyordu — eski resultsEl ref geçersizdi
 *            Fix: doInlineTranslate() — resultsEl parametresi kaldırıldı,
 *            her zaman DOM'dan canlı #sat-inline-results okur
 *     - Fix: popup içindeki tüm click/mousedown'lara e.stopPropagation() eklendi
 *   1.0.0 - 2026-06-25
 *     - Add: Admin bar "Translate Page" dropdown (tüm diller)
 *     - Add: Admin bar "Inline Mode" toggle
 *     - Add: Metin seçince mini popup — çevir, alternatifler, clipboard
 *     - Add: Inline mode kapalıyken sıfır interference
 */
(function() {
  'use strict';

  if (typeof satFrontend === 'undefined') return;

  const NONCE   = satFrontend.nonce;
  const AJAXURL = satFrontend.ajaxUrl;
  const LANGS   = satFrontend.langs;   // [{code:'de',label:'German'}, ...]
  const POST_ID = satFrontend.postId;  // 0 = archive/taxonomy sayfası
  const SOURCE  = satFrontend.sourceLang;

  let inlineMode = false;
  let popup      = null;
  let selTimeout = null;
  let savedRange = null;  // Seçili text range — Apply için saklanır

  // ── Admin bar menüsü ──────────────────────────────────────────────────────

  function initAdminBar() {
    const bar = document.getElementById('wpadminbar');
    if (!bar) return;

    // post_id=0 ise Translate Page submenu item'larını ve parent node'u gizle
    // (archive, taxonomy, search gibi sayfalarda sayfa çevirisi desteklenmiyor)
    const translatePageNode = document.getElementById('wp-admin-bar-sat-translate-page');
    if (!POST_ID) {
      if (translatePageNode) translatePageNode.style.display = 'none';
    } else {
      // Submenu item'larına click ekle
      LANGS.forEach(function(l) {
        const node = document.getElementById('wp-admin-bar-sat-translate-page-' + l.code);
        if (!node) return;
        const link = node.querySelector('a');
        if (!link) return;
        link.dataset.lang = l.code;
        link.classList.add('sat-ab-translate-lang');
        link.addEventListener('click', function(e) {
          e.preventDefault();
          translatePage(l.code, this);
        });
      });
    }

    // Inline mode toggle — ID: wp-admin-bar-sat-inline-mode
    const inlineNode = document.getElementById('wp-admin-bar-sat-inline-mode');
    if (inlineNode) {
      const inlineLink = inlineNode.querySelector('a');
      if (inlineLink) {
        inlineLink.id = 'sat-ab-inline-toggle';
        inlineLink.addEventListener('click', function(e) {
          e.preventDefault();
          toggleInlineMode();
        });
      }
    }
  }

  // ── Translate Page ────────────────────────────────────────────────────────

  function translatePage(lang, triggerEl) {
    if (!POST_ID) { showToast('No post ID found.', 'error'); return; }

    const origText = triggerEl ? triggerEl.textContent.trim() : '';
    if (triggerEl) {
      triggerEl.textContent = '⏳ Translating...';
      triggerEl.style.opacity = '0.6';
      triggerEl.style.pointerEvents = 'none';
    }

    ajax('sat_inline_translate_page', { post_id: POST_ID, lang: lang }, function(res) {
      if (triggerEl) {
        triggerEl.textContent = '✓ Done';
        triggerEl.style.opacity = '';
        triggerEl.style.pointerEvents = '';
        setTimeout(function() { triggerEl.textContent = origText; }, 3000);
      }
      if (res.success && res.data.view_url) {
        showToast('✓ Page translated! <a href="' + res.data.view_url + '" style="color:#fff;text-decoration:underline;">View →</a>', 'success', 6000);
      }
    }, function(err) {
      if (triggerEl) {
        triggerEl.textContent = origText;
        triggerEl.style.opacity = '';
        triggerEl.style.pointerEvents = '';
      }
      showToast('✗ ' + (err || 'Translation failed'), 'error');
    });
  }

  // ── Inline Mode ───────────────────────────────────────────────────────────

  function toggleInlineMode() {
    inlineMode = !inlineMode;
    const inlineNode = document.getElementById('wp-admin-bar-sat-inline-mode');
    const btn = inlineNode ? inlineNode.querySelector('a') : null;

    if (inlineMode) {
      document.body.classList.add('sat-inline-active');
      if (btn) {
        btn.innerHTML = '✕ Exit Inline Mode';
        btn.style.color = '#fbbf24';
      }
      if (inlineNode) inlineNode.style.background = 'rgba(99,102,241,0.3)';
      showToast('🌐 Inline mode ON — select any text to translate', 'info', 4000);
      document.addEventListener('mouseup', onTextSelect);
    } else {
      document.body.classList.remove('sat-inline-active');
      if (btn) {
        btn.innerHTML = '✏️ Inline Mode';
        btn.style.color = '';
      }
      if (inlineNode) inlineNode.style.background = '';
      document.removeEventListener('mouseup', onTextSelect);
      removePopup();
    }
  }

  function onTextSelect(e) {
    // Popup içinde bir şeye tıklandıysa ignore et — popup kapanmasın
    if (popup && e.target && popup.contains(e.target)) return;

    clearTimeout(selTimeout);
    selTimeout = setTimeout(function() {
      const sel  = window.getSelection();
      const text = sel ? sel.toString().trim() : '';

      // Seçim yoksa veya çok kısaysa popup'ı kapat
      if (!text || text.length < 2) {
        // Ama popup açıksa kapatma — kullanıcı popup içinde tıklıyor olabilir
        if (!popup) return;
        // Seçim popup dışında temizlendiyse kapat
        return;
      }

      // Seçim popup içindeyse ignore
      if (popup && sel.anchorNode && popup.contains(sel.anchorNode)) return;

      const range = sel.getRangeAt(0);
      savedRange = range.cloneRange();  // Apply için sakla
      const rect  = range.getBoundingClientRect();
      showPopup(text, rect);
    }, 200);
  }

  // ── Popup ─────────────────────────────────────────────────────────────────

  function showPopup(text, rect) {
    removePopup();

    popup = document.createElement('div');
    popup.id = 'sat-inline-popup';
    popup.innerHTML = buildPopupHtml(text);

    document.body.appendChild(popup);

    // Pozisyon — selection rect'in altında
    const scrollY = window.scrollY || window.pageYOffset;
    const scrollX = window.scrollX || window.pageXOffset;
    let top  = rect.bottom + scrollY + 8;
    let left = rect.left  + scrollX;

    // Ekran dışına taşmasın
    const pw = 320;
    if (left + pw > window.innerWidth + scrollX) {
      left = Math.max(scrollX + 8, window.innerWidth + scrollX - pw - 8);
    }

    popup.style.top  = top + 'px';
    popup.style.left = left + 'px';

    // Popup içindeki mousedown/mouseup event'lerini durdur —
    // böylece onTextSelect ve onOutsideClick tetiklenmez
    popup.addEventListener('mousedown', function(e) { e.stopPropagation(); });
    popup.addEventListener('mouseup',   function(e) { e.stopPropagation(); });

    const langSelect   = popup.querySelector('#sat-inline-lang');
    const translateBtn = popup.querySelector('#sat-inline-btn');
    const closeBtn     = popup.querySelector('#sat-inline-close');
    const resultsEl    = popup.querySelector('#sat-inline-results');

    if (closeBtn) closeBtn.addEventListener('click', function(e) {
      e.stopPropagation();
      removePopup();
    });

    if (translateBtn) {
      translateBtn.addEventListener('click', function(e) {
        e.stopPropagation();
        const lang = langSelect ? langSelect.value : (LANGS[0] ? LANGS[0].code : '');
        if (!lang) return;
        doInlineTranslate(text, lang, translateBtn);
      });
    }

    // Click dışarısı → kapat (küçük gecikme ile ekle)
    setTimeout(function() {
      document.addEventListener('mousedown', onOutsideClick);
    }, 150);
  }

  function buildPopupHtml(text) {
    const shortText = text.length > 60 ? text.substring(0, 60) + '…' : text;
    const langOptions = LANGS
      .filter(function(l) { return l.code !== SOURCE; })
      .map(function(l) { return '<option value="' + esc(l.code) + '">' + esc(l.label) + '</option>'; })
      .join('');

    return '<div class="sat-popup-header">'
      + '<span class="sat-popup-icon">🌐</span>'
      + '<span class="sat-popup-text">"' + esc(shortText) + '"</span>'
      + '<button id="sat-inline-close" class="sat-popup-close" title="Close">✕</button>'
      + '</div>'
      + '<div class="sat-popup-controls">'
      + '<select id="sat-inline-lang" class="sat-popup-select">' + langOptions + '</select>'
      + '<button id="sat-inline-btn" class="sat-popup-btn">Translate</button>'
      + '</div>'
      + '<div id="sat-inline-results" class="sat-popup-results"></div>';
  }

  function doInlineTranslate(text, lang, btn) {
    // Her zaman DOM'dan canlı referans al — eski ref geçersiz olabilir
    const resultsEl = document.querySelector('#sat-inline-popup #sat-inline-results');
    if (!resultsEl) return;

    resultsEl.innerHTML = '<div class="sat-popup-loading">⏳ Translating…</div>';
    btn.disabled = true;

    ajax('sat_inline_translate', { text: text, lang: lang, count: 3 }, function(res) {
      // AJAX döndüğünde popup hâlâ açık mı?
      const el = document.querySelector('#sat-inline-popup #sat-inline-results');
      if (!el) return; // popup kapanmış, işlem yapmaya gerek yok

      btn.disabled = false;

      if (!res.success) {
        el.innerHTML = '<div class="sat-popup-error">✗ ' + esc(res.data || 'Error') + '</div>';
        return;
      }

      const alts = res.data.alternatives || [];
      if (!alts.length) {
        el.innerHTML = '<div class="sat-popup-error">No results</div>';
        return;
      }

      let html = '<div class="sat-popup-alts">';
      alts.forEach(function(alt, i) {
        html += '<label class="sat-popup-alt">'
          + '<input type="radio" name="sat_alt" value="' + esc(alt) + '"' + (i === 0 ? ' checked' : '') + '>'
          + '<span>' + esc(alt) + '</span>'
          + '</label>';
      });
      html += '</div>';
      html += '<div class="sat-popup-actions">'
        + '<button class="sat-popup-copy-btn" id="sat-apply-btn">✓ Apply</button>'
        + '<button class="sat-popup-copy-btn sat-popup-copy-only" id="sat-copy-btn">📋 Copy</button>'
        + '</div>';
      el.innerHTML = html;

      // Apply — DOM'da anlık değiştir
      el.querySelector('#sat-apply-btn').addEventListener('click', function(e) {
        e.stopPropagation();
        const selected = el.querySelector('input[name="sat_alt"]:checked');
        if (!selected) return;
        applyToDOM(selected.value);
        this.textContent = '✓ Applied!';
        setTimeout(function() { removePopup(); }, 800);
      });

      // Copy — clipboard
      el.querySelector('#sat-copy-btn').addEventListener('click', function(e) {
        e.stopPropagation();
        const selected = el.querySelector('input[name="sat_alt"]:checked');
        if (!selected) return;
        copyToClipboard(selected.value);
        this.textContent = '✓ Copied!';
        setTimeout(function() {
          const b2 = el.querySelector('#sat-copy-btn');
          if (b2) b2.textContent = '📋 Copy';
        }, 2000);
      });
    }, function(err) {
      const el = document.querySelector('#sat-inline-popup #sat-inline-results');
      if (el) el.innerHTML = '<div class="sat-popup-error">✗ ' + esc(err || 'Error') + '</div>';
      btn.disabled = false;
    });
  }

  function removePopup() {
    if (popup) { popup.remove(); popup = null; }
    document.removeEventListener('mousedown', onOutsideClick);
  }

  function onOutsideClick(e) {
    if (popup && !popup.contains(e.target)) removePopup();
  }

  // ── Yardımcılar ───────────────────────────────────────────────────────────

  function ajax(action, data, onSuccess, onError) {
    const fd = new FormData();
    fd.append('action', action);
    fd.append('_ajax_nonce', NONCE);
    Object.keys(data).forEach(function(k) { fd.append(k, data[k]); });

    fetch(AJAXURL, { method: 'POST', body: fd })
      .then(function(r) { return r.json(); })
      .then(function(res) {
        if (res.success) { onSuccess(res); }
        else { (onError || function() {})(res.data); }
      })
      .catch(function(e) { (onError || function() {})(e.message); });
  }

  function copyToClipboard(text) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text);
    } else {
      const ta = document.createElement('textarea');
      ta.value = text;
      ta.style.position = 'fixed';
      ta.style.opacity = '0';
      document.body.appendChild(ta);
      ta.select();
      document.execCommand('copy');
      ta.remove();
    }
  }

  function showToast(html, type, duration) {
    duration = duration || 4000;
    const t = document.createElement('div');
    t.className = 'sat-toast sat-toast-' + type;
    t.innerHTML = html;
    document.body.appendChild(t);
    setTimeout(function() { t.classList.add('sat-toast-show'); }, 10);
    setTimeout(function() {
      t.classList.remove('sat-toast-show');
      setTimeout(function() { t.remove(); }, 300);
    }, duration);
  }

  function esc(str) {
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  // ── Init ──────────────────────────────────────────────────────────────────

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAdminBar);
  } else {
    initAdminBar();
  }

})();
