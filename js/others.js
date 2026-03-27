const { __, sprintf } = wp.i18n;

document.addEventListener('DOMContentLoaded', function () {
    const langSelector = document.getElementById('salt-language-selector');
    const langSelect = document.getElementById('salt-translate-lang');
    const infoBox = document.getElementById('salt-translation-info');
    const statusText = document.getElementById('salt-translation-status');

    const btns = document.getElementById('salt-start-buttons');
    const startMenuBtn = document.getElementById('salt-start-menu-translation');
    const startStringBtn = document.getElementById('salt-start-string-translation');
    const startPostTypeTaxonomyBtn = document.getElementById('salt-start-post_type-taxonomy-translation');

    const viewer = document.getElementById("salt-translation-viewer");


    let untranslatedPosts = [];

    if (!langSelect) return;

    langSelect.addEventListener('change', function (e) {
        startMenuBtn.disabled = false;
        statusText.innerHTML = "";
    });

    startMenuBtn.addEventListener('click', function () {
        const lang = langSelect.value;

        if (!lang) {
            alert('Lütfen bir dil seçin.');
            return;
        }

        viewer.style.display = 'flex';
        viewer.classList.add("salt-spinner");
        btns.style.display = 'none';
        statusText.innerText = '';

        const langLabel = langSelect.options[langSelect.selectedIndex].text;

        fetch(ajaxurl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: new URLSearchParams({
                action: 'get_untranslated_posts_terms',
                lang: lang,
                _ajax_nonce: saltTranslator.nonce
            })
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                const status = data.data.status;
                const msg = (data?.data?.status_text || '') + (data?.data?.info?.status_text ? `<small class="text-danger" style="display:block;opacity:.75;">${data.data.info.status_text}</small>` : '');

                infoBox.style.display = 'flex';
                viewer.classList.remove("salt-spinner");
                statusText.innerHTML = msg;

                if(status){
                    salt_translate_menu(lang);
                }else{
                    btns.style.display = "block"
                    startMenuBtn.disabled = true;
                }
            }
        })
        .catch(err => {
            console.error("AJAX hatası:", err);
            alert('Bir bağlantı hatası oluştu.');
            viewer.classList.remove("salt-spinner");
        });
    });

    function salt_translate_menu(lang){
        fetch(ajaxurl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: new URLSearchParams({
                action: 'translate_menu',
                lang: lang,
                retranslate: saltTranslator.settings.menu.retranslate ?? 0,
                _ajax_nonce: saltTranslator.nonce
            })
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                const status = data.data.status;
                const msg = data.data.status_text;

                btns.style.display = "block"
                infoBox.style.display = 'flex';
                viewer.classList.remove("salt-spinner");
                statusText.innerHTML = msg;

            }
        })
        .catch(err => {
            console.error("AJAX hatası:", err);
            alert('Bir bağlantı hatası oluştu.');
            viewer.classList.remove("salt-spinner");
        });
    }

    if(startStringBtn){
        startStringBtn.addEventListener('click', function () {
            const lang = langSelect.value;

            if (!lang) {
                alert('Lütfen bir dil seçin.');
                return;
            }

            viewer.style.display = 'flex';
            viewer.classList.add("salt-spinner");
            btns.style.display = 'none';
            statusText.innerText = '';

            const langLabel = langSelect.options[langSelect.selectedIndex].text;

            fetch(ajaxurl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: new URLSearchParams({
                    action: 'translate_strings',
                    lang: lang,
                    retranslate: saltTranslator.settings.strings.retranslate,
                    _ajax_nonce: saltTranslator.nonce
                })
            })
            .then(res => res.json())
            .then(data => {
                console.log(data)
                if (data.success) {
                    const status = data.data.status;
                    const msg = data?.data?.status_text;

                    infoBox.style.display = 'flex';
                    viewer.classList.remove("salt-spinner");
                    statusText.innerHTML = msg;
                    btns.style.display = "block"
                }
            })
            .catch(err => {
                console.error("AJAX hatası:", err);
                alert('Bir bağlantı hatası oluştu.');
                viewer.classList.remove("salt-spinner");
            });
        });
    }

    if(startPostTypeTaxonomyBtn){
        startPostTypeTaxonomyBtn.addEventListener('click', function () {
            const lang = langSelect.value;

            if (!lang) {
                alert('Lütfen bir dil seçin.');
                return;
            }

            viewer.style.display = 'flex';
            viewer.classList.add("salt-spinner");
            btns.style.display = 'none';
            statusText.innerText = '';

            const langLabel = langSelect.options[langSelect.selectedIndex].text;

            fetch(ajaxurl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: new URLSearchParams({
                    action: 'translate_post_type_taxonomy',
                    lang: lang,
                    _ajax_nonce: saltTranslator.nonce
                })
            })
            .then(res => res.json())
            .then(data => {
                console.log(data)
                if (data.success) {
                    const status = data.data.status;
                    const msg = data?.data?.status_text;

                    infoBox.style.display = 'flex';
                    viewer.classList.remove("salt-spinner");
                    statusText.innerHTML = msg;
                    btns.style.display = "block"
                }
            })
            .catch(err => {
                console.error("AJAX hatası:", err);
                alert('Bir bağlantı hatası oluştu.');
                viewer.classList.remove("salt-spinner");
            });
        });

    }

});