const { __, sprintf } = wp.i18n;

document.addEventListener('DOMContentLoaded', function () {
    const langSelector = document.getElementById('salt-language-selector');
    const langSelect = document.getElementById('salt-translate-lang');
    const checkBtn = document.getElementById('salt-check-translation');
    const infoBox = document.getElementById('salt-translation-info');
    const statusText = document.getElementById('salt-translation-status');
    const progress = document.getElementById('salt-translation-progress');
    const progressBar = progress.querySelector('.salt-progress-bar');

    const btns = document.getElementById('salt-start-buttons');
    const startBtn = document.getElementById('salt-start-translation');
    const startCronBtn = document.getElementById('salt-start-cron-translation');

    const viewer = document.getElementById("salt-translation-viewer");

    const resultsTable = document.querySelector('#results-ui');
    const resultsTableBody = document.querySelector('#results-ui tbody');

    const queue_initial_total = document.getElementById("queue_initial_total");
    const queue_language = document.getElementById("queue_language");
    const queue_started_at = document.getElementById("queue_started_at");
    const queue_completed_at = document.getElementById("queue_completed_at");
    const queue_processing_time = document.getElementById("queue_processing_time");
    const queue_status = document.getElementById("queue_status");

    let untranslatedTerms = [];

    if (!langSelect || !checkBtn) return;

    checkBtn.addEventListener('click', function () {
        const lang = langSelect.value;

        if (!lang) {
            alert('Lütfen bir dil seçin.');
            return;
        }

        progress.style.display = 'none';
        progressBar.style.width = '0%';
        progressBar.textContent = '0%';

        viewer.style.display = 'flex';
        viewer.classList.add("salt-spinner");
        btns.style.display = 'none';
        statusText.innerText = '';

        resultsTableBody.innerHTML = '';
        resultsTable.style.display = 'none';

        const langLabel = langSelect.options[langSelect.selectedIndex].text;

        fetch(ajaxurl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: new URLSearchParams({
                action: 'get_untranslated_terms',
                lang: lang,
                _ajax_nonce: saltTranslator.nonce
            })
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                untranslatedTerms = data.data.terms;
                const total = data.data.total;
                const need_translate = data.data.need_translate;
                const translated = total - need_translate;
                const msg = data.data.status_text;

                infoBox.style.display = 'flex';
                //btns.style.display = 'block';

                viewer.classList.remove("salt-spinner");

                if (need_translate > 0) {
                    btns.style.display  = 'block';
                } else {
                    btns.style.display  = saltTranslator.settings?.retranslate ? 'block' : 'none';
                }
                statusText.innerText = msg;
            }
        })
        .catch(err => {
            console.error("AJAX hatası:", err);
            alert('Bir bağlantı hatası oluştu.');

            viewer.classList.remove("salt-spinner");
        });
    });

    startBtn.addEventListener('click', () => {
        const lang = langSelect.value;
        if (!lang || untranslatedTerms.length === 0) return;

        langSelector.style.display = 'none';
        btns.style.display = 'none';
        progress.style.display = 'flex';

        resultsTableBody.innerHTML = '';
        resultsTable.style.display = 'table';

        let total     = untranslatedTerms.length;
        let completed = 0;

        statusText.innerHTML = `<strong>Preparing...</strong>`;

        function translateNext() {
          if (completed >= total) {
            //alert(`${total} içerik "${lang}" diline çevrildi!`);
            statusText.innerHTML = "<strong class='text-success'>Completed</strong>";
            langSelector.style.display = 'block';
            resultsTableBody.insertAdjacentHTML('beforeend', "<tr><td colspan='5' style='text-align:center;'>COMPLETED</td></tr>");
            return;
          }

          const term = untranslatedTerms[completed];

          fetch(ajaxurl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                action:     'translate_term',
                term_id:    term.term_id,
                taxonomy:   term.taxonomy,
                lang:       lang,
                _ajax_nonce: saltTranslator.nonce
            })
          })
          .then(r => r.json())
          .then(res => {
            if (!res.success) {
                console.error(`#${term.term_id} hata:`, res.data);
            }
            completed++;
            // progress % hesapla
            const pct = Math.round((completed / total) * 100);
            progressBar.style.width = pct + '%';
            progressBar.textContent   = pct + '%';

            // Durum metnini güncelle (isteğe bağlı)
            statusText.innerHTML = `<strong>${completed}/${total} çevrildi</strong>`;

            if (res.data.html) {
                resultsTableBody.insertAdjacentHTML('beforeend', res.data.html);
            }


            // Sonraki
            translateNext();
          })
          .catch(err => {
            console.error('Çeviri AJAX hatası:', err);
            completed++;
            translateNext();
          });
        }

        // Başlat
        translateNext();
    });

    startCronBtn.addEventListener('click', () => {
        const lang = langSelect.value;
        if (!lang) {
            alert("Lütfen bir dil seçin.");
            return;
        }
        
        btns.style.display = 'none';
        progress.style.display = 'flex';
        langSelector.style.display = 'none';

        resultsTableBody.innerHTML = '';
        resultsTable.style.display = 'none';

        statusText.innerHTML = `<strong>Preparing...</strong>`;
        
        fetch(ajaxurl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: new URLSearchParams({
                action: 'start_term_translation_queue',
                lang: lang,
                _ajax_nonce: saltTranslator.nonce
            })
        })
        .then(res => res.json())
        .then(data => {
            //startCronBtn.textContent = 'Çevirmeye Başla (Cron)';
            if (data.success) {
                queue_initial_total.innerHTML = data.data.initial_total;
                queue_language.innerHTML = data.data.lang;
                queue_started_at.innerHTML = data.data.started_at;
                queue_completed_at.innerHTML = "-";
                queue_processing_time.innerHTML = "-";
                queue_status.innerHTML = __('Processing', 'salt-ai-translator');
                startQueuePolling();
            } else {
                alert('Bir hata oluştu: ' + (data.data || ''));
            }
        })
        .catch(err => {
            console.error('Hata:', err);
            alert("İstek gönderilirken hata oluştu.");
        });
    });
    
});

function startQueuePolling() {
    const viewer      = document.getElementById("salt-translation-viewer");
    const statusText  = document.getElementById('salt-translation-status');
    const progress = document.getElementById('salt-translation-progress');
    const progressBar = progress.querySelector('.salt-progress-bar');

    const started_at = document.getElementById("queue_started_at");
    const completed_at = document.getElementById("queue_completed_at");
    const processing_time = document.getElementById("queue_processing_time");
    const queue_status = document.getElementById("queue_status");
    

    const interval = setInterval(() => {
        fetch(ajaxurl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: new URLSearchParams({
                action: 'check_queue_status',
                type: 'term',
                _ajax_nonce: saltTranslator.nonce
            })
        })
        .then(res => res.json())
        .then(data => {
            if (!data.success) return;

            const status    = data.data.status;
            const completed = data.data.completed || 0;
            const total     = Math.max(1, data.data.initial_total || 1);
            const pct       = Math.round((completed / total) * 100);

            progress.style.display  = 'flex';
            progressBar.style.width    = pct + '%';
            progressBar.textContent    = pct + '%';
            statusText.innerHTML       =  '<strong>' + sprintf(
                __('%1$d / %2$d translated.', 'salt-ai-translator'),
                completed,
                total
            );

            if (status === 'done') {
                clearInterval(interval);
                completed_at.innerHTML     = data.data.completed_at;
                processing_time.innerHTML  = data.data.processing_time;
                queue_status.innerHTML     = `<strong class='text-success'>Completed!</strong>`;
                statusText.innerHTML       = `<strong class='text-success'>Completed!</strong>`;
            }
        })
        .catch(err => {
            console.error('Queue AJAX hatası:', err);
        });
    }, 5000); // her 5 saniyede bir
}

// sayfa açıldığında status “processing” ise polling başlat
document.addEventListener('DOMContentLoaded', () => {
    if (saltTranslator.queue === 'processing') {
        startQueuePolling();
    }
});
