<?php
    $viewer_loading_class = "salt-spinner";
    $info_display = "none";
    $buttons_display = "block";
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
            <strong><?php _e('Other Translations', 'salt-ai-translator'); ?></strong>
        </h1>
        
        <div id="salt-language-selector" class="salt-form-group mb-4">
            <select id="salt-translate-lang" class="salt-select select2">
                <option value=""><?php _e('Select a language to translate', 'salt-ai-translator'); ?></option>
                <?php foreach ($this->languages as $code => $label): ?>
                <option value="<?= esc_attr($code) ?>"><?= esc_html($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div id="salt-translation-viewer" class="salt-translation-viewer" >

            <div id="salt-translation-info"  class="salt-translation-info">

                <p id="salt-translation-status" class="salt-translation-status mb-4"></p>

                <div id="salt-start-buttons" class="start-buttons" style="display:<?= $buttons_display?>;">
                    <button id="salt-start-menu-translation" class="salt-button bg-primary" style="min-width:250px;">
                        <strong><?php _e('Translate Menus', 'salt-ai-translator'); ?></strong>
                        <span><?php _e('Create translated versions of menus', 'salt-ai-translator'); ?></span>
                    </button>
                    <?php 
                    if($this->ml_plugin["key"] == "polylang"){
                    ?>
                    <button id="salt-start-string-translation" class="salt-button bg-primary" style="min-width:250px;">
                        <strong><?php _e('Translate Strings', 'salt-ai-translator'); ?></strong>
                        <span><?php _e('Translate Polylang registered strings', 'salt-ai-translator'); ?></span>
                    </button>
                    <?php 
                    }
                    ?>
                    <?php 
                    if($this->ml_plugin["key"] == "qtranslate-xt"){
                    ?>
                    <button id="salt-start-post_type-taxonomy-translation" class="salt-button bg-primary" style="min-width:250px;">
                        <strong><?php _e('Translate Post Types & Taxonomies', 'salt-ai-translator'); ?></strong>
                        <span><?php _e('Translate label & slugs', 'salt-ai-translator'); ?></span>
                    </button>
                    <?php 
                    }
                    ?>
                </div>

            </div>
            
        </div>

    </div>
</div>
