<?php
namespace SAT\Admin;

use SAT\Core\Container;

class AjaxHandler {

    private Container $container;

    public function __construct(Container $container) {
        $this->container = $container;
    }

    public function register(): void {
        $actions = [
            'sat_translate_post'           => 'translatePost',
            'sat_translate_term'           => 'translateTerm',
            'sat_translate_post_alts'      => 'translatePostAlternatives',
            'sat_get_untranslated'         => 'getUntranslated',
            'sat_estimate_cost'            => 'estimateCost',
            'sat_get_credits'              => 'getCredits',
            'sat_get_logs'                 => 'getLogs',
            'sat_sync_models'              => 'syncModels',
            'sat_save_settings'            => 'saveSettings',
            'sat_export_xlsx'              => 'exportXlsx',
            'sat_translate_menu'           => 'translateMenu',
            'sat_translate_strings'        => 'translateStrings',
            'sat_get_untranslated_strings' => 'getUntranslatedStrings',
            'sat_bulk_media_alt'           => 'bulkMediaAlt',
            'sat_autocomplete_posts'       => 'autocompletePosts',
            'sat_autocomplete_terms'       => 'autocompleteTerms',
            'sat_clear_logs'               => 'clearLogs',
            'sat_set_translation_lock'     => 'setTranslationLock',
            'sat_get_po_files'             => 'getPOFiles',
            'sat_translate_po_file'        => 'translatePOFile',
            'sat_translate_string_alts'    => 'translateStringAlternatives',
            'sat_get_po_strings'           => 'getPOStrings',
            'sat_save_po_string'           => 'savePOString',
            'sat_export_full'              => 'exportFull',
            'sat_import_csv'               => 'importCsv',
            'sat_parse_xlsx'               => 'parseXlsx',
            'sat_queue_clear_done'         => 'queueClearDone',
            'sat_import_glossary'          => 'importGlossary',
            'sat_memory_stats'             => 'getMemoryStats',
            'sat_memory_clear'             => 'clearMemory',
        ];

        foreach ($actions as $action => $method) {
            add_action("wp_ajax_{$action}", [$this, $method]);
        }
    }

    public function translatePost(): void {
        $this->checkNonce();
        $postId      = (int) ($_POST['post_id'] ?? 0);
        $lang        = sanitize_key($_POST['lang'] ?? '');
        $customPrompt= sanitize_textarea_field($_POST['custom_prompt'] ?? '');

        if (!$postId || !$lang) wp_send_json_error('Missing parameters');

        $integration = $this->container->get('integration');
        if (!$integration) wp_send_json_error('No integration');

        // Temporarily set custom prompt
        $translator = $this->container->get('translator');
        if ($translator && $customPrompt && method_exists($translator, 'setCustomPrompt')) {
            $translator->setCustomPrompt($customPrompt);
        }

        try {
            $translatedId = $integration->translatePost($postId, $lang);
            wp_send_json_success([
                'translated_id' => $translatedId,
                'edit_url'      => get_edit_post_link($translatedId, 'raw'),
                'view_url'      => get_permalink($translatedId),
            ]);
        } catch (\Throwable $e) {
            wp_send_json_error($e->getMessage());
        }
    }

    public function translateTerm(): void {
        $this->checkNonce();
        $termId   = (int) ($_POST['term_id'] ?? 0);
        $taxonomy = sanitize_key($_POST['taxonomy'] ?? '');
        $lang     = sanitize_key($_POST['lang'] ?? '');

        if (!$termId || !$lang) wp_send_json_error('Missing parameters');

        $integration = $this->container->get('integration');
        if (!$integration) wp_send_json_error('No integration');

        try {
            $translatedId = $integration->translateTerm($termId, $taxonomy, $lang);
            wp_send_json_success(['translated_id' => $translatedId]);
        } catch (\Throwable $e) {
            wp_send_json_error($e->getMessage());
        }
    }

    public function translatePostAlternatives(): void {
        $this->checkNonce();
        $postId = (int) ($_POST['post_id'] ?? 0);
        $lang   = sanitize_key($_POST['lang'] ?? '');
        $field  = sanitize_key($_POST['field'] ?? 'title');
        $count  = (int) ($_POST['count'] ?? 3);

        if (!$postId || !$lang) wp_send_json_error('Missing parameters');

        $post       = get_post($postId);
        $translator = $this->container->get('translator');

        if (!$post || !$translator) wp_send_json_error('Invalid request');

        $text = $field === 'title' ? $post->post_title : wp_trim_words(strip_tags($post->post_content), 50);

        if (method_exists($translator, 'translateWithAlternatives')) {
            $alts = $translator->translateWithAlternatives($text, $lang, $count);
        } else {
            $alts = [$translator->translate($text, $lang)];
        }

        wp_send_json_success(['alternatives' => $alts, 'field' => $field]);
    }

    public function getUntranslated(): void {
        $this->checkNonce();
        $type  = sanitize_key($_POST['type'] ?? 'post');
        $lang  = sanitize_key($_POST['lang'] ?? '');
        $langs = array_map('sanitize_key', (array)($_POST['langs'] ?? [])); // çoklu dil
        $page  = (int) ($_POST['page'] ?? 1);
        $limit = (int) ($_POST['limit'] ?? 20);

        $integration = $this->container->get('integration');
        if (!$integration) wp_send_json_error('Invalid request');

        // Birden fazla dil desteği — her dil için untranslated ID'leri topla
        $checkLangs = $langs ?: ($lang ? [$lang] : []);
        if (empty($checkLangs)) wp_send_json_error('No language specified');

        if ($type === 'post') {
            // Her dil için untranslated ID'leri al, post başına hangi diller eksik bilgisini ekle
            $allIds  = [];
            $missing = []; // post_id → [missing langs]

            // Seçili post type'ları filtrele
            $selectedPostTypes = array_map('sanitize_key', (array)($_POST['post_types'] ?? []));
            $skipTranslated    = (int)($_POST['skip_translated'] ?? 1); // default: skip
            $excludeIds        = array_map('intval', (array)($_POST['exclude_ids'] ?? []));

            foreach ($checkLangs as $l) {
                if ($skipTranslated) {
                    // Sadece çevrilmemişleri al
                    $ids = $integration->getUntranslatedPostIds($l, $selectedPostTypes ?: []);
                } else {
                    // Tüm post'ları al (zaten çevrilmişler de dahil — yeniden çeviri için)
                    // Sadece default dildeki post'ları al — çeviriler hariç
                    $ptypes = $selectedPostTypes ?: array_values(array_filter(
                        array_keys(get_post_types(['public' => true])),
                        [$integration, 'isTranslatablePostType']
                    ));
                    $allPosts = get_posts([
                        'post_type'      => $ptypes,
                        'post_status'    => 'publish',
                        'posts_per_page' => -1,
                        'fields'         => 'ids',
                        'lang'           => $integration->getDefaultLanguage(), // sadece default dil
                    ]);
                    $ids = $allPosts;
                }
                foreach ($ids as $id) {
                    if (!isset($missing[$id])) $missing[$id] = [];
                    // Skip translated kapalıysa: sadece bu dilde çevirisi olmayanları işaretle
                    if (!$integration->getPostTranslation($id, $l)) {
                        $missing[$id][] = ['lang' => $l, 'has_translation' => false];
                    } else {
                        $missing[$id][] = ['lang' => $l, 'has_translation' => true];
                    }
                    $allIds[$id] = true;
                }
            }

            // skip_translated kapalıysa tüm ID'leri göster, açıksa sadece missing olanları
            if (!$skipTranslated) {
                $allIds = array_keys($allIds);
            } else {
                // Sadece en az bir dilde çevirisi eksik olanları göster
                $allIds = array_keys(array_filter($missing, fn($m) => !empty(array_filter($m, fn($x) => !$x['has_translation']))));
            }

            // exclude_ids: daha önce çevrilen ID'leri çıkar (sayfalama için)
            if (!empty($excludeIds)) {
                $allIds = array_values(array_diff($allIds, $excludeIds));
            }

            $total  = count($allIds);
            $paged  = array_slice($allIds, ($page - 1) * $limit, $limit);

            $items = array_map(function($id) use ($missing) {
                $post = get_post($id);

                // Post'a ekli term'lerin hangi dillerde çevirisi yok bilgisini de al
                $missingTerms = [];
                $checkLangsForTerms = $missing[$id] ?? [];
                if (!empty($checkLangsForTerms)) {
                    $taxonomies = get_object_taxonomies($post->post_type ?? 'post', 'names');
                    foreach ($taxonomies as $tax) {
                        if (in_array($tax, ['language', 'post_translations', 'term_language', 'term_translations'])) continue;
                        if (!function_exists('pll_is_translated_taxonomy') || !pll_is_translated_taxonomy($tax)) continue;

                        $termIds = wp_get_object_terms($id, $tax, ['fields' => 'ids']);
                        if (is_wp_error($termIds)) continue;

                        foreach ($termIds as $termId) {
                            foreach ($checkLangsForTerms as $langEntry) {
                                // langEntry {lang, has_translation} objesi veya string olabilir
                                $langCode = is_array($langEntry) ? ($langEntry['lang'] ?? '') : $langEntry;
                                if (!$langCode) continue;
                                if (!function_exists('pll_get_term') || !pll_get_term($termId, $langCode)) {
                                    $term = get_term($termId);
                                    if ($term && !is_wp_error($term)) {
                                        $missingTerms[] = $term->name . ' (' . $tax . ')';
                                    }
                                    break; // Bu term için bir dilde eksikse göster, tüm diller için tekrarlama
                                }
                            }
                        }
                    }
                    $missingTerms = array_unique($missingTerms);
                }

                return [
                    'id'            => $id,
                    'title'         => $post->post_title ?? '',
                    'type'          => $post->post_type ?? '',
                    'missing_langs' => $missing[$id] ?? [],
                    'missing_terms' => $missingTerms,
                ];
            }, $paged);

        } else {
            $allIds  = [];
            $missing = [];

            // Seçili taxonomy'leri filtrele
            $selectedTaxonomies = array_map('sanitize_key', (array)($_POST['taxonomies'] ?? []));
            $skipTranslated     = (int)($_POST['skip_translated'] ?? 1); // default: skip
            $excludeTermIds     = array_map('intval', (array)($_POST['exclude_ids'] ?? []));

            foreach ($checkLangs as $l) {
                if ($skipTranslated) {
                    // Sadece çevrilmemişleri al
                    $ids = $integration->getUntranslatedTermIds($l, $selectedTaxonomies ?: []);
                } else {
                    // Tüm default dil term'lerini al (zaten çevrilmişler dahil)
                    $taxonomies = !empty($selectedTaxonomies) ? $selectedTaxonomies : array_values(array_filter(
                        array_keys(get_taxonomies(['public' => true])),
                        [$integration, 'isTranslatableTaxonomy']
                    ));
                    $allTerms = get_terms([
                        'taxonomy'   => $taxonomies,
                        'hide_empty' => false,
                        'lang'       => $integration->getDefaultLanguage(),
                        'number'     => 0,
                    ]);
                    $ids = is_wp_error($allTerms) ? [] : array_column((array)$allTerms, 'term_id');
                }

                foreach ($ids as $id) {
                    if (!isset($missing[$id])) $missing[$id] = [];
                    // Çevirisi var mı kontrol et
                    $hasTr = (bool) $integration->getTermTranslation((int)$id, $l);
                    $missing[$id][] = ['lang' => $l, 'has_translation' => $hasTr];
                    $allIds[$id] = true;
                }
            }

            // skip_translated açıksa sadece en az bir dilde eksik olanları göster
            if ($skipTranslated) {
                $allIds = array_keys(array_filter($missing, fn($m) => !empty(array_filter($m, fn($x) => !$x['has_translation']))));
            } else {
                $allIds = array_keys($allIds);
            }

            // exclude_ids: daha önce çevrilen term ID'lerini çıkar (batch için)
            if (!empty($excludeTermIds)) {
                $allIds = array_values(array_diff($allIds, $excludeTermIds));
            }

            $total  = count($allIds);
            $paged  = array_slice($allIds, ($page - 1) * $limit, $limit);

            $items = array_map(function($id) use ($missing) {
                $term = get_term($id);
                return [
                    'id'           => $id,
                    'title'        => $term->name ?? '',
                    'taxonomy'     => $term->taxonomy ?? '',
                    'missing_langs'=> $missing[$id] ?? [],
                ];
            }, $paged);
        }

        wp_send_json_success(['items' => $items, 'total' => $total, 'pages' => ceil($total / $limit)]);
    }

    public function estimateCost(): void {
        $this->checkNonce();
        $ids   = array_map('intval', (array)($_POST['ids'] ?? []));
        $type  = sanitize_key($_POST['type'] ?? 'post');
        $langs = array_map('sanitize_key', (array)($_POST['langs'] ?? []));

        $credits = $this->container->get('credits');
        if (!$credits) wp_send_json_error('No credit tracker');

        // Direct text hesaplama (Cost Calculator için)
        if ( ! empty($_POST['text']) ) {
            $text     = wp_strip_all_tags( wp_unslash( $_POST['text'] ) );
            $estimate = $credits->estimateCost($text);
            $estimate['langs']      = count($langs) ?: 1;
            $estimate['total_cost'] = round($estimate['cost_usd'] * (count($langs) ?: 1), 8);
            $estimate['items_count'] = 1;
            wp_send_json_success($estimate);
            return;
        }

        $totalText = '';
        foreach ($ids as $id) {
            if ($type === 'post') {
                $post = get_post($id);
                $totalText .= ($post->post_title ?? '') . ' ' . ($post->post_content ?? '');
            } else {
                $term = get_term($id);
                $totalText .= ($term->name ?? '') . ' ' . ($term->description ?? '');
            }
        }

        $estimate = $credits->estimateCost($totalText);
        $estimate['langs']       = count($langs);
        $estimate['total_cost']  = round($estimate['cost_usd'] * count($langs), 6);
        $estimate['items_count'] = count($ids);

        wp_send_json_success($estimate);
    }

    public function getCredits(): void {
        $this->checkNonce();
        $credits = $this->container->get('credits');
        if (!$credits) wp_send_json_error('No credit tracker');
        wp_send_json_success([
            'remaining' => $credits->getRemainingCredits(),
            'capacity'  => $credits->estimateCapacity(),
        ]);
    }

    public function getLogs(): void {
        $this->checkNonce();
        $logger = $this->container->get('logger');
        if (!$logger) wp_send_json_error('No logger');

        $args = [
            'limit'       => (int) ($_POST['limit'] ?? 50),
            'offset'      => (int) ($_POST['offset'] ?? 0),
            'status'      => sanitize_key($_POST['status'] ?? ''),
            'target_lang' => sanitize_key($_POST['lang'] ?? ''),
            'object_type' => sanitize_key($_POST['object_type'] ?? ''),
            'date_from'   => sanitize_text_field($_POST['date_from'] ?? ''),
            'date_to'     => sanitize_text_field($_POST['date_to'] ?? ''),
        ];

        wp_send_json_success([
            'logs'  => $logger->getLogs($args),
            'stats' => $logger->getStats($args),
        ]);
    }

    public function syncModels(): void {
        $this->checkNonce();
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');
        \SAT\Translator\ModelSync::sync();
        $settings = $this->container->get('settings');
        wp_send_json_success([
            'models'    => $settings->get('custom_models', []),
            'synced_at' => date('d.m.Y H:i', $settings->get('models_last_sync', 0)),
        ]);
    }

    public function bulkMediaAlt(): void {
        $this->checkNonce();
        $lang  = sanitize_key($_POST['lang'] ?? '');
        $limit = (int) ($_POST['limit'] ?? 50);

        $mediaTranslator = new \SAT\Content\MediaTranslator($this->container);
        $result = $mediaTranslator->bulkGenerate($lang, $limit);
        wp_send_json_success($result);
    }

    public function autocompletePosts(): void {
        $this->checkNonce();
        $search = sanitize_text_field($_POST['q'] ?? '');
        $posts  = get_posts(['s' => $search, 'post_type' => 'any', 'posts_per_page' => 20, 'post_status' => 'publish']);
        $result = array_map(fn($p) => ['id' => $p->ID, 'text' => $p->post_title . ' (' . $p->post_type . ')'], $posts);
        wp_send_json_success($result);
    }

    public function autocompleteTerms(): void {
        $this->checkNonce();
        $search = sanitize_text_field($_POST['q'] ?? '');
        $terms  = get_terms(['search' => $search, 'hide_empty' => false, 'number' => 20]);
        $result = is_wp_error($terms) ? [] : array_map(fn($t) => ['id' => $t->term_id, 'text' => $t->name . ' (' . $t->taxonomy . ')'], $terms);
        wp_send_json_success($result);
    }

    public function translateMenu(): void {
        $this->checkNonce();
        $menuId = (int) ($_POST['menu_id'] ?? 0);
        $lang   = sanitize_key($_POST['lang'] ?? '');
        if (!$menuId || !$lang) wp_send_json_error('Missing parameters');

        $translator  = $this->container->get('translator');
        $integration = $this->container->get('integration');
        if (!$translator) wp_send_json_error('No translator');

        $sourceMenu = wp_get_nav_menu_object($menuId);
        if (!$sourceMenu) wp_send_json_error('Menu not found');

        $items = wp_get_nav_menu_items($menuId);
        if (!$items) wp_send_json_error('No items in menu');

        // Polylang'da hedef dilde menu var mı?
        $targetMenuId = 0;
        if ( function_exists('pll_get_term') ) {
            $translated = pll_get_term($menuId, $lang);
            if ($translated) $targetMenuId = $translated;
        }

        // Hedef dilde menu yoksa yeni oluştur
        if (!$targetMenuId) {
            // Menu adı: orijinal isim + " - LANG" (çeviri yok, API çağrısı yok)
            $newMenuName = $sourceMenu->name . ' - ' . strtoupper($lang);

            $newMenu = wp_create_nav_menu($newMenuName);
            if (is_wp_error($newMenu)) {
                wp_send_json_error('Could not create menu: ' . $newMenu->get_error_message());
            }
            $targetMenuId = $newMenu;

            // Polylang'a dil ata
            if (function_exists('pll_set_term_language')) {
                pll_set_term_language($targetMenuId, $lang);
            }
            // Polylang çeviri ilişkisi kur
            if (function_exists('pll_save_term_translations') && function_exists('pll_get_term_translations')) {
                $translations = pll_get_term_translations($menuId);
                $translations[$lang] = $targetMenuId;
                pll_save_term_translations($translations);
            }

            // Polylang menu location ataması:
            // Kaynak menünün hangi location'da olduğunu bul, yeni menüye aynı location'ın dil versiyonunu ata
            $pllOptions = get_option('polylang', []);
            if (is_array($pllOptions) && isset($pllOptions['nav_menus'])) {
                $theme   = get_option('stylesheet');
                $updated = false;

                if (isset($pllOptions['nav_menus'][ $theme ])) {
                    foreach ($pllOptions['nav_menus'][ $theme ] as $location => $langMap) {
                        foreach ($langMap as $locLang => $locMenuId) {
                            if ((int)$locMenuId === (int)$menuId) {
                                // Kaynak menü bu location'da — hedef dil için de ata
                                $pllOptions['nav_menus'][ $theme ][ $location ][ $lang ] = $targetMenuId;
                                $updated = true;
                            }
                        }
                    }
                }

                if ($updated) {
                    update_option('polylang', $pllOptions);
                }
            }
        }

        // Menu item'larını çevir ve hedefe ekle
        $count = 0;
        $idMap = []; // kaynak ID → hedef ID

        // Önce mevcut hedef menu'nun item'larını sil (yeniden oluşturacağız)
        $existingTargetItems = wp_get_nav_menu_items($targetMenuId);
        if ($existingTargetItems) {
            foreach ($existingTargetItems as $ei) {
                wp_delete_post($ei->ID, true);
            }
        }

        foreach ($items as $item) {
            // ── Smart title resolution — önce linked object'in çevirisini kullan ──
            $translatedTitle = '';
            $objectId        = 0;
            $objectType      = $item->type;

            if ($item->type === 'post_type' && function_exists('pll_get_post')) {
                // Post/page/CPT — hedef dildeki çevirisinin title'ını kullan
                $translatedPostId = pll_get_post($item->object_id, $lang);
                if ($translatedPostId) {
                    $trPost = get_post($translatedPostId);
                    if ($trPost) {
                        $translatedTitle = $trPost->post_title; // AI çağrısı YOK
                        $objectId        = $translatedPostId;
                    }
                }
                if (!$translatedTitle) $objectId = $item->object_id; // fallback — post çevrilmemiş

            } elseif ($item->type === 'taxonomy' && function_exists('pll_get_term')) {
                // Term/category/tag — hedef dildeki çevirisinin name'ini kullan
                $translatedTermId = pll_get_term($item->object_id, $lang);
                if ($translatedTermId) {
                    $trTerm = get_term($translatedTermId);
                    if ($trTerm && !is_wp_error($trTerm)) {
                        $translatedTitle = $trTerm->name; // AI çağrısı YOK
                        $objectId        = $translatedTermId;
                    }
                }
                if (!$translatedTitle) $objectId = $item->object_id;

            } elseif ($item->type === 'post_type_archive') {
                // Archive — post type label'ını çevir (kısa, ucuz)
                $objectId = $item->object_id;

            } else {
                // custom (URL, WC endpoint vs.) — objectId aynen kalsın
                $objectId = $item->object_id;
                // WC endpoint URL'sini dil bazlı güncelle (Polylang URL dönüşümü)
                // wp_logout_url, wc_get_endpoint_url gibi dinamik URL'ler için
                // Polylang'ın pll_translate_url'si varsa kullan
                if (!empty($item->url) && function_exists('pll_translate_url')) {
                    $trUrl = pll_translate_url($item->url, $lang);
                    if ($trUrl && $trUrl !== $item->url) {
                        $item->url = $trUrl;
                    }
                }
            }

            // Eğer title hâlâ boşsa (object çevrilmemiş veya custom) → AI ile çevir
            if (empty($translatedTitle) && !empty($item->title)) {
                if (method_exists($translator, 'setContext')) {
                    $translator->setContext([
                        'object_type' => 'menu',
                        'object_id'   => $menuId,
                        'source_lang' => '',
                        'target_lang' => $lang,
                        'field_name'  => 'menu_item:' . $item->ID,
                    ]);
                }
                $translatedTitle = $translator->translate($item->title, $lang, 'Short navigation menu item label.');
            }

            // Üst item ID'sini map'ten çevir
            $parentId = isset($idMap[$item->menu_item_parent]) ? $idMap[$item->menu_item_parent] : 0;

            $newItemId = wp_update_nav_menu_item($targetMenuId, 0, [
                'menu-item-title'     => $translatedTitle,
                'menu-item-object'    => $item->object,
                'menu-item-object-id' => $objectId,
                'menu-item-type'      => $objectType,
                'menu-item-status'    => 'publish',
                'menu-item-parent-id' => $parentId,
                'menu-item-position'  => $item->menu_order,
                'menu-item-url'       => $item->url,
            ]);

            if (!is_wp_error($newItemId)) {
                $idMap[$item->ID] = $newItemId;
                $count++;
            }
        }

        wp_send_json_success([
            'translated' => $count,
            'menu_id'    => $targetMenuId,
            'menu_name'  => get_term($targetMenuId, 'nav_menu')->name ?? '',
            'edit_url'   => admin_url('nav-menus.php?action=edit&menu=' . $targetMenuId),
        ]);
    }

    public function translateStrings(): void {
        $this->checkNonce();

        $langs   = array_map('sanitize_key', (array)($_POST['langs'] ?? []));
        $lang    = sanitize_key($_POST['lang'] ?? '');
        $strings = (array)($_POST['strings'] ?? []);

        $checkLangs = $langs ?: ($lang ? [$lang] : []);
        if (empty($checkLangs) || empty($strings)) {
            wp_send_json_error('Missing parameters');
        }

        if (!class_exists('\PLL_Admin_Strings') && !function_exists('pll_get_strings')) {
            wp_send_json_error('Polylang is not active');
        }

        $translator = $this->container->get('translator');
        if (!$translator) wp_send_json_error('No translator configured');

        $translated = 0;
        $results    = [];

        // Her dil için mevcut PLL_MO objesini yükle
        $moObjects = [];
        foreach ($checkLangs as $l) {
            if (class_exists('\PLL_Language') && function_exists('PLL') && PLL() && isset(PLL()->model)) {
                $pllLang = PLL()->model->get_language($l); // $lang değişkenini shadow etme
                if ($pllLang) {
                    $mo = new \PLL_MO();
                    $mo->import_from_db($pllLang);
                    $moObjects[$l] = ['mo' => $mo, 'lang' => $pllLang];
                }
            }
        }

        foreach ($strings as $item) {
            $name   = sanitize_text_field($item['name'] ?? '');
            $string = wp_unslash($item['string'] ?? '');
            $group  = sanitize_text_field($item['group'] ?? '');

            if (!$name || !$string) continue;

            // Slug grup tespiti — URL kısa isimleri, endpoint slugları vb.
            $isSlugGroup = $this->isSlugGroup($group, $string);

            foreach ($checkLangs as $l) {
                try {
                    // Log context'i set et — string çevirisi
                    if (method_exists($translator, 'setContext')) {
                        $translator->setContext([
                            'object_type' => 'string',
                            'object_id'   => 0,
                            'source_lang' => '',
                            'target_lang' => $l,
                            'field_name'  => $group ?: $name,
                        ]);
                    }

                    // forced_translation: kullanıcı alternatifleri arasından seçim yaptıysa direkt kaydet
                    $forcedTr = wp_unslash($item['forced_translation'] ?? '');

                    if ($forcedTr !== '') {
                        $translatedText = $isSlugGroup ? sanitize_title($forcedTr) : $forcedTr;
                    } else {
                        $prompt = $isSlugGroup
                            ? "Translate this URL slug to {$l}. Context: {$group}. Use only lowercase letters, numbers and hyphens (no spaces, no special characters, no accents). Return ONLY the slug, nothing else. Example: 'order-received' → 'siparis-alindi'"
                            : "This is a UI string for a WordPress theme or plugin. Context: {$group}. Keep it short and natural.";

                        $translatedText = $translator->translate($string, $l, $prompt);
                        if ($isSlugGroup) {
                            $translatedText = sanitize_title($translatedText);
                        }
                    }

                    // PLL_MO ile kaydet
                    if (isset($moObjects[$l])) {
                        $mo      = $moObjects[$l]['mo'];
                        $pllLang = $moObjects[$l]['lang'];
                        $entry = new \Translation_Entry(['singular' => $string, 'translations' => [$translatedText]]);
                        $mo->add_entry($entry);
                        $mo->export_to_db($pllLang);
                    }

                    $results[] = ['name' => $name, 'lang' => $l, 'translation' => $translatedText, 'success' => true];
                    $translated++;
                } catch (\Throwable $e) {
                    $results[] = ['name' => $name, 'lang' => $l, 'translation' => '', 'success' => false, 'error' => $e->getMessage()];
                }
            }
        }

        // Polylang Pro'da pll_save_string varsa onu da çağır (ekstra uyumluluk)
        if (function_exists('pll_save_string')) {
            foreach ($results as $r) {
                if ($r['success']) {
                    $orig = '';
                    foreach ($strings as $item) {
                        if (sanitize_text_field($item['name'] ?? '') === $r['name']) {
                            $orig = wp_unslash($item['string'] ?? '');
                            $grp  = sanitize_text_field($item['group'] ?? '');
                            break;
                        }
                    }
                    if ($orig) pll_save_string($r['name'], $r['translation'], $r['lang'], $grp ?? 'polylang');
                }
            }
        }

        wp_send_json_success(['translated' => $translated, 'results' => $results]);
    }

    /**
     * Polylang string cache temizle
     */
    private function invalidateStringsCache(): void {
        global $wpdb;
        $wpdb->query(
            "DELETE FROM {$wpdb->options}
             WHERE option_name LIKE '_transient_sat_str_result_%'
                OR option_name LIKE '_transient_timeout_sat_str_result_%'"
        );
    }

    public function getUntranslatedStrings(): void {
        $this->checkNonce();

        $lang  = sanitize_key($_POST['lang'] ?? '');
        $langs = array_map('sanitize_key', (array)($_POST['langs'] ?? []));
        $group = sanitize_text_field($_POST['group'] ?? '');
        // skip_translated: "1"/1/true → skip, "0"/0/false → show all. Default: skip (true).
        $rawSkip = $_POST['skip_translated'] ?? '1';
        $skip    = ($rawSkip !== '0' && $rawSkip !== 0 && $rawSkip !== false);
        $page    = max(1, (int)($_POST['page'] ?? 1));
        $limit   = min(100, max(10, (int)($_POST['limit'] ?? 50)));

        $checkLangs = $langs ?: ($lang ? [$lang] : []);
        if (empty($checkLangs)) wp_send_json_error('Missing language');

        // Polylang veya WPML string API kontrol
        if (!class_exists('\PLL_Admin_Strings') && !function_exists('pll_get_strings') && !defined('ICL_SITEPRESS_VERSION')) {
            wp_send_json_error('No multilanguage string plugin detected');
        }

        // ── Cache key: strings + translations yükleme pahalı, transient ile önbelleğe al ──
        // Cache'i sayfa 1'de hem doldur hem kullan; diğer sayfalarda sadece oku.
        $cacheKey = 'sat_str_result_' . md5(implode(',', $checkLangs) . '|' . $group . '|' . (int)$skip);
        $result   = get_transient($cacheKey);

        if ($result === false) {
            // ── String'leri al ──────────────────────────────────────────────
            $allStrings = [];
            if (class_exists('\PLL_Admin_Strings')) {
                $allStrings = \PLL_Admin_Strings::get_strings();
            } elseif (function_exists('pll_get_strings')) {
                $allStrings = pll_get_strings();
            }
            if (!is_array($allStrings)) $allStrings = [];

            // ── Her dil için mevcut çevirileri al ───────────────────────────
            $translations = [];
            foreach ($checkLangs as $l) {
                $translations[$l] = [];
                // Önce PLL_MO yöntemi (Pro + Free)
                if (class_exists('\PLL_Language') && function_exists('PLL') && PLL() && isset(PLL()->model)) {
                    $pllLang = PLL()->model->get_language($l);
                    if ($pllLang) {
                        $mo = new \PLL_MO();
                        $mo->import_from_db($pllLang);
                        foreach ($mo->entries as $entry) {
                            $src = $entry->singular ?? '';
                            $tr  = $entry->translations[0] ?? '';
                            if ($src && $tr) $translations[$l][$src] = $tr;
                        }
                    }
                }
                // Fallback: options tablosundaki ham dizi
                if (empty($translations[$l])) {
                    $mo = get_option('pll_mo_' . $l, []);
                    if (is_array($mo)) $translations[$l] = $mo;
                }
            }

            // ── Filtrele ve result dizisi oluştur ───────────────────────────
            $result = [];
            foreach ($allStrings as $entry) {
                if ($group && ($entry['context'] ?? '') !== $group) continue;

                $name   = $entry['name']    ?? '';
                $string = $entry['string']  ?? '';
                $ctx    = $entry['context'] ?? 'polylang';
                if (!$name || !$string) continue;

                if ($skip) {
                    $hasEmpty = false;
                    foreach ($checkLangs as $l) {
                        if (($translations[$l][$string] ?? '') === '') { $hasEmpty = true; break; }
                    }
                    if (!$hasEmpty) continue;
                }

                $langData = [];
                foreach ($checkLangs as $l) {
                    $langData[$l] = $translations[$l][$string] ?? '';
                }

                $result[] = [
                    'name'         => $name,
                    'string'       => $string,
                    'group'        => $ctx,
                    'translations' => $langData,
                ];
            }

            // 5 dakika cache — pagination boyunca tutarlı veri için
            set_transient($cacheKey, $result, 5 * MINUTE_IN_SECONDS);
        }

        // ── Tüm grupları topla (sadece sayfa 1'de gönder) ───────────────────
        $groups = [];
        if ($page === 1) {
            $groups = array_values(array_unique(array_filter(array_column($result, 'group'))));
            sort($groups);
        }

        $total  = count($result);
        $offset = ($page - 1) * $limit;
        $paged  = array_slice($result, $offset, $limit);

        wp_send_json_success([
            'strings' => $paged,
            'total'   => $total,
            'pages'   => (int) ceil($total / $limit),
            'page'    => $page,
            'groups'  => $groups,
        ]);
    }

    public function exportXlsx(): void {
        $this->checkNonce();

        $type      = sanitize_key($_POST['type'] ?? 'posts');
        $langs     = array_map('sanitize_key', (array)($_POST['langs'] ?? []));
        $format    = sanitize_key($_POST['format'] ?? 'csv');
        $postTypes = array_map('sanitize_key', (array)($_POST['post_types'] ?? []));

        $integration = $this->container->get('integration');
        if (!$integration) wp_send_json_error('No integration available');

        $sourceLang = $integration->getDefaultLanguage();
        if (empty($langs)) wp_send_json_error('No languages selected');

        // Veriyi topla
        $rows    = [];
        $headers = ['ID', 'Type', 'Source Lang', 'Source Title'];
        foreach ($langs as $lang) {
            $headers[] = strtoupper($lang) . ' Title';
        }
        $headers[] = 'Status';

        if ($type === 'terms') {
            // Term export
            $terms = get_terms(['hide_empty' => false, 'lang' => $sourceLang, 'number' => 2000]);
            if (!is_wp_error($terms)) {
                foreach ($terms as $term) {
                    $row = [$term->term_id, $term->taxonomy, $sourceLang, $term->name];
                    foreach ($langs as $lang) {
                        $trId   = function_exists('pll_get_term') ? pll_get_term($term->term_id, $lang) : 0;
                        $trTerm = $trId ? get_term($trId) : null;
                        $row[]  = $trTerm ? $trTerm->name : '';
                    }
                    $row[]  = 'published';
                    $rows[] = $row;
                }
            }
        } else {
            // Post export
            $ptypes = !empty($postTypes) ? $postTypes : ['post', 'page'];
            $posts  = get_posts([
                'post_type'      => $ptypes,
                'post_status'    => 'publish',
                'posts_per_page' => 2000,
                'lang'           => $sourceLang,
            ]);
            foreach ($posts as $post) {
                $row = [$post->ID, $post->post_type, $sourceLang, $post->post_title];
                foreach ($langs as $lang) {
                    $trId   = function_exists('pll_get_post') ? pll_get_post($post->ID, $lang) : 0;
                    $trPost = $trId ? get_post($trId) : null;
                    $row[]  = $trPost ? $trPost->post_title : '';
                }
                $row[]  = $post->post_status;
                $rows[] = $row;
            }
        }

        // CSV output
        $filename = 'translations-export-' . date('Y-m-d') . '.csv';

        // Output buffer'ı temizle
        if (ob_get_level()) ob_end_clean();

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');

        $output = fopen('php://output', 'w');
        // UTF-8 BOM (Excel uyumluluğu için)
        fputs($output, "\xEF\xBB\xBF");
        fputcsv($output, $headers);
        foreach ($rows as $row) {
            fputcsv($output, $row);
        }
        fclose($output);
        exit;
    }

    public function setTranslationLock(): void {
        $this->checkNonce();
        $postId = (int)($_POST['post_id'] ?? 0);
        $locked = (int)($_POST['locked'] ?? 0);
        if (!$postId) wp_send_json_error('Missing post_id');

        if ($locked) {
            update_post_meta($postId, '_sat_translation_lock', 1);
        } else {
            delete_post_meta($postId, '_sat_translation_lock');
        }
        wp_send_json_success(['locked' => (bool)$locked]);
    }

    public function clearLogs(): void {
        $this->checkNonce();
        global $wpdb;
        $table  = $wpdb->prefix . 'sat_translate_logs';
        $result = $wpdb->query("TRUNCATE TABLE {$table}");
        if ($result !== false) {
            wp_send_json_success(['message' => 'All logs cleared.']);
        } else {
            wp_send_json_error('Could not clear logs: ' . $wpdb->last_error);
        }
    }

    public function saveSettings(): void {
        $this->checkNonce();
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');
        $data = $_POST['settings'] ?? [];
        $this->container->get('settings')->save($data);
        wp_send_json_success(['message' => 'Settings saved']);
    }

    /**
     * Bir .po dosyasının tüm string'lerini okur ve mevcut çevirilerle döndürür.
     * Polylang string listesi gibi görüntülemek için kullanılır.
     */
    public function getPOStrings(): void {
        $this->checkNonce();

        $path   = sanitize_text_field(wp_unslash($_POST['path'] ?? ''));
        $page   = (int)($_POST['page'] ?? 1);
        $limit  = (int)($_POST['limit'] ?? 50);
        $skip   = filter_var($_POST['skip_translated'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $search = sanitize_text_field($_POST['search'] ?? '');

        if (!$path) wp_send_json_error('Missing path');

        // Güvenlik kontrolü
        $allowedBase = [WP_LANG_DIR, WP_PLUGIN_DIR, get_template_directory(), get_stylesheet_directory()];
        $realPath    = realpath($path);
        $allowed     = false;
        foreach ($allowedBase as $base) {
            $realBase = realpath($base);
            if ($realPath && $realBase && str_starts_with($realPath, $realBase)) {
                $allowed = true; break;
            }
        }
        if (!$allowed || !file_exists($path)) wp_send_json_error('File not found or not allowed');

        $entries = $this->parsePO($path);

        // Arama filtresi
        if ($search) {
            $entries = array_values(array_filter($entries, function($e) use ($search) {
                return str_contains(strtolower($e['msgid']), strtolower($search))
                    || str_contains(strtolower(is_array($e['msgstr']) ? implode(' ', $e['msgstr']) : $e['msgstr']), strtolower($search));
            }));
        }

        // Skip translated filtresi
        if ($skip) {
            $entries = array_values(array_filter($entries, function($e) {
                if ($e['is_plural']) {
                    $forms = (array)$e['msgstr'];
                    return empty($forms) || in_array('', $forms, true);
                }
                return (string)$e['msgstr'] === '';
            }));
        }

        $total  = count($entries);
        $paged  = array_slice($entries, ($page - 1) * $limit, $limit);

        // Response için temizle
        $items = array_map(function($e) {
            return [
                'msgid'     => $e['msgid'],
                'msgstr'    => $e['is_plural'] ? ($e['msgstr'][0] ?? '') : (string)$e['msgstr'],
                'msgstr_all'=> $e['is_plural'] ? (array)$e['msgstr'] : [(string)$e['msgstr']],
                'msgctxt'   => $e['msgctxt'],
                'plural'    => $e['plural'],
                'is_plural' => $e['is_plural'],
                'is_slug'   => $this->isSlugGroup('', $e['msgid']),
            ];
        }, $paged);

        wp_send_json_success([
            'items' => $items,
            'total' => $total,
            'pages' => (int)ceil($total / $limit),
        ]);
    }

    /**
     * Tek bir string'i .po dosyasına kaydet.
     */
    public function savePOString(): void {
        $this->checkNonce();

        $path       = sanitize_text_field(wp_unslash($_POST['path'] ?? ''));
        $msgid      = wp_unslash($_POST['msgid'] ?? '');
        $msgstr     = wp_unslash($_POST['msgstr'] ?? '');
        $msgctxt    = sanitize_text_field($_POST['msgctxt'] ?? '');
        $targetLang = sanitize_text_field($_POST['target_lang'] ?? '');

        if (!$path || !$msgid) wp_send_json_error('Missing parameters');

        $entries = $this->parsePO($path);

        foreach ($entries as &$entry) {
            if ($entry['msgid'] !== $msgid) continue;
            if ($msgctxt && $entry['msgctxt'] !== $msgctxt) continue;
            $entry['msgstr'] = $msgstr;
            break;
        }
        unset($entry);

        $this->writePO($path, $entries);

        // .mo derle
        $moPath = preg_replace('/\.po$/', '.mo', $path);
        $this->compileMO($path, $moPath);

        wp_send_json_success(['saved' => true, 'mo_updated' => file_exists($moPath)]);
    }

    private function checkNonce(): void {
        if (!check_ajax_referer('sat_nonce', 'nonce', false)) {
            wp_send_json_error('Invalid nonce');
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
    }

    public function exportFull(): void {
        (new \SAT\Admin\ExportImport($this->container))->exportFull();
    }

    public function importCsv(): void {
        (new \SAT\Admin\ExportImport($this->container))->importCsv();
    }

    public function parseXlsx(): void {
        (new \SAT\Admin\ExportImport($this->container))->parseXlsx();
    }

    /**
     * Queue'daki done/error row'larını temizle — kullanıcı tetiklemeli.
     * ajaxStatus'ta otomatik silme kaldırıldı, bu action kullanılır.
     */
    public function queueClearDone(): void {
        $this->checkNonce();
        global $wpdb;
        $table = $wpdb->prefix . 'sat_queue';
        $type  = sanitize_key($_POST['type'] ?? '');
        $where = $type ? $wpdb->prepare('AND object_type = %s', $type) : '';
        $wpdb->query("DELETE FROM {$table} WHERE status IN ('done','error') {$where}");
        wp_send_json_success(['cleared' => true]);
    }

    /**
     * Glossary XLSX/CSV Import
     *
     * Format (her iki format da desteklenir):
     *   XLSX/CSV: | source | target | notes (opsiyonel) |
     * Başlık satırı otomatik atlanır.
     * mode: 'append' (varsayılan) veya 'replace'
     */
    public function importGlossary(): void {
        $this->checkNonce();
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');

        if (empty($_FILES['file']['tmp_name'])) {
            wp_send_json_error('No file uploaded');
        }

        $mode      = sanitize_key($_POST['mode'] ?? 'append'); // 'append' | 'replace'
        $file      = $_FILES['file'];
        $ext       = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $tmpPath   = $file['tmp_name'];

        if (!in_array($ext, ['xlsx', 'csv'])) {
            wp_send_json_error('Only .xlsx and .csv files are supported');
        }

        $rows = [];

        if ($ext === 'xlsx') {
            // PhpSpreadsheet ile parse
            if (!class_exists('\PhpOffice\PhpSpreadsheet\IOFactory')) {
                wp_send_json_error('PhpSpreadsheet not available — install via composer');
            }
            try {
                $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmpPath);
                $sheet       = $spreadsheet->getActiveSheet();
                $data        = $sheet->toArray(null, true, true, false);
                // Başlık satırını tespit et (ilk satır "source"/"target" veya metin içeriyorsa atla)
                $startRow = 0;
                if (!empty($data[0])) {
                    $firstCell = strtolower(trim((string)($data[0][0] ?? '')));
                    if (in_array($firstCell, ['source', 'term', 'word', 'original', '#'])) {
                        $startRow = 1;
                    }
                }
                for ($i = $startRow; $i < count($data); $i++) {
                    $row = $data[$i];
                    $source = trim((string)($row[0] ?? ''));
                    $target = trim((string)($row[1] ?? ''));
                    if ($source === '') continue;
                    $rows[] = ['source' => $source, 'target' => $target];
                }
            } catch (\Throwable $e) {
                wp_send_json_error('XLSX parse error: ' . $e->getMessage());
            }
        } else {
            // CSV parse
            $handle = fopen($tmpPath, 'r');
            if (!$handle) wp_send_json_error('Could not open CSV file');

            $lineNo = 0;
            while (($data = fgetcsv($handle, 0, ',')) !== false) {
                $lineNo++;
                $source = trim($data[0] ?? '');
                $target = trim($data[1] ?? '');
                // Başlık satırını atla
                if ($lineNo === 1 && in_array(strtolower($source), ['source', 'term', 'word', 'original', '#'])) continue;
                if ($source === '') continue;
                $rows[] = ['source' => $source, 'target' => $target];
            }
            fclose($handle);
        }

        if (empty($rows)) {
            wp_send_json_error('No valid rows found in file. Expected format: source | target');
        }

        // Sanitize
        $newEntries = array_map(function($row) {
            return [
                'source' => sanitize_text_field($row['source']),
                'target' => sanitize_text_field($row['target']),
            ];
        }, $rows);

        $settings = $this->container->get('settings');
        $existing = $settings->get('glossary', []);

        if ($mode === 'replace') {
            $merged = $newEntries;
        } else {
            // Append: source'a göre duplicate kontrolü
            $existingSources = array_column($existing, 'source');
            $merged = $existing;
            $added  = 0;
            foreach ($newEntries as $entry) {
                if (!in_array($entry['source'], $existingSources, true)) {
                    $merged[]          = $entry;
                    $existingSources[] = $entry['source'];
                    $added++;
                }
            }
        }

        $settings->save(['glossary' => array_values($merged)]);

        wp_send_json_success([
            'imported' => count($newEntries),
            'total'    => count($merged),
            'mode'     => $mode,
            'message'  => count($newEntries) . ' term(s) imported. Glossary now has ' . count($merged) . ' entries.',
        ]);
    }

    /**
     * Translation Memory istatistikleri.
     */
    public function getMemoryStats(): void {
        $this->checkNonce();
        $memory = $this->container->get('memory');
        if (!$memory) wp_send_json_error('Translation Memory not available');
        wp_send_json_success($memory->getStats());
    }

    /**
     * Translation Memory temizle.
     */
    public function clearMemory(): void {
        $this->checkNonce();
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');
        $memory = $this->container->get('memory');
        if (!$memory) wp_send_json_error('Translation Memory not available');
        $lang    = sanitize_key($_POST['lang'] ?? '');
        $deleted = $memory->clear($lang);
        wp_send_json_success(['deleted' => $deleted, 'lang' => $lang ?: 'all']);
    }

    /**
     * Verilen grup veya string'in URL slug formatında olup olmadığını tespit et.
     * "URL kısa isimleri", "slug", "endpoint" gibi gruplar slug formatıdır.
     * Ayrıca string'in kendisi slug-like ise (sadece harf, rakam, tire, alt çizgi) de slug sayılır.
     */
    private function isSlugGroup(string $group, string $string): bool {
        // Grup adına göre tespit
        $slugGroupKeywords = ['url', 'slug', 'endpoint', 'kısa', 'kisa', 'permalink', 'rewrite'];
        $groupLower = mb_strtolower($group);
        foreach ($slugGroupKeywords as $keyword) {
            if (str_contains($groupLower, $keyword)) return true;
        }

        // String'in kendisi slug-like mi? (lowercase, harf/rakam/tire/alt çizgi, boşluk yok)
        // "order-received", "favorites", "edit-account" gibi
        if (preg_match('/^[a-z0-9][a-z0-9\-_]*[a-z0-9]$/', $string) && !str_contains($string, ' ')) {
            return true;
        }

        return false;
    }

    /**
     * Polylang string için alternatif çeviriler üret.
     * Her dil için count kadar farklı çeviri döndürür.
     * Daha önce üretilmiş çevirileri exclude ederek yeni alternatifler üretir.
     */
    public function translateStringAlternatives(): void {
        $this->checkNonce();

        $langs    = array_map('sanitize_key', (array)($_POST['langs'] ?? []));
        $string   = wp_unslash($_POST['string'] ?? '');
        $group    = sanitize_text_field($_POST['group'] ?? '');
        $count    = min((int)($_POST['count'] ?? 3), 5);
        $excludes = (array)($_POST['excludes'] ?? []); // dil → [exclude string'ler]

        if (empty($langs) || !$string) wp_send_json_error('Missing parameters');

        $translator = $this->container->get('translator');
        if (!$translator) wp_send_json_error('No translator configured');

        $results = [];

        foreach ($langs as $l) {
            $alts      = [];
            $excluded  = array_map('sanitize_text_field', (array)($excludes[$l] ?? []));
            $isSlug    = $this->isSlugGroup($group, $string);
            $excludeNote = !empty($excluded)
                ? ' Do NOT use any of these existing translations: ' . implode(', ', array_map(fn($e) => '"'.$e.'"', $excluded)) . '.'
                : '';

            if ($isSlug) {
                $prompt = "Translate this URL slug to {$l}. Context: {$group}."
                    . " Use only lowercase letters, numbers and hyphens (no spaces, no special characters, no accents)."
                    . " Return exactly {$count} different slug options, one per line, numbered 1. 2. 3."
                    . " Example format: 1. order-received\n2. order-completed\n3. order-done"
                    . $excludeNote;
            } else {
                $prompt = "Translate this UI string to {$l}. Context: {$group}."
                    . " Keep it short and natural."
                    . " Return exactly {$count} different translation options, one per line, numbered 1. 2. 3."
                    . " No explanations, just the translations."
                    . $excludeNote;
            }

            try {
                $raw = $translator->translate($string, $l, $prompt);
                preg_match_all('/^\d+\.\s*(.+)$/m', $raw, $m);
                $alts = array_map('trim', $m[1]);
                if (count($alts) < $count) {
                    $lines = array_filter(array_map('trim', explode("\n", $raw)));
                    $alts  = array_values(array_slice($lines, 0, $count));
                }
                // Slug grupları için sanitize et
                if ($isSlug) {
                    $alts = array_map('sanitize_title', $alts);
                }
                // Exclude edilen string'leri filtrele
                if (!empty($excluded)) {
                    $alts = array_values(array_filter($alts, fn($a) => !in_array($a, $excluded)));
                }
            } catch (\Throwable $e) {
                $alts = [];
            }

            $results[$l] = $alts;
        }

        wp_send_json_success(['alternatives' => $results]);
    }

    // ── PO File Translation ────────────────────────────────────────────────────

    /**
     * Mevcut .po dosyalarını listele.
     * Tema dil dosyalarını (template + child) + isteğe bağlı tüm plugin/WP dosyalarını döndürür.
     * Her dosya için dil integration'dan otomatik detect edilir.
     */
    public function getPOFiles(): void {
        $this->checkNonce();

        $scope      = sanitize_key($_POST['scope'] ?? 'theme'); // 'theme' veya 'all'
        $filterLang = sanitize_key($_POST['lang'] ?? '');

        // Integration'dan dil listesini al
        $integration = $this->container->get('integration');
        $knownLangs  = $integration ? array_keys($integration->getLanguages()) : [];

        $dirs = [];

        if ($scope === 'all') {
            // Tüm: WP core, tüm plugin'ler, tema
            $dirs['wp-lang']    = WP_LANG_DIR;
            $dirs['theme']      = get_template_directory() . '/languages';
            if (get_stylesheet_directory() !== get_template_directory()) {
                $dirs['child-theme'] = get_stylesheet_directory() . '/languages';
            }
            $activePlugins = get_option('active_plugins', []);
            foreach ($activePlugins as $plugin) {
                $slug    = dirname($plugin);
                if ($slug === '.') continue;
                $langDir = WP_PLUGIN_DIR . '/' . $slug . '/languages';
                if (is_dir($langDir)) {
                    $dirs['plugin:' . $slug] = $langDir;
                }
            }
        } else {
            // Sadece tema
            $dirs['theme'] = get_template_directory() . '/languages';
            if (get_stylesheet_directory() !== get_template_directory()) {
                $dirs['child-theme'] = get_stylesheet_directory() . '/languages';
            }
        }

        $files = [];
        foreach ($dirs as $group => $dir) {
            if (!is_dir($dir)) continue;
            $poFiles = glob($dir . '/*.po') ?: [];
            foreach ($poFiles as $filePath) {
                $filename = basename($filePath);

                // Dili dosya adından çıkar
                // Örnekler: tr_TR.po, en_US.po, plugin-name-tr_TR.po, tr.po
                $locale = '';
                if (preg_match('/-([a-z]{2}(?:_[A-Z]{2})?)\.po$/', $filename, $m)) {
                    $locale = $m[1];
                } elseif (preg_match('/^([a-z]{2}(?:_[A-Z]{2})?)\.po$/', $filename, $m)) {
                    $locale = $m[1];
                } else {
                    $locale = pathinfo($filename, PATHINFO_FILENAME);
                }

                // Lang filtresi
                $localeLower = strtolower(str_replace('_', '-', $locale));
                if ($filterLang) {
                    $filterLower = strtolower(str_replace('_', '-', $filterLang));
                    if ($localeLower !== $filterLower && !str_starts_with($localeLower, $filterLower)) continue;
                }

                // İstatistikler
                $entries      = $this->parsePO($filePath);
                $totalCount   = count($entries);
                $translated   = count(array_filter($entries, fn($e) => $e['is_plural']
                    ? !empty(array_filter((array)$e['msgstr'], fn($s) => $s !== ''))
                    : (string)$e['msgstr'] !== ''));
                $untranslated = $totalCount - $translated;

                // Integration dillerinden eşleştir
                $matchedLang = '';
                foreach ($knownLangs as $code) {
                    if (str_starts_with(strtolower($locale), strtolower($code))) {
                        $matchedLang = $code;
                        break;
                    }
                }

                $files[] = [
                    'path'         => $filePath,
                    'filename'     => $filename,
                    'locale'       => $locale,
                    'matched_lang' => $matchedLang,
                    'group'        => $group,
                    'total'        => $totalCount,
                    'translated'   => $translated,
                    'untranslated' => $untranslated,
                ];
            }
        }

        // Dile göre grupla
        usort($files, fn($a, $b) => strcmp($a['locale'], $b['locale']));

        wp_send_json_success(['files' => $files, 'known_langs' => $knownLangs]);
    }

    /**
     * Seçilen .po dosyasını AI ile çevir ve kaydet.
     * Plural forms, msgctxt ve skip_translated destekler.
     */
    public function translatePOFile(): void {
        $this->checkNonce();

        $path           = sanitize_text_field(wp_unslash($_POST['path'] ?? ''));
        $targetLang     = sanitize_text_field($_POST['target_lang'] ?? '');
        $skipTranslated = filter_var($_POST['skip_translated'] ?? true, FILTER_VALIDATE_BOOLEAN);
        $limit          = (int)($_POST['limit'] ?? 20);
        $offset         = (int)($_POST['offset'] ?? 0);

        if (!$path || !$targetLang) wp_send_json_error('Missing parameters');

        // Path güvenlik kontrolü
        $allowedBase = [WP_LANG_DIR, WP_PLUGIN_DIR, get_template_directory(), get_stylesheet_directory()];
        $realPath    = realpath($path);
        $allowed     = false;
        foreach ($allowedBase as $base) {
            $realBase = realpath($base);
            if ($realPath && $realBase && str_starts_with($realPath, $realBase)) {
                $allowed = true; break;
            }
        }
        if (!$allowed) wp_send_json_error('Path not allowed');
        if (!file_exists($path)) wp_send_json_error('File not found');

        $translator = $this->container->get('translator');
        if (!$translator) wp_send_json_error('No translator configured');

        $entries = $this->parsePO($path);

        // Çevrilmesi gerekenleri filtrele
        $toTranslate = [];
        foreach ($entries as $i => $entry) {
            if (!$entry['msgid']) continue;
            if ($entry['is_plural']) {
                $forms = (array)$entry['msgstr'];
                $hasEmpty = empty($forms) || in_array('', $forms, true);
                if ($skipTranslated && !$hasEmpty) continue;
            } else {
                if ($skipTranslated && (string)$entry['msgstr'] !== '') continue;
            }
            $toTranslate[] = ['index' => $i, 'entry' => $entry];
        }

        $total = count($toTranslate);
        $batch = array_slice($toTranslate, $offset, $limit);

        $translated = 0;
        $errors     = 0;

        // Plural form sayısını dile göre belirle
        $pluralCount = $this->getPluralFormCount($targetLang);

        foreach ($batch as $item) {
            $entry = $item['entry'];
            $idx   = $item['index'];

            try {
                // Log context
                if (method_exists($translator, 'setContext')) {
                    $translator->setContext([
                        'object_type' => 'po_file',
                        'object_id'   => 0,
                        'source_lang' => '',
                        'target_lang' => $targetLang,
                        'field_name'  => basename($path) . ':' . $entry['msgid'],
                    ]);
                }

                if ($entry['is_plural']) {
                    // Singular + plural ayrı ayrı çevir
                    $ctxNote = $entry['msgctxt'] ? " (context: {$entry['msgctxt']})" : '';
                    $singularPrompt = "Translate this UI string to {$targetLang}{$ctxNote}. Return ONLY the translation, nothing else.";
                    $pluralPrompt   = "Translate this plural UI string to {$targetLang}{$ctxNote}. Return ONLY the plural form translation, nothing else.";

                    $singularTr = $translator->translate($entry['msgid'], $targetLang, $singularPrompt);
                    $pluralTr   = $translator->translate($entry['plural'], $targetLang, $pluralPrompt);

                    // Plural forms doldur: [0] = singular, [1..n] = plural
                    $forms = array_fill(0, $pluralCount, $pluralTr);
                    $forms[0] = $singularTr;
                    $entries[$idx]['msgstr'] = $forms;
                } else {
                    $ctxNote = $entry['msgctxt'] ? " (context: {$entry['msgctxt']})" : '';
                    $prompt  = "Translate this UI string to {$targetLang}{$ctxNote}. Keep it short and natural. Return ONLY the translation.";
                    $entries[$idx]['msgstr'] = $translator->translate($entry['msgid'], $targetLang, $prompt);
                }
                $translated++;
            } catch (\Throwable $e) {
                $errors++;
            }
        }

        // .po dosyasını güncelle
        $this->writePO($path, $entries);

        // .mo binary derle
        $moPath = preg_replace('/\.po$/', '.mo', $path);
        $this->compileMO($path, $moPath);

        wp_send_json_success([
            'translated'  => $translated,
            'errors'      => $errors,
            'total'       => $total,
            'has_more'    => ($offset + $limit) < $total,
            'next_offset' => $offset + $limit,
            'mo_updated'  => file_exists($moPath),
        ]);
    }

    /**
     * Dile göre plural form sayısını döndür.
     * tr, ja, zh, ko gibi diller = 1 (plural yok)
     * en, de, fr, es gibi diller = 2
     * Slavic diller = 3+
     */
    private function getPluralFormCount(string $lang): int {
        $onePlural  = ['tr', 'ja', 'zh', 'ko', 'hu', 'vi', 'id', 'ms', 'th', 'fa'];
        $threePlural = ['ru', 'uk', 'be', 'bs', 'hr', 'sr', 'cs', 'sk', 'pl'];
        $fourPlural  = ['sl'];

        $base = strtolower(explode('-', explode('_', $lang)[0])[0]);
        if (in_array($base, $onePlural))   return 1;
        if (in_array($base, $threePlural)) return 3;
        if (in_array($base, $fourPlural))  return 4;
        return 2;
    }

    /**
     * .po dosyasını parse et — msgid/msgstr çiftlerini döndür.
     * msgctxt, msgid_plural, msgstr[n] (plural forms) destekler.
     * @return array<array{msgid: string, msgstr: string|string[], msgctxt: string, plural: string, is_plural: bool}>
     */
    private function parsePO(string $path): array {
        $content = file_get_contents($path);
        if ($content === false) return [];

        $entries = [];
        // Blokları boş satırlara göre ayır
        $blocks = preg_split('/\n{2,}/', $content);

        foreach ($blocks as $block) {
            $block = trim($block);
            if (!$block) continue;

            $msgctxt  = '';
            $msgid    = '';
            $plural   = '';
            $msgstr   = '';
            $msgstrN  = []; // plural forms
            $lines    = explode("\n", $block);

            $current = null;
            foreach ($lines as $line) {
                $line = rtrim($line);
                if ($line === '' || str_starts_with($line, '#')) {
                    $current = null;
                    continue;
                }

                if (preg_match('/^msgctxt\s+"(.*)"$/', $line, $m)) {
                    $msgctxt = stripcslashes($m[1]);
                    $current = 'msgctxt';
                } elseif (preg_match('/^msgid\s+"(.*)"$/', $line, $m)) {
                    $msgid   = stripcslashes($m[1]);
                    $current = 'msgid';
                } elseif (preg_match('/^msgid_plural\s+"(.*)"$/', $line, $m)) {
                    $plural  = stripcslashes($m[1]);
                    $current = 'plural';
                } elseif (preg_match('/^msgstr\s+"(.*)"$/', $line, $m)) {
                    $msgstr  = stripcslashes($m[1]);
                    $current = 'msgstr';
                } elseif (preg_match('/^msgstr\[(\d+)\]\s+"(.*)"$/', $line, $m)) {
                    $msgstrN[(int)$m[1]] = stripcslashes($m[2]);
                    $current = 'msgstr_n';
                } elseif (preg_match('/^"(.*)"$/', $line, $m)) {
                    // Continuation line
                    $val = stripcslashes($m[1]);
                    switch ($current) {
                        case 'msgctxt': $msgctxt .= $val; break;
                        case 'msgid':   $msgid   .= $val; break;
                        case 'plural':  $plural  .= $val; break;
                        case 'msgstr':  $msgstr  .= $val; break;
                    }
                }
            }

            if ($msgid === '') continue; // header

            $isPlural = !empty($plural) || !empty($msgstrN);
            $entries[] = [
                'msgid'     => $msgid,
                'msgstr'    => $isPlural ? $msgstrN : $msgstr,
                'msgctxt'   => $msgctxt,
                'plural'    => $plural,
                'is_plural' => $isPlural,
            ];
        }

        return $entries;
    }

    /**
     * .po dosyasını güncellenmiş entries ile yeniden yaz.
     * Orijinal yorum satırlarını ve formatı korur.
     */
    private function writePO(string $path, array $entries): void {
        $content = file_get_contents($path);
        if ($content === false) return;

        foreach ($entries as $entry) {
            if (!$entry['msgid']) continue;

            if ($entry['is_plural']) {
                // Plural: msgstr[0], msgstr[1] ... güncelle
                $msgstrN = (array)$entry['msgstr'];
                foreach ($msgstrN as $i => $val) {
                    if (!$val) continue;
                    $escapedId  = addcslashes($entry['msgid'], '"\\');
                    $escapedStr = addcslashes($val, '"\\');
                    $content = preg_replace(
                        '/(msgid\s+"' . preg_quote($escapedId, '/') . '".*?msgstr\[' . $i . '\]\s+)"[^"]*"/s',
                        '${1}"' . $escapedStr . '"',
                        $content, 1
                    );
                }
            } else {
                $msgstr = (string)$entry['msgstr'];
                if (!$msgstr) continue;
                $escapedId  = addcslashes($entry['msgid'], '"\\');
                $escapedStr = addcslashes($msgstr, '"\\');
                // msgctxt varsa daha spesifik match et
                if ($entry['msgctxt']) {
                    $escapedCtx = addcslashes($entry['msgctxt'], '"\\');
                    $content = preg_replace(
                        '/(msgctxt\s+"' . preg_quote($escapedCtx, '/') . '"\nmsgid\s+"' . preg_quote($escapedId, '/') . '"\nmsgstr\s+)"[^"]*"/s',
                        '${1}"' . $escapedStr . '"',
                        $content, 1
                    );
                } else {
                    $content = preg_replace(
                        '/((?<!msgid_)msgid\s+"' . preg_quote($escapedId, '/') . '"\nmsgstr\s+)"[^"]*"/s',
                        '${1}"' . $escapedStr . '"',
                        $content, 1
                    );
                }
            }
        }

        file_put_contents($path, $content);
    }

    /**
     * String'i PO format'ına escape et ve quote yap.
     */
    private function quotePO(string $str): string {
        $escaped = str_replace(['\\', '"', "\n", "\t"], ['\\\\', '\\"', '\\n', '\\t'], $str);
        return '"' . $escaped . '"';
    }

    /**
     * .po dosyasından .mo derle.
     * PHP'de native MO compiler — binary bağımlılık yok.
     */
    private function compileMO(string $poPath, string $moPath): void {
        $entries = $this->parsePO($poPath);
        // Sadece çevrilmişleri al
        $entries = array_filter($entries, fn($e) => $e['msgid'] !== '' && $e['msgstr'] !== '');
        $entries = array_values($entries);

        // MO dosya formatı (GNU MO binary)
        $magic    = 0x950412de; // little-endian magic
        $revision = 0;
        $count    = count($entries);
        $offsetIds = 28 + $count * 8;      // msgid string table offset
        $offsetStrs = $offsetIds + $count * 8; // msgstr string table offset
        $hashSize = 0;
        $hashOffset = $offsetStrs + $count * 8;

        // String pool oluştur
        $origPool = '';
        $transPool = '';
        $origOffsets = [];
        $transOffsets = [];

        foreach ($entries as $entry) {
            $origOffsets[]  = [strlen($entry['msgid']),  strlen($origPool)];
            $origPool      .= $entry['msgid'] . "\0";
            $transOffsets[] = [strlen($entry['msgstr']), strlen($transPool)];
            $transPool     .= $entry['msgstr'] . "\0";
        }

        $strPoolOffset = $hashOffset;

        // Binary header yaz
        $mo = pack('V', $magic)
            . pack('V', $revision)
            . pack('V', $count)
            . pack('V', $offsetIds)
            . pack('V', $offsetStrs)
            . pack('V', $hashSize)
            . pack('V', $hashOffset);

        // msgid offset table
        foreach ($origOffsets as [$len, $off]) {
            $mo .= pack('V', $len) . pack('V', $strPoolOffset + $off);
        }

        // msgstr offset table
        $transPoolStart = $strPoolOffset + strlen($origPool);
        foreach ($transOffsets as [$len, $off]) {
            $mo .= pack('V', $len) . pack('V', $transPoolStart + $off);
        }

        $mo .= $origPool . $transPool;

        file_put_contents($moPath, $mo);
    }
}
