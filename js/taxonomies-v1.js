const { __, sprintf } = wp.i18n;

document.addEventListener('DOMContentLoaded', function () {
    const langSelect = document.getElementById('salt-translate-lang');
    const checkBtn = document.getElementById('salt-check-translation');
    const infoBox = document.getElementById('salt-translation-info');
    const statusText = document.getElementById('salt-translation-status');
    const progressBar = document.getElementById('salt-translation-progress');
    const startBtn = document.getElementById('salt-start-translation');

    let untranslatedTerms = [];

    if (!langSelect || !checkBtn) return;

    checkBtn.addEventListener('click', function () {
        const lang = langSelect.value;

        if (!lang) {
            alert('Lütfen bir dil seçin.');
            return;
        }

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

                infoBox.style.display = 'block';
                startBtn.style.display = 'inline-block';

                if (need_translate > 0) {

                    let msg = `${total} terimin ${need_translate} adedi "${langLabel}" diline çevrilmemiş.`;
                    if (saltTranslator.settings?.retranslate) {
                        if(translated > 0){
                            msg = `${translated} adedinin çevirisi yapılmış toplam ${total} terim "${langLabel}" diline yeniden çevrilecek.`;
                        }else{
                            msg = `${total} adet terim "${langLabel}" diline çevrilecek.`;
                        }
                    }

                    statusText.innerText = msg;
                    progressBar.style.width = '0%';
                    progressBar.innerText = '0%';

                } else {
                    const msg = saltTranslator.settings?.retranslate
                        ? `${total} adet terimin tümü "${langLabel}" diline çevrilmişti. Hepsi yeniden çevrilecek.`
                        : `${total} adet terimin tümü "${langLabel}" diline çevrilmiş.`;

                    statusText.innerText = msg;
                    progressBar.style.width = saltTranslator.settings?.retranslate ? '0%' : '100%';
                    progressBar.innerText   = saltTranslator.settings?.retranslate ? '0%' : 'Tamamlandı';
                    startBtn.style.display  = saltTranslator.settings?.retranslate ? 'inline-block' : 'none';
                }
            }
        })
        .catch(err => {
            console.error("AJAX hatası:", err);
            alert('Bir bağlantı hatası oluştu.');
        });
    });

    startBtn.addEventListener('click', () => {
        const lang = langSelect.value;
        if (!lang || untranslatedTerms.length === 0) return;

        startBtn.disabled = true;
        let total     = untranslatedTerms.length;
        let completed = 0;

        function translateNext() {
            if (completed >= total) {
                alert(`${total} terim \"${lang}\" diline çevrildi!`);
                startBtn.disabled = false;
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
                const pct = Math.round((completed / total) * 100);
                progressBar.style.width = pct + '%';
                progressBar.textContent = pct + '%';
                statusText.innerHTML = `<strong>${completed}/${total} çevrildi</strong>`;
                translateNext();
            })
            .catch(err => {
                console.error('Çeviri AJAX hatası:', err);
                completed++;
                translateNext();
            });
        }

        translateNext();
    });
});
