var avada_debug = false;
var avada_debugStatus = [];

/* INIT */

jQuery(document).ready(function() {
    if (jQuery('body').hasClass('post-php')) {
        avada_debug_init();
        avada_log('Avada Fusion Builder detected. Setting up event listeners.');
        setupFusionBuilder_Events();
    }
});

/* GLOBALS */

var fusionModalContent = '';
var fusionModalView = '';
var fusionModalElType = '';
var fusionModalType = '';
var fusionModalCID = '';

var fusionLastModelView = '';
var fusionLastModelElType = '';
var fusionLastModelType = '';
var fusionLastModelCID = '';

var fusionModalExists = false;
var fusionModalSwapping = false;
var fusionModalSaving = false;

var fusionModalSavePending = false;



/* EVENTS */

function setupFusionBuilder_Events() {
    // Listen for Avada Fusion Builder events to trigger modal refresh
    if (window.FusionPageBuilderEvents && window.FusionPageBuilderEvents.on) {
        window.FusionPageBuilderEvents.on('fusion-element-edited', onFusionBuilder_ElementEdited);
        window.FusionPageBuilderEvents.on('fusion-element-added', onFusionBuilder_ElementAdded);
        window.FusionPageBuilderEvents.on('fusion-close-modal', onFusionBuilder_CloseModal);
        window.FusionPageBuilderEvents.on('fusion-close-inner-modal', onFusionBuilder_CloseInnerModal);
        window.FusionPageBuilderEvents.on('fusion-modal-view-removed', onFusionBuilder_ModalViewRemoved);
        window.FusionPageBuilderEvents.on('fusion-dynamic-data-added', onFusionBuilder_DataAdded);
        window.FusionPageBuilderEvents.on('fusion-dynamic-data-removed', onFusionBuilder_DataRemoved);
        window.FusionPageBuilderEvents.on('fusion-save-layout', onFusionBuilder_SaveLayout);

        // CUSTOM EVENTS
        onFusionBuilder_BeforePublish();

        // OBSERVE
        setupFusionBuilder_Observers();
    } else {
        avada_log('FusionPageBuilderEvents not found. Avada Fusion Builder event listeners not set up.');
    }
}

function onFusionBuilder_ElementEdited() {
    fusionModalSaving = true;
    avada_log('[fusion-element-edited] called');
    jQuery(document).trigger('fusion-element-edited');
    fusionModalSaving = false;
}

function onFusionBuilder_ElementAdded() {
    onFusionBuilder_ModalChanged();
    avada_log('[fusion-element-added] called');
    jQuery(document).trigger('fusion-element-added');
}

function onFusionBuilder_CloseModal() {
    avada_log('[fusion-close-modal] called');
    jQuery(document).trigger('fusion-close-modal');
}

function onFusionBuilder_CloseInnerModal() {
    avada_log('[fusion-close-inner-modal] called');
    jQuery(document).trigger('fusion-close-inner-modal');
}

function onFusionBuilder_ModalViewRemoved() {
    avada_log('[fusion-modal-removed] called');
    clear_status_flags();
    jQuery(document).trigger('fusion-modal-removed');
}

function onFusionBuilder_DataAdded() {
    avada_log('[fusion-data-added] called');
    jQuery(document).trigger('fusion-data-added');
}

function onFusionBuilder_DataRemoved() {
    avada_log('[fusion-data-removed] called');
    jQuery(document).trigger('fusion-data-removed');
}

function onFusionBuilder_SaveLayout() {
    consolelog('[fusion-save-layout] called');
    jQuery(document).trigger('fusion-save-layout');
}

// CUSTOM EVENTS

function onFusionBuilder_BeforePublish() {
    jQuery('#publish, #save-post').on('click', function(e) {
        e.preventDefault();
        e.stopPropagation();

        avada_log('[fusion-before-publish] called');

        jQuery(document).trigger('fusion-before-publish');

        if (fusionModalSavePending) {
            var waitForSave = setInterval(function() {
                if (!jQuery('#fusion-loader').is(':visible')) {
                    clearInterval(waitForSave);
                    jQuery('#post').submit();
                    fusionModalSavePending = false;
                }
            }, 100);
        } else {
            setTimeout(function() {
                jQuery('#post').submit();
            }, 0);
        }
    });
}

function onFusionBuilder_AjaxEvents() {
    jQuery(document).on('ajaxStart', function() {
        avada_log('[fusion-ajax-started] called');
        jQuery(document).trigger('fusion-ajax-started');
    });
    jQuery(document).on('ajaxStop', function() {
        avada_log('[fusion-ajax-stopped] called');
        jQuery(document).trigger('fusion-ajax-stopped');
    });
}



/* OBSERVERS */

setInterval(function() {
    avada_printStatus();
}, 300);

function setupFusionBuilder_Observers() {
    var bodyClassObserver = new MutationObserver(function(mutations) {
        mutations.forEach(function(mutation) {
            if (mutation.type === 'attributes' && mutation.attributeName === 'class') {
                if (jQuery('body').hasClass('fusion-builder-enabled')) {
                    jQuery(document).trigger('fusion-builder-updated');
                }
            }
            onFusionBuilder_ModalChanged();
        });
    });
    bodyClassObserver.observe(document.body, {
        attributes: true
    });
}

/* OBSERVED EVENTS */

function onFusionBuilder_ModalChanged() {
    if (fusionModalSaving) {
        return;
    }
    let modalExists = false;
    if (jQuery('.fusion_builder_modal_overlay, .fusion-builder-modal-top-container:visible').length) {
        modalExists = true;
    }
    let container = jQuery('.fusion-builder-modal-settings-container');
    let modalTop = jQuery('.fusion-builder-modal-top-container:visible');

    // Defensive: get container/settings only if modal exists
    let modalView = container.attr('data-modal_view') || '';
    let modalType = '';
    let modalElType = '';
    let modalCID = container.attr('data-cid') || modalTop.parent().attr('data-cid') || '';
    if (modalExists) {
        let settings;
        if (container.length) {
            settings = container.find('.fusion_builder_module_settings');
            modalType = settings.attr('data-type') || '';
            modalElType = settings.attr('data-element_type') || '';
            modalCID = settings.attr('data-cid') || '';
        }
        if (!container.length && modalTop.length) {
            settings = modalTop.parent().find('.fusion_builder_module_settings');
            modalType = 'builder';
            modalView = 'nested_columns';
        }
    }

    // Detect reason for change and which globals changed (using switch/case)
    let reason = '';
    let change = '';
    let changedGlobals = {};
    let prev = {
        fusionModalExists,
        fusionModalView,
        fusionModalType,
        fusionModalElType,
        fusionModalCID
    };
    let curr = {
        fusionModalExists: modalExists,
        fusionModalView: modalView,
        fusionModalType: modalType,
        fusionModalElType: modalElType,
        fusionModalCID: modalCID
    };
    // Compare each global
    Object.keys(curr).forEach(function(key) {
        if (prev[key] !== curr[key]) {
            changedGlobals[key] = {
                old: prev[key],
                new: curr[key]
            };
        }
    });

    // CHANGE
    if (!fusionModalExists && modalExists) {
        change = 'opened';
    } else if (!modalExists && fusionModalExists) {
        change = 'closed';
    } else {
        change = 'swapped';
    }

    // REASON
    switch (true) {
        case (Object.keys(changedGlobals).length > 0):
            // Map global keys to friendly names
            const keyMap = {
                fusionModalView: 'view',
                fusionModalType: 'type',
                fusionModalElType: 'element type',
                fusionModalCID: 'CID'
            };
            let changedKeys = Object.keys(changedGlobals)
                .filter(k => k !== 'fusionModalExists')
                .map(k => keyMap[k] || k);
            if (changedKeys.length === 1) {
                reason = changedKeys[0];
            } else if (changedKeys.length > 1) {
                reason = changedKeys;
            } else {
                reason = 'updated';
            }
            break;
        default:
            reason = '';
    }

    // Update globals

    fusionLastModelView = fusionModalView;
    fusionLastModelType = fusionModalType;
    fusionLastModelElType = fusionModalElType;
    fusionLastModelCID = fusionModalCID;

    fusionModalExists = modalExists;
    fusionModalView = modalView;
    fusionModalType = modalType;
    fusionModalElType = modalElType;
    fusionModalCID = modalCID;

    if (reason) {
        avada_log('fusion-modal-changed] ' + change);
        jQuery(document).trigger('fusion-modal-changed', [reason, changedGlobals]);

        if (change === 'swapped') {
            jQuery(document).trigger('fusion-modal-swapped');
        } else if (change === 'opened') {
            jQuery(document).trigger('fusion-modal-opened');
        } else if (change === 'closed') {
            if (fusionModalSwapping) {
                return;
            }
            jQuery(document).trigger('fusion-modal-closed');
            clear_status_flags();
        }
    }
}

function clear_status_flags() {
    fusionModalExists = false;
    fusionModalSwapping = false;
    fusionModalSaving = false;
    fusionModalView = '';
    fusionModalType = '';
    fusionModalElType = '';
    fusionModalCID = '';
}

/* HELPERS */


function waitForRefresh(callback) {
    setTimeout(function() {
        var waitForSave = setInterval(function() {
            if (!jQuery('#fusion-loader').is(':visible')) {
                clearInterval(waitForSave);
                callback();
            }
        }, 100);
    }, 100);
}

function waitForTinyMCE(callback) {
    setTimeout(function() {
        // Wait for TinyMCE editor to change before proceeding
        var iframe = document.getElementById('content_ifr');
        if (iframe && iframe.contentDocument) {
            var tinymceNode = iframe.contentDocument.getElementById('tinymce');
            if (tinymceNode) {
                var observer = new MutationObserver(function(mutations, obs) {
                    if (callback && typeof callback === 'function') {
                        callback();
                    }
                    obs.disconnect();
                });
                observer.observe(tinymceNode, {
                    childList: true,
                    subtree: true,
                    characterData: true
                });
                return;
            }
        }
    }, 100);
}

/* TINYMCE */


function syncFusionBuilder() {
    avada_log('[syncFusionBuilder] Syncing builder to shortcodes...');
    if (typeof FusionPageBuilderApp !== 'undefined' && typeof FusionPageBuilderApp.builderToShortcodes === 'function') {
        // Force Fusion to regenerate shortcodes from current builder state
        FusionPageBuilderApp.builderToShortcodes();
        avada_log('[syncFusionBuilder] Builder synced, allContent length: ' + (FusionPageBuilderApp.allContent ? FusionPageBuilderApp.allContent.length : 0));
    } else {
        avada_log('[syncFusionBuilder] ERROR: FusionPageBuilderApp.builderToShortcodes not available');
    }
}

function avada_get_data(textareaID, removeAutoP, initialLoad) { // jshint ignore:line

    var content;

    if ('undefined' === typeof removeAutoP) {
        removeAutoP = false;
    }

    if ('undefined' === typeof initialLoad) {
        initialLoad = false;
    }

    if (!initialLoad && 'undefined' !== typeof window.tinyMCE && window.tinyMCE.get(textareaID) && !window.tinyMCE.get(textareaID).isHidden()) {
        content = window.tinyMCE.get(textareaID).getContent();
    } else if (jQuery('#' + textareaID).length) {
        content = jQuery('#' + textareaID).val().replace(/\r?\n/g, '\r\n');
    }

    // Remove auto p tags from content.
    if (removeAutoP && 'undefined' !== typeof window.tinyMCE && 'undefined' !== typeof content) {
        content = content.replace(/<p>\[/g, '[');
        content = content.replace(/\]<\/p>/g, ']');
    }

    if ('undefined' !== typeof content) {
        return content.trim();
    }
};


function switchToVisualEditor(callback) {
    //avada_log('[switchToVisualEditor] Checking editor mode...');
    // Check if we're in text mode by looking for the tmce/html tabs
    var $tmceTab = jQuery('#content-tmce');
    var $htmlTab = jQuery('#content-html');
    if (jQuery('#content_ifr').length === 0) {
        //var wasInTextMode = $htmlTab.hasClass('active');
        var wasInTextMode = true;
        if (wasInTextMode) {
            console.log('[switchToVisualEditor] Currently in text mode, switching to visual...');
            $tmceTab.trigger('click');
            // Wait for TinyMCE iframe to be created and content to load
            var checkInterval = setInterval(function() {
                var iframe = document.getElementById('content_ifr');
                if (iframe && iframe.contentDocument) {
                    var tinymce = iframe.contentDocument.getElementById('tinymce');
                    if (tinymce) {
                        clearInterval(checkInterval);
                        console.log('[switchToVisualEditor] TinyMCE loaded');

                        // Execute callback
                        if (callback && typeof callback === 'function') {
                            callback();
                        }

                        // Switch back to text mode
                        console.log('[switchToVisualEditor] Switching back to text mode...');
                        $htmlTab.trigger('click');
                    }
                }
            }, 100);
            // Timeout after 5 seconds
            setTimeout(function() {
                clearInterval(checkInterval);
            }, 5000);
            return true; // Switched
        }
    }
    if (callback && typeof callback === 'function') {
        callback();
    }
    return false;
}

function getCodeFromTinyMCE() {
    const editor = window.tinymce && window.tinymce.get("content");
    if (editor && typeof editor.getContent === 'function') {
        return editor.getContent();
    }
    return '';
}

function getCodeFromTinyMCEForced() {
    let content = ''
    //let content = FusionPageBuilderApp.allContent;
    //if(content) return content;
    var iframe = document.getElementById('content_ifr');
    if (iframe && iframe.contentDocument) {
        var tinymce = iframe.contentDocument.getElementById('tinymce');
        if (tinymce) {
            content = tinymce.innerHTML;
        }
    }
    return content;
}

function getCodeFromTinyMCEAdv() {
    var content = '';
    // Try FusionPageBuilderApp.allContent first (most reliable)
    if (typeof FusionPageBuilderApp !== 'undefined' && FusionPageBuilderApp.el) {
        content = FusionPageBuilderApp.allContent;
        avada_log('content: ' + content);
        avada_log('[getCodeFromTinyMCE] Got content from FusionPageBuilderApp.allContent');
        return content;
    }
    // Try TinyMCE iframe (visual editor)
    var iframe = document.getElementById('content_ifr');
    if (iframe && iframe.contentDocument) {
        var tinymce = iframe.contentDocument.getElementById('tinymce');
        if (tinymce) {
            content = tinymce.innerHTML;
            avada_log('[getCodeFromTinyMCE] Got content from TinyMCE iframe');
            return content;
        }
    }

    // Fallback to textarea (text editor mode)
    var $textarea = $('#content');
    if ($textarea.length > 0) {
        content = $textarea.val();
        avada_log('[getCodeFromTinyMCE] Got content from textarea (text mode)');
        return content;
    }

    avada_log('[getCodeFromTinyMCE] ERROR: Could not get content from any source');
    return content;
}


/* DEBUGGING */

function avada_printStatus() {
    if (!avada_debug) return;
    avada_debugStatus.push('Modal View: ' + fusionModalView);
    avada_debugStatus.push('Type: ' + fusionModalType);
    avada_debugStatus.push('ElType: ' + fusionModalElType);
    avada_debugStatus.push('CID: ' + fusionModalCID);

    jQuery(document).trigger('fusion-debug-print-status');

    if (fusionModalSaving) avada_debugStatus.push('[Saving]');

    if (jQuery('#av_status').length) {
        jQuery('#av_status').text(avada_debugStatus.join(', '));
    }

    avada_debugStatus = [];
}

function avada_debug_init() {
    if (!avada_debug) return;
    jQuery('body').append('<div id="av_status"></div>');
}

function avada_log(msg) {
    if (!avada_debug) return;
    console.log(msg);
}