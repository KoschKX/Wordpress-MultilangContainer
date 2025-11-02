/**
 * Avada Multilang Container Admin JavaScript
 * Handles TinyMCE initialization and reinitialization
 */

(function($) {
    'use strict';

    console.log('test');

    var initAttempts = 0;
    var maxAttempts = 10;

    // More robust TinyMCE initialization function
    window.multilang_init_tinymce = function(force) {
        force = force || false;
        initAttempts++;
        
        // Find multilang container textareas
        var $textareas = $('.fusion-builder-option[data-option-id*="content_"] textarea, textarea[name*="content_"]');
        
        if ($textareas.length === 0 && initAttempts < maxAttempts) {
            // Textareas not found yet, try again
            setTimeout(function() {
                multilang_init_tinymce(force);
            }, 200);
            return;
        }

        $textareas.each(function() {
            var $textarea = $(this);
            var textareaId = $textarea.attr('id');
            
            if (!textareaId) {
                // Generate ID if missing
                textareaId = 'multilang_editor_' + Math.random().toString(36).substr(2, 9);
                $textarea.attr('id', textareaId);
            }

            // Check if this textarea should be initialized
            var shouldInit = force || 
                           ($textarea.is(':visible') && 
                            $textarea.closest('.fusion-builder-option').is(':visible') &&
                            !$textarea.hasClass('wp-editor-area'));

            if (shouldInit) {
                // Remove existing TinyMCE instance
                if (window.tinymce && window.tinymce.get(textareaId)) {
                    try {
                        window.tinymce.get(textareaId).remove();
                    } catch(e) {}
                }
                
                // Initialize TinyMCE with minimal, safe settings
                if (window.tinymce && window.tinymce.init) {
                    try {
                        window.tinymce.init({
                            selector: '#' + textareaId,
                            height: 300,
                            menubar: false,
                            branding: false,
                            resize: 'vertical',
                            plugins: 'lists link paste', // Only basic plugins that exist
                            toolbar: 'undo redo | bold italic | alignleft aligncenter alignright | bullist numlist | link unlink',
                            paste_as_text: true,
                            setup: function(editor) {
                                editor.on('init', function() {
                                    $textarea.addClass('wp-editor-area');
                                });
                                editor.on('change keyup', function() {
                                    editor.save();
                                    $textarea.trigger('change');
                                });
                            }
                        });
                    } catch(e) {
                        console.log('TinyMCE init failed:', e);
                    }
                }
            }
        });
    };

    // Initialize when document is ready
    $(document).ready(function() {
        
        // Multiple initialization attempts for different Fusion Builder states
        setTimeout(function() { multilang_init_tinymce(true); }, 500);
        setTimeout(function() { multilang_init_tinymce(); }, 1000);
        setTimeout(function() { multilang_init_tinymce(); }, 2000);
        
        // Listen for dropdown changes
        $(document).on('change', 'select[data-param="edit_language"]', function() {
            setTimeout(function() { multilang_init_tinymce(true); }, 100);
        });

        // Listen for modal/dialog events
        $(document).on('click', '.fusion-builder-element-button', function() {
            if ($(this).find('.fusiona-flag').length > 0) {
                setTimeout(function() { multilang_init_tinymce(true); }, 300);
            }
        });

        // Listen for settings panel opens
        $(document).on('click', '.fusion-builder-settings', function() {
            setTimeout(function() { multilang_init_tinymce(true); }, 300);
        });

        // Listen for any modal opens
        $(document).on('DOMNodeInserted', '.fusion-builder-modal-settings-container', function() {
            setTimeout(function() { multilang_init_tinymce(true); }, 300);
        });

        // Fusion Builder specific events
        if (typeof FusionPageBuilderEvents !== 'undefined') {
            FusionPageBuilderEvents.on('fusion-element-settings-modal-open', function() {
                setTimeout(function() { multilang_init_tinymce(true); }, 300);
            });
        }

        // Generic mutation observer as fallback
        if (window.MutationObserver) {
            var observer = new MutationObserver(function(mutations) {
                var shouldInit = false;
                mutations.forEach(function(mutation) {
                    if (mutation.type === 'childList') {
                        var $target = $(mutation.target);
                        if ($target.hasClass('fusion-builder-modal-settings-container') ||
                            $target.find('textarea[name*="content_"]').length > 0) {
                            shouldInit = true;
                        }
                    }
                });
                if (shouldInit) {
                    setTimeout(function() { multilang_init_tinymce(); }, 200);
                }
            });
            
            observer.observe(document.body, {
                childList: true,
                subtree: true
            });
        }
    });

})(jQuery);