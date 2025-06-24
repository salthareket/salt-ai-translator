<?php

$options = $this->options;
$translator = $options['translator'] ?? '';
$api_keys_map = $options['api_keys'] ?? [];
$api_keys = $api_keys_map[$translator] ?? [];
$prompt = $options['prompt'] ?? '';

$model = $options['model'] ?? '';
$default_model = '';
$temperature = $options['temperature'] ?? '';
$default_temperature = '';

$formality = $options['formality'] ?? '';
$default_formality = '';
$retranslate = $options['retranslate_existing'] ?? 0;
$retranslate_menu = $options["menu"]['retranslate'] ?? 0;
$exclude_post_types = $options['exclude_post_types'] ?? [];
$exclude_taxonomies = $options['exclude_taxonomies'] ?? [];
$exclude_posts = $options['exclude_posts'] ?? [];
$exclude_terms = $options['exclude_terms'] ?? [];

$all_post_types = get_post_types(['public' => true], 'objects');
$all_taxonomies = get_taxonomies(['public' => true], 'objects');
if ($this->ml_plugin["key"] === 'polylang') {
	$all_post_types = array_filter($all_post_types, function ($post_type) {
		return isset($post_type->name) && pll_is_translated_post_type($post_type->name);
	});
	$all_taxonomies = array_filter($all_taxonomies, function ($taxonomy) {
		return isset($taxonomy->name) && pll_is_translated_taxonomy($taxonomy->name);
	});
	$all_post_types = array_values($all_post_types);
	$all_taxonomies = array_values($all_taxonomies);
}

$translators = [];
foreach (glob(SALT_AI_TRANSLATOR_DIR . 'inc/translator/*.php') as $file) {
	$basename = basename($file, '.php');
	$contents = file_get_contents($file);
	preg_match('/Translator name\s*:\s*(.+)/i', $contents, $match);
	$translators[$basename] = $match[1] ?? $basename;
}

// Eğer OpenAI ise model listesini al
$models = [];
if ($translator === 'openai' && file_exists(SALT_AI_TRANSLATOR_DIR . 'inc/translator/openai.php')) {
	require_once SALT_AI_TRANSLATOR_DIR . 'inc/translator/openai.php';
	if (class_exists('SaltAI\Translator\Translator')) {
		$translator_class = $this->container->get("translator");
		if (property_exists($translator_class, 'models')) {
			$models = $translator_class->models;
			if(empty($model)){
				$model = $translator_class->model;
				$default_model = $model;
			}
		}
	}
}

$temperatures = [];
if ($translator === 'openai' && file_exists(SALT_AI_TRANSLATOR_DIR . 'inc/translator/openai.php')) {
	require_once SALT_AI_TRANSLATOR_DIR . 'inc/translator/openai.php';
	if (class_exists('SaltAI\Translator\Translator')) {
		$translator_class = $this->container->get("translator");
		if (property_exists($translator_class, 'temperatures')) {
			$temperatures = $translator_class->temperatures;
			if(empty($temperature)){
				$temperature = $translator_class->temperature;
				$default_temperature = $temperature;
			}
		}
	}
}

$formalities = [];
if ($translator === 'deepl' && file_exists(SALT_AI_TRANSLATOR_DIR . 'inc/translator/deepl.php')) {
	require_once SALT_AI_TRANSLATOR_DIR . 'inc/translator/deepl.php';
	if (class_exists('SaltAI\Translator\Translator')) {
		$translator_class = $this->container->get("translator");
		if (property_exists($translator_class, 'formalities')) {
			$formalities = $translator_class->formalities;
			if(empty($formalities)){
				$formality = $translator_class->formality;
				$default_formality = $formality;
			}
		}
	}
}

$model_meta_desc = $options["seo"]["meta_desc"]["model"];
if(empty($model_meta_desc)){
	$model_meta_desc = $translator_class->model_meta_desc;
}
$temperature_meta_desc = $options["seo"]["meta_desc"]["temperature"];
if(empty($temperature_meta_desc)){
	$temperature_meta_desc = $translator_class->temperature_meta_desc;
}
            
$model_image_alttext = $options["seo"]["image_alttext"]["model"];
if(empty($model_image_alttext)){
	$model_image_alttext = $translator_class->model_image_alttext;
}
$temperature_image_alttext = $options["seo"]["image_alttext"]["temperature"];
if(empty($temperature_image_alttext)){
	$temperature_image_alttext = $translator_class->temperature_image_alttext;
}


$selected_posts = $options['exclude_posts'] ?? [];
$post_options = [];
if (!empty($selected_posts)) {
	$posts = get_posts([
		'post__in' => $selected_posts,
		'post_type' => 'any',
		'post_status' => 'publish',
		'orderby' => 'post__in'
	]);
	foreach ($posts as $post) {
		$post_options[] = "<option value='{$post->ID}' selected>" . esc_html($post->post_title) . "</option>";
	}
}

$selected_terms = $options['exclude_terms'] ?? [];
$term_options = [];
if (!empty($selected_terms)) {
	$terms = get_terms([
		'include' => $selected_terms,
		'hide_empty' => false
	]);
	foreach ($terms as $term) {
		$term_options[] = "<option value='{$term->term_id}' selected>" . esc_html($term->name) . "</option>";
	}
}

?>
<form method="post" action="options.php">
	<?php settings_fields(SALT_AI_TRANSLATOR_PREFIX . '_options'); ?>
	<div class="salt-container">

		<header class="salt-header">
		  <div class="salt-logo">A</div>
		  <div class="salt-title">
			<h1><?php _e('SALT AI TRANSLATOR', 'salt-ai-translator'); ?></h1>
			<p><?php _e('Automatic Multilingual Translation System — AI-Powered', 'salt-ai-translator'); ?></p>
		  </div>
		</header>

		<nav class="salt-tabs">
		    <button class="salt-tab is-active" data-tab="translator"><?php _e('Translator', 'salt-ai-translator'); ?></button>
			<button class="salt-tab" data-tab="settings"><?php _e('Settings', 'salt-ai-translator'); ?></button>
			<button class="salt-tab" data-tab="media"><?php _e('Media', 'salt-ai-translator'); ?></button>
			<button class="salt-tab" data-tab="quotas"><?php _e('Quotas', 'salt-ai-translator'); ?></button>
			<button class="salt-tab salt-pro" data-tab="pro"><?php _e('Get Pro 🚀', 'salt-ai-translator'); ?></button>
		</nav>


		<section class="salt-tab-content" id="translator">

		  	<div class="salt-form-group">
				<label class="salt-label d-block"><strong><?php _e('Multilanguage Plugin', 'salt-ai-translator'); ?></strong></label>

				<?php if ($this->ml_plugin): ?>
					<div class="salt-alert salt-alert-success mb-0">
						Aktif: <strong><?= esc_html($this->ml_plugin["name"]) ?></strong>
					</div>
				<?php else: ?>
					<div class="salt-alert salt-alert-danger mb-0">
						<strong class="d-block"><?php _e('No multilanguage plugin is installed.', 'salt-ai-translator'); ?></strong>
						Lütfen 
						<?php
							$plugin_links = [];
							foreach ($this->supported_ml_plugins as $plugin) {
								if (!empty($plugin['is_pro'])) continue;
								$plugin_links[] = '<a href="' . esc_url($plugin['url']) . '" target="_blank">' . esc_html($plugin['name']) . '</a>';
							}
							echo implode(' veya ', $plugin_links);
						?>
						eklentisini yükleyin.
					</div>
				<?php endif; ?>
			</div>

			<div class="salt-form-group">
				<label class="salt-label"><strong><?php _e('Translator', 'salt-ai-translator'); ?></strong></label>
				<select name="<?= SALT_AI_TRANSLATOR_PREFIX ?>_settings[translator]" id="salt_translator_select" class="salt-select" <?= $this->ml_plugin ? '' : 'disabled' ?>>
					<option value=""><?php _e('Please select', 'salt-ai-translator'); ?></option>
					<?php foreach ($translators as $key => $label): ?>
						<option value="<?= esc_attr($key) ?>" <?= selected($translator, $key, false) ?>><?= esc_html($label) ?></option>
					<?php endforeach; ?>
				</select>
			</div>

			<div class="salt-form-group api_keys_row" style="display:block;">
			    <label class="salt-label"><strong><?php _e('API Keys', 'salt-ai-translator'); ?></strong></label>

			    <?php foreach ($translators as $key => $label): ?>
				    <textarea name="<?= SALT_AI_TRANSLATOR_PREFIX ?>_settings[api_keys][<?= esc_attr($key) ?>]"
				              rows="6"
				              class="salt-textarea salt-api-key-textarea"
				              data-translator="<?= esc_attr($key) ?>"
				              style="display: <?= $key === $translator ? 'block' : 'none' ?>;">
				        <?= esc_textarea(implode("\n", $api_keys_map[$key] ?? [])) ?>
				    </textarea>
				<?php endforeach; ?>

			    <small class="text-muted"><?php _e('One key per line', 'salt-ai-translator'); ?></small>
			</div>


			<?php if ($translator === 'openai' && $models): ?>
				<div class="openai_settings">
					<h3 class="salt-section-header">
		            	<img src="<?= SALT_AI_TRANSLATOR_URL ?>assets/icon-openai.png" class="" alt="OpenAI"/> <strong><?php _e('OpenAI Settings', 'salt-ai-translator'); ?></strong>
		            </h3>
					<div class="salt-form-group">
						<label class="salt-label"><strong><?php _e('Prompt', 'salt-ai-translator'); ?></strong> <?php _e('(Optional)', 'salt-ai-translator'); ?></label>
						<textarea name="<?= SALT_AI_TRANSLATOR_PREFIX ?>_settings[prompt]" rows="4" class="salt-textarea"><?= esc_textarea($prompt) ?></textarea>
					</div>
					<div class="salt-form-group">
						<label class="salt-label"><strong><?php _e('Model', 'salt-ai-translator'); ?></strong></label>
						<select name="<?= SALT_AI_TRANSLATOR_PREFIX ?>_settings[model]" class="salt-select">
							<?php foreach ($models as $key => $modelData): ?>
								<option value="<?= esc_attr($key) ?>" <?= selected($model, $key, false) ?>>
									<?= esc_html("{$modelData['name']} ({$modelData['input_price']}/input | {$modelData['output_price']}/output)") ?>
									<?php
									if(!empty($default_model) && $key == $default_model){
										echo "(".__("Suggested").")";
									}
									?>
								</option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="salt-form-group">
						<label class="salt-label"><strong><?php _e('Temperature', 'salt-ai-translator'); ?></strong></label>
						<select name="<?= SALT_AI_TRANSLATOR_PREFIX ?>_settings[temperature]" class="salt-select">
							<?php foreach ($temperatures as $key => $temperatureData): ?>
								<option value="<?= esc_attr($key) ?>" <?= selected($temperature, $key, false) ?>>
									<?= esc_html("{$key}") ?> - <?= esc_html("{$temperatureData}") ?>
									<?php
									if(!empty($default_temperature) && $key == $default_temperature){
										echo "(".__("Suggested").")";
									}
									?>
								</option>
							<?php endforeach; ?>
						</select>
					</div>
				</div>
			<?php endif; ?>

			<?php if ($translator === 'deepl'): ?>
				<div class="deepl_settings">
					<h3 class="salt-section-header">
		            	<img src="<?= SALT_AI_TRANSLATOR_URL ?>assets/icon-deepl.png" class="" alt="Deepl"/> <strong><?php _e('Deepl Settings', 'salt-ai-translator'); ?></strong>
		            </h3>
					<div class="salt-form-group">
						<label class="salt-label"><strong><?php _e('Formality', 'salt-ai-translator'); ?></strong></label>
						<select name="<?= SALT_AI_TRANSLATOR_PREFIX ?>_settings[formality]" class="salt-select">
							<?php foreach ($formalities as $key => $formalityData): ?>
								<option value="<?= esc_attr($key) ?>" <?= selected($formality, $key, false) ?>>
									<?= esc_html("{$formalityData}") ?>
									<?php
									if(!empty($default_formality) && $key == $default_formality){
										echo "(".__("Suggested").")";
									}
									?>
								</option>
							<?php endforeach; ?>
						</select>
						<small class="text-muted" style="display:block;"><?php _e('Only supported in DE, FR, ES, IT, NL, PL, PT, RU', 'salt-ai-translator'); ?></small>
					</div>
				</div>
			<?php endif; ?>

			<button type="submit" class="salt-button"><?php _e('Save', 'salt-ai-translator'); ?></button>

		</section>







		<section class="salt-tab-content is-hidden" id="settings">

			<h3 class="salt-section-header">
		       <strong><?php _e('Content Settings', 'salt-ai-translator'); ?></strong>
		    </h3>

		    <div class="salt-form-group">
		        <label class="salt-switch">
		            <input type="checkbox"
		                   id="retranslate_existing"
		                   name="<?= SALT_AI_TRANSLATOR_PREFIX ?>_settings[retranslate_existing]"
		                   value="1"
		                   <?= checked($options['retranslate_existing'] ?? '', 1, false); ?>>
		            <span class="salt-switch-slider"></span>
		        </label>
		        <label for="retranslate_existing"><strong><?php _e('Retranslate existing content by overwriting it.', 'salt-ai-translator'); ?></strong></label>
		    </div>

			<div class="salt-form-group">
		        <label class="salt-switch">
		            <input type="checkbox"
		                   id="auto_translate"
		                   name="<?= SALT_AI_TRANSLATOR_PREFIX ?>_settings[auto_translate]"
		                   value="1"
		                   <?= checked($options['auto_translate'] ?? '', 1, false); ?>>
		            <span class="salt-switch-slider"></span>
		        </label>
		        <label for="auto_translate"><strong><?php _e('Automatically translate content on save.', 'salt-ai-translator'); ?></strong></label>
		    </div>


		    <div class="salt-form-group">
		        <label class="salt-label"><strong><?php _e('Exclude Post Types', 'salt-ai-translator'); ?></strong></label>
		        <select name="<?= SALT_AI_TRANSLATOR_PREFIX ?>_settings[exclude_post_types][]" class="salt-select select2" multiple>
		            <?php foreach ($all_post_types as $type): ?>
		                <option value="<?= esc_attr($type->name) ?>" <?= in_array($type->name, $exclude_post_types) ? 'selected' : '' ?>><?= esc_html($type->label) ?></option>
		            <?php endforeach; ?>
		        </select>
		    </div>

		    <div class="salt-form-group">
		        <label class="salt-label"><strong><?php _e('Exclude Taxonomies', 'salt-ai-translator'); ?></strong></label>
		        <select name="<?= SALT_AI_TRANSLATOR_PREFIX ?>_settings[exclude_taxonomies][]" class="salt-select select2" multiple>
		            <?php foreach ($all_taxonomies as $tax): ?>
		                <option value="<?= esc_attr($tax->name) ?>" <?= in_array($tax->name, $exclude_taxonomies) ? 'selected' : '' ?>><?= esc_html($tax->label) ?></option>
		            <?php endforeach; ?>
		        </select>
		    </div>

		    <div class="salt-form-group">
		        <label class="salt-label"><strong><?php _e('Exclude Posts', 'salt-ai-translator'); ?></strong></label>
		        <select name="<?= SALT_AI_TRANSLATOR_PREFIX ?>_settings[exclude_posts][]" class="salt-select select2-ajax-posts" multiple>
		            <?= implode('', $post_options) ?>
		        </select>
		    </div>

		    <div class="salt-form-group">
		        <label class="salt-label"><strong><?php _e('Exclude Terms', 'salt-ai-translator'); ?></strong></label>
		        <select name="<?= SALT_AI_TRANSLATOR_PREFIX ?>_settings[exclude_terms][]" class="salt-select select2-ajax-terms" multiple>
		            <?= implode('', $term_options) ?>
		        </select>
		    </div>







            <h2 class="salt-section-header">
            	<strong><?php _e('SEO Settings', 'salt-ai-translator'); ?></strong>
            </h2>
            <h3 class="salt-section-title">
            	<strong><?php _e('Meta Description Generation', 'salt-ai-translator'); ?></strong>
            </h3>
		    <div class="salt-form-group">
		        <label class="salt-switch">
		            <input type="checkbox"
		                   id="seo_generate_metadesc"
		                   name="<?= SALT_AI_TRANSLATOR_PREFIX ?>_settings[seo][meta_desc][generate]"
		                   value="1"
		                   <?= checked($options["seo"]["meta_desc"]["generate"] ?? '', 1, false); ?>>
		            <span class="salt-switch-slider"></span>
		        </label>
		        <label for="seo_generate_metadesc"><strong><?php _e('Generate meta description in the default language if it does not exist.', 'salt-ai-translator'); ?></strong></label>
		    </div>

		    <div style="padding-left:60px;" data-salt-toggle="seo_generate_metadesc">

	            <?php if ($translator === 'deepl' ): ?>
	            	<div class="salt-form-group">
		            	<div class="salt-alert salt-alert-warning">
		            		<?php _e('DeepL cannot generate meta descriptions from content. It can only translate existing meta descriptions into other languages. To generate descriptions automatically, please use OpenAI.', 'salt-ai-translator'); ?>
		            	</div>
		            </div>
	            <?php endif; ?>

	            <div class="salt-form-group">
					<label class="salt-label" for="seo_generate_metadesc_on_content_changed">
						<input type="checkbox"
				            id="seo_generate_metadesc_on_content_changed"
				            name="<?= SALT_AI_TRANSLATOR_PREFIX ?>_settings[seo][meta_desc][on_content_changed]"
				            value="1"
				            <?= checked($options["seo"]["meta_desc"]["on_content_changed"] ?? '', 1, false); ?>>
				        <strong><?php _e('Only regenerate if the content has changed.', 'salt-ai-translator'); ?></strong>
				    </label>
			    </div>

			    <div class="salt-form-group">
					<label class="salt-label" for="seo_generate_metadesc_on_save">
						<input type="checkbox"
				            id="seo_generate_metadesc_on_save"
				            name="<?= SALT_AI_TRANSLATOR_PREFIX ?>_settings[seo][meta_desc][on_save]"
				            value="1"
				            <?= checked($options["seo"]["meta_desc"]["on_save"] ?? '', 1, false); ?>>
				        <strong><?php _e('Generate on save post', 'salt-ai-translator'); ?></strong>
				    </label>
			    </div>

			    <div class="salt-form-group">
					<label class="salt-label" for="seo_translate_metadesc_translate">
						<input type="checkbox"
				            id="seo_translate_metadesc_translate"
				            name="<?= SALT_AI_TRANSLATOR_PREFIX ?>_settings[seo][meta_desc][translate]"
				            value="1"
				            <?= checked($options["seo"]["meta_desc"]["translate"] ?? '', 1, false); ?>>
				        <strong><?php _e('Translate to other languages', 'salt-ai-translator'); ?></strong>
				    </label>
			    </div>

			    <div class="salt-form-group">
					<label class="salt-label" for="seo_translate_metadesc_preserve">
						<input type="checkbox"
				            id="seo_translate_metadesc_preserve"
				            name="<?= SALT_AI_TRANSLATOR_PREFIX ?>_settings[seo][meta_desc][preserve]"
				            value="1"
				            <?= checked($options["seo"]["meta_desc"]["preserve"] ?? '', 1, false); ?>>
				        <strong><?php _e('Preserve existing meta descriptions. Do not regenerate.', 'salt-ai-translator'); ?></strong>
				    </label>
			    </div>


                <?php if ($translator === 'openai' ): ?>
				    <div class="salt-form-group">
						<label class="salt-label"><strong><?php _e('Prompt', 'salt-ai-translator'); ?></strong>  <?php _e('(Optional)', 'salt-ai-translator'); ?></label>
						<textarea name="<?= SALT_AI_TRANSLATOR_PREFIX ?>_settings[seo][meta_desc][prompt]" rows="4" class="salt-textarea"><?= esc_textarea($options["seo"]["meta_desc"]["prompt"]) ?></textarea>
					</div>
					<div class="salt-form-group">
						<label class="salt-label"><strong><?php _e('Model', 'salt-ai-translator'); ?></strong></label>
						<select name="<?= SALT_AI_TRANSLATOR_PREFIX ?>_settings[seo][meta_desc][model]" class="salt-select">
							<?php foreach ($models as $key => $modelData): ?>
								<option value="<?= esc_attr($key) ?>" <?= selected($model_meta_desc, $key, false) ?>>
									<?= esc_html("{$modelData['name']} ({$modelData['input_price']}/input | {$modelData['output_price']}/output)") ?>
									<?php
									if(!empty($translator_class->model_meta_desc) && $key == $translator_class->model_meta_desc){
										echo "(".__("Suggested").")";
									}
									?>
								</option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="salt-form-group">
						<label class="salt-label"><strong><?php _e('Temperature', 'salt-ai-translator'); ?></strong></label>
						<select name="<?= SALT_AI_TRANSLATOR_PREFIX ?>_settings[seo][meta_desc][temperature]" class="salt-select">
							<?php foreach ($temperatures as $key => $temperatureData): ?>
								<option value="<?= esc_attr($key) ?>" <?= selected($temperature_meta_desc, $key, false) ?>>
									<?= esc_html("{$key}") ?> - <?= esc_html("{$temperatureData}") ?>
									<?php
									if(!empty($translator_class->temperature_meta_desc) && $key == $translator_class->temperature_meta_desc){
										echo "(".__("Suggested").")";
									}
									?>
								</option>
							<?php endforeach; ?>
						</select>
					</div>
				<?php endif; ?>

			 </div>








		    <h3 class="salt-section-title">
            	<strong><?php _e('Image Alt Text Generation', 'salt-ai-translator'); ?></strong>
            </h3>

            <?php
			    $image_alttext_generate_disabled = false;
			    $image_alttext_generate = $options["seo"]["image_alttext"]["generate"];
			    if($this->islocalhost()){
			    	$image_alttext_generate_disabled = true;
			    	$image_alttext_generate = false;
			    }
			?>

            <?php if ($this->islocalhost()): ?>
	           	<div class="salt-form-group">
		            <div class="salt-alert salt-alert-danger">
		            	<?php _e('ALT text generation is disabled on localhost.', 'salt-ai-translator'); ?>
		            </div>
		        </div>
	        <?php endif; ?>

		    <div class="salt-form-group">
		        <label class="salt-switch">
		            <input type="checkbox"
		                   id="seo_generate_image_alttext"
		                   name="<?= SALT_AI_TRANSLATOR_PREFIX ?>_settings[seo][image_alttext][generate]"
		                   value="1"
		                   <?= checked($image_alttext_generate ?? '', 1, false); ?> <?= $image_alttext_generate_disabled?"disabled":""?>>
		            <span class="salt-switch-slider"></span>
		        </label>
		        <label for="seo_generate_image_alttext"><strong><?php _e('Generate image alt text in the default language.', 'salt-ai-translator'); ?></strong></label>
		    </div>

		    <div style="padding-left:60px;" data-salt-toggle="seo_generate_image_alttext">

	            <?php if ($translator === 'deepl' ): ?>
		            <div class="salt-form-group">
			            <div class="salt-alert salt-alert-warning">
			            	<?php _e('DeepL cannot generate ALT texts from images. It can only translate existing ALT text into other languages. To generate ALT texts automatically, please use OpenAI.', 'salt-ai-translator'); ?>
			            </div>
			        </div>
		        <?php endif; ?>
                
                <?php if ($translator === 'openai' ): ?>
	            <div class="salt-form-group" >
				    <label class="salt-label"><strong>Image Size</strong></label>
				    <select name="<?= SALT_AI_TRANSLATOR_PREFIX ?>_settings[seo][image_alttext][image_size]" class="salt-select">
				        <?php 
				        $sizes = get_intermediate_image_sizes();
				        $selected = $this->options["seo"]["image_alttext"]['image_size'] ?? 'thumbnail';
				        foreach ($sizes as $size) : ?>
				            <option value="<?= esc_attr($size) ?>" <?= selected($selected, $size) ?>>
				                <?= esc_html($size) ?>
				            </option>
				        <?php endforeach; ?>
				    </select>
				    <small class="text-muted" style="display:block;">Select the smallest recognizable image size. Smaller sizes reduce API cost and improve performance.</small>
				</div>
			    <?php endif; ?>

			    <?php
			    $image_alttext_translate_disabled = false;
			    $image_alttext_translate = $options["seo"]["image_alttext"]["translate"];
			    /*if(!$this->container->get("integration")->is_media_translation_enabled()){
			    	$image_alttext_translate_disabled = true;
			    	$image_alttext_translate = false;
			    }*/
			    ?>
			    <div class="salt-form-group">
					<label class="salt-label" for="seo_translate_image_alttext_translate">
						<input type="checkbox"
				            id="seo_translate_image_alttext_translate"
				            name="<?= SALT_AI_TRANSLATOR_PREFIX ?>_settings[seo][image_alttext][translate]"
				            value="1"
				            <?= checked($image_alttext_translate ?? '', 1, false); ?> <?= $image_alttext_translate_disabled?"disabled":""?>>
				        <strong><?php _e('Translate to other languages', 'salt-ai-translator'); ?></strong>
				    </label>
				    
				    <?php
				    if(!$this->container->get("integration")->is_media_translation_enabled()){ ?>
				        <div class="text-primary">
				      	    <?php printf(__('Multilingual media management is not enabled on %s, but ALT text will be generated for each language.', 'salt-ai-translator'), "<strong>".$this->ml_plugin["name"]."</strong>"); ?>
				        </div>
				    <?php 
				    }
				    ?>
				    <?php
				    /*if($image_alttext_translate_disabled){ ?>
				        <div class="text-danger">
				      	    <?php printf(__('Media translation is not possible with %s. Please activate it if possible.', 'salt-ai-translator'), "<strong>".$this->ml_plugin["name"]."</strong>"); ?>
				        </div>
				    <?php 
				    }*/
				    ?>
			    </div>

			    <div class="salt-form-group">
					<label class="salt-label" for="seo_generate_image_alttext_on_save">
						<input type="checkbox"
				            id="seo_generate_image_alttext_on_save"
				            name="<?= SALT_AI_TRANSLATOR_PREFIX ?>_settings[seo][image_alttext][on_save]"
				            value="1"
				            <?= checked($options["seo"]["image_alttext"]["on_save"] ?? '', 1, false); ?>>
				        <strong>
				        	<?php if ($translator === 'deepl'): ?>
							    <?php _e('Translate on save content.', 'salt-ai-translator'); ?>
							<?php else: ?>
							    <?php _e('Generate on save content.', 'salt-ai-translator'); ?>
							<?php endif; ?>
						</strong>
				    </label>
			    </div>

			    <div class="salt-form-group">
					<label class="salt-label" for="seo_translate_image_alttext_preserve">
						<input type="checkbox"
				            id="seo_translate_image_alttext_preserve"
				            name="<?= SALT_AI_TRANSLATOR_PREFIX ?>_settings[seo][image_alttext][preserve]"
				            value="1"
				            <?= checked($options["seo"]["image_alttext"]["preserve"] ?? '', 1, false); ?>>
				            <strong>
				        	<?php if ($translator === 'deepl'): ?>
							    <?php _e('Preserve existing ALT texts. Do not translate.', 'salt-ai-translator'); ?>
							<?php else: ?>
							    <?php _e('Preserve existing ALT texts. Do not regenerate.', 'salt-ai-translator'); ?>
							<?php endif; ?>
						    </strong>
				    </label>
			    </div>

			    <?php if ($translator === 'openai' ): ?>
				    <div class="salt-form-group">
						<label class="salt-label"><strong><?php _e('Prompt', 'salt-ai-translator'); ?></strong> <?php _e('(Optional)', 'salt-ai-translator'); ?></label>
						<textarea name="<?= SALT_AI_TRANSLATOR_PREFIX ?>_settings[seo][image_alttext][prompt]" rows="4" class="salt-textarea"><?= esc_textarea($options["seo"]["image_alttext"]["prompt"]) ?></textarea>
					</div>
					<div class="salt-form-group">
						<label class="salt-label"><strong><?php _e('Model', 'salt-ai-translator'); ?></strong></label>
						<select name="<?= SALT_AI_TRANSLATOR_PREFIX ?>_settings[seo][image_alttext][model]" class="salt-select">
							<?php foreach ($models as $key => $modelData): ?>
								<option value="<?= esc_attr($key) ?>" <?= selected($model_image_alttext, $key, false) ?>>
									<?= esc_html("{$modelData['name']} ({$modelData['input_price']}/input | {$modelData['output_price']}/output)") ?>
									<?php
									if(!empty($translator_class->model_image_alttext) && $key == $translator_class->model_image_alttext){
										echo "(".__("Suggested").")";
									}
									?>
								</option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="salt-form-group">
						<label class="salt-label"><strong><?php _e('Temperature', 'salt-ai-translator'); ?></strong></label>
						<select name="<?= SALT_AI_TRANSLATOR_PREFIX ?>_settings[seo][image_alttext][temperature]" class="salt-select">
							<?php foreach ($temperatures as $key => $temperatureData): ?>
								<option value="<?= esc_attr($key) ?>" <?= selected($temperature_image_alttext, $key, false) ?>>
									<?= esc_html("{$key}") ?> - <?= esc_html("{$temperatureData}") ?>
									<?php
									if(!empty($translator_class->temperature_image_alttext) && $key == $translator_class->temperature_image_alttext){
										echo "(".__("Suggested").")";
									}
									?>
								</option>
							<?php endforeach; ?>
						</select>
					</div>
				<?php endif; ?>

			</div>




			<h2 class="salt-section-header">
            	<strong><?php _e('Other Translations', 'salt-ai-translator'); ?></strong>
            </h2>
            <h3 class="salt-section-title">
            	<strong><?php _e('Menu Tranlation', 'salt-ai-translator'); ?></strong>
            </h3>
		    <div class="salt-form-group">
		        <label class="salt-switch">
		            <input type="checkbox"
		                   id="retranslate_menu"
		                   name="<?= SALT_AI_TRANSLATOR_PREFIX ?>_settings[menu][retranslate]"
		                   value="1"
		                   <?= checked($options["menu"]["retranslate"] ?? '', 1, false); ?>>
		            <span class="salt-switch-slider"></span>
		        </label>
		        <label for="retranslate_menu"><strong><?php _e('Recreate Menus if Already Exists.', 'salt-ai-translator'); ?></strong></label>
		    </div>

		    <?php 
               if($this->ml_plugin["key"] == "polylang"){
            ?>
		    <h3 class="salt-section-title">
            	<strong><?php _e('String Tranlation', 'salt-ai-translator'); ?></strong>
            </h3>
		    <div class="salt-form-group">
		        <label class="salt-switch">
		            <input type="checkbox"
		                   id="retranslate_strings"
		                   name="<?= SALT_AI_TRANSLATOR_PREFIX ?>_settings[strings][retranslate]"
		                   value="1"
		                   <?= checked($options["strings"]["retranslate"] ?? '', 1, false); ?>>
		            <span class="salt-switch-slider"></span>
		        </label>
		        <label for="retranslate_strings"><strong><?php _e('Retranslate strings if Already Exists.', 'salt-ai-translator'); ?></strong></label>
		    </div>
		    <?php 
			}
			?>

		    <button type="submit" class="salt-button"><?php _e('Save', 'salt-ai-translator'); ?></button>
		</section>






		<section class="salt-tab-content is-hidden" id="media">
		  <p>Media options will be here...</p>
		</section>






		<section class="salt-tab-content is-hidden" id="quotas">
		    <?php
				$translator_class = $this->container->get("translator");
	            if (method_exists($translator_class, 'quota_info')) {
	                $quotas = $translator_class->quota_info($api_keys);
	                ?>
	                <div class="salt-form-group">
	                	<h2><?php _e('API Key Usage Status', 'salt-ai-translator'); ?></h2>
	                    <table class="salt-table">
	                    	<thead>
	                    		<tr>
	                    			<th>#</th>
	                    			<th><?php _e('API Key', 'salt-ai-translator'); ?></th>
	                    			<th><?php _e('Status', 'salt-ai-translator'); ?></th>
	                    		</tr>
	                    	</thead>
	                        <tbody>
	                        <?php
		                    foreach ($api_keys as $i => $key) {
							    $display_key = substr($key, 0, 5) . '*****' . substr($key, -5);
							    $status = $quotas[$i] ?? 'Bilinmiyor';

							    echo '<tr>';
							    echo '<td><strong>'.$translator.' Api Key #' . ($i + 1) . '</strong></td>';
							    echo '<td><code>' . esc_html($display_key) . '</code></td>';
							    echo '<td>';

							    if (stripos($status, 'Geçerli') !== false) {
							        echo '<span class="text-success"><strong>✅ Geçerli</strong></span>';
							    } elseif (stripos($status, 'API hatası') !== false) {
							        echo '<span class="text-danger"><strong>❌ ' . esc_html($status) . '</strong></span>';
							    } else {
							        echo '<span class="text-success"><strong>⚠️ ' . esc_html($status) . '</strong></span>';
							    }

							    echo '</td></tr>';
							}
                            ?>
	                        </tbody>
	                    </table>
	                </div>
	        <?php
	             }
	        ?>
		</section>





		<section class="salt-tab-content is-hidden" id="pro">
		  <div class="salt-pro-box">
			<h3><?php _e("What's included in Salt AI Translator PRO", 'salt-ai-translator'); ?></h3>
			<ul>
				<li><?php _e('Background translation queue', 'salt-ai-translator'); ?></li>
				<li><?php _e('Faster API processing', 'salt-ai-translator'); ?></li>
				<li><?php _e('Reusable blocks + FSE support', 'salt-ai-translator'); ?></li>
				<li><?php _e('Media ALT text AI', 'salt-ai-translator'); ?></li>
				<li><?php _e('Custom prompt', 'salt-ai-translator'); ?></li>
			</ul>
			<button class="salt-button salt-pro-button"><?php _e('Switch to Pro', 'salt-ai-translator'); ?></button>
		  </div>
		</section>





	</div>
</form>