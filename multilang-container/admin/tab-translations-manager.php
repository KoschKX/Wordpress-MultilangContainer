<?php

// AJAX handler to serve structure.json as JSON
add_action('wp_ajax_get_structure_file', function() {
	if (!current_user_can('manage_options')) {
		wp_send_json_error('Permission denied');
		wp_die();
	}
	if (!function_exists('get_structure_file_path')) {
		require_once plugin_dir_path(dirname(__FILE__)) . 'multilang-container.php';
	}
	$structure_path = get_structure_file_path();
	$data_dir = get_translations_data_dir();
	$languages = get_multilang_available_languages();
	$result = array();
	$result['data_directory'] = $data_dir;
	$result['languages'] = array();
	foreach ($languages as $lang) {
		$file = $data_dir . $lang . '.json';
		$file_info = array();
		$file_info['file'] = $lang . '.json';
		$file_info['status'] = file_exists($file) ? '✓ Exists' : 'Missing';
		$file_info['size'] = file_exists($file) ? (filesize($file) . ' B') : '-';
		$result['languages'][$lang] = $file_info;
	}
	// Structure file info
	$structure_info = array();
	$structure_info['file'] = 'structure.json';
	$structure_info['status'] = file_exists($structure_path) ? '✓ Exists' : 'Missing';
	$structure_info['size'] = file_exists($structure_path) ? (filesize($structure_path) . ' B') : '-';
	$result['structure'] = $structure_info;
	$result['available_languages'] = $languages;
	wp_send_json($result);
	wp_die();
});

/**
 * AJAX handler for saving translations as JSON files per language
 */
function save_translations_json() {
	// Only allow admins
	if (!current_user_can('manage_options')) {
		wp_send_json_error(['message' => 'Permission denied.']);
		wp_die();
	}
	$raw = file_get_contents('php://input');
	$data = json_decode($raw, true);
	if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
		wp_die();
	}
	$languages = get_multilang_available_languages();
	foreach ($languages as $lang) {
		$lang_data = array();
		foreach ($data as $category => $keys) {
			// Skip any section named 'nonce' (case-insensitive)
			if (strtolower($category) === 'nonce') continue;
			$lang_data[$category] = array();
			foreach ($keys as $key => $translations) {
				if (isset($translations[$lang])) {
					$lang_data[$category][$key] = $translations[$lang];
				}
			}
		}
		// Save translations in uploads/multilang/
		if (!function_exists('get_translations_data_dir')) {
			require_once plugin_dir_path(dirname(__FILE__)) . 'multilang-container.php';
		}
		$file_path = get_translations_data_dir() . $lang . '.json';
		// Save with keys in the order received
		$json = json_encode($lang_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
		file_put_contents($file_path, $json);
	}
	// Remove deleted sections from structure.json
	if (!function_exists('get_translations_data_dir')) {
		require_once plugin_dir_path(dirname(__FILE__)) . 'multilang-container.php';
	}
	$structure_path = get_structure_file_path();
	$structure = array();
	if (file_exists($structure_path)) {
		$structure_content = file_get_contents($structure_path);
		$structure = json_decode($structure_content, true) ?: array();
	}
	// Build new structure in the order of $data
	$new_structure = array();
	foreach ($data as $section => $keys) {
		// Skip any section named 'nonce' (case-insensitive)
		if (strtolower($section) === 'nonce') continue;
		if (isset($structure[$section])) {
			// Keep existing section but update meta fields from incoming data
			$new_structure[$section] = $structure[$section];
			
			// Update meta fields if provided in incoming data
			foreach ($keys as $k => $v) {
				if (strpos($k, '_') === 0) {
					$new_structure[$section][$k] = $v;
				}
			}
		} else {
			// Add new section with all available meta fields from $keys
			$meta = array();
			foreach ($keys as $k => $v) {
				if (strpos($k, '_') === 0) {
					$meta[$k] = $v;
				}
			}
			// Always set defaults if missing
			if (!isset($meta['_selectors'])) $meta['_selectors'] = array('body');
			if (!isset($meta['_pages'])) $meta['_pages'] = '*';
			if (!isset($meta['_collapsed'])) $meta['_collapsed'] = false;
			if (!isset($meta['_method'])) $meta['_method'] = 'server';
			$new_structure[$section] = $meta;
		}
		
		// Save pages setting
		if (isset($_POST['pages'][$section])) {
			$new_structure[$section]['_pages'] = sanitize_text_field($_POST['pages'][$section]);
		}
		if (!isset($new_structure[$section]['_pages'])) {
			$new_structure[$section]['_pages'] = '*';
		}
		
		// CRITICAL FIX: Convert _selectors to array if it's a string
		if (isset($new_structure[$section]['_selectors'])) {
			$selectors = $new_structure[$section]['_selectors'];
			if (is_string($selectors)) {
				// Split by comma and trim each selector
				$selectors_array = array_map('trim', explode(',', $selectors));
				// Remove empty values
				$selectors_array = array_filter($selectors_array);
				// Re-index array to ensure it's a proper array (not object in JSON)
				$new_structure[$section]['_selectors'] = array_values($selectors_array);
			}
		}
	}
	// Always write structure file (create if missing)
	file_put_contents($structure_path, json_encode($new_structure, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
	wp_send_json_success(['message' => 'Translations saved to per-language JSON files.']);
	wp_die();
}
add_action('wp_ajax_save_translations_json', 'save_translations_json');

/**
 * AJAX handler for saving section collapse state
 */
function save_section_collapse_state() {
	// Verify nonce
	if (!wp_verify_nonce($_POST['nonce'], 'save_collapse_state')) {
		wp_die('Security check failed');
	}
	
	$category = sanitize_text_field($_POST['category']);
	$collapsed = $_POST['collapsed'] === '1';
	
	// Load structure file directly
	$structure_path = get_structure_file_path();
	$structure = array();
	if (file_exists($structure_path)) {
		$structure_content = file_get_contents($structure_path);
		$structure = json_decode($structure_content, true) ?: array();
	}
	// Update just the collapsed state for this category
	if (!isset($structure[$category])) {
		$structure[$category] = array();
	}
	$structure[$category]['_collapsed'] = $collapsed;
	
	// Save structure file
	file_put_contents($structure_path, json_encode($structure, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
	
	wp_die(); // Always call wp_die() at the end of AJAX handlers
}
add_action('wp_ajax_save_section_collapse_state', 'save_section_collapse_state');

/**
 * AJAX handler for saving section order
 */
function save_section_order_ajax() {

    

	// Verify nonce
	if (!wp_verify_nonce($_POST['nonce'], 'save_section_order')) {
		wp_die('Security check failed');
	}
	
	$section_order = json_decode(stripslashes($_POST['section_order']), true);
	
	if (is_array($section_order)) {
		$result = save_section_order($section_order);
		wp_send_json_success(array('message' => 'Section order saved to JSON file'));
	} else {
		wp_send_json_error(array('message' => 'Invalid section order data'));
	}
}

/**
 * Placeholder for translation tab content (this would be the large function from main file)
 */
function multilang_translations_tab_content() {

	multilang_add_translations_manager_resources();

	// --- Handle form submissions (deletion and saving) ---
	if ($_SERVER['REQUEST_METHOD'] === 'POST') {
		// Get raw POST data (JSON)
		$raw = file_get_contents('php://input');
		$data = json_decode($raw, true);
		if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
			return;
		}

		// Build per-language arrays
		$languages = get_multilang_available_languages();
		foreach ($languages as $lang) {
			$lang_data = array();
			foreach ($data as $category => $keys) {
				foreach ($keys as $key => $translations) {
					if (isset($translations[$lang])) {
						if (!isset($lang_data[$category])) $lang_data[$category] = array();
						$lang_data[$category][$key] = $translations[$lang];
					}
				}
			}
			// Save each language file
			$file_path = dirname(__FILE__) . '/../../data/translations-' . $lang . '.json';
			file_put_contents($file_path, json_encode($lang_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
		}
		echo '<div class="notice notice-success"><p>Translations saved to per-language JSON files.</p></div>';
	}

	?>
	<div class="translations-manager wrap">
		<h2>Multilang Translation Manager</h2>
		<p>Manage translations for different content sections. Each section can have translations in multiple languages.</p>
		
		<form method="post" action="" id="main-translations-form">
			<?php wp_nonce_field('save_translations', 'translations_nonce'); ?>
			
			<?php
			// Get available sections and languages
			$available_sections = get_available_translation_sections();
			$available_languages = get_multilang_available_languages();
			$default_language = get_multilang_default_language();
			
			// Form processing handled at top of file
			
			?>
			
			<?php if (!empty($available_sections)): ?>
				<div id="sortable-sections" class="sortable-sections">
				<?php 
				foreach ($available_sections as $category => $section_data): 
					$structure_data = $section_data['structure'] ?? array();
					$keys = $section_data['keys'] ?? array();
					$selector = $structure_data['selector'] ?? '';
					// Read collapsed state from JSON data
					$is_collapsed = isset($structure_data['collapsed']) && $structure_data['collapsed'];
					$is_disabled = isset($structure_data['_disabled']) && $structure_data['_disabled'];
					$section_classes = 'postbox sortable-section';
					if ($is_disabled) $section_classes .= ' section-disabled';
				?>
					<div class="<?php echo esc_attr($section_classes); ?>" data-section="<?php echo esc_attr($category); ?>">
						<!-- Collapsible Header -->
						<h2 class="collapsible-header" onclick="toggleCollapse('category-<?php echo esc_attr($category); ?>')">
							<div class="header-content">
								<div class="header-left">
									<span class="drag-handle" title="Drag to reorder sections">⋮⋮</span>
									<span class="collapse-arrow <?php echo $is_collapsed ? 'collapsed' : ''; ?>"></span>
									<strong><?php echo esc_html(ucfirst($category)); ?></strong>
									<span style="font-weight: normal; color: #666; margin-left: 10px;">
										(<?php echo count($keys); ?> keys)
									</span>
								</div>
								<div class="header-right" class="delete_btn_holder">
									<button type="button" 
											onclick="event.stopPropagation(); deleteSectionConfirm('<?php echo esc_js($category); ?>');"
											class="button button-small section-delete-btn"
											title="Delete entire section"
											style="background: #dc3545; color: white; border: none; padding: 2px 8px; font-size: 11px;">
										Delete Section
									</button>
								</div>
							</div>
						</h2>
						
						<div id="category-<?php echo esc_attr($category); ?>" 
							 class="collapsible-content" 
							 style="<?php echo $is_collapsed ? 'display: none;' : 'display: block;'; ?>">
						
						<div class="inside" style="padding: 0;">
							<!-- Language Selector and Current Info -->
							<div class="selector-section">
								<div>
								<h4>CSS Selectors (Comma-separated)</h4>
								<input class="selectors" type="text" 
									   name="selectors[<?php echo esc_attr($category); ?>]"
									   value="<?php echo esc_attr($selector); ?>"
									   placeholder="CSS selector for this section (e.g., .buttons, #nav-menu)" />
								
								<h4 style="margin-top: 15px;">Pages to Apply (Use * for all pages)</h4>
								<?php
								$pages = isset($structure_data['_pages']) ? $structure_data['_pages'] : '*';
								?>
								<input class="pages" type="text" 
									   name="pages[<?php echo esc_attr($category); ?>]"
									   value="<?php echo esc_attr($pages); ?>"
									   placeholder="* for all pages, or comma-separated slugs (e.g., home, about, contact)" />
								
								<!-- Translation Method for this Section -->
									<?php
									$current_section_method = isset($structure_data['_method']) ? $structure_data['_method'] : 'server';
									?>
									<div class="section_option" style="">
										<h4>Translation Method</h4>
										<div>
											<label>
												<input type="radio" 
													   name="section_methods[<?php echo esc_attr($category); ?>]" 
													   value="javascript" 
													   <?php checked($current_section_method, 'javascript'); ?> />
												<span>JavaScript (Client-side)</span>
											</label>
											<label>
												<input type="radio" 
													   name="section_methods[<?php echo esc_attr($category); ?>]" 
													   value="server" 
													   <?php checked($current_section_method, 'server'); ?> />
												<span>Server-side (PHP)</span>
											</label>
										</div>
									</div>
								</div>
							</div>
							
							<!-- Translation Table -->
							<table class="wp-list-table widefat fixed striped category-translations-table" 
								   data-category="<?php echo esc_attr($category); ?>">
								<thead>
									<tr>
										<th style="width: 25%;">Translation Key</th>
										<th style="width: 65%;">
											Translation 
											<select id="lang-selector-<?php echo esc_attr($category); ?>" 
													class="category-lang-selector">
												<?php foreach ($available_languages as $lang): ?>
													<option value="<?php echo esc_attr($lang); ?>">
														<?php echo esc_html(strtoupper($lang)); ?>
													</option>
												<?php endforeach; ?>
											</select>
										</th>
										<th style="width: 10%;">Actions</th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ($keys as $key): 
										// Get all language translations for this key
										$lang_translations = array();
										foreach ($available_languages as $lang) {
											$lang_translations[$lang] = get_translation($category, $key, $lang);
										}
									?>
										<tr class="translation-row sortable-key-row" data-key="<?php echo esc_attr($key); ?>">
											<td style="padding: 10px; vertical-align: middle; position: relative;">
												<span class="key-drag-handle" title="Drag to reorder keys" style="display: inline-block; margin-right: 0px; cursor: move; color: #666; font-size: 14px; vertical-align: middle;">⋮⋮</span>
												<input type="text"
													class="key-name-input"
													name="key_names[<?php echo esc_attr($category); ?>][<?php echo esc_attr($key); ?>]"
													value="<?php echo htmlspecialchars($key, ENT_QUOTES, 'UTF-8'); ?>"
													data-original-key="<?php echo esc_attr($key); ?>"
													style="width: calc(100% - 2.5em);"
													onblur="this.style.background='transparent'; this.style.border='1px solid transparent';"
													onfocus="this.style.background='#fff'; this.style.border='1px solid #ccd0d4';" />
											</td>
											<td style="padding: 10px;">
												<input type="text" 
													class="translation-input widefat" 
													data-key="<?php echo esc_attr($key); ?>"
													style="width: 100%; padding: 8px;"
													placeholder="Enter translation..."
													value="<?php echo esc_attr($lang_translations[$default_language] ?? ''); ?>" />
												
												<!-- Hidden inputs for each language -->
												<?php foreach ($available_languages as $lang): ?>
													<input type="hidden" 
														   class="hidden-translation" 
														   data-lang="<?php echo esc_attr($lang); ?>"
														   name="translations[<?php echo esc_attr($category); ?>][<?php echo esc_attr($key); ?>][<?php echo esc_attr($lang); ?>]"
														   value="<?php echo esc_attr($lang_translations[$lang] ?? ''); ?>" />
												<?php endforeach; ?>
											</td>
											<td style="padding: 10px; vertical-align: middle;" class="delete_btn_holder">
												<button type="button" 
														onclick="markForDeletion('<?php echo esc_js($category); ?>', '<?php echo esc_js($key); ?>', this)"
														class="button button-link-delete" 
														style="color: #a00;">
													Delete
												</button>
											</td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
							
							<!-- Add Key Form for this section -->
							<div class="add_key_form" style="">
								<div>
									<div></div>
									<div class="add_key_form_controls">
										<input type="text" name="add_key_to_section[<?php echo esc_attr($category); ?>]" 
											   id="new_key_<?php echo esc_attr($category); ?>"
											   placeholder="e.g., Submit, Next, Search" >
										<button type="button" onclick="addKeyToSection('<?php echo esc_js($category); ?>')" 
												class="button button-secondary">Add Key</button>
									</div>
									<div></div>
								</div>
							</div>
						</div>
						</div> <!-- End collapsible-content -->
					</div>
				<?php endforeach; ?>
				</div> <!-- End sortable-sections -->
				
				

			<?php endif; ?>

			<div id="sections"></div>

			<div id="save_btn_holder" class="save_btn_holder" style=""></div>

		</form>
		
		<!-- File Information -->
		<div id="file_info" class="postbox"></div>
		
	</div>

	<?php
}




/**
 * Load all translations (backward compatibility)
 */
if (!function_exists('load_translations')) {
    function load_translations() {
	$languages = get_multilang_available_languages();
	$translations = array();
	
	// First try to load structure file
	$structure_path = get_structure_file_path();
	$structure = array();
	if (file_exists($structure_path)) {
		$structure_content = file_get_contents($structure_path);
		$structure = json_decode($structure_content, true) ?: array();
	}
	
	// Load each language file and combine with structure
	foreach ($languages as $lang) {
		$lang_file = get_language_file_path($lang);
		if (file_exists($lang_file)) {
			$lang_content = file_get_contents($lang_file);
			$lang_data = json_decode($lang_content, true) ?: array();
			
			// Merge language data into structure
			foreach ($lang_data as $category => $keys) {
				foreach ($keys as $key => $translation) {
					if (!isset($translations[$category])) {
						$translations[$category] = array();
						// Copy meta data from structure
						if (isset($structure[$category]['_selectors'])) {
							$translations[$category]['_selectors'] = $structure[$category]['_selectors'];
						}
						if (isset($structure[$category]['_collapsed'])) {
							$translations[$category]['_collapsed'] = $structure[$category]['_collapsed'];
						}
						if (isset($structure[$category]['_method'])) {
							$translations[$category]['_method'] = $structure[$category]['_method'];
						}
						if (isset($structure[$category]['_key_order'])) {
							$translations[$category]['_key_order'] = $structure[$category]['_key_order'];
						}
					}
					$translations[$category][$key][$lang] = $translation;
				}
			}
		}
	}
	
	// Return sections in natural order
	return $translations;
    }
}

/**
 * Save translations in new format (split into structure and language files)
 */
function save_translations_split_format($translations) {
	$data_dir = get_translations_data_dir();
	
	// Ensure data directory exists
	if (!is_dir($data_dir)) {
		wp_mkdir_p($data_dir);
	}
	
	// Extract structure information
	$structure = array();
	$language_data = array();
	
    foreach ($translations as $category => $keys) {
        // Check for meta keys
        $meta_keys = ['_selectors', '_collapsed', '_method', '_key_order', '_pages'];
        $has_meta = false;
        $structure[$category] = array();
        foreach ($meta_keys as $meta_key) {
            if (isset($keys[$meta_key])) {
                $structure[$category][$meta_key] = $keys[$meta_key];
                $has_meta = true;
            }
        }

        // Only add translation keys if there are any (exclude meta-only sections)
        $has_real_keys = false;
        foreach ($keys as $key => $lang_translations) {
            if (in_array($key, $meta_keys)) {
                continue; // Skip meta keys
            }
            $has_real_keys = true;
            if (is_array($lang_translations)) {
                foreach ($lang_translations as $lang => $translation) {
                    if (!isset($language_data[$lang])) {
                        $language_data[$lang] = array();
                    }
                    if (!isset($language_data[$lang][$category])) {
                        $language_data[$lang][$category] = array();
                    }
                    $language_data[$lang][$category][$key] = $translation;
                }
            }
        }
        // If no real keys and no meta keys, remove category from structure
        if (!$has_real_keys && !$has_meta) {
            unset($structure[$category]);
            foreach ($language_data as $lang => &$lang_translations) {
                if (isset($lang_translations[$category])) {
                    unset($lang_translations[$category]);
                }
            }
        }
        // If no real keys but has meta, remove from language_data but keep in structure
        elseif (!$has_real_keys && $has_meta) {
            foreach ($language_data as $lang => &$lang_translations) {
                if (isset($lang_translations[$category])) {
                    unset($lang_translations[$category]);
                }
            }
        }
    }
	
	// Save structure file
	$structure_content = json_encode($structure, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	$structure_result = file_put_contents(get_structure_file_path(), $structure_content);
	
	// Save individual language files
	$all_success = ($structure_result !== false);
	foreach ($language_data as $lang => $lang_translations) {
		$lang_content = json_encode($lang_translations, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		$lang_result = file_put_contents(get_language_file_path($lang), $lang_content);
		if ($lang_result === false) {
			$all_success = false;
		}
	}
	
	return $all_success;
}

/**
 * Helper function to get translation from language files
 */
function multilang_get_translation($key, $category = null, $lang = null) {
	if (!$lang) {
		$lang = get_multilang_current_language();
	}
	
	// Load translations from file
	$translations = load_translations();
	
	if ($category) {
		// Look in specific category
		if (isset($translations[$category][$key][$lang])) {
			return $translations[$category][$key][$lang];
		}
	} else {
		// Search all categories
		foreach ($translations as $cat => $keys) {
			if (isset($keys[$key][$lang])) {
				return $keys[$key][$lang];
			}
		}
	}
	
	// Fallback to default language
	$default_lang = get_multilang_default_language();
	if ($lang !== $default_lang) {
		if ($category && isset($translations[$category][$key][$default_lang])) {
			return $translations[$category][$key][$default_lang];
		} elseif (!$category) {
			foreach ($translations as $cat => $keys) {
				if (isset($keys[$key][$default_lang])) {
					return $keys[$key][$default_lang];
				}
			}
		}
	}
	
	// Return original key if no translation found
	return $key;
}

/**
 * Helper function to load all translation keys from all categories (with caching)
 */
function multilang_load_all_translation_keys() {
	static $all_keys = null;
	
	if ($all_keys === null) {
		$all_keys = array();
		$translations = load_translations();
		
		foreach ($translations as $category => $keys) {
			foreach ($keys as $key => $lang_translations) {
				// Skip meta keys like _selectors, _collapsed
				if (strpos($key, '_') === 0) {
					continue;
				}
				$all_keys[$key] = $lang_translations;
			}
		}
	}
	
	return $all_keys;
}

/**
 * Get available translation sections with structure and keys
 */
function get_available_translation_sections() {

	$translations = load_translations();
	$sections = array();

	// Load structure file directly
	$structure_path = get_structure_file_path();
	$structure = array();
	if (file_exists($structure_path)) {
		$structure_content = file_get_contents($structure_path);
		$structure = json_decode($structure_content, true) ?: array();
	}

	// Add all sections from structure file first
	foreach ($structure as $category => $meta) {
		if (strpos($category, '_') === 0) continue; // skip meta keys
		$sections[$category] = array(
			'structure' => array(),
			'keys' => array()
		);
		// Extract structure data
		if (isset($meta['_selectors'])) {
			$sections[$category]['structure']['selector'] = is_array($meta['_selectors'])
				? implode(', ', $meta['_selectors'])
				: $meta['_selectors'];
		}
		if (isset($meta['_collapsed'])) {
			$sections[$category]['structure']['collapsed'] = $meta['_collapsed'];
		}
		if (isset($meta['_method'])) {
			$sections[$category]['structure']['_method'] = $meta['_method'];
		}
		if (isset($meta['_disabled'])) {
			$sections[$category]['structure']['_disabled'] = $meta['_disabled'];
		}
		if (isset($meta['_pages'])) {
			$sections[$category]['structure']['_pages'] = $meta['_pages'];
		}
	}

	// Add keys from translations data
	foreach ($translations as $category => $data) {
		if (!isset($sections[$category])) {
			$sections[$category] = array(
				'structure' => array(),
				'keys' => array()
			);
		}
		// Extract structure data (if not already set)
		if (isset($data['_selectors']) && !isset($sections[$category]['structure']['selector'])) {
			$sections[$category]['structure']['selector'] = is_array($data['_selectors'])
				? implode(', ', $data['_selectors'])
				: $data['_selectors'];
		}
		if (isset($data['_collapsed']) && !isset($sections[$category]['structure']['collapsed'])) {
			$sections[$category]['structure']['collapsed'] = $data['_collapsed'];
		}
		if (isset($data['_method']) && !isset($sections[$category]['structure']['_method'])) {
			$sections[$category]['structure']['_method'] = $data['_method'];
		}
		if (isset($data['_disabled']) && !isset($sections[$category]['structure']['_disabled'])) {
			$sections[$category]['structure']['_disabled'] = $data['_disabled'];
		}
		if (isset($data['_pages']) && !isset($sections[$category]['structure']['_pages'])) {
			$sections[$category]['structure']['_pages'] = $data['_pages'];
		}
		// Extract translation keys (exclude meta keys)
		$unordered_keys = array();
		foreach ($data as $key => $value) {
			if (strpos($key, '_') !== 0) {
				$unordered_keys[] = $key;
			}
		}
		// Apply saved key order for this category
		$sections[$category]['keys'] = $unordered_keys;
	}

	return $sections;
}

/**
 * Get translation for specific category, key, and language
 */
function get_translation($category, $key, $lang) {
	$translations = load_translations();
	
	if (isset($translations[$category][$key][$lang])) {
		return $translations[$category][$key][$lang];
	}
	return '';
}

/**
 * Set translation for specific category, key, and language
 */
function set_translation($category, $key, $lang, $translation) {
	$translations = load_translations();
	
	if (!isset($translations[$category])) {
		$translations[$category] = array();
		$translations[$category]['_selectors'] = array('body'); // Default selector
	}
	
	if (!isset($translations[$category][$key])) {
		$translations[$category][$key] = array();
	}
	
	$translations[$category][$key][$lang] = $translation;
	
	return save_translations_split_format($translations);
}

/**
 * Resources for language settings
 */
function multilang_add_translations_manager_resources() {
	wp_enqueue_script(
		'multilang-admin-translations-tab',
		plugins_url('/admin/js/tab-translations-manager.js', dirname(__FILE__)),
		[],
		null,
		true
	);
	wp_localize_script(
		'multilang-admin-translations-tab',
		'multilangVars',
		[
			'ajaxUrl'   => admin_url('admin-ajax.php'),
            'availableSections' => json_encode(get_available_translation_sections()),
            'availableLanguages' => json_encode(get_multilang_available_languages()),
            'defaultLanguage' => esc_js(get_multilang_default_language()),
			'langDataUrl' => get_translations_data_url(),
            'nonce' => wp_create_nonce('save_collapse_state')
		]
	);
}