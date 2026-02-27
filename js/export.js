const { __, sprintf } = wp.i18n;

document.addEventListener('DOMContentLoaded', function () {
    
    const exportOptions = document.getElementById('export-options-container');

    const langSource = document.getElementById('export-source-language');
    const langTarget = document.getElementById('export-target-language');
    const exportFormat     = document.getElementById('export-output-format');
    const checkBtn   = document.getElementById('salt-check-translations');

    const latestExports = document.getElementById('salt-translation-latest-exports');

    const infoBox = document.getElementById('salt-translation-info');
    const statusText = document.getElementById('salt-translation-status');
    const progress = document.getElementById('salt-translation-progress');
    const progressBar = progress.querySelector('.salt-progress-bar');

    const btns = document.getElementById('salt-start-buttons');
    const startBtn = document.getElementById('salt-start-export');
    const startCronBtn = document.getElementById('salt-start-cron-export');

    const startCacheBtn = document.getElementById('salt-start-cache-export');

    const viewer = document.getElementById("salt-translation-viewer");
    
    const resultsTable = document.querySelector('#results-ui');
    const resultsTableBody = document.querySelector('#results-ui tbody');

    const queue_initial_total = document.getElementById("queue_initial_total");
    const queue_language = document.getElementById("queue_language");
    const queue_started_at = document.getElementById("queue_started_at");
    const queue_completed_at = document.getElementById("queue_completed_at");
    const queue_processing_time = document.getElementById("queue_processing_time");
    const queue_status = document.getElementById("queue_status");

    let untranslatedPosts = [];
    let translations = [];

    if (!langTarget || !checkBtn) return;

    checkBtn.addEventListener('click', function () {
        const lang_source = langSource.value;
        const lang_target = langTarget.value;
        const format      = exportFormat.value;

        if (!lang_target) {
            alert('Lütfen bir dil seçin.');
            return;
        }

        exportOptions.style.display = 'none';

        progress.style.display = 'none';
        progressBar.style.width = '0%';
        progressBar.textContent = '0%';

        viewer.style.display = 'flex';
        viewer.classList.add("salt-spinner");
        btns.style.display = 'none';
        statusText.innerText = '';

        resultsTableBody.innerHTML = '';
        resultsTable.style.display = 'none';
        
        if (latestExports) {
            latestExports.style.display = 'none';
        }

        const langLabel = langTarget.options[langTarget.selectedIndex].text;

        fetch(ajaxurl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: new URLSearchParams({
                action: 'get_sitemap_urls',
                lang_source: lang_source,
                lang: lang_target,
                format: format,
                _ajax_nonce: saltTranslator.nonce
            })
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                untranslatedPosts = data.data.posts;
                const total = data.data.total;
                //const need_translate = data.data.need_translate;
                //const translated = total - need_translate;
                const msg = data.data.status_text;

                infoBox.style.display = 'flex';
                //btns.style.display = 'block';

                viewer.classList.remove("salt-spinner");

                /*if (need_translate > 0) {*/
                    btns.style.display  = 'block';
                /*} else {
                    btns.style.display  = saltTranslator.settings?.retranslate ? 'block' : 'none';
                }*/
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
        const lang_source = langSource.value;
        const lang_target = langTarget.value;
        const format      = exportFormat.value;

        if (!lang_target) {
            alert('Lütfen bir dil seçin.');
            return;
        }
        if (untranslatedPosts.length === 0) return;

        exportOptions.style.display = 'none';
        btns.style.display = 'none';
        progress.style.display = 'flex';
        
        resultsTableBody.innerHTML = '';
        resultsTable.style.display = 'table';
        
        if (latestExports) {
            latestExports.style.display = 'none';
        }

        let total     = untranslatedPosts.length;
        let completed = 0;

        statusText.innerHTML = `<strong>Preparing...</strong>`;

        function translateNext() {
          if (completed >= total) {
            //alert(`${total} içerik "${lang}" diline çevrildi!`);
            statusText.innerHTML = "<strong class='text-success'>Completed</strong>";
            exportOptions.style.display = 'grid';
            //resultsTableBody.insertAdjacentHTML('beforeend', "<tr><td colspan='5' style='text-align:center;'>COMPLETED</td></tr>");
            downloadTranslations(lang_source, lang_target, format);
            return;
          }

          const id = untranslatedPosts[completed].ID || untranslatedPosts[completed].id;

          fetch(ajaxurl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                action: 'get_translations_by_url',
                data: JSON.stringify(untranslatedPosts[completed]),
                lang_source: lang_source,
                lang: lang_target,
                format: format,
                _ajax_nonce: saltTranslator.nonce
            })
          })
          .then(r => r.json())
          .then(res => {
            if (!res.success) {
              console.error(`#${id} hata:`, res.data);
            }

            //translations.push(...res.data);

            completed++;
            // progress % hesapla
            const pct = Math.round((completed / total) * 100);
            progressBar.style.width = pct + '%';
            progressBar.textContent   = pct + '%';

            // Durum metnini güncelle (isteğe bağlı)
            statusText.innerHTML = `<strong>${completed}/${total} oluşturuldu</strong>`;

            /*if (res.data.html) {
                resultsTableBody.insertAdjacentHTML('beforeend', res.data.html);
            }*/

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

    if(startCacheBtn){
        startCacheBtn.addEventListener('click', (event) => {
            const format = exportFormat.value;

            const clickedButton = event.currentTarget;
            const lang = clickedButton.getAttribute('data-lang');

            const statusText = document.getElementById('salt-translation-status');
            const progress = document.getElementById('salt-translation-progress');

            statusText.innerHTML = "<strong class='text-success'>Generating "+format+" file...</strong>";

            const formData = new FormData();
            formData.append('action', 'export_translations_download_cache');
            formData.append('lang', lang);
            formData.append('format', format);
            formData.append('_ajax_nonce', saltTranslator.nonce);

            fetch(ajaxurl, {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    console.log(data);
                    statusText.innerHTML = "<a href='"+data.data.file+"' class='salt-button btn-success' style='display:block;text-decoration:none;font-size:16px;background-color:#28a745;' download><strong>DOWNLOAD</strong></a>";
                    progress.style.display = "none";
                }
            })
            .catch(err => {
                console.error('File export error:', err);
            });
        });
    }
    
    if(startCronBtn){
        startCronBtn.addEventListener('click', () => {
            const lang_source = langSource.value;
            const lang_target = langTarget.value;
            const format      = exportFormat.value;

            if (!lang_target) {
                alert('Lütfen bir dil seçin.');
                return;
            }
            
            btns.style.display = 'none';
            progress.style.display = 'flex';
            exportOptions.style.display = 'none';

            resultsTableBody.innerHTML = '';
            resultsTable.style.display = 'none';

            statusText.innerHTML = `<strong>Preparing...</strong>`;
            
            fetch(ajaxurl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: new URLSearchParams({
                    action: 'start_export_translation_queue',
                    lang: lang_target,
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
    }
    
});

function downloadTranslations(lang_source, lang_target, format) {
    const statusText = document.getElementById('salt-translation-status');
    const progress = document.getElementById('salt-translation-progress');

    statusText.innerHTML = "<strong class='text-success'>Generating "+format+" file...</strong>";

    const formData = new FormData();
    formData.append('action', 'export_translations_download');
    formData.append('lang_source', lang_source);
    formData.append('lang_target', lang_target);
    formData.append('format', format);
    formData.append('_ajax_nonce', saltTranslator.nonce);

    fetch(ajaxurl, {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            console.log(data);
            statusText.innerHTML = "<a href='"+data.data.file+"' class='salt-button btn-success' style='display:block;text-decoration:none;font-size:16px;background-color:#28a745;' download><strong>DOWNLOAD</strong></a>";
            progress.style.display = "none";
        }
    })
    .catch(err => {
        console.error('File export error:', err);
    });
}


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
                type: 'export',
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
                __('%1$d / %2$d generated.', 'salt-ai-translator'),
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
