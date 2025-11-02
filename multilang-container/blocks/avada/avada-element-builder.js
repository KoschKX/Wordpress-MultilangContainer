(function($) {

   

    jQuery(document).ready(function() {
        initMultilangElement();
    });


     // GLOBALS 

    let flag_dir = '/img/flags/';

    /* STATE */

    var inMultilangEdit = false;
    var inMultilangSubEdit = false;
    var multilangIDX = '';

    var originalElementData = {};
    var isEditingMultilang = false;
    var currentMarkerID = '';

    /* TEMPLATES */

    // Function to build container shortcode dynamically based on available languages
    function buildContainerShortcode(markerID) {
        if (typeof multilangAvadaData === 'undefined' || !multilangAvadaData.available_languages) {
            avada_log('[Multilang] ERROR: multilangAvadaData not available');
            return '';
        }

        var languages = multilangAvadaData.available_languages;
        var numLangs = languages.length;

        // All columns should be full width (1/1) - stacked vertically
        var columnSize = '1_1';

        // Just create row_inner with column_inner elements (no wrapper column)
        var shortcode = '[fusion_builder_row_inner fusion_builder_row_inner="true" class="multilang-wrapper"]';

        // CREATE MARKER COLUMN FOR IDENTIFICATION
            shortcode += '[fusion_builder_column_inner class="multilang_marker" ';
                shortcode += 'type="' + columnSize + '" ';
            shortcode += ']';
                shortcode += '[fusion_text]multilang_marker_' + markerID + '[/fusion_text]';
            shortcode += '[/fusion_builder_column_inner]';

        // Create a column_inner for each language
        for (var i = 0; i < languages.length; i++) {
            var lang = languages[i];
            var isLast = (i === languages.length - 1);

            shortcode += '[fusion_builder_column_inner ';
            shortcode += 'type="' + columnSize + '" ';
            shortcode += 'class="translate lang-' + lang + ' ml-lang-column" ';
            shortcode += 'data_lang="' + lang + '" ';
            shortcode += 'last="' + (isLast ? 'true' : 'false') + '" ';
            shortcode += ']';

            // Empty column - user will add their own content
            shortcode += '[/fusion_builder_column_inner]';
        }

        shortcode += '[/fusion_builder_row_inner]';

        return shortcode;
    }

    function createNestedElement(){
        avada_log('[Multilang] createNestedElement called');
        
        // Get the multilang element
        var elementView = FusionPageBuilderViewManager.getView(fusionModalCID);
        if (!elementView || !elementView.model) {
            avada_log('[Multilang] ERROR: Could not find element model');
            return;
        }
        
        // Generate unique marker ID
        currentMarkerID = 'ml_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
        var newShortcode = buildContainerShortcode(currentMarkerID);
        
        avada_log('[Multilang] Generated shortcode length: ' + newShortcode.length);
        
        // Get the parent of the multilang element
        var parentCID = elementView.model.get('parent');
        if (!parentCID) {
            avada_log('[Multilang] ERROR: No parent CID found');
            return;
        }
        
        var parentView = FusionPageBuilderViewManager.getView(parentCID);
        if (!parentView) {
            avada_log('[Multilang] ERROR: Could not find parent view');
            return;
        }
        
        avada_log('[Multilang] Parent CID: ' + parentCID);
        avada_log('[Multilang] Parent type: ' + parentView.model.get('element_type'));
        
        // Check if shortcodesToBuilder exists
        if (typeof FusionPageBuilderApp === 'undefined' || typeof FusionPageBuilderApp.shortcodesToBuilder !== 'function') {
            avada_log('[Multilang] ERROR: shortcodesToBuilder not available');
            return;
        }
        
        // Parse the new shortcode to create nested columns
        avada_log('[Multilang] Creating nested columns in parent');
        FusionPageBuilderApp.shortcodesToBuilder(newShortcode, parentCID);

        closeAllEditors();

        setTimeout(function() {
            convertNestedColumns();
        }, 0);
    }

    function convertNestedColumns(){
        // Only look for row_inner elements (containers), not individual column elements
        $('.fusion_builder_row_inner').each(function() {
            let rowInner = $(this);
            let ml_id;
            
            // Look for the marker text element within this row_inner
            let markerElement = rowInner.find('.fusion_module_block_preview:contains("multilang_marker_")').first();
            
            if(markerElement.length){
                let previewContent = markerElement.text().trim();
                
                // Check if this is a marker column
                if(previewContent.includes('multilang_marker_')){    
                    ml_id = previewContent.replace('multilang_marker_', 'multilang_id_');
                    markerElement.text(ml_id);
                    
                    rowInner.addClass('multilang_avada');
                    
                    let title = rowInner.find('.fusion_module_title').first();
                    let icon = '<span class="fusion-module-icon fusiona-flag"></span>';
                    let controls = rowInner.find('.fusion-builder-controls.fusion-builder-inner-row-controls').first();
                    let content = rowInner.find('.fusion-builder-row-container-inner').first();
                    
                    if(title.length){
                        title.html(icon+' '+multilangAvadaData.element_name);
                    }

                    // Get languages from multilangAvadaData
                    let languages = multilangAvadaData.available_languages || [];
                    
                    content.find('.fusion-builder-column').each(function(idx){
                        let col = $(this);
                        if(idx == 0){
                            col.addClass('multilang-marker-column');
                        } else {
                            // Assign language based on index position
                            // idx 1 = first language, idx 2 = second language, etc.
                            let langIndex = idx - 1; // Subtract 1 to account for marker column at idx 0
                            if(langIndex >= 0 && langIndex < languages.length){
                                let lang = languages[langIndex];
                                col.attr('data-lang', lang);
                                col.attr('data-ml-marker', currentMarkerID);
                                col.addClass('lang-' + lang);
                                
                                // Add language badge div
                                if(!col.find('.multilang-lang-badge').length) {
                                    let badge = '';
                                    
                                    let flag = '<span class="multilang-flag"></span>';
                                    let flag_filename = multilang_fix_flag_filename(lang);
                                    let flag_url = '';
                                    if(flag_filename) {
                                        flag_url = multilangAvadaData.plugin_url + flag_dir + flag_filename + '.svg';
                                        flag = '<span class="multilang-flag" style="background-image: url(' + flag_url + ');"></span>';
                                    }
                                    badge = $('<div class="multilang-lang-badge"><span class="multilang-text">' + lang.toUpperCase() + '</span>'+flag+'</div>');
                                    col.prepend(badge);
                                }
                            }
                        }
                        col.addClass('multilang-column');
                    });
                    
                    // Disable sortable on the row content to prevent column reordering
                    if(content.hasClass('ui-sortable')) {
                        content.sortable('destroy');
                    }
                    
                    // Remove any existing click handlers first, then add new one
                    controls.find('.fusion-builder-module-control').off('click.multilang').on('click.multilang', function(e){
                        console.log('Settings button clicked for multilang element');
                        setTimeout(function() {
                            addLanguageSelector();
                        }, 0);
                    });
                }
            }
        });

    }

    function addLanguageSelector() {
        // Check if we're in the nested columns modal
        if (!$('.fusion-builder-modal-top-container:visible').length) return;

        // Check if selector already exists
        if ($('#ml-language-selector').length > 0) return;

        avada_log('[addLanguageSelector] Adding language selector to modal...');

        var languages = multilangAvadaData.available_languages;

        if (!languages || languages.length === 0) return;

        // Create the selector HTML
        var selectorHTML = '<div id="ml-language-selector" style="padding: 15px; background: #f5f5f5; border-bottom: 1px solid #ddd;">';
        selectorHTML += '<label style="margin-right: 10px; font-weight: bold;">Edit Language:</label>';
        selectorHTML += '<select id="ml-lang-dropdown" style="padding: 5px 10px; font-size: 14px;">';


        for (var i = 0; i < languages.length; i++) {
            var lang = languages[i].toUpperCase();
            selectorHTML += '<option value="' + languages[i] + '">' + lang + '</option>';
        }

        // Add "All" option first
        selectorHTML += '<option value="all">ALL</option>';

        selectorHTML += '</select>';
        selectorHTML += '</div>';

        // Insert at the top of the modal
        var $modal = $('.fusion-builder-modal-top-container:visible');
        if ($modal.length > 0) {
            $modal.prepend(selectorHTML);
            $('.fusion-builder-modal-top-container').parent().addClass('ml-has-language-selector');
            // Change the modal title to the element name from PHP
            var elementName = multilangAvadaData.element_name || 'Multilang Container';
            $modal.find('h2').text(elementName);
            // Remove the "Add Columns" button from the bottom container
            $('.fusion-builder-modal-bottom-container .fusion-builder-insert-inner-column').remove();
            
            // Filter out columns that aren't in available languages
            if (currentMarkerID) {
                $('.fusion-builder-column-inner[data-ml-marker="' + currentMarkerID + '"][data-lang]').each(function() {
                    var colLang = $(this).attr('data-lang');
                    if (colLang && languages.indexOf(colLang) === -1) {
                        // This column's language is not in available languages - hide it permanently
                        $(this).hide().addClass('ml-invalid-lang');
                    }
                });
            }
            
            // Add change event handler
            $('#ml-lang-dropdown').on('change', function() {
                var selectedLang = $(this).val();
                filterColumnsByLanguage(selectedLang);
            });
            // Initially show only the first language
            filterColumnsByLanguage(languages[0]);
        }
    }

    function filterColumnsByLanguage(lang) {
        console.log('[filterColumnsByLanguage] Filtering for: ' + lang);
        
        var languages = multilangAvadaData.available_languages || [];
        
        // If "all" is selected, show all valid language columns
        if (lang === 'all') {
            if (currentMarkerID) {
                // Show all columns that have valid languages
                $('.fusion-builder-column-inner[data-ml-marker="' + currentMarkerID + '"][data-lang]').each(function() {
                    var colLang = $(this).attr('data-lang');
                    // Only show if language is in available languages list
                    if (colLang && languages.indexOf(colLang) !== -1) {
                        $(this).show();
                    }
                });
            } else {
                // Fallback - show all valid language columns
                $('.fusion-builder-column-inner[data-lang]').each(function() {
                    var colLang = $(this).attr('data-lang');
                    if (colLang && languages.indexOf(colLang) !== -1) {
                        $(this).show();
                    }
                });
            }
        } else {
            // Show only the selected language
            if (currentMarkerID) {
                // Hide all language columns with this marker
                $('.fusion-builder-column-inner[data-ml-marker="' + currentMarkerID + '"][data-lang]').each(function() {
                    $(this).hide();
                });
                // Show only the selected language column with this marker
                $('.fusion-builder-column-inner[data-ml-marker="' + currentMarkerID + '"][data-lang="' + lang + '"]').show();
            } else {
                // Fallback to old method (less specific - may affect other elements)
                $('.fusion-builder-column-inner[data-lang]').each(function() {
                    $(this).hide();
                });
                $('.fusion-builder-column-inner[data-lang="' + lang + '"]').show();
            }
        }
    }


    /* EVENTS */

    function initMultilangElement() {
        if (typeof multilangAvadaData !== 'undefined') {
            avada_log('[Multilang] Available languages: ' + multilangAvadaData.available_languages.join(', '));
        }
        setupMultilangElementEvents();
        setupMultilangElementObservers();
    };

    function setupMultilangElementEvents() {
        // avada_log( 'Setting up multilang element events...' );
        $(document).on('fusion-element-added', function() {
            if (fusionModalElType == 'multilang_avada') {
                createNestedElement();
            }
        });
        $(document).on('fusion-builder-updated', function() {
            convertNestedColumns();
        });
        $(document).on('fusion-before-publish', function() {});
        $(document).on('fusion-modal-changed', function() {
            
        });
        $(document).on('fusion-modal-removed', function() {});
        $(document).on('fusion-modal-swapped', function() {});
        $(document).on('fusion-modal-opened', function() {
            if (fusionModalElType == 'multilang_avada') {
                // Store element data BEFORE modal closes
                var elementView = FusionPageBuilderViewManager.getView(fusionModalCID);
                if (elementView && elementView.model) {
                    originalElementData = {
                        cid: fusionModalCID,
                        parentCID: elementView.model.get('parent'),
                        element_type: elementView.model.get('element_type')
                    };
                    avada_log('[Multilang] Stored element data - CID: ' + fusionModalCID + ', Parent: ' + originalElementData.parentCID);
                }
                // Close the modal immediately
                FusionPageBuilderEvents.trigger('fusion-close-modal');
            }
        });

        // Save content when modal is edited (fires when save button is clicked)
        $(document).on('fusion-element-edited', function() {});
        $(document).on('fusion-modal-closed', function() {});
        $(document).on('fusion-debug-print-status', function() {
            if (inMultilangSubEdit) avada_debugStatus.push('mIDX: ' + multilangIDX);
            if (inMultilangEdit) avada_debugStatus.push('[inMultilangEdit]');
            if (inMultilangSubEdit) avada_debugStatus.push('[inMultilangSubEdit]');
            if (isEditingMultilang) avada_debugStatus.push('[isEditingMultilang]');
        });
    }

    function setupMultilangElementObservers(){
        // Observe body class changes
        var bodyClassObserver = new MutationObserver(function(mutations) {
            mutations.forEach(function(mutation) {
                if (mutation.type === 'attributes' && mutation.attributeName === 'class') {
                    if (jQuery('body').hasClass('fusion-builder-enabled')) {
                        setTimeout(function() {
                            convertNestedColumns();
                        }, 100);
                    }
                }
            });
        });
        bodyClassObserver.observe(document.body, {
            attributes: true
        });

        // Observe Fusion Builder layout changes
        var fusionLayoutElement = document.getElementById('fusion_builder_layout');
        if (fusionLayoutElement) {
            var fusionBuilderObserver = new MutationObserver(function(mutations) {
                setTimeout(function() {
                    convertNestedColumns();
                }, 0);
            });
            fusionBuilderObserver.observe(fusionLayoutElement, {
                childList: true,
                subtree: true
            });
        }
    }

    function saveEditor() {
        $('.fusion-builder-modal-save').each(function() {
            $(this).trigger('click');
        });
    }

    function closeAllEditors(keepElementType) {
        if (keepElementType) {
            $('div:not([data-element_type="' + keepElementType + '"]) .fusion-builder-modal-close').each(function() {
                $(this).trigger('click');
            });
        } else {
            $('.fusion-builder-modal-close').each(function() {
                $(this).trigger('click');
            });
        }
    }

    function refreshBuilder() {
        let bldr_btn = $('#fusion_toggle_builder');
        if (bldr_btn.length) {
            if (bldr_btn.hasClass('fusion_builder_is_active')) {
                $('body').addClass('ml_hide_builder_content');
                $('#fusion_toggle_builder').trigger('click');
                setTimeout(function() {
                    $('#fusion_toggle_builder').trigger('click');
                    $('body').removeClass('ml_hide_builder_content');
                }, 0);
            }
        }
    }


    /* HELPERS */

    function hideMLModal(elType) {
        let modal = $('.fusion-builder-modal-container [data-element_type="' + elType + '"]');
        if (modal) modal = modal.closest('.fusion-builder-modal-settings-container');
        if (modal) modal.addClass('mock-modal-hide');
    }

    function showMLModal(elType) {
        let modal = $('.fusion-builder-modal-container [data-element_type="' + elType + '"]');
        if (modal) modal = modal.closest('.fusion-builder-modal-settings-container');
        if (modal) modal.removeClass('mock-modal-hide');
    }

})(jQuery);