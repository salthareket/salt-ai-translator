<?php
namespace SAT\Admin;

use SAT\Core\Container;

/**
 * Full Export / Import — posts, terms, menus, ACF, SEO
 *
 * @version 1.0.0
 * @changelog
 *   1.0.0 - 2026-06-10
 *     - Add: exportFull() — CSV export, tüm içerik tipleri, tüm field'lar
 *     - Add: importCsv() — CSV import, batch AJAX, skip-same, dry-run
 */
class ExportImport {

    private Container $container;

    public function __construct(Container $container) {
        $this->container = $container;
    }

    /**
     * Full CSV Export
     * sat_id | sat_type | sat_field | sat_context | [default_lang] | [lang1] | [lang2] | ...
     */
    public function exportFull(): void {
        check_ajax_referer('sat_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');

        $integration = $this->container->get('integration');
        if (!$integration) wp_die('No integration');

        $langs      = array_map('sanitize_key', (array)($_POST['langs'] ?? []));
        $ptypes     = array_map('sanitize_key', (array)($_POST['ptypes'] ?? []));
        $taxes      = array_map('sanitize_key', (array)($_POST['taxes'] ?? []));
        $others     = array_map('sanitize_key', (array)($_POST['others'] ?? []));
        $fields     = array_map('sanitize_key', (array)($_POST['fields'] ?? ['title']));
        $strGroups  = array_map('sanitize_text_field', (array)($_POST['str_groups'] ?? []));
        $default    = $integration->getDefaultLanguage();

        // Header kolonları: default dil her zaman ilk
        $langCols = array_merge([$default], $langs);

        $rows = [];

        // ── Posts ──────────────────────────────────────────────────────────────
        if (!empty($ptypes)) {
            $posts = get_posts([
                'post_type'      => $ptypes,
                'post_status'    => 'publish',
                'posts_per_page' => -1,
                'lang'           => $default,
                'orderby'        => 'type',
                'order'          => 'ASC',
            ]);

            foreach ($posts as $post) {
                // Sadece default dildeki post'ları işle — Polylang lang filtresi bazen tüm dilleri döner
                if (function_exists('pll_get_post_language')) {
                    $postLang = pll_get_post_language($post->ID);
                    if ($postLang && $postLang !== $default) continue;
                }

                $postRows = [];

                // Her dil için çeviri post'unu bul
                $translated = [$default => $post];
                foreach ($langs as $l) {
                    $trId = function_exists('pll_get_post') ? pll_get_post($post->ID, $l) : 0;
                    $translated[$l] = $trId ? get_post($trId) : null;
                }

                // Title
                if (in_array('title', $fields)) {
                    $row = [$post->ID, 'post', 'title', $post->post_type];
                    foreach ($langCols as $l) {
                        $row[] = $translated[$l]->post_title ?? '';
                    }
                    $postRows[] = $row;
                }

                // Content
                if (in_array('content', $fields)) {
                    $row = [$post->ID, 'post', 'content', $post->post_type];
                    foreach ($langCols as $l) {
                        $row[] = $translated[$l]->post_content ?? '';
                    }
                    $postRows[] = $row;
                }

                // Excerpt
                if (in_array('excerpt', $fields)) {
                    $row = [$post->ID, 'post', 'excerpt', $post->post_type];
                    foreach ($langCols as $l) {
                        $row[] = $translated[$l]->post_excerpt ?? '';
                    }
                    $postRows[] = $row;
                }

                // Slug
                if (in_array('slug', $fields)) {
                    $row = [$post->ID, 'post', 'slug', $post->post_type];
                    foreach ($langCols as $l) {
                        $row[] = $translated[$l]->post_name ?? '';
                    }
                    $postRows[] = $row;
                }

                // ACF
                if (in_array('acf', $fields) && function_exists('get_fields')) {
                    $acfFields = get_fields($post->ID);
                    if ($acfFields) {
                        foreach ($acfFields as $key => $value) {
                            if (is_array($value) || is_object($value)) continue; // sadece scalar
                            if (empty($value) && $value !== '0') continue;
                            $row = [$post->ID, 'post', 'acf:' . $key, $post->post_type];
                            foreach ($langCols as $l) {
                                $trPostId = $translated[$l]->ID ?? $post->ID;
                                $row[] = get_field($key, $trPostId) ?? '';
                            }
                            $postRows[] = $row;
                        }
                    }
                }

                // SEO (Yoast)
                if (in_array('seo', $fields)) {
                    // meta description
                    $row = [$post->ID, 'post', 'seo:meta_desc', $post->post_type];
                    foreach ($langCols as $l) {
                        $trId2 = $translated[$l]->ID ?? $post->ID;
                        $desc  = get_post_meta($trId2, '_yoast_wpseo_metadesc', true)
                               ?: get_post_meta($trId2, 'rank_math_description', true);
                        $row[] = $desc ?? '';
                    }
                    $postRows[] = $row;

                    // seo title
                    $row = [$post->ID, 'post', 'seo:title', $post->post_type];
                    foreach ($langCols as $l) {
                        $trId2 = $translated[$l]->ID ?? $post->ID;
                        $title = get_post_meta($trId2, '_yoast_wpseo_title', true)
                               ?: get_post_meta($trId2, 'rank_math_title', true);
                        $row[] = $title ?? '';
                    }
                    $postRows[] = $row;
                }

                if (!empty($postRows)) {
                    foreach ($postRows as $r) $rows[] = $r;
                    $rows[] = []; // blank separator
                }
            }
        }

        // ── Terms ──────────────────────────────────────────────────────────────
        if (!empty($taxes)) {
            $terms = get_terms([
                'taxonomy'   => $taxes,
                'hide_empty' => false,
                'lang'       => $default,
                'number'     => 0,
            ]);

            if (!is_wp_error($terms)) {
                foreach ($terms as $term) {
                    $termRows   = [];
                    $translated = [$default => $term];
                    foreach ($langs as $l) {
                        $trId = function_exists('pll_get_term') ? pll_get_term($term->term_id, $l) : 0;
                        $translated[$l] = $trId ? get_term($trId) : null;
                    }

                    if (in_array('title', $fields)) {
                        $row = [$term->term_id, 'term', 'name', $term->taxonomy];
                        foreach ($langCols as $l) {
                            $row[] = $translated[$l]->name ?? '';
                        }
                        $termRows[] = $row;
                    }

                    if (in_array('content', $fields) || in_array('excerpt', $fields)) {
                        $row = [$term->term_id, 'term', 'description', $term->taxonomy];
                        foreach ($langCols as $l) {
                            $row[] = $translated[$l]->description ?? '';
                        }
                        $termRows[] = $row;
                    }

                    if (in_array('slug', $fields)) {
                        $row = [$term->term_id, 'term', 'slug', $term->taxonomy];
                        foreach ($langCols as $l) {
                            $row[] = $translated[$l]->slug ?? '';
                        }
                        $termRows[] = $row;
                    }

                    if (!empty($termRows)) {
                        foreach ($termRows as $r) $rows[] = $r;
                        $rows[] = [];
                    }
                }
            }
        }

        // ── Menus ──────────────────────────────────────────────────────────────
        if (in_array('menus', $others)) {
            $menus = wp_get_nav_menus();
            // Polylang nav_menus option — location → lang → menu_id haritası
            $pllOptions  = get_option('polylang', []);
            $pllNavMenus = $pllOptions['nav_menus'] ?? [];
            $theme       = get_stylesheet();

            foreach ($menus as $menu) {
                // Sadece default dildeki menüleri kaynak olarak kullan
                if (function_exists('pll_get_term_language')) {
                    $menuLang = pll_get_term_language($menu->term_id);
                    if ($menuLang && $menuLang !== $default) continue;
                    // Dil ataması yoksa — location bazlı kontrol yap
                    if (!$menuLang) {
                        // Bu menünün bir location'da default dil olup olmadığını kontrol et
                        $isDefault = false;
                        foreach ($pllNavMenus[$theme] ?? [] as $location => $langMenus) {
                            if (isset($langMenus[$default]) && (int)$langMenus[$default] === (int)$menu->term_id) {
                                $isDefault = true;
                                break;
                            }
                        }
                        if (!$isDefault) continue;
                    }
                }

                $items = wp_get_nav_menu_items($menu->term_id);
                if (!$items) continue;

                // Bu menünün hangi location'da olduğunu bul
                $menuLocation = '';
                foreach ($pllNavMenus[$theme] ?? [] as $loc => $langMenus) {
                    if (isset($langMenus[$default]) && (int)$langMenus[$default] === (int)$menu->term_id) {
                        $menuLocation = $loc;
                        break;
                    }
                }

                // Her dil için aynı location'daki karşılık menüyü bul (location bazlı)
                $trMenuIds = [$default => $menu->term_id];
                foreach ($langs as $l) {
                    // Önce location bazlı dene
                    if ($menuLocation && isset($pllNavMenus[$theme][$menuLocation][$l])) {
                        $trMenuIds[$l] = (int)$pllNavMenus[$theme][$menuLocation][$l];
                    } else {
                        // Fallback: pll_get_term
                        $trMenuIds[$l] = function_exists('pll_get_term') ? (int)pll_get_term($menu->term_id, $l) : 0;
                    }
                }

                foreach ($items as $item) {
                    $row = [$item->ID, 'menu_item', 'title', $menu->name];
                    foreach ($langCols as $l) {
                        if ($l === $default) {
                            $row[] = $item->title;
                        } else {
                            $trMenuId = $trMenuIds[$l] ?? 0;
                            if ($trMenuId) {
                                $trItems = wp_get_nav_menu_items($trMenuId);
                                $found   = '';
                                if ($trItems) {
                                    foreach ($trItems as $trItem) {
                                        if ($trItem->menu_order === $item->menu_order) {
                                            $found = $trItem->title;
                                            break;
                                        }
                                    }
                                }
                                $row[] = $found;
                            } else {
                                $row[] = '';
                            }
                        }
                    }
                    $rows[] = $row;
                }
                $rows[] = [];
            }
        }

        // ── Polylang Strings ───────────────────────────────────────────────────
        if (!empty($strGroups) && class_exists('\PLL_Admin_Strings')) {
            $pllStrings = \PLL_Admin_Strings::get_strings();
            if (is_array($pllStrings)) {
                $groupedStrings = [];
                foreach ($pllStrings as $s) {
                    $ctx = $s['context'] ?? '';
                    if (!in_array($ctx, $strGroups)) continue;
                    $groupedStrings[$ctx][] = $s;
                }

                foreach ($groupedStrings as $group => $strings) {
                    foreach ($strings as $s) {
                        $origStr = (string)($s['string'] ?? '');
                        $name    = $s['name'] ?? '';
                        $hash    = abs(crc32($group . '::' . $name));
                        $row     = [$hash, 'string', $name, $group];
                        foreach ($langCols as $l) {
                            if ($l === $default) {
                                $row[] = $origStr;
                            } else {
                                $mo  = new \PLL_MO();
                                $mo->import_from_db($l);
                                $tr  = $mo->translate($origStr);
                                $row[] = ($tr && $tr !== $origStr) ? $tr : '';
                            }
                        }
                        $rows[] = $row;
                    }
                    $rows[] = []; // group separator
                }
            }
        }

        // ── Theme PO Files ─────────────────────────────────────────────────────
        if (in_array('theme_po', $others)) {
            $themeLangDir = get_template_directory() . '/languages';
            if (is_dir($themeLangDir)) {
                // Dil → dosya eşleştirmesi: tr_TR.po → 'tr', de_DE.po → 'de'
                $langToFile = [];
                foreach ($langs as $l) {
                    // locale formatında ara: tr → tr_TR.po, de → de_DE.po
                    $langUpper = strtolower($l) . '_' . strtoupper($l);
                    $found = glob($themeLangDir . '/' . $langUpper . '.po');
                    if (empty($found)) {
                        // Wildcard ile dene: *tr*.po veya *_TR.po
                        $found = glob($themeLangDir . '/' . strtolower($l) . '_*.po');
                    }
                    if (!empty($found)) {
                        $langToFile[$l] = $found[0];
                    }
                }

                if (!empty($langToFile)) {
                    // İlk bulunan dosyayı referans al — tüm msgid'ler aynı olmalı
                    $refFile    = reset($langToFile);
                    $refLang    = key($langToFile);
                    // Default dil için .pot dosyası varsa kullan (source strings)
                    $defaultFile = null;
                    $defFound = glob($themeLangDir . '/' . strtolower($default) . '_*.po');
                    if (!empty($defFound)) {
                        $defaultFile = $defFound[0];
                    } else {
                        // .pot dosyasını kaynak olarak kullan
                        $potFound = glob($themeLangDir . '/*.pot');
                        if (!empty($potFound)) $defaultFile = $potFound[0];
                    }

                    // Tüm dillerin entry'lerini yükle
                    $allEntries = [];
                    foreach ($langToFile as $l => $poFile) {
                        $allEntries[$l] = $this->parsePOForExport($poFile);
                    }
                    // Default dil source'u referans dosyadan al
                    $sourceEntries = $defaultFile ? $this->parsePOForExport($defaultFile) : [];

                    // Referans dosyadan msgid listesi çek
                    $msgids = array_keys($this->parsePOForExport($refFile));
                    // Diğer dillerdeki msgid'leri de ekle
                    foreach ($allEntries as $entries) {
                        foreach (array_keys($entries) as $mid) {
                            if (!in_array($mid, $msgids)) $msgids[] = $mid;
                        }
                    }

                    $filename_ref    = basename($refFile);
                    $allFileNames    = implode(', ', array_map('basename', array_values($langToFile)));
                    $themeFileBase   = preg_replace('/_[a-zA-Z]{2,5}(_[A-Z]{2,5})?\.po$/', '', $filename_ref);
                    foreach ($msgids as $msgid) {
                        $hash = abs(crc32('po_theme::' . $msgid));
                        $row  = [$hash, 'po_theme', $allFileNames, 'po_theme'];
                        foreach ($langCols as $lc) {
                            if ($lc === $default) {
                                $srcStr = $sourceEntries[$msgid] ?? '';
                                // .pot'ta msgstr boş olur, msgid'i source olarak kullan
                                $row[] = !empty($srcStr) ? $srcStr : $msgid;
                            } else {
                                $row[] = $allEntries[$lc][$msgid] ?? '';
                            }
                        }
                        $rows[] = $row;
                    }
                    $rows[] = [];
                }
            }
        }

        // ── XLSX Output ────────────────────────────────────────────────────────
        if (ob_get_level()) ob_end_clean();

        $filename = 'translations-' . get_bloginfo('name') . '-' . date('Y-m-d') . '.xlsx';
        $filename = sanitize_file_name($filename);

        // Header row
        $header = ['sat_id', 'sat_type', 'sat_field', 'sat_context'];
        foreach ($langCols as $l) $header[] = strtoupper($l);

        if (class_exists('\PhpOffice\PhpSpreadsheet\Spreadsheet')) {
            $this->outputXlsx($header, $rows, $filename, $langCols);
        } else {
            // Fallback: CSV
            $filename = str_replace('.xlsx', '.csv', $filename);
            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Cache-Control: no-cache');
            $out = fopen('php://output', 'w');
            fputs($out, "\xEF\xBB\xBF");
            fputcsv($out, $header);
            foreach ($rows as $row) {
                if (empty($row)) fwrite($out, "\n");
                else fputcsv($out, $row);
            }
            fclose($out);
        }
        exit;
    }

    /**
     * XLSX çıktısı — PhpSpreadsheet ile
     * Wrap text, frozen header, sütun genişlikleri ayarlı
     */
    private function outputXlsx(array $header, array $rows, string $filename, array $langCols = []): void {
        require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet       = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Translations');

        // ── Styles ──────────────────────────────────────────────────────────────
        $headerStyle = [
            'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill'      => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => '4A4AFF']],
            'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER, 'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER, 'wrapText' => true],
            'borders'   => ['allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN, 'color' => ['rgb' => 'CCCCCC']]],
        ];
        $cellStyle = [
            'alignment' => ['vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_TOP, 'wrapText' => true],
            'borders'   => ['allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN, 'color' => ['rgb' => 'E0E0E0']]],
        ];
        $idStyle = [
            'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER, 'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_TOP],
            'font'      => ['color' => ['rgb' => '888888'], 'size' => 9],
        ];

        // ── Header row ──────────────────────────────────────────────────────────
        $sheet->fromArray([$header], null, 'A1');
        $lastCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($header));
        $sheet->getStyle('A1:' . $lastCol . '1')->applyFromArray($headerStyle);
        $sheet->getRowDimension(1)->setRowHeight(24);
        $sheet->freezePane('A2');

        // ── Data rows ───────────────────────────────────────────────────────────
        $rowIdx  = 2;
        $groupBg = false; // alternatif grup rengi

        foreach ($rows as $row) {
            if (empty($row)) {
                $groupBg = !$groupBg; // boş satır = yeni grup
                continue;
            }

            // fromArray yerine explicit string write — HTML/comment içeren değerler bozulmasın
            foreach ($row as $colIdx => $val) {
                $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIdx + 1);
                $cell = $sheet->getCell($colLetter . $rowIdx);
                $cell->setValueExplicit((string)$val, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            }
            $sheet->getStyle('A' . $rowIdx . ':' . $lastCol . $rowIdx)->applyFromArray($cellStyle);

            // Grup rengi
            if ($groupBg) {
                $sheet->getStyle('A' . $rowIdx . ':' . $lastCol . $rowIdx)
                      ->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                      ->getStartColor()->setRGB('F9F9FF');
            }

            // ID/type/field kolonları küçük
            $sheet->getStyle('A' . $rowIdx . ':D' . $rowIdx)->applyFromArray($idStyle);

            // Satır yüksekliği — content uzunluğuna göre
            $maxLen = max(array_map('mb_strlen', array_slice($row, 4)));
            $height = min(max(15, ceil($maxLen / 60) * 15), 200);
            $sheet->getRowDimension($rowIdx)->setRowHeight($height);

            $rowIdx++;
        }

        // ── Sütun genişlikleri ──────────────────────────────────────────────────
        $colWidths = [8, 8, 18, 12]; // sat_id, sat_type, sat_field, sat_context
        $langColCount = count($langCols ?? []) + 1; // +1 default dil
        for ($i = 0; $i < $langColCount; $i++) $colWidths[] = 55;

        foreach ($colWidths as $i => $width) {
            $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
            $sheet->getColumnDimension($colLetter)->setWidth($width);
        }

        // ── HTML içeriği strip et — sadece basic formatting tut, block comment'lar korunsun ──
        // NOT: content field'larını strip etmiyoruz — translator'ın HTML'i görmesi gerekiyor
        // Sadece excerpt/title gibi kısa field'larda basic cleanup yap
        $shortFields = ['title', 'name', 'description', 'slug', 'excerpt'];
        $shortFieldCols = array_keys(array_filter($header, function($h) use ($shortFields) {
            foreach ($shortFields as $sf) {
                if (str_contains(strtolower($h), $sf)) return false; // dil kolonları — dokunma
            }
            return !in_array($h, ['sat_id','sat_type','sat_field','sat_context']);
        }));
        // Aslında hiçbir şeyi strip etmiyoruz — ham değer kalsın
        // strip_tags kaldırıldı

        // ── Download ─────────────────────────────────────────────────────────────
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: max-age=0');

        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save('php://output');
    }

    /**
     * XLSX dosyasını parse et — rows JSON döndür
     * Import için server-side parse
     */
    public function parseXlsx(): void {
        check_ajax_referer('sat_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');

        if (empty($_FILES['file'])) wp_send_json_error('No file uploaded');

        $file = $_FILES['file'];
        if ($file['error'] !== UPLOAD_ERR_OK) wp_send_json_error('Upload error: ' . $file['error']);

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($ext !== 'xlsx') wp_send_json_error('Only .xlsx files accepted');

        // MIME type ve magic bytes kontrolü
        $finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : null;
        if ($finfo) {
            $mime = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
            $allowedMimes = [
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'application/zip', // XLSX zip tabanlı, bazı sistemlerde zip olarak algılanır
                'application/octet-stream',
            ];
            if (!in_array($mime, $allowedMimes)) {
                wp_send_json_error('Invalid file type. Only .xlsx files are accepted.');
            }
        }

        require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

        try {
            $reader      = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($file['tmp_name']);
            $sheet       = $spreadsheet->getActiveSheet();
            $data        = $sheet->toArray(null, true, true, false);
        } catch (\Throwable $e) {
            wp_send_json_error('Parse error: ' . $e->getMessage());
        }

        if (empty($data)) wp_send_json_error('Empty file');

        // İlk satır header
        $header = array_map('trim', array_shift($data));

        // Format kontrolü — bizim CSV/XLSX formatında olmalı
        $required = ['sat_id', 'sat_type', 'sat_field', 'sat_context'];
        $missing  = array_diff($required, $header);
        if (!empty($missing)) {
            wp_send_json_error('Invalid file format. This file was not exported from Salt AI Translator. Missing columns: ' . implode(', ', $missing));
        }
        // Dil kolonları — boş olmayan, sadece 2-5 karakter büyük harf (EN, TR, DE, FR vs)
        $langKeys = array_values(array_filter($header, fn($h) => 
            !empty($h) && 
            !in_array($h, ['sat_id','sat_type','sat_field','sat_context']) &&
            preg_match('/^[A-Z]{2,5}$/', $h)
        ));

        $rows      = [];
        $validTypes = ['post', 'term', 'menu_item'];

        foreach ($data as $rawRow) {
            if (empty(array_filter($rawRow, fn($v) => $v !== null && $v !== ''))) continue; // boş satır
            $row = [];
            foreach ($header as $i => $key) {
                $row[$key] = (string)($rawRow[$i] ?? '');
            }
            if (!empty($row['sat_id']) && !empty($row['sat_type']) && !empty($row['sat_field'])) {
                $rows[] = $row;
            }
        }

        $types = array_values(array_unique(array_filter(
            array_column($rows, 'sat_type'),
            fn($t) => in_array($t, $validTypes)
        )));

        wp_send_json_success([
            'rows'  => $rows,
            'langs' => array_values($langKeys),
            'types' => $types,
        ]);
    }

    /**
     * Batch CSV Import
     * Her batch 50 satır — client-side ile çağrılır
     * @return void — JSON response
     */
    public function importCsv(): void {
        check_ajax_referer('sat_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');

        $integration = $this->container->get('integration');
        if (!$integration) wp_send_json_error('No integration');

        // sanitize_text_field kullanma — newline karakterlerini siliyor, JSON bozuluyor
        $rowsJson = wp_unslash($_POST['rows'] ?? '[]');
        try {
            $rows = json_decode($rowsJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            wp_send_json_error('Invalid JSON payload: ' . $e->getMessage());
        }
        $skipSame = (bool)($_POST['skip_same'] ?? true);
        $dryRun   = (bool)($_POST['dry_run']   ?? false);
        $default  = $integration->getDefaultLanguage();

        if (!is_array($rows) || empty($rows)) {
            wp_send_json_success(['updated' => 0, 'skipped' => 0, 'errors' => 0, 'log' => []]);
        }

        // Header'dan dil kolonlarını belirle — sadece gerçek dil kodları (EN, TR, DE vs)
        // default dili baştan çıkar
        $firstRow = reset($rows);
        $defaultUpper = strtoupper($default);
        $langKeys = array_values(array_filter(
            array_keys($firstRow),
            fn($k) => !in_array($k, ['sat_id','sat_type','sat_field','sat_context'])
                   && !empty($k)
                   && strtoupper($k) !== $defaultUpper // default dili çıkar
                   && preg_match('/^[A-Za-z]{2,5}$/', $k) // sadece dil kodu formatı
        ));

        $updated = 0; $skipped = 0; $errors = 0; $log = [];

        foreach ($rows as $row) {
            $satId    = (int)($row['sat_id']    ?? 0);
            $satType  = sanitize_key($row['sat_type']  ?? '');
            $satField = sanitize_text_field($row['sat_field'] ?? '');
            $satCtx   = sanitize_text_field($row['sat_context'] ?? '');
            if (!$satId || !$satType || !$satField) { continue; } // boş ayraç satırları sayma

            foreach ($langKeys as $langCol) {
                $lang      = strtolower($langCol);
                $newValue  = $row[$langCol] ?? '';

                if ($lang === $default) continue; // default dili update etme

                try {
                    $diffInfo = [];
                    $result = $this->updateField($satType, $satId, $satField, $lang, $newValue, $skipSame, $dryRun, $integration, $diffInfo, $satCtx);
                    if ($result === 'updated') {
                        $updated++;
                        if ($dryRun && !empty($diffInfo)) {
                            // Dry run: farkı göster — ilk farklı konumu bul
                            $db  = $diffInfo['db'];
                            $csv = $diffInfo['csv'];
                            $diffPos = 0;
                            $minLen  = min(strlen($db), strlen($csv));
                            for ($p = 0; $p < $minLen; $p++) {
                                if ($db[$p] !== $csv[$p]) { $diffPos = $p; break; }
                                $diffPos = $p + 1;
                            }
                            $snippet = max(0, $diffPos - 20);
                            // Replace invisible chars with visible markers for display
                            $vis = fn($s) => str_replace(["\n","\r","\t"], ['↵','⏎','→'], mb_substr(substr($s, $snippet), 0, 120));
                            $log[] = '🔍 ' . $satType . '#' . $satId . ' ' . $satField . ' [' . $lang . '] diff@' . $diffPos
                                   . "\n  DB : " . $vis($db)
                                   . "\n  CSV: " . $vis($csv);
                        } else {
                            $log[] = '✅ ' . $satType . '#' . $satId . ' ' . $satField . ' [' . $lang . ']';
                        }
                    }
                    elseif ($result === 'skipped') { $skipped++; }
                    elseif ($result === 'error')   { $errors++;  $log[] = '❌ ' . $satType . '#' . $satId . ' ' . $satField . ' [' . $lang . ']'; }
                } catch (\Throwable $e) {
                    $errors++;
                    $log[] = '❌ Exception: ' . $e->getMessage();
                }
            }
        }

        wp_send_json_success(['updated' => $updated, 'skipped' => $skipped, 'errors' => $errors, 'log' => $log]);
    }

    /**
     * Bir field'ı güncelle
     * @return string 'updated'|'skipped'|'error'
     */
    private function updateField(string $type, int $id, string $field, string $lang, string $value, bool $skipSame, bool $dryRun, $integration, array &$diffInfo = [], string $context = ''): string {
        $diffInfo = [];

        if ($type === 'post') {
            // Hedef dildeki post ID'sini bul
            $trId = function_exists('pll_get_post') ? pll_get_post($id, $lang) : 0;
            if (!$trId) return 'skipped'; // çeviri yok

            if (str_starts_with($field, 'acf:')) {
                $key     = substr($field, 4);
                $current = (string)(get_field($key, $trId) ?? '');
                if ($skipSame && $current === $value) return 'skipped';
                $diffInfo = ['db' => $current, 'csv' => $value];
                if (!$dryRun) update_field($key, $value, $trId);
                return 'updated';
            }

            if (str_starts_with($field, 'seo:')) {
                $metaKey = $field === 'seo:meta_desc' ? '_yoast_wpseo_metadesc' : '_yoast_wpseo_title';
                $current = (string)(get_post_meta($trId, $metaKey, true) ?? '');
                if ($skipSame && $current === $value) return 'skipped';
                $diffInfo = ['db' => $current, 'csv' => $value];
                if (!$dryRun) update_post_meta($trId, $metaKey, $value);
                return 'updated';
            }

            $wpField = ['title' => 'post_title', 'content' => 'post_content', 'excerpt' => 'post_excerpt', 'slug' => 'post_name'][$field] ?? null;
            if (!$wpField) return 'skipped';
            $post = get_post($trId);
            if (!$post) return 'error';
            $current = $this->normalizeLineEndings((string)($post->$wpField ?? ''));
            $val     = $this->normalizeLineEndings($value);
            if ($skipSame && $current === $val) return 'skipped';
            $diffInfo = ['db' => $current, 'csv' => $val];
            if (!$dryRun) wp_update_post(['ID' => $trId, $wpField => $value]);
            return 'updated';
        }

        if ($type === 'term') {
            $trId = function_exists('pll_get_term') ? pll_get_term($id, $lang) : 0;
            if (!$trId) return 'skipped';
            $term = get_term($trId);
            if (!$term || is_wp_error($term)) return 'error';
            $wpField = ['name' => 'name', 'description' => 'description', 'slug' => 'slug'][$field] ?? null;
            if (!$wpField) return 'skipped';
            $current = $this->normalizeLineEndings((string)($term->$wpField ?? ''));
            $val     = $this->normalizeLineEndings($value);
            if ($skipSame && $current === $val) return 'skipped';
            $diffInfo = ['db' => $current, 'csv' => $val];
            if (!$dryRun) wp_update_term($trId, $term->taxonomy, [$wpField => $value]);
            return 'updated';
        }

        if ($type === 'menu_item') {
            if ($field !== 'title') return 'skipped';
            $sourceItem = get_post($id);
            if (!$sourceItem) return 'skipped';

            // Polylang nav_menus option — location bazlı hedef menüyü bul
            $pllOptions  = get_option('polylang', []);
            $pllNavMenus = $pllOptions['nav_menus'] ?? [];
            $theme       = get_stylesheet();

            // Kaynak item'ın hangi menüde olduğunu bul
            $targetItemId = 0;
            $menus = wp_get_nav_menus();
            foreach ($menus as $menu) {
                $items = wp_get_nav_menu_items($menu->term_id);
                if (!$items) continue;
                $foundInMenu = false;
                foreach ($items as $item) {
                    if ((int)$item->ID === $id) { $foundInMenu = true; break; }
                }
                if (!$foundInMenu) continue;

                // Hedef dil menüsünü bul: önce location bazlı, sonra pll_get_term
                $trMenuId = 0;
                // Location bazlı arama
                foreach ($pllNavMenus[$theme] ?? [] as $location => $langMenus) {
                    $defLang = $integration->getDefaultLanguage();
                    if (isset($langMenus[$defLang]) && (int)$langMenus[$defLang] === (int)$menu->term_id) {
                        if (isset($langMenus[$lang])) {
                            $trMenuId = (int)$langMenus[$lang];
                        }
                        break;
                    }
                }
                // Fallback: pll_get_term
                if (!$trMenuId && function_exists('pll_get_term')) {
                    $trMenuId = (int)pll_get_term($menu->term_id, $lang);
                }
                if (!$trMenuId) return 'skipped';

                $trItems = wp_get_nav_menu_items($trMenuId);
                if (!$trItems) return 'skipped';
                foreach ($trItems as $trItem) {
                    if ($trItem->menu_order === $sourceItem->menu_order) {
                        $targetItemId = $trItem->ID;
                        break;
                    }
                }
                break;
            }

            if (!$targetItemId) return 'skipped';
            $targetPost = get_post($targetItemId);
            $current    = $targetPost->post_title ?? '';
            // post_title boşsa bağlı object'in title'ını kullan (WP menu davranışıyla tutarlı)
            if (empty($current) && $targetPost) {
                $objId   = get_post_meta($targetItemId, '_menu_item_object_id', true);
                $objType = get_post_meta($targetItemId, '_menu_item_type', true);
                if ($objId && $objType === 'post_type') {
                    $obj     = get_post((int)$objId);
                    $current = $obj->post_title ?? '';
                } elseif ($objId && $objType === 'taxonomy') {
                    $term    = get_term((int)$objId);
                    $current = (!is_wp_error($term) && $term) ? $term->name : '';
                }
            }
            if ($skipSame && $current === $value) return 'skipped';
            $diffInfo = ['db' => $current, 'csv' => $value];
            if (!$dryRun) wp_update_post(['ID' => $targetItemId, 'post_title' => $value]);
            return 'updated';
        }

        // ── Polylang String ────────────────────────────────────────────────────
        if ($type === 'string') {
            if (!class_exists('\PLL_MO')) return 'skipped';
            if (empty($value)) return 'skipped';

            // context = group, field = name — bu ikisiyle orijinal string'i bul
            $origString = '';
            if (class_exists('\PLL_Admin_Strings')) {
                $pllStrings = \PLL_Admin_Strings::get_strings();
                foreach ($pllStrings as $s) {
                    $sGroup = $s['context'] ?? '';
                    $sName  = $s['name'] ?? '';
                    // context ve field (name) ile eşleş, yoksa id (hash) ile dene
                    if (($sGroup === $context && $sName === $field)
                        || abs(crc32($sGroup . '::' . $sName)) === $id) {
                        $origString = (string)($s['string'] ?? '');
                        break;
                    }
                }
            }
            if (empty($origString)) return 'skipped';

            $mo = new \PLL_MO();
            $mo->import_from_db($lang);
            $current = $mo->translate($origString);
            if ($skipSame && $current === $value) return 'skipped';
            $diffInfo = ['db' => $current, 'csv' => $value];
            if (!$dryRun) {
                $mo->add_entry($mo->make_entry($origString, $value));
                $mo->export_to_db($lang);
            }
            return 'updated';
        }

        // ── Theme PO String ────────────────────────────────────────────────────
        if ($type === 'po_theme') {
            // field = dosya adı (suffix'siz), id = hash(msgid)
            $themeLangDir = get_template_directory() . '/languages';

            // Hedef dile ait .po dosyasını bul: lang → tr_TR.po
            $langUpper = strtolower($lang) . '_' . strtoupper($lang);
            $poFiles   = glob($themeLangDir . '/' . $langUpper . '.po');
            if (empty($poFiles)) $poFiles = glob($themeLangDir . '/' . strtolower($lang) . '_*.po');
            if (empty($poFiles) || empty($value)) return 'skipped';
            $poFile = $poFiles[0];

            // Referans (source) dosyadan msgid'i bul — hash'e göre
            $refEntries = [];
            // Tüm .po ve .pot dosyalarından msgid bul
            $allPo = array_merge(
                glob($themeLangDir . '/*.po') ?: [],
                glob($themeLangDir . '/*.pot') ?: []
            );
            foreach ($allPo as $pf) {
                $entries = $this->parsePOForExport($pf);
                foreach (array_keys($entries) as $mid) {
                    if (abs(crc32('po_theme::' . $mid)) === $id) {
                        $origMsgid = $mid;
                        break 2;
                    }
                }
            }

            if (empty($origMsgid)) return 'skipped';
            $entries = $this->parsePOForExport($poFile);
            $current = $entries[$origMsgid] ?? '';
            if ($skipSame && $current === $value) return 'skipped';
            $diffInfo = ['db' => $current, 'csv' => $value];
            if (!$dryRun) {
                $ok = $this->writePOEntry($poFile, $origMsgid, $value, $lang);
                if (!$ok) return 'error';
            }
            return 'updated';
        }

        return 'skipped';
    }

    /**
     * PO/POT dosyasından msgid → msgstr map'i döndür (export için)
     * Multiline msgid/msgstr destekler.
     * POT dosyasında msgstr "" olur — bu durumda msgid'i source kabul et.
     */
    private function parsePOForExport(string $poFile): array {
        if (!file_exists($poFile)) return [];
        $content  = file_get_contents($poFile);
        $entries  = [];

        // Satır satır ayrıştır
        $lines    = explode("\n", str_replace("\r\n", "\n", $content));
        $msgid    = null;
        $msgstr   = null;
        $inMsgid  = false;
        $inMsgstr = false;

        foreach ($lines as $line) {
            $line = rtrim($line);
            if (str_starts_with($line, 'msgid "')) {
                // Önceki entry'yi kaydet
                if ($msgid !== null && $msgid !== '') {
                    $entries[$msgid] = $msgstr ?? '';
                }
                $msgid   = substr($line, 7, -1);
                $msgstr  = null;
                $inMsgid = true; $inMsgstr = false;
            } elseif (str_starts_with($line, 'msgstr "')) {
                $msgstr   = substr($line, 8, -1);
                $inMsgid  = false; $inMsgstr = true;
            } elseif (str_starts_with($line, '"') && str_ends_with($line, '"')) {
                $part = substr($line, 1, -1);
                if ($inMsgid)  $msgid  .= $part;
                if ($inMsgstr) $msgstr .= $part;
            } elseif (empty($line)) {
                if ($msgid !== null && $msgid !== '') {
                    $entries[$msgid] = $msgstr ?? '';
                }
                $msgid = null; $msgstr = null;
                $inMsgid = false; $inMsgstr = false;
            }
        }
        // Son entry
        if ($msgid !== null && $msgid !== '') {
            $entries[$msgid] = $msgstr ?? '';
        }

        // Unescape PO string'leri
        $result = [];
        foreach ($entries as $k => $v) {
            $result[stripcslashes($k)] = stripcslashes($v);
        }
        return $result;
    }

    /**
     * PO dosyasında tek bir entry'yi güncelle + MO'yu derle
     */
    private function writePOEntry(string $poFile, string $msgid, string $msgstr, string $lang): bool {
        if (!file_exists($poFile)) return false;
        $content = file_get_contents($poFile);
        $escaped_id  = addcslashes($msgid, '"\\');
        $escaped_str = addcslashes($msgstr, '"\\');
        $pattern = '/(msgid\s+"' . preg_quote($escaped_id, '/') . '"\nmsgstr\s+)"[^"]*"/';
        if (preg_match($pattern, $content)) {
            $content = preg_replace($pattern, '$1"' . $escaped_str . '"', $content);
        } else {
            $content .= "\nmsgid \"$escaped_id\"\nmsgstr \"$escaped_str\"\n";
        }
        file_put_contents($poFile, $content);
        // MO derle
        $moFile = str_replace('.po', '.mo', $poFile);
        if (class_exists('\SAT\Admin\AjaxHandler')) {
            // compileMO AjaxHandler'da private — reflection ile çağır
            try {
                $ref    = new \ReflectionClass(\SAT\Admin\AjaxHandler::class);
                $method = $ref->getMethod('compileMO');
                $method->setAccessible(true);
                $handler = $ref->newInstanceWithoutConstructor();
                $method->invoke($handler, $poFile, $moFile);
            } catch (\Throwable $e) {
                // MO derleme başarısız — sadece PO güncellendi
            }
        }
        return true;
    }

    /**
     * Normalize for comparison only — never used for saving
     * Strips ALL whitespace for reliable equality check on HTML content
     */
    private function normalizeLineEndings(string $str): string {
        // \r\n ve \r → \n normalize et, sonra tüm whitespace'i sil
        $str = str_replace(["\r\n", "\r"], "\n", $str);
        return preg_replace('/\s+/', '', $str);
    }
}