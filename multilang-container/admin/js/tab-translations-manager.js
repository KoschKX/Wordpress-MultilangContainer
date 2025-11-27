// Toggle section disabled/enabled state (global)
function toggleSectionDisabled(category) {
    var sectionBox = document.querySelector('.postbox[data-section="' + category + '"]');
    if (!sectionBox) return;
    var disabled = sectionBox.classList.toggle('section-disabled');
    // Update button text
    var btn = sectionBox.querySelector('.section-disable-btn');
    if (btn) btn.textContent = disabled ? 'Enable Section' : 'Disable Section';
    // Set all inputs in this section to readonly/disabled
    sectionBox.querySelectorAll('input, textarea, select').forEach(function(input) {
        if (!input.classList.contains('section-disable-btn')) {
            input.disabled = disabled;
        }
    });
}

// Override form submission to send JSON
document.addEventListener('DOMContentLoaded', function() {
    // Always show Save button after the form
    // Add enable/disable toggle to each section
    document.querySelectorAll('.postbox').forEach(function(sectionBox) {
            // Always inject the enable/disable button next to the delete button in the header-right div
            var headerRight = sectionBox.querySelector('.header-right');
            if (headerRight && !headerRight.querySelector('.section-disable-btn')) {
                var sanitizedName = sectionBox.getAttribute('data-section');
                var disableBtn = document.createElement('button');
                disableBtn.type = 'button';
                disableBtn.className = 'button button-small section-disable-btn';
                disableBtn.title = 'Disable/Enable section';
                disableBtn.style.background = '#ffc107';
                disableBtn.style.color = '#333';
                disableBtn.style.border = 'none';
                disableBtn.style.padding = '2px 8px';
                disableBtn.style.fontSize = '11px';
                disableBtn.style.marginRight = '6px';
                var isDisabled = sectionBox.classList.contains('section-disabled');
                disableBtn.textContent = isDisabled ? 'Enable Section' : 'Disable Section';
                disableBtn.onclick = function(event) {
                    event.stopPropagation();
                    toggleSectionDisabled(sanitizedName);
                };
                headerRight.insertBefore(disableBtn, headerRight.firstChild);
            }
            // If section is disabled, set all inputs to disabled
            if (sectionBox.classList.contains('section-disabled')) {
                sectionBox.querySelectorAll('input, textarea, select').forEach(function(input) {
                    if (!input.classList.contains('section-disable-btn')) {
                        input.disabled = true;
                    }
                });
            }
    });
    var mainForm = document.getElementById('main-translations-form');
    if (mainForm && !document.getElementById('save-translations-btn')) {
        var saveBtnDiv = document.getElementById('save_btn_holder');
        saveBtnDiv.className = 'save_btn_holder';
        saveBtnDiv.innerHTML = '<button type="submit" id="save-translations-btn" form="main-translations-form" name="save_translations" class="button button-primary button-large">Save All Translations</button>';
        // Find the last translation section (postbox)
        var postboxes = document.querySelectorAll('.postbox');
        if (postboxes.length > 0) {
            var lastSection = postboxes[postboxes.length - 1];
            //lastSection.parentNode.insertBefore(saveBtnDiv, lastSection.nextSibling);
        } else {
            // If no sections, append after the form
            mainForm.parentNode.appendChild(saveBtnDiv);
        }
    }
    var form = document.getElementById('main-translations-form');
    if (form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            var jsonData = buildTranslationsJSON();
            jsonData.nonce = multilangVars.nonce;
            var xhr = new XMLHttpRequest();
            var url = multilangVars.ajaxUrl + '?action=save_translations_json';
            console.log('Saving translations to:', url);
            xhr.open('POST', url, true);
            xhr.setRequestHeader('Content-Type', 'application/json');
            xhr.onreadystatechange = function() {
                if (xhr.readyState === 4) {
                    console.log('AJAX response:', xhr.status, xhr.responseText);
                    if (xhr.status === 200) {
                        try {
                            var resp = JSON.parse(xhr.responseText);
                            if (resp.success) {
                                console.log('Translations saved successfully!');
                                document.querySelectorAll('.postbox[data-deleted]').forEach(function(section) {
                                    section.parentNode.removeChild(section);
                                });
                                document.querySelectorAll('.translation-row[data-deleted]').forEach(function(row) {
                                    row.parentNode.removeChild(row);
                                });
                                // Refresh File Information box
                                refreshFileInfoBox();
                            } else {
                                console.log('Error: ' + (resp.data && resp.data.message ? resp.data.message : 'Unknown error'));
                            }
                        } catch (e) {
                            console.log('Error saving translations.');
                        }
                    } else {
                        console.log('Error saving translations.');
                    }
                }
            };
            xhr.send(JSON.stringify(jsonData));
        });
    }
});

// Build JSON from current form state
function buildTranslationsJSON() {
    var result = {};
    // Loop through each section
    document.querySelectorAll('.postbox').forEach(function(sectionBox) {
        if (sectionBox.hasAttribute('data-deleted')) return; // Skip deleted sections
        var collapsible = sectionBox.querySelector('.collapsible-content, .section-content');
        if (!collapsible) return; // Skip if not a translation section
        var sectionId = collapsible.getAttribute('id');
        if (!sectionId) return;
        var category = sectionId.replace('category-', '');
        result[category] = {};
        // Add meta fields for each section
        var selectorInput = sectionBox.querySelector('input[name^="selectors["]');
        var methodInput = sectionBox.querySelector('input[name^="section_methods["]:checked');
        var collapsed = collapsible.style.display === 'none';
        var disabled = sectionBox.classList.contains('section-disabled');
        result[category]['_selectors'] = selectorInput ? selectorInput.value : 'body';
        result[category]['_collapsed'] = collapsed;
        result[category]['_method'] = methodInput ? methodInput.value : 'server';
        result[category]['_disabled'] = disabled;
        var hasKeys = false;
        sectionBox.querySelectorAll('.translation-row').forEach(function(row) {
            if (row.hasAttribute('data-deleted')) return; // Skip deleted keys
            // Support textarea for key (HTML block)
            var keyInput = row.querySelector('.key-name-input');
            var key = keyInput ? keyInput.value : row.getAttribute('data-key');
            if (!key) return;
            hasKeys = true;
            result[category][key] = {};
            row.querySelectorAll('.hidden-translation').forEach(function(hiddenInput) {
                var lang = hiddenInput.getAttribute('data-lang');
                var value = hiddenInput.value;
                result[category][key][lang] = value;
            });
        });
        // Always include meta fields even if no keys
        if (!hasKeys) {
            result[category]['_selectors'] = selectorInput ? selectorInput.value : 'body';
            result[category]['_collapsed'] = collapsed;
        }
        // Always set _method for every section
        result[category]['_method'] = methodInput ? methodInput.value : 'server';
        result[category]['_disabled'] = disabled;
    });
    return result;
}

function toggleCollapse(sectionId) {
    var content = document.getElementById(sectionId);
    var header = content.previousElementSibling;
    var arrow = header.querySelector('.collapse-arrow');
    var category = sectionId.replace('category-', '');
    
    var isCollapsing = content.style.display !== 'none';
    
    if (content.style.display === 'none') {
        content.style.display = 'block';
        arrow.classList.remove('collapsed');
    } else {
        content.style.display = 'none';
        arrow.classList.add('collapsed');
    }
    
    // Save the collapsed state
    saveCollapseState(category, isCollapsing);
}

// Save collapse state via AJAX
function saveCollapseState(category, isCollapsed) {
    var xhr = new XMLHttpRequest();
    xhr.open('POST', ajaxurl || multilangVars.ajaxUrl, true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.send('action=save_section_collapse_state&category=' + encodeURIComponent(category) + '&collapsed=' + (isCollapsed ? '1' : '0') + '&nonce='+multilangVars.nonce);
}

// Language switching functionality
var categoryLanguageStates = {};
var defaultLanguage = multilangVars.defaultLanguage;

// Initialize language switching
document.addEventListener('DOMContentLoaded', function() {
    // Initialize all category language selectors
    document.querySelectorAll('.category-lang-selector').forEach(function(selector) {
        var category = selector.id.replace('lang-selector-', '');
            var langs = window.selectedLanguages && window.selectedLanguages.length ? window.selectedLanguages : [defaultLanguage];
            categoryLanguageStates[category] = langs[0];
        
        // Set the dropdown to the first option (default language)
        if (selector.options && selector.options.length > 0) {
            selector.selectedIndex = 0;
            selector.value = selector.options[0].value;
            // Update the language state to match the selected option
                categoryLanguageStates[category] = langs[0];
        }
        
        // Initialize display for this category
        updateCategoryDisplay(category, categoryLanguageStates[category]);
        
        // Add event listener for language changes
        selector.addEventListener('change', function(e) {
            var newLang = this.value;
            var category = this.id.replace('lang-selector-', '');
            
            // Save current inputs before switching
            saveCategoryInputs(category);
            
            // Update language state
            categoryLanguageStates[category] = newLang;
            
            // Update display
            updateCategoryDisplay(category, newLang);
        });
    });
    
    // Add control buttons and add section form
    var controlsDiv = document.createElement('div');
    controlsDiv.style.cssText = 'margin: 10px 0; display: flex; justify-content: space-between; align-items: center;';
    
    // Left side - Add section form
    var addSectionForm = '<div style="display: flex; align-items: center; gap: 10px;">' +
                        '<button type="button" onclick="addNewSection()" class="button button-secondary">Add Section</button>' +
                        '<input type="text" name="new_section_name" id="new_section_name" ' +
                        'placeholder="e.g., buttons, labels, messages" style="width: 225px; padding: 6px 8px;">' +
                        '</div>';
    
    // Right side - Collapse/Expand buttons
    var toggleButtons = '<div>' +
                        '<button type="button" onclick="toggleAllSections(false)" class="button" style="margin-right: 5px;">Collapse All</button>' +
                        '<button type="button" onclick="toggleAllSections(true)" class="button">Expand All</button>' +
                        '</div>';
    
    controlsDiv.innerHTML = addSectionForm + toggleButtons;
    
    var mainForm = document.getElementById('main-translations-form');
    if (mainForm && mainForm.parentNode) {
        mainForm.parentNode.insertBefore(controlsDiv, mainForm);
    }
});

// Save current input values to hidden fields
function saveCategoryInputs(category) {
    var currentLang = categoryLanguageStates[category];
    var table = document.querySelector('.category-translations-table[data-category="' + category + '"]');
    
    if (table) {
        table.querySelectorAll('.translation-input').forEach(function(input) {
            var key = input.getAttribute('data-key');
            // Find the hidden input by data-lang and key in name (for HTML keys)
            var targetHidden = null;
            table.querySelectorAll('input.hidden-translation[data-lang="' + currentLang + '"]').forEach(function(h) {
                if (h.getAttribute('name') && h.getAttribute('name').includes(key)) {
                    targetHidden = h;
                }
            });
            if (targetHidden) {
                targetHidden.value = input.value;
            }
        });
    }
}

// Update display for a category when language changes
function updateCategoryDisplay(category, lang) {
    var table = document.querySelector('.category-translations-table[data-category="' + category + '"]');
    
    if (table) {
        // Update all translation inputs
        table.querySelectorAll('.translation-row').forEach(function(row) {
            var key = row.getAttribute('data-key');
            var input = row.querySelector('.translation-input');
            // Find the hidden input by data-lang and key in name (for HTML keys)
            var hiddenInput = null;
            row.querySelectorAll('input.hidden-translation[data-lang="' + lang + '"]').forEach(function(h) {
                if (h.getAttribute('name') && h.getAttribute('name').includes(key)) {
                    hiddenInput = h;
                }
            });
            if (input && hiddenInput) {
                var currentValue = hiddenInput.value || '';
                if (!currentValue && lang !== defaultLanguage) {
                    var defaultHiddenInput = null;
                    row.querySelectorAll('input.hidden-translation[data-lang="' + defaultLanguage + '"]').forEach(function(h) {
                        if (h.getAttribute('name') && h.getAttribute('name').includes(key)) {
                            defaultHiddenInput = h;
                        }
                    });
                    if (defaultHiddenInput && defaultHiddenInput.value) {
                        input.value = defaultHiddenInput.value;
                        input.style.fontStyle = 'italic';
                        input.style.color = '#666';
                        input.placeholder = 'Enter ' + lang.toUpperCase() + ' translation (showing default: ' + defaultLanguage.toUpperCase() + ')';
                    } else {
                        input.value = '';
                        input.style.fontStyle = 'normal';
                        input.style.color = '';
                        input.placeholder = 'Enter ' + lang.toUpperCase() + ' translation...';
                    }
                } else {
                    input.value = currentValue;
                    input.style.fontStyle = 'normal';
                    input.style.color = '';
                    input.placeholder = 'Enter ' + lang.toUpperCase() + ' translation...';
                }
            }
        });
    }
}

// Update hidden inputs when typing
document.addEventListener('input', function(e) {
    if (e.target.classList.contains('translation-input')) {
        var input = e.target;
        var row = input.closest('.translation-row');
        var table = input.closest('.category-translations-table');
        var category = table.getAttribute('data-category');
        var key = row.getAttribute('data-key');
        var currentLang = categoryLanguageStates[category];
        
        var hiddenInput = row.querySelector('input[name="translations[' + category + '][' + key + '][' + currentLang + ']"]');
        if (hiddenInput) {
            hiddenInput.value = input.value;
        }
    }
});

function toggleAllSections(expand) {
    var sections = document.querySelectorAll('.collapsible-content');
    var arrows = document.querySelectorAll('.collapse-arrow');
    
    sections.forEach(function(section, index) {
        // Extract category name from section ID
        var sectionId = section.getAttribute('id');
        var category = sectionId ? sectionId.replace('category-', '') : '';
        
        if (expand) {
            section.style.display = 'block';
            arrows[index].classList.remove('collapsed');
        } else {
            section.style.display = 'none';
            arrows[index].classList.add('collapsed');
        }
        
        // Save the collapsed state for each section
        if (category) {
            saveCollapseState(category, !expand); // !expand because collapsed = true when not expanded
        }
    });
}

// Mark items for deletion
function markForDeletion(category, key, button) {
    var row = button.closest('tr');
    row.setAttribute('data-deleted', 'true');

    button.classList.add('delete');
    button.innerHTML = 'Undo';
    button.style.color = '#0073aa';
    button.onclick = function() { unmarkForDeletion(category, key, button); };
}

// Unmark items for deletion
function unmarkForDeletion(category, key, button) {
    var row = button.closest('tr');
    row.style.textDecoration = 'none';
    row.removeAttribute('data-deleted');

    button.classList.remove('delete');
    button.innerHTML = 'Delete';
    button.style.color = '#a00';
    button.onclick = function() { markForDeletion(category, key, button); };
}

function decode_json(str){
    if (typeof str === 'string') {
        try {
            str = JSON.parse(str);
        } catch (e) {
            str = [];
        }
    }
    return str
}

// Add key to specific section
function addKeyToSection(category) {
    var input = document.getElementById('new_key_' + category);
    var keyName = input.value.trim();
    
    if (!keyName) {
        alert('Please enter a translation key name.');
        return;
    }
    
    // Check if key already exists in this section
    var existingKeys = document.querySelectorAll('#category-' + category + ' .translation-row');
    for (var i = 0; i < existingKeys.length; i++) {
        var existingKeyInput = existingKeys[i].querySelector('.key-name-input');
        if (existingKeyInput && existingKeyInput.value.trim() === keyName) {
            alert('Key "' + keyName + '" already exists in this section.');
            return;
        }
    }
    
    // Get available languages
    var availableLanguages = decode_json(multilangVars.availableLanguages);

    // Create new key row HTML
    var newRowHtml = createKeyRowHtml(category, keyName, availableLanguages);
    
    // Find the existing table in the section
    var sectionContent = document.querySelector('#category-' + category);
    var table = sectionContent.querySelector('table.category-translations-table');
    
    if (table) {
        // Add the new row to the existing table body
        var tbody = table.querySelector('tbody');
        tbody.insertAdjacentHTML('beforeend', newRowHtml);
    } else {
        // If no table exists, we need to find where to insert it
        // Look for the selector section and insert table after it
        var selectorSection = sectionContent.querySelector('.selector-section');
        if (selectorSection) {
            var tableHtml = '<table class="wp-list-table widefat fixed striped category-translations-table" data-category="' + category + '" style="margin-top: 20px;">';
            tableHtml += '<thead><tr>';
            tableHtml += '<th style="width: 25%;">Translation Key</th>';
            tableHtml += '<th style="width: 65%;">Translation <select id="lang-selector-' + category + '" class="category-lang-selector" style="padding: 2px 5px; margin-left: 5px; font-size: 12px;">';
            
            // Add language options
            availableLanguages.forEach(function(lang) {
                tableHtml += '<option value="' + lang + '">' + lang.toUpperCase() + '</option>';
            });
            
            tableHtml += '</select></th>';
            tableHtml += '<th style="width: 10%;">Actions</th>';
            tableHtml += '</tr></thead><tbody>' + newRowHtml + '</tbody></table>';
            selectorSection.insertAdjacentHTML('afterend', tableHtml);
        }
    }
    
    // Clear the input field
    input.value = '';
    
    // Update key count in header
    updateSectionKeyCount(category);
    
    // Reinitialize sortable for the new/updated table
    setTimeout(function() {
        jQuery('.category-translations-table tbody').each(function() {
            var $tbody = jQuery(this);
            if (!$tbody.hasClass('ui-sortable')) {
                initializeTableSortable($tbody);
            }
        });
    }, 100);
}

// Helper function to create key row HTML
function createKeyRowHtml(category, keyName, availableLanguages) {
    function htmlEscape(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/\"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }
    var safeKey = htmlEscape(keyName);
    var html = '<tr class="translation-row sortable-key-row" data-key="' + safeKey + '">';
    html += '<td style="padding: 10px; vertical-align: middle; position: relative;">';
    html += '<span class="key-drag-handle" title="Drag to reorder keys" style="display: inline-block; margin-right: 0px; cursor: move; color: #666; font-size: 14px; vertical-align: middle;">⋮⋮</span>';
    html += '<input type="text" class="key-name-input" name="key_names[' + category + '][' + safeKey + ']" value="' + safeKey + '" data-original-key="' + safeKey + '" style="width: calc(100% - 2.5em); font-weight: bold; background: transparent; border: 1px solid transparent; padding: 4px 6px; border-radius: 3px;" onblur="this.style.background=\'transparent\'; this.style.border=\'1px solid transparent\';" onfocus="this.style.background=\'#fff\'; this.style.border=\'1px solid #ccd0d4\';">';
    html += '</td>';
    html += '<td style="padding: 10px;">';
    html += '<input type="text" class="translation-input widefat" data-key="' + safeKey + '" style="width: 100%; padding: 8px; font-style: normal;" placeholder="Enter EN translation..." value="">';
    var langs = window.selectedLanguages && window.selectedLanguages.length ? window.selectedLanguages : availableLanguages;
    langs.forEach(function(lang) {
        html += '<input type="hidden" class="hidden-translation" data-lang="' + lang + '" data-key="' + safeKey + '" name="translations[' + category + '][' + safeKey + '][' + lang + ']" value="" />';
    });
    html += '</td>';
    html += '<td style="padding: 10px; vertical-align: middle;">';
    html += '<button type="button" onclick="markForDeletion(\'' + category + '\', \'' + safeKey + '\', this)" class="button button-link-delete" style="color: #a00;">Delete</button>';
    html += '</td>';
    html += '</tr>';
    return html;
}

// Helper function to update section key count
function updateSectionKeyCount(category) {
    var sectionHeader = document.querySelector('#category-' + category).closest('.postbox').querySelector('h2 strong').nextElementSibling;
    var keyRows = document.querySelectorAll('#category-' + category + ' .translation-row');
    var keyCount = keyRows.length; // Each row is one key in the new structure
    sectionHeader.textContent = '(' + keyCount + ' keys)';
}

// Delete entire section
function deleteSectionConfirm(category) {
    var sectionBox = document.querySelector('#category-' + category).closest('.postbox');
    var deleteBtn = sectionBox.querySelector('.section-delete-btn');
    
    // console.log('Marking section for deletion:', category);
    
    // Mark section for deletion
    sectionBox.style.filter = 'grayscale(100%)';
    sectionBox.setAttribute('data-deleted', 'true');
    
    // Change button to "Undo Delete Section"
    deleteBtn.innerHTML = 'Undo';
    deleteBtn.style.background = '#0073aa';
    deleteBtn.onclick = function(event) { 
        event.stopPropagation(); 
        undoDeleteSection(category); 
    };
    deleteBtn.title = 'Undo section deletion';
    
    // Add to the main translations form by ID
    var mainForm = document.getElementById('main-translations-form');
}

// Undo section deletion
function undoDeleteSection(category) {
    var sectionBox = document.querySelector('#category-' + category).closest('.postbox');
    var deleteBtn = sectionBox.querySelector('.section-delete-btn');

    // Restore section appearance
    sectionBox.style.filter = 'none';
    sectionBox.removeAttribute('data-deleted');
    
    // Change button back to "Delete Section"
    deleteBtn.innerHTML = 'Delete Section';
    deleteBtn.style.background = '#dc3545';
    deleteBtn.onclick = function(event) { 
        event.stopPropagation(); 
        deleteSectionConfirm(category); 
    };
    deleteBtn.title = 'Delete entire section';
}

// Add new section function
function addNewSection() {
    var sectionName = document.getElementById('new_section_name').value.trim();
    if (!sectionName) {
        alert('Please enter a section name.');
        return;
    }
    
    // Sanitize section name (replace spaces with underscores, lowercase, etc.)
    var sanitizedName = sectionName.toLowerCase().replace(/[^a-z0-9_]/g, '_').replace(/_+/g, '_');
    
    // Check if section already exists
    var existingSections = document.querySelectorAll('.postbox h2 strong');
    for (var i = 0; i < existingSections.length; i++) {
        if (existingSections[i].textContent.toLowerCase() === sanitizedName) {
            alert('A section with this name already exists.');
            return;
        }
    }
    
    // Create the new section HTML
    var newSectionHtml = createNewSectionHtml(sanitizedName, sectionName);
    
    // Always insert new sections into #sections container
    var sectionsContainer = document.getElementById('sections');
    if (sectionsContainer) {
        sectionsContainer.insertAdjacentHTML('beforeend', newSectionHtml);
    }
    
    // Clear the input field
    document.getElementById('new_section_name').value = '';
    
    // Reinitialize sortable functionality for new sections
    setTimeout(function() {
        jQuery('.category-translations-table tbody').each(function() {
            var $tbody = jQuery(this);
            if (!$tbody.hasClass('ui-sortable')) {
                initializeTableSortable($tbody);
            }
        });
        // Initialize language selector for the new section
        var selector = document.getElementById('lang-selector-' + sanitizedName);
        if (selector) {
            categoryLanguageStates[sanitizedName] = defaultLanguage;
            selector.selectedIndex = 0;
            selector.value = selector.options[0].value;
            updateCategoryDisplay(sanitizedName, selector.options[0].value);
            selector.addEventListener('change', function(e) {
                var newLang = this.value;
                saveCategoryInputs(sanitizedName);
                categoryLanguageStates[sanitizedName] = newLang;
                updateCategoryDisplay(sanitizedName, newLang);
            });
        }
    }, 100);
}

function createNewSectionHtml(sanitizedName, displayName) {
    var html = '<div class="postbox sortable-section" data-section="' + sanitizedName + '">';
    html += '<h2 class="collapsible-header" onclick="toggleCollapse(\'category-' + sanitizedName + '\')">';
    html += '<div class="header-content">';
    html += '<div class="header-left">';
    html += '<span class="drag-handle" title="Drag to reorder sections">⋮⋮</span>';
    html += '<span class="collapse-arrow"></span>';
    html += '<strong>' + displayName.charAt(0).toUpperCase() + displayName.slice(1) + '</strong>';
    html += '<span style="font-weight: normal; color: #666; margin-left: 10px;">(0 keys)</span>';
    html += '</div>';
    html += '<div class="header-right">';
    html += '<button type="button" onclick="event.stopPropagation(); toggleSectionDisabled(\'' + sanitizedName + '\');" class="button button-small section-disable-btn" title="Disable/Enable section" style="background: #ffc107; color: #333; border: none; padding: 2px 8px; font-size: 11px; margin-right: 6px;">Disable Section</button>';
    html += '<button type="button" onclick="event.stopPropagation(); deleteSectionConfirm(\'' + sanitizedName + '\');" class="button button-small section-delete-btn" title="Delete entire section" style="background: #dc3545; color: white; border: none; padding: 2px 8px; font-size: 11px;">Delete Section</button>';
    html += '</div>';
    html += '</h2>';
    html += '<div class="collapsible-content" id="category-' + sanitizedName + '" style="display: block;">';
    html += '<div class="inside" style="padding: 0;">';
    html += '<div class="selector-section">';
    html += '<div>';
    html += '<h4>CSS Selectors (Comma-separated)</h4>';
    html += '<input class="selectors" type="text" name="selectors[' + sanitizedName + ']" value="body" placeholder="CSS selector for this section (e.g., .buttons, #nav-menu)" />';
    html += '<div class="section_option" style="margin-top: 15px;">';
    html += '<h4>Translation Method</h4>';
    html += '<div>';
    html += '<label>';
    html += '<input type="radio" name="section_methods[' + sanitizedName + ']" value="javascript" />';
    html += '<span>JavaScript (Client-side)</span>';
    html += '</label>';
    html += '<label>';
    html += '<input type="radio" name="section_methods[' + sanitizedName + ']" value="server" checked="checked" />';
    html += '<span>Server-side (PHP)</span>';
    html += '</label>';
    html += '</div>';
    html += '</div>';
    html += '</div>';
    html += '</div>';
    // Add key table (empty)
    html += '<table class="wp-list-table widefat fixed striped category-translations-table" data-category="' + sanitizedName + '">';
    html += '<thead><tr>';
    html += '<th style="width: 25%;">Translation Key</th>';
    html += '<th style="width: 65%;">Translation <select id="lang-selector-' + sanitizedName + '" class="category-lang-selector">';
    var langs = window.selectedLanguages && window.selectedLanguages.length ? window.selectedLanguages : decode_json(multilangVars.availableLanguages);
    langs.forEach(function(lang) {
        html += '<option value="' + lang + '">' + lang.toUpperCase() + '</option>';
    });
    html += '</select></th>';
    html += '<th style="width: 10%;">Actions</th>';
    html += '</tr></thead><tbody></tbody></table>';
    // Add key form
    html += '<div class="add_key_form" style="">';
    html += '<div><div></div><div class="add_key_form_controls">';
    html += '<input type="text" name="add_key_to_section[' + sanitizedName + ']" id="new_key_' + sanitizedName + '" placeholder="e.g., Submit, Next, Search" >';
    html += '<button type="button" onclick="addKeyToSection(\'' + sanitizedName + '\')" class="button button-secondary">Add Key</button>';
    html += '</div><div></div></div></div>';
    html += '</div>';
    html += '</div>';
    return html;
}

// Initialize sortable functionality
jQuery(document).ready(function($) {
    // Check if already initialized
    if (jQuery('#sortable-sections').hasClass('ui-sortable')) {
        return; // Already initialized
    }
    
    // Check if sortable function is available
    if (typeof $.fn.sortable !== 'function' && typeof jQuery.fn.sortable !== 'function') {
        console.error('Sortable function not available. Check if jQuery UI Sortable is loaded.');
        return;
    }
    
    // Make sections sortable - try $ first, fallback to jQuery
    var sortableElement = $('#sortable-sections').length ? $('#sortable-sections') : jQuery('#sortable-sections');
    var sortableFunc = typeof $.fn.sortable === 'function' ? $ : jQuery;
    
    sortableFunc('#sortable-sections').sortable({
        handle: '.drag-handle',
        cursor: 'move',
        placeholder: 'sortable-placeholder',
        tolerance: 'pointer',
        forcePlaceholderSize: true,
        start: function(e, ui) {
            // Only set height and style for the placeholder to match the dragged item
            if (ui && ui.placeholder && ui.item && typeof ui.item.height === 'function') {
                ui.placeholder.height(ui.item.height());
                ui.placeholder.css({
                    'background': '#e0eaff',
                    'border': '2px dashed #0073aa',
                    'visibility': 'visible'
                });
            }
        },
        update: function(event, ui) {
            var order = [];
            sortableFunc('#sortable-sections').children().each(function() {
                var sectionName = sortableFunc(this).attr('data-section');
                if (sectionName) {
                    order.push(sectionName);
                }
            });
            updateSectionOrder(order);
        }
    });
    // Restrict placeholder styling to only the actual placeholder
    var style = document.createElement('style');
    document.head.appendChild(style);
    
    // Prevent sorting when clicking on other elements
    sortableFunc('.collapsible-header').on('mousedown', function(e) {
        if (!sortableFunc(e.target).hasClass('drag-handle')) {
            sortableFunc(this).closest('.sortable-section').addClass('no-sort');
        }
    }).on('mouseup', function(e) {
        sortableFunc(this).closest('.sortable-section').removeClass('no-sort');
    });
    
    // Update sortable options to respect no-sort class
    sortableFunc('#sortable-sections').on('sortstart', function(event, ui) {
        if (ui.item.hasClass('no-sort')) {
            sortableFunc(this).sortable('cancel');
        }
    });
    
    // Initialize sortable functionality for translation keys (use the same sortableFunc)
    function initializeKeySortable() {
        if (typeof sortableFunc.fn.sortable !== 'function') {
            console.error('Sortable function not available for keys');
            return;
        }

        // Inject style for sortable-key-placeholder
        var keyStyle = document.createElement('style');
        document.head.appendChild(keyStyle);

        jQuery('.category-translations-table tbody').each(function() {
            var $tbody = jQuery(this);
            var category = $tbody.closest('.category-translations-table').attr('data-category');

            if (category && $tbody.find('.sortable-key-row').length > 0) {
                $tbody.sortable({
                    handle: '.key-drag-handle',
                    cursor: 'move',
                    placeholder: 'sortable-key-placeholder',
                    tolerance: 'pointer',
                    items: '.sortable-key-row',
                    start: function(e, ui) {
                        if (ui && ui.placeholder && ui.item && typeof ui.item.height === 'function') {
                            ui.placeholder.height(ui.item.height());
                            ui.placeholder.html('<td colspan="3" style="height: ' + ui.item.height() + 'px;"></td>');
                        }
                    },
                    update: function(event, ui) {
                        // Get the new order of keys for this category
                        var keyOrder = [];
                        $tbody.find('.sortable-key-row').each(function() {
                            var keyName = jQuery(this).attr('data-key');
                            if (keyName) {
                                keyOrder.push(keyName);
                            }
                        });
                        updateKeyOrder(category, keyOrder);
                    }
                });
            }
        });

        refreshFileInfoBox();
        
    }

// Make translation key tables sortable
initializeKeySortable();

}); // End of jQuery ready block

// Global function to initialize sortable for a single table tbody
function initializeTableSortable($tbody) {
    if (typeof jQuery === 'undefined' || typeof jQuery.fn.sortable !== 'function') {
        return;
    }
    
    var category = $tbody.closest('.category-translations-table').attr('data-category');
    
    if (category && $tbody.find('.sortable-key-row').length > 0) {
        $tbody.sortable({
            handle: '.key-drag-handle',
            cursor: 'move',
            placeholder: 'sortable-key-placeholder',
            tolerance: 'pointer',
            items: '.sortable-key-row',
            start: function(e, ui) {
                if (ui && ui.placeholder && ui.item && typeof ui.item.height === 'function') {
                    ui.placeholder.height(ui.item.height());
                    ui.placeholder.html('<td colspan="3" style="height: ' + ui.item.height() + 'px;"></td>');
                }
            },
            update: function(event, ui) {
                // Get the new order of keys for this category
                var keyOrder = [];
                $tbody.find('.sortable-key-row').each(function() {
                    var keyName = jQuery(this).attr('data-key');
                    if (keyName) {
                        keyOrder.push(keyName);
                    }
                });
                updateKeyOrder(category, keyOrder);
            }
        });
    }
}

// Function to update key order for a specific category (save on form submission only)
function updateKeyOrder(category, keyOrder) {
    // Update or create hidden input for this category's key order
    var inputId = 'key-order-' + category;
    jQuery('#' + inputId).remove();
    var orderInput = jQuery('<input type="hidden" id="' + inputId + '" name="key_orders[' + category + ']" />');
    orderInput.val(JSON.stringify(keyOrder));
    jQuery('#sortable-sections').closest('form').append(orderInput);
}

// Function to update section order in hidden input (save on form submission only)
function updateSectionOrder(order) {
    // Update hidden input for form submission
    jQuery('#section-order-input').remove();
    var orderInput = jQuery('<input type="hidden" id="section-order-input" name="section_order" />');
    orderInput.val(JSON.stringify(order));
    jQuery('#sortable-sections').closest('form').append(orderInput);
}

// Find the save button and disable deleted fields on click
function disableDeletedOnSave() {
    var saveBtn = document.querySelector('#main-translations-form [type="submit"]');
    if (saveBtn) {
        saveBtn.addEventListener('click', function() {
            // Disable all inputs in sections marked for deletion
            document.querySelectorAll('.postbox[data-deleted], .postbox[data-deleted] *').forEach(function(input) {
                input.disabled = true;
            });
            // Disable all inputs in translation key rows marked for deletion
            document.querySelectorAll('.translation-row[data-deleted], .translation-row[data-deleted] *').forEach(function(input) {
                input.disabled = true;
            });
        });
    }
}
document.addEventListener('DOMContentLoaded', disableDeletedOnSave);

// Listen for changes to window.selectedLanguages and update language dropdowns and hidden inputs
function updateLanguageDropdownsAndInputs() {
    // Update all language dropdowns
    document.querySelectorAll('.category-lang-selector').forEach(function(selector) {
        var category = selector.id.replace('lang-selector-', '');
        var currentValue = selector.value;
        // Remove all options
        while (selector.options.length > 0) selector.remove(0);
        // Add new options
        var langs = window.selectedLanguages && window.selectedLanguages.length ? window.selectedLanguages : decode_json(multilangVars.availableLanguages);
        langs.forEach(function(lang) {
            var opt = document.createElement('option');
            opt.value = lang;
            opt.textContent = lang.toUpperCase();
            selector.appendChild(opt);
        });
        // Restore previous selection if possible
        if (langs.includes(currentValue)) {
            selector.value = currentValue;
        } else {
            selector.value = langs[0];
        }
        // Update display for this category
        updateCategoryDisplay(category, selector.value);
    });
    // Update all translation key hidden inputs
    document.querySelectorAll('.category-translations-table').forEach(function(table) {
        var category = table.getAttribute('data-category');
        table.querySelectorAll('.translation-row').forEach(function(row) {
            var key = row.getAttribute('data-key');
            // Collect existing values before removing
            var existingValues = {};
            row.querySelectorAll('.hidden-translation').forEach(function(input) {
                var lang = input.getAttribute('data-lang');
                existingValues[lang] = input.value;
            });
            // Remove all hidden inputs
            row.querySelectorAll('.hidden-translation').forEach(function(input) { input.remove(); });
            // Add hidden inputs for each language
            var langs = window.selectedLanguages && window.selectedLanguages.length ? window.selectedLanguages : decode_json(multilangVars.availableLanguages);
            langs.forEach(function(lang) {
                var hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.className = 'hidden-translation';
                hidden.setAttribute('data-lang', lang);
                hidden.setAttribute('data-key', key);
                hidden.name = 'translations[' + category + '][' + key + '][' + lang + ']';
                hidden.value = (lang in existingValues) ? existingValues[lang] : '';
                row.querySelector('td:nth-child(2)').appendChild(hidden);
            });
        });
    });
}

// Custom event to trigger update from Languages tab
window.addEventListener('selectedLanguagesChanged', function() {
    updateLanguageDropdownsAndInputs();
    // Load translations for any newly checked language
    var langs = window.selectedLanguages && window.selectedLanguages.length ? window.selectedLanguages : decode_json(multilangVars.availableLanguages);
    langs.forEach(function(lang) {
        // For each category, if hidden inputs for this lang are empty, fetch and populate
        document.querySelectorAll('.category-translations-table').forEach(function(table) {
            var category = table.getAttribute('data-category');
            var needsLoad = false;
            table.querySelectorAll('.translation-row').forEach(function(row) {
                var key = row.getAttribute('data-key');
                // Find hidden input by data-lang and key in name
                var hidden = null;
                row.querySelectorAll('input.hidden-translation[data-lang="' + lang + '"]').forEach(function(h) {
                    if (h.getAttribute('name') && h.getAttribute('name').includes(key)) {
                        hidden = h;
                    }
                });
                if (hidden && !hidden.value) {
                    needsLoad = true;
                }
            });
            if (needsLoad) {
                // Fetch translations for this language/category
                fetch(multilangVars.langDataUrl + lang + '.json')
                    .then(function(response) { return response.json(); })
                    .then(function(data) {
                        table.querySelectorAll('.translation-row').forEach(function(row) {
                            var key = row.getAttribute('data-key');
                            // Find hidden input by data-lang and key in name
                            var hidden = null;
                            row.querySelectorAll('input.hidden-translation[data-lang="' + lang + '"]').forEach(function(h) {
                                if (h.getAttribute('name') && h.getAttribute('name').includes(key)) {
                                    hidden = h;
                                }
                            });
                            if (hidden && data[category] && data[category][key]) {
                                hidden.value = data[category][key];
                                // If this is the currently selected language, update the visible input
                                var selector = document.getElementById('lang-selector-' + category);
                                if (selector && selector.value === lang) {
                                    var input = row.querySelector('.translation-input');
                                    if (input) input.value = data[category][key];
                                }
                            }
                        });
                    });
            }
        });
    });
});

// Override form submission to send JSON
document.addEventListener('DOMContentLoaded', function() {
    // Always show Save button after the form
    var mainForm = document.getElementById('main-translations-form');
    if (mainForm && !document.getElementById('save-translations-btn')) {
        var saveBtnDiv = document.getElementById('save_btn_holder');
        saveBtnDiv.className = 'save_btn_holder';
        saveBtnDiv.innerHTML = '<button type="submit" id="save-translations-btn" form="main-translations-form" name="save_translations" class="button button-primary button-large">Save All Translations</button>';
        // Find the last translation section (postbox)
        var postboxes = document.querySelectorAll('.postbox');
        if (postboxes.length > 0) {
            var lastSection = postboxes[postboxes.length - 1];
            //lastSection.parentNode.insertBefore(saveBtnDiv, lastSection.nextSibling);
        } else {
            // If no sections, append after the form
            mainForm.parentNode.appendChild(saveBtnDiv);
        }
    }
    var form = document.getElementById('main-translations-form');
    if (form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            var jsonData = buildTranslationsJSON();
            jsonData.nonce = multilangVars.nonce;
            var xhr = new XMLHttpRequest();
            var url = multilangVars.ajaxUrl + '?action=save_translations_json';
            console.log('Saving translations to:', url);
            xhr.open('POST', url, true);
            xhr.setRequestHeader('Content-Type', 'application/json');
            xhr.onreadystatechange = function() {
                if (xhr.readyState === 4) {
                    console.log('AJAX response:', xhr.status, xhr.responseText);
                    if (xhr.status === 200) {
                        try {
                            var resp = JSON.parse(xhr.responseText);
                            if (resp.success) {
                                console.log('Translations saved successfully!');
                                document.querySelectorAll('.postbox[data-deleted]').forEach(function(section) {
                                    section.parentNode.removeChild(section);
                                });
                                document.querySelectorAll('.translation-row[data-deleted]').forEach(function(row) {
                                    row.parentNode.removeChild(row);
                                });
                                // Refresh File Information box
                                refreshFileInfoBox();
                            } else {
                                console.log('Error: ' + (resp.data && resp.data.message ? resp.data.message : 'Unknown error'));
                            }
                        } catch (e) {
                            console.log('Error saving translations.');
                        }
                    } else {
                        console.log('Error saving translations.');
                    }
                }
            };
            xhr.send(JSON.stringify(jsonData));
        });
    }
});
// Refresh the File Information box after saving
function refreshFileInfoBox() {
    var fileInfoBox = document.getElementById('file_info');
    if (fileInfoBox) {
        fetch(multilangVars.ajaxUrl + '?action=get_structure_file')
            .then(function(response) { return response.json(); })
            .then(function(data) {
                var infoHtml = '';
                if (data && typeof data === 'object') {
                    infoHtml += '<div style="padding: 15px;">';
                    infoHtml += '<div><strong>Data Directory:</strong> <code>' + (data.data_directory || multilangVars.langDataUrl || '') + '</code></div>';
                    infoHtml += '<br><div><strong>Language Files:</strong></div>';
                    infoHtml += '<table class="file-info-table">';
                    infoHtml += '<thead><tr><th>Language</th><th>File</th><th>Status</th><th>Size</th></tr></thead><tbody>';
                    if (data.languages) {
                        Object.keys(data.languages).forEach(function(lang) {
                            var file = data.languages[lang].file || '';
                            var status = data.languages[lang].status || '';
                            var size = data.languages[lang].size || '';
                            var statusClass = '';
                            if (status.includes('Exists')) {
                                statusClass = 'file-info-status-exists';
                            } else if (status.includes('Missing')) {
                                statusClass = 'file-info-status-missing';
                            }
                            infoHtml += '<tr>';
                            infoHtml += '<td>' + lang + '</td>';
                            infoHtml += '<td><code>' + file + '</code></td>';
                            infoHtml += '<td class="' + statusClass + '">' + status + '</td>';
                            infoHtml += '<td>' + size + '</td>';
                            infoHtml += '</tr>';
                        });
                    }
                    // Structure file row
                    if (data.structure) {
                        var sStatus = data.structure.status || '';
                        var sStatusClass = '';
                        if (sStatus.includes('Exists')) {
                            sStatusClass = 'file-info-status-exists';
                        } else if (sStatus.includes('Missing')) {
                            sStatusClass = 'file-info-status-missing';
                        }
                        infoHtml += '<tr>';
                        infoHtml += '<td>Structure</td>';
                        infoHtml += '<td><code>' + (data.structure.file || 'structure.json') + '</code></td>';
                        infoHtml += '<td class="' + sStatusClass + '">' + sStatus + '</td>';
                        infoHtml += '<td>' + (data.structure.size || '') + '</td>';
                        infoHtml += '</tr>';
                    }
                    infoHtml += '</tbody></table>';
                    infoHtml += '<div style="margin-top: 10px; font-size: 13px; color: #666;">Available Languages: ' + (data.available_languages ? data.available_languages.join(', ') : '') + '</div>';
                    infoHtml += '</div>';
                } else {
                    infoHtml = '<div style="padding: 15px;">Unable to load file information.</div>';
                }
                fileInfoBox.innerHTML = infoHtml;
            })
            .catch(function(err) {
                console.log('Error refreshing file info:', err);
            });
    }
}

