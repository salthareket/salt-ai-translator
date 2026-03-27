const { __, sprintf } = wp.i18n;

(function(){
  const tabButtons = document.querySelectorAll('.salt-tab');
  const tabContents = document.querySelectorAll('.salt-tab-content');

  if (!tabButtons.length || !tabContents.length) return;

  tabButtons.forEach(button => {
    button.addEventListener('click', (e) => {
      e.preventDefault(); // Form submit veya sayfa yenilenmesini engelle

      // Tab buttons aktiflik
      tabButtons.forEach(btn => btn.classList.remove('is-active'));
      button.classList.add('is-active');

      // Content alanlarını gizle/göster
      const target = button.dataset.tab;
      tabContents.forEach(section => {
        section.classList.toggle('is-hidden', section.id !== target);
      });
    });
  });
})();


document.addEventListener('DOMContentLoaded', function () {
  const checkboxes = document.querySelectorAll('input[type="checkbox"]');

  checkboxes.forEach(checkbox => {
    const toggleId = checkbox.id;
    if (!toggleId) return;

    const targets = document.querySelectorAll(`[data-salt-toggle="${toggleId}"]`);
    if (!targets.length) return;

    // İlk yüklemede durum kontrolü
    updateVisibility(checkbox, targets);

    checkbox.addEventListener('change', () => updateVisibility(checkbox, targets));
  });

  function updateVisibility(checkbox, targets) {
    const isChecked = checkbox.checked;
    targets.forEach(target => {
      target.classList.toggle('is-visible', isChecked);
    });
  }
});



document.addEventListener('DOMContentLoaded', function () {
    const select = document.getElementById('salt_translator_select');
     if (!select) return; // Sayfa o değilse çık
     
    const textareaSelector = `[name="salt_ai_translator_settings[api_keys][${select.value}]"]`;
    let textarea = document.querySelector(textareaSelector);

    const apiRow = document.querySelector('.api_keys_row');
    const openaiSettings = document.querySelector('.openai_settings');

    const apiKeyStore = {};

    function toggleFields() {
        const val = select.value;

        // textarea'yı güncel şekilde bul
        const textareaSelector = `[name="salt_ai_translator_settings[api_keys][${val}]"]`;
        const newTextarea = document.querySelector(textareaSelector);

        // Önceki textarea'yı sakla
        if (select.dataset.prev) {
            const prevTextareaSelector = `[name="salt_ai_translator_settings[api_keys][${select.dataset.prev}]"]`;
            const prevTextarea = document.querySelector(prevTextareaSelector);
            if (prevTextarea) {
                apiKeyStore[select.dataset.prev] = prevTextarea.value.trim();
            }
        }

        // Yükle
        if (newTextarea) {
            newTextarea.value = apiKeyStore[val] || '';
        }

        // Alanları göster/gizle
        apiRow.style.display = val ? 'block' : 'none';
        if (openaiSettings) openaiSettings.style.display = val === 'openai' ? 'block' : 'none';

        select.dataset.prev = val;
    }

    select.addEventListener('change', toggleFields);
    select.dataset.prev = select.value;
    toggleFields();
});

jQuery(document).ready(function($){
    $(document).on('click', '.salt-translate-manual-submit', function(e){
        e.preventDefault();

        const button = $(this);
        const isPost = button.data('post-id') !== undefined;
        const isTerm = button.data('term-id') !== undefined;

        const postId = button.data('post-id') || 0;
        const termId = button.data('term-id') || 0;
        const taxonomy = button.data('taxonomy') || '';
        const lang = $('#salt_translate_lang_' + (postId || termId)).val() || $('.salt-translate-lang').val();
        const prompt = $('#salt_translate_prompt_' + (postId || termId)).val() || $('[name="salt_translate_prompt"]').val();
        const nonce = $('#salt_translate_manual_nonce').val() || saltTranslator.nonce;

        const resultBox = button.siblings('.salt-translate-response');

        if (!lang) {
            if (resultBox.length) {
                resultBox.html('<span style="color:red;">Lütfen bir dil seçin.</span>');
            } else {
                alert('Lütfen bir dil seçin.');
            }
            return;
        }

        button.prop('disabled', true).text('Çevriliyor...');

        const requestData = {
            language: lang,
            prompt: prompt,
            nonce: nonce
        };

        if (isPost) {
            requestData.action = 'salt_translate_post_manual_ajax';
            requestData.post_id = postId;
        } else if (isTerm) {
            requestData.action = 'salt_translate_term_manual_ajax';
            requestData.term_id = termId;
            requestData.taxonomy = taxonomy;
        } else {
            alert('Hedef tür belirlenemedi.');
            return;
        }

        $.ajax({
            type: 'POST',
            url: ajaxurl,
            data: requestData,
            success: function(response){
                button.prop('disabled', false).text('Çevir');
                if (response.success) {
                    if (resultBox.length) {
                        resultBox.html('<span style="color:green;">✅ Başarıyla çevrildi</span>');
                    } else {
                        alert('✅ Başarıyla çevrildi');
                        location.reload();
                    }
                } else {
                    const msg = response.data || 'Bilinmeyen bir hata oluştu';
                    if (resultBox.length) {
                        resultBox.html('<span style="color:red;">❌ Hata: ' + msg + '</span>');
                    } else {
                        alert('❌ Hata: ' + msg);
                    }
                }
            },
            error: function(){
                button.prop('disabled', false).text('Çevir');
                if (resultBox.length) {
                    resultBox.html('<span style="color:red;">❌ AJAX bağlantı hatası</span>');
                } else {
                    alert('❌ AJAX bağlantı hatası');
                }
            }
        });
    });
});
