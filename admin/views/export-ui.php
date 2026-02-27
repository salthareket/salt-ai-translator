<?php
    $queue_status = $this->container->get("manager")->check_process_queue("export");
    $viewer_display = "none";
    $viewer_loading_class = "salt-spinner";
    $info_display = "none";
    $buttons_display = "block";
    $progress_display = "none";
    if($queue_status == "processing"){
        $viewer_display = "flex";
        $viewer_loading_class = "";
        $info_display = "flex";
        $buttons_display = "none";
        $progress_display = "flex";
    }
?>
<div class="salt-container">

    <header class="salt-header">
        <div class="salt-logo">A</div>
        <div class="salt-title">
            <h1><?php _e('SALT AI TRANSLATOR', 'salt-ai-translator'); ?></h1>
            <p><?php _e('Automatic Multilingual Translation System — AI-Powered', 'salt-ai-translator'); ?></p>
        </div>
    </header>

    <div class="wrap">

        <h1 class="salt-section-header mb-4" style="margin-bottom:30px;">
            <strong><?php _e('Export Translations', 'salt-ai-translator'); ?></strong>
        </h1>


        <?php
        if($queue_status != "processing"){
        ?>        
        <div id="export-options-container" class="salt-form-group-row">
            <div class="salt-form-group">
                <label class="salt-label"><strong><?php _e('Output Format', 'salt-ai-translator'); ?></strong></label>
                <select id="export-output-format" name="export_output_format" class="salt-select">
                    <?php /*<option value="word">Word</option>*/?>
                    <option value="excel">Excel</option>
                </select>
            </div>
            <div class="salt-form-group">
                <label class="salt-label"><strong><?php _e('Source Language', 'salt-ai-translator'); ?></strong></label>
                <select id="export-source-language" name="export_source_language" class="salt-select">
                    <option value=""><?php _e('Default Language', 'salt-ai-translator'); ?></option>
                    <?php foreach ($this->languages as $code => $label): ?>
                        <option value="<?= esc_attr($code) ?>"><?= esc_html($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="salt-form-group">
                <label class="salt-label"><strong><?php _e('Target Language', 'salt-ai-translator'); ?></strong></label>
                <select id="export-target-language" name="export_target_language" class="salt-select" required>
                    <option value=""><?php _e('Select a target language', 'salt-ai-translator'); ?></option>
                    <?php foreach ($this->languages as $code => $label): ?>
                        <option value="<?= esc_attr($code) ?>"><?= esc_html($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="salt-form-group" style="grid-column: 1 / -1; text-align: center;">
                <button id="salt-check-translations" class="salt-button btn-primary"> <?php _e('Check Translations', 'salt-ai-translator'); ?></button>
            </div>
        </div>
        <?php
        }
        ?>


        <?php 

        $transient_key = 'salt_translations_output';
        $translations  = get_transient($transient_key) ?? [];
        if($translations){
            $translate_languages = array_keys($translations[0]);
            $translate_languages_text = implode(" -> ", $translate_languages);
            ?>
            <button id="salt-start-cache-export" class="salt-button btn-primary" data-lang="<?php echo($translate_languages[1]);?>"> <?php _e('Generate again for '.$translate_languages_text, 'salt-ai-translator'); ?></button>
            <?php
        }
        ?>



        <div id="salt-translation-viewer" class="salt-translation-viewer mb-4 <?= $viewer_loading_class ?>" style="display:<?= $viewer_display?>;">

            <div id="salt-translation-info" style="display:<?= $info_display?>;" class="salt-translation-info mt-4">

                <?php 
                $queue_percent = 0;
                if($queue_status == "processing"){
                    $queue_status_data = $this->container->get("manager")->get_queue_status("export");
                    $queue_started_at  = date('d.m.Y H:i:s', $queue_status_data["started_at"]);
                    $queue_initial_total = max(1, $queue_status_data['initial_total']); // 0’a bölünmeyi engeller
                    $queue_completed   = $queue_status_data['completed'];
                    $queue_percent     = round(($queue_completed / $queue_initial_total) * 100);
                }
                ?>

                <p id="salt-translation-status" class="salt-translation-status mb-2">
                    <strong>
                        <?php
                        printf(
                            __('%1$d/%2$d translated', 'salt-ai-translator'),
                            $queue_completed,
                            $queue_initial_total
                        );
                        ?>
                    </strong>
                </p>

                <div class="salt-form-group mb-3" style="width:100%;max-width: 600px;" >
                    <div id="salt-translation-progress" class="salt-progress flex-grow-1" style="height: 28px;<?= $progress_display ?>;">
                        <div class="salt-progress-bar salt-progress-bar-animated" role="progressbar" style="width:<?= $queue_percent ?>%;"><?= $queue_percent ?>%</div>
                    </div>
                </div>

                <div id="salt-start-buttons" class="start-buttons" style="display:<?= $buttons_display?>;">
                    <button id="salt-start-export" class="salt-button bg-primary" style="min-width:250px;">
                        <strong><?php _e('Export Now', 'salt-ai-translator'); ?></strong>
                        <span><?php _e('Instant AJAX translation', 'salt-ai-translator'); ?></span>
                    </button>
                    <?php /*<button id="salt-start-cron-export" class="salt-button bg-primary" style="min-width:250px;">
                        <strong><?php _e('Add to Queue', 'salt-ai-translator'); ?></strong>
                        <span><?php _e('Background CRON translation', 'salt-ai-translator'); ?></span>
                    </button>*/?>
                </div>

                <table id="results-ui" class="results-ui table" style="display:none;">
                    <tbody>

                    </tbody>
                </table>

            </div>
            
        </div>



        <?php
        $upload_dir = wp_upload_dir();
        $export_dir = $upload_dir['basedir'];
        $export_url = $upload_dir['baseurl'];
        $existing_files = [];

        // Joker karakter (*) kullanarak dosya isimlerini ara
        $docx_files = glob($export_dir . '/salt-translations-*.docx');
        $xlsx_files = glob($export_dir . '/salt-translations-*.xlsx');

        // Tüm bulunan dosyaları tek bir dizide birleştir
        $found_files = array_merge($docx_files, $xlsx_files);

        foreach ($found_files as $file_path) {
            if (file_exists($file_path)) {
                $file_name = basename($file_path);
                $timestamp = filemtime($file_path) + (get_option('gmt_offset') * HOUR_IN_SECONDS);
                $existing_files[] = [
                    'name' => $file_name,
                    'url'  => $export_url . '/' . $file_name,
                    'path' => $file_path,
                    'date' => date("d.m.Y H:i:s", $timestamp),
                    'size' => size_format(filesize($file_path), 2)
                ];
            }
        }
        if (!empty($existing_files)) :
        ?>
        <div id="salt-translation-latest-exports">
            <h3 style="margin-top:40px;"><?php _e("Latest Exported Files", 'salt-ai-translator');?></h3>
            <table class="salt-table">
                <thead>
                    <tr>
                        <td><?php _e("Date", 'salt-ai-translator');?></td>
                        <td><?php _e("File Name", 'salt-ai-translator');?></td>
                        <td><?php _e("Size", 'salt-ai-translator');?></td>
                        <td style="text-align: center;"><?php _e("Download", 'salt-ai-translator');?></td>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($existing_files as $f) : ?>
                    <tr>
                        <td><?= esc_html($f['date']) ?></td>
                        <td><?= esc_html($f['name']) ?></td>
                        <td><?= esc_html($f['size']) ?></td>
                        <td style="text-align: center;">
                            <a href="<?= esc_url($f['url']) ?>" 
                               class="salt-button bg-primary" 
                               download>
                                <?php _e("Download", 'salt-ai-translator');?>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>




        <?php 
        if(!empty($queue_status)){
            $queue_total_title = __('Total', 'salt-ai-translator');
            $queue_language_title = __('Language', 'salt-ai-translator');
            $queue_started_at_title = __('Started at', 'salt-ai-translator');
            $queue_completed_at_title = __('Completed at', 'salt-ai-translator');
            $queue_processing_time_title = __('Processing Time', 'salt-ai-translator');
            $queue_status_title = __('Status', 'salt-ai-translator');

            if($queue_status == "idle"){
                $queue_initial_total = "-";
                $queue_language = "-";
                $queue_started_at = "-";
                $queue_completed_at = "-";
                $queue_duration_str = "-";
                $queue_status_str = "<strong class='text-primary'>" . __("Awaiting first translation task", "salt-ai-translator") . "</strong>";     
            }else{
                $queue_status_data = $this->container->get("manager")->get_queue_status("export");
                $queue_initial_total = sprintf(
                    _n('%d post', '%d posts', $queue_status_data["initial_total"], 'salt-ai-translator'),
                    $queue_status_data["initial_total"]
                );
                $queue_language = $this->container->get("integration")->get_language_label($queue_status_data["lang"]);
                $queue_started_at = get_date_from_gmt( date( 'Y-m-d H:i:s', $queue_status_data["started_at"] ), 'd.m.Y H:i:s' );
                $queue_completed_at = "-";
                $queue_duration_str = "-";
                $queue_status_str = "<strong class='text-success'>" . __("Processing", "salt-ai-translator") . "</strong>";                
            }

            if($queue_status == "done"){
                $queue_completed_at = get_date_from_gmt( date( 'Y-m-d H:i:s', $queue_status_data["completed_at"] ), 'd.m.Y H:i:s' );
                $queue_duration = $queue_status_data["completed_at"] - $queue_status_data["started_at"];
                $queue_hours   = floor($queue_duration / 3600);
                $queue_minutes = floor(($queue_duration % 3600) / 60);
                $queue_seconds = $queue_duration % 60;
                $queue_duration_parts = [];
                if ($queue_hours > 0) {
                    $queue_duration_parts[] = sprintf(
                        _n('%d hour', '%d hours', $queue_hours, 'salt-ai-translator'),
                        $queue_hours
                    );
                }
                if ($queue_minutes > 0) {
                    $queue_duration_parts[] = sprintf(
                        _n('%d minute', '%d minutes', $queue_minutes, 'salt-ai-translator'),
                        $queue_minutes
                    );
                }
                if ($queue_seconds > 0 || empty($queue_duration_parts)) {
                    $queue_duration_parts[] = sprintf(
                        _n('%d second', '%d seconds', $queue_seconds, 'salt-ai-translator'),
                        $queue_seconds
                    );
                }
                $queue_duration_str = implode(' ', $queue_duration_parts);     
                $queue_status_str = "<strong class='text-success'>" . __("Done", "salt-ai-translator") . "</strong>";           
            }

            ?>
            <h3 style="margin-top:40px;"><?php _e("Last Scheduled Task", 'salt-ai-translator');?></h3>
            <table class="salt-table">
                    <thead>
                        <tr>
                            <td>
                               <?= $queue_total_title ?>
                            </td>
                            <td>
                               <?= $queue_language_title ?>
                            </td>
                            <td>
                               <?= $queue_started_at_title ?>
                            </td>
                            <td>
                               <?= $queue_completed_at_title ?>
                            </td>
                            <td>
                               <?= $queue_processing_time_title ?>
                            </td>
                            <td>
                               <?= $queue_status_title ?>
                            </td>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td id="queue_initial_total">
                                <?= $queue_initial_total ?>
                            </td>
                            <td id="queue_language">
                                <?= $queue_language ?>
                            </td>
                            <td id="queue_started_at">
                                <?= $queue_started_at ?>
                            </td>
                            <td id="queue_completed_at">
                                <?= $queue_completed_at ?>
                            </td>
                            <td id="queue_processing_time">
                                <?= $queue_duration_str ?>
                            </td>
                            <td id="queue_status">
                                <?= $queue_status_str ?>
                            </td>
                        </tr>
                    </tbody>
            </table>
        <?php
        }
        ?>

    </div>
</div>
