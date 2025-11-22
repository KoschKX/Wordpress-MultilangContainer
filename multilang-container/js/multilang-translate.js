/* -------------------- LANGUAGE TRANSLATION -------------------- */

(() => {
    'use strict';
    
    // hide filter determines display handling
    var hideFilterEnabled = false;

    function checkHideFilterStatus() {
        return document.body && (
            document.body.hasAttribute('data-multilang-hide-filter') ||
            document.body.classList.contains('multilang-hide-filter-active')
        );
    }

    if (document.body) {
        hideFilterEnabled = checkHideFilterStatus();
    } else {
        document.addEventListener('DOMContentLoaded', function() {
            hideFilterEnabled = checkHideFilterStatus();
        });
    }

    // bail if server-side only
    if (window.multilangLangBar && window.multilangLangBar.translationMethod === 'server') {
        return;
    }

    var translations = window.translations || (window.multilangLangBar && window.multilangLangBar.translations) || {};
    var currentLangTranslations = {};
    var defaultLangTranslations = {};
    var languageCache = {};
    var isTranslationInProgress = false;

    // caches
    var elementCache = new Map();
    var languageSpansCache = new Map();
    var performanceCache = {
        hideFilterActive: null,
        hideFilterCheckedAt: 0,
        hasWrappers: null,
        wrappersCheckedAt: 0
    };

    function invalidateCache() {
        performanceCache.hideFilterActive = null;
        performanceCache.hideFilterCheckedAt = 0;
        languageSpansCache.delete('allTranslateElements');
    }

    function isHideFilterActive() {
        const now = Date.now();
        if (performanceCache.hideFilterActive !== null && (now - performanceCache.hideFilterCheckedAt) < 1000) {
            return performanceCache.hideFilterActive;
        }

        performanceCache.hideFilterActive = hideFilterEnabled;
        performanceCache.hideFilterCheckedAt = now;
        return performanceCache.hideFilterActive;
    }

    const excludedSelectors = [
        ".no-translate",
        '[class*=code-block]',
        '.fusion-syntax-highlighter-container'
    ];

    // encode for data attributes - matches PHP side
    function encodeForDataAttr(text) {
        if (!text) return '';

        var decoded = text.replace(/&quot;/g, '"').replace(/&#39;/g, "'").replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&amp;/g, '&');

        if (!/[\u0080-\uFFFF]/.test(decoded) && !/["']/.test(decoded)) {
            return decoded.replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/'/g, '&#39;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
        }

        var json = JSON.stringify(decoded);
        var result = json.slice(1, -1).replace(/"/g, '\\u0022').replace(/'/g, '\\u0027');
        return result;
    }

    // decode data attributes
    function decodeDataAttr(dataStr) {
        if (!dataStr) return '';

        if (hideFilterEnabled) {
            const textarea = document.createElement('textarea');
            textarea.innerHTML = dataStr;
            return textarea.value;
        } else {
            try {
                const decoded = JSON.parse('"' + dataStr + '"');
                return decoded;
            } catch (e) {
                const textarea = document.createElement('textarea');
                textarea.innerHTML = dataStr;
                let result = textarea.value;

                result = result.replace(/\\u([0-9a-fA-F]{4})/g, function(match, code) {
                    return String.fromCharCode(parseInt(code, 16));
                });

                result = result.replace(/&quot;/g, '"')
                    .replace(/&lt;/g, '<')
                    .replace(/&gt;/g, '>')
                    .replace(/&amp;/g, '&');

                return result;
            }
        }
    }

    function isHTMLContent(content) {
        if (!content || typeof content !== 'string') return false;

        return content.includes('<') ||
            content.includes('&lt;') ||
            content.includes('&gt;') ||
            content.includes('&quot;') ||
            content.includes('&#');
    }

    function safeInsertContent(element, content) {
        if (isHTMLContent(content)) {
            element.innerHTML = content;
        } else {
            element.textContent = content;
        }
    }

    // Helper function to get the language of an element from its classes
    function getElementLanguage(element) {
        const classes = element.className;
        const match = classes.match(/\blang-(\w+)\b/);
        return match ? match[1] : null;
    }

    // get lang file from preloaded data
    function loadLanguageFile(lang, callback) {
        if (languageCache[lang]) {
            callback(languageCache[lang]);
            return;
        }

        var languageFiles = window.multilangLangBar ? window.multilangLangBar.languageFiles : {};
        if (!languageFiles) {
            callback({});
            return;
        }

        if (languageFiles[lang]) {
            languageCache[lang] = languageFiles[lang];
            callback(languageFiles[lang]);
        } else {
            languageCache[lang] = {};
            callback({});
        }
    }

    // init early to prevent flash
    function initializeImmediately() {
        var languageFiles = window.multilangLangBar && window.multilangLangBar.languageFiles ? window.multilangLangBar.languageFiles : {};
        if (languageFiles) {
            Object.keys(languageFiles).forEach(function(lang) {
                languageCache[lang] = languageFiles[lang];
            });
        }

        var currentLang = document.documentElement.getAttribute('data-lang') ||
            document.documentElement.getAttribute('lang') ||
            document.body.getAttribute('lang') ||
            'en';
        var defaultLang = window.defaultLanguage || 'en';

        currentLangTranslations = languageCache[currentLang] || {};
        defaultLangTranslations = languageCache[defaultLang] || {};

        setupLanguageSwitching();
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', runTranslations);
        } else {
            runTranslations();
        }
       //runTranslations();

        document.addEventListener('multilangBarReady', function(e) {
            setupLanguageSwitching();
        });

        setTimeout(function() {
            var flags = document.querySelectorAll('.multilang-flags');
            if (flags.length > 0) {
                setupLanguageSwitching();
            }
        }, 2000);
    }

    setTimeout(initializeImmediately, 100);

    function setupLanguageSwitching() {
        setTimeout(function() {
            // try to find language links if not already found
            if (jQuery('.multilang-flags li').length === 0) {
                jQuery('ul').each(function() {
                    var links = jQuery(this).find('a[hreflang]');
                    if (links.length > 0) {
                        jQuery(this).addClass('multilang-flags');
                    }
                });
            }
            var handlerCount = 0;
            jQuery('.multilang-flags li').each(function() {
                if (jQuery(this).data('translate-handler-attached')) {
                    return;
                }

                jQuery(this).off('click.translate');

                jQuery(this).on('click.translate', function(e) {
                    e.preventDefault();

                    if (isTranslationInProgress) {
                        return;
                    }

                    var lang = jQuery(this).find('a').attr('hreflang');

                    if (typeof window.normalizeLang === 'function') {
                        lang = window.normalizeLang(lang);
                    } else {
                        lang = lang ? lang.toLowerCase() : null;
                    }

                    if (!lang) return;

                    isTranslationInProgress = true;

                    currentLangTranslations = languageCache[lang] || {};
                    var defaultLang = window.defaultLanguage || 'en';
                    defaultLangTranslations = languageCache[defaultLang] || {};

                    switchToLanguage(lang);
                    isTranslationInProgress = false;
                });

                jQuery(this).data('translate-handler-attached', true);
                handlerCount++;
            });

            if (handlerCount === 0) {
                setTimeout(function() {
                    var flags = document.querySelectorAll('.multilang-flags');
                    if (flags.length > 0) {
                        setupLanguageSwitching();
                    }
                }, 2000);
            }
        }, 100);

        observeLangTags();
    }

    function switchToLanguage(lang) {
        document.documentElement.setAttribute("data-lang", lang);
        document.documentElement.setAttribute("lang", lang);
        document.body.setAttribute("lang", lang);

        if (window.setLangCookie) {
            window.setLangCookie(lang);
        }

        updateLanguageDisplay(lang);
        runTranslations();
    }

    function runTranslations() {
        if (!currentLangTranslations && !defaultLangTranslations) {
            return;
        }

        convertAllLangTags();

        var structureData = window.multilangLangBar && window.multilangLangBar.structureData ? window.multilangLangBar.structureData : {};

        // group sections by selectors
        var selectorGroups = {};
        
        Object.keys(structureData).forEach(function(sectionName) {
            var sectionConfig = structureData[sectionName];
            if (sectionConfig && typeof sectionConfig === 'object' && sectionConfig['_selectors']) {
                var sectionMethod = sectionConfig['_method'] || 'server';
                if (sectionMethod === 'javascript') {
                    var selectors = sectionConfig['_selectors'];
                    if (Array.isArray(selectors) && selectors.length > 0) {
                        var selectorKey = selectors.join('|||');
                        
                        if (!selectorGroups[selectorKey]) {
                            selectorGroups[selectorKey] = {
                                selectors: selectors,
                                sections: []
                            };
                        }
                        
                        selectorGroups[selectorKey].sections.push(sectionName);
                    }
                }
            }
        });

        // Process each selector group (merge translations from all sections with same selectors)
        Object.keys(selectorGroups).forEach(function(selectorKey) {
            var group = selectorGroups[selectorKey];
            var selectors = group.selectors;
            var sections = group.sections;
            
            // Merge translations from all sections in this group
            var mergedTranslations = {};
            
            // Get all available languages
            var availableLanguages = [];
            if (window.multilangLangBar && window.multilangLangBar.languageFiles) {
                availableLanguages = Object.keys(window.multilangLangBar.languageFiles);
            }
            
            availableLanguages.forEach(function(lang) {
                var langData = languageCache[lang] || {};
                
                if (!mergedTranslations[lang]) {
                    mergedTranslations[lang] = {};
                }
                
                sections.forEach(function(sectionName) {
                    if (langData[sectionName] && typeof langData[sectionName] === 'object') {
                        var sectionData = langData[sectionName];
                        Object.keys(sectionData).forEach(function(key) {
                            if (!key.startsWith('_')) {
                                if (!mergedTranslations[lang][key]) {
                                    mergedTranslations[lang][key] = sectionData[key];
                                }
                            }
                        });
                    }
                });
            });
            
            if (Object.keys(mergedTranslations).length > 0) {
                translateLang(mergedTranslations, selectors);
            }
        });

        var currentLang = document.documentElement.getAttribute('data-lang') ||
            document.documentElement.getAttribute('lang') ||
            document.body.getAttribute('lang') ||
            'en';
        updateLanguageDisplay(currentLang);
        
    }

    function convertAllLangTags() {
        var translateElements = document.querySelectorAll('[class*="translate-"], translate-en, translate-de, translate-fr, translate-es, translate-pt, translate-it, translate-nl, translate-pl, translate-ru, translate-zh, translate-ja, translate-ko, translate-ar, translate-hi');
        var tags = new Set();

        for (let i = 0; i < translateElements.length; i++) {
            var tagName = translateElements[i].tagName.toLowerCase();
            if (tagName.indexOf('translate-') === 0) {
                tags.add(tagName);
            }
        }

        var customTranslateTags = document.querySelectorAll('*');
        for (let i = 0; i < customTranslateTags.length; i++) {
            var tagName = customTranslateTags[i].tagName.toLowerCase();
            if (tagName.indexOf('translate-') === 0) {
                tags.add(tagName);
                break;
            }
        }

        if (tags.size > 0) {
            convertLangTags(Array.from(tags).join(', '));
        }
    }

    function convertLangTags(tags) {
        jQuery(tags).each(function() {
            if (jQuery(this).data('converted')) return;
            var tagName = this.tagName.toLowerCase();
            jQuery(this).replaceWith(
                jQuery('<span>')
                .addClass('translate')
                .addClass('lang-' + tagName.replace('translate-', ''))
                .html(jQuery(this).html())
                .data('converted', true)
            );
        });
    }

    function translateWidget(trn, target) {
        const observer = new MutationObserver(function(mutations, obs) {
            const wgt = document.querySelector(target);
            if (wgt) {
                translateLang(trn, [wgt]);
                if (wgt.querySelector('.translate')) {
                    obs.disconnect();
                    window.removeEventListener('load', cancelObserver);
                }
            }
        });

        function cancelObserver() {
            observer.disconnect();
        }

        observer.observe(document.body, {
            childList: true,
            subtree: true
        });

        if (document.readyState === "complete") {
            cancelObserver();
        } else {
            window.addEventListener('load', cancelObserver);
        }
        const wgt = document.querySelector(target);
        if (wgt && wgt.querySelector('.translate')) {
            translateLang(trn, [wgt]);
            cancelObserver();
        }
    }

    // Function to update language display when switching languages (ultra-optimized v3 - no RAF)
    function updateLanguageDisplay(newLang) {
        // Updated selector to handle multiple patterns:
        // 1. .translate elements with lang- classes
        // 2. Any elements with lang- classes (server-side generated)
        // 3. Elements with lang- classes nested inside .translate elements
        const allElements = document.querySelectorAll('.translate[class*="lang-"], [class*="lang-"], .translate [class*="lang-"]');

        for (let i = 0; i < allElements.length; i++) {
            const element = allElements[i];
            // Get language from class
            const match = element.className.match(/lang-([a-z]{2})/);
            if (!match) continue;
            const elementLang = match[1];

            // Ensure data-default-text is set/updated on every span
            const wrapper = element.closest('.multilang-wrapper');
            let defaultText = '';
            if (wrapper) {
                // Try to get from wrapper attribute first
                defaultText = wrapper.getAttribute('data-default-text') || '';
                if (!defaultText) {
                    var originalText = wrapper.getAttribute('data-original-text');
                    var defaultLang = window.defaultLanguage || 'en';
                    if (window.multilangLangBar && window.multilangLangBar.languageFiles && window.multilangLangBar.languageFiles[defaultLang]) {
                        var langData = window.multilangLangBar.languageFiles[defaultLang];
                        if (langData[originalText]) {
                            defaultText = langData[originalText];
                        } else {
                            for (var cat in langData) {
                                if (langData.hasOwnProperty(cat) && typeof langData[cat] === 'object') {
                                    if (langData[cat][originalText]) {
                                        defaultText = langData[cat][originalText];
                                        break;
                                    }
                                }
                            }
                        }
                    }
                }
            }
            element.setAttribute('data-default-text', defaultText);

            const dataTranslation = element.getAttribute('data-translation');
            if (dataTranslation && !element.hasAttribute('data-decoded')) {
                if (hideFilterEnabled) {
                    if (elementLang === newLang) {
                        let decodedContent = decodeDataAttr(dataTranslation);
                        if (!decodedContent || decodedContent === dataTranslation) {
                            decodedContent = dataTranslation.replace(/\\u([0-9a-fA-F]{4})/g, function(match, hex) {
                                return String.fromCharCode(parseInt(hex, 16));
                            });
                        }
                        element.innerHTML = decodedContent;
                        element.setAttribute('data-decoded', 'true');
                    }
                } else {
                    let decodedContent = decodeDataAttr(dataTranslation);
                    if (!decodedContent || decodedContent === dataTranslation) {
                        decodedContent = dataTranslation.replace(/\\u([0-9a-fA-F]{4})/g, function(match, hex) {
                            return String.fromCharCode(parseInt(hex, 16));
                        });
                    }
                    element.innerHTML = decodedContent;
                    element.setAttribute('data-decoded', 'true');
                }
            }

            if (element.classList.contains('translate') || element.closest('.translate')) {
                if (elementLang === newLang) {
                    element.style.display = '';
                } else {
                    element.style.display = 'none';
                }
            }
        }

        document.querySelectorAll('[data-multilang-button-wrapped]').forEach(function(button) {
            var translationKey = 'data-text-' + newLang;
            if (button.hasAttribute(translationKey)) {
                button.textContent = button.getAttribute(translationKey);
            }
        });

        // Update input values for all inputs with value-xx attributes
        document.querySelectorAll('input[value][value-' + newLang + ']').forEach(function(input) {
            var newValue = input.getAttribute('value-' + newLang);
            if (typeof newValue === 'string') {
                input.value = newValue;
            }
        });
        // Optionally, reset to default if no value-xx exists
        document.querySelectorAll('input[value]').forEach(function(input) {
            if (!input.hasAttribute('value-' + newLang)) {
                var defaultValue = input.getAttribute('value');
                if (typeof defaultValue === 'string') {
                    input.value = defaultValue;
                }
            }
        });
    }

    function findTranslationInData(text, translationData) {
        if (!translationData || typeof translationData !== 'object') return null;

        if (translationData[text]) {
            return translationData[text];
        }

        for (var category in translationData) {
            if (translationData.hasOwnProperty(category) && typeof translationData[category] === 'object') {
                if (translationData[category][text]) {
                    return translationData[category][text];
                }
            }
        }

        return null;
    }

    function translateLang(sectionTranslations, targets) {
        targets = targets || ['body'];
        if (!sectionTranslations) {
            return;
        }

        cleanupNestedWrappers();

        var elements = [];
        targets.forEach(function(t) {
            if (typeof t === 'string') {
                var found = Array.from(document.querySelectorAll(t));
                elements = elements.concat(found);
            } else if (t instanceof Element) elements.push(t);
            else if (t instanceof NodeList || Array.isArray(t)) elements = elements.concat(Array.from(t));
        });

        elements = elements.filter(el => {
            return !excludedSelectors.some(sel => el.closest(sel)) &&
                !el.closest('.multilang-wrapper') &&
                !el.classList.contains('translate') &&
                !el.hasAttribute('data-multilang-processed');
        });
        
        var translationCache = {};
        var currentLang = document.documentElement.getAttribute('data-lang') ||
            document.documentElement.getAttribute('lang') ||
            document.body.getAttribute('lang') ||
            'en';
        var defaultLang = window.defaultLanguage || 'en';
        var availableLanguages = sectionTranslations ? Object.keys(sectionTranslations) : [];

        function translateText(text, sectionTranslations) {
            if (!text.trim()) return text;
            
            if (translationCache[text]) {
                return translationCache[text];
            }

            var allTranslations = {};
            availableLanguages.forEach(function(lang) {
                var langTranslations = sectionTranslations[lang] || {};
                if (langTranslations[text]) {
                    allTranslations[lang] = langTranslations[text];
                }
            });

            if (Object.keys(allTranslations).length > 0) {
                var allSpans = '';
                var hasCurrentLangTranslation = false;
                
                var defaultLangTranslation = allTranslations[defaultLang] || text;

                availableLanguages.forEach(function(lang) {
                    var display = '';
                    if (!hideFilterEnabled) {
                        display = (lang === currentLang) ? '' : 'none';
                    }

                    if (lang === currentLang && allTranslations[lang]) {
                        hasCurrentLangTranslation = true;
                    }

                    var translation = allTranslations[lang] || defaultLangTranslation;
                    var encoded_content = encodeForDataAttr(translation);
                    
                    if (hideFilterEnabled) {
                        allSpans += '<span class="translate lang-' + lang + '" data-translation="' + encoded_content + '">' + translation + '</span>';
                    } else {
                        allSpans += '<span class="translate lang-' + lang + '" data-translation="' + encoded_content + '" style="display: ' + display + ';">' + translation + '</span>';
                    }
                });

                return allSpans || text;
            }

            // Token-based fallback - check individual words but only in section translations
            var tokens = text.match(/[\p{L}\p{N}]+|[^\p{L}\p{N}\s]+/gu) || [];
            var result = '';
            var lastIndex = 0;
            var hasAnyTranslation = false;

            tokens.forEach(function(token) {
                var idx = text.indexOf(token, lastIndex);
                if (idx > lastIndex) result += text.slice(lastIndex, idx);

                // Check token in section translations for all languages
                var tokenTranslations = {};
                availableLanguages.forEach(function(lang) {
                    var langTranslations = sectionTranslations[lang] || {};
                    if (langTranslations[token]) {
                        tokenTranslations[lang] = langTranslations[token];
                    }
                });

                if (Object.keys(tokenTranslations).length > 0) {
                    hasAnyTranslation = true;
                    var tokenSpans = '';
                    var hasCurrentLangTokenTranslation = false;
                    
                    // Get the clean token (without punctuation) for data-original-text
                    var originalToken = token.replace(/^[^\p{L}\p{N}]+|[^\p{L}\p{N}]+$/gu, '');
                    
                    // Get default language translation for fallback
                    var defaultTokenTranslation = tokenTranslations[defaultLang] || token;

                    // Create spans for ALL available languages (not just ones with translations)
                    availableLanguages.forEach(function(lang) {
                        var display = '';
                        if (!hideFilterEnabled) {
                            // Only add display styles if hide filter is NOT active
                            display = (lang === currentLang) ? '' : 'none';
                        }

                        if (lang === currentLang && tokenTranslations[lang]) {
                            hasCurrentLangTokenTranslation = true;
                        }

                        // Use actual translation if exists, otherwise use default language translation
                        var translation = tokenTranslations[lang] || defaultTokenTranslation;
                        var encoded_content = encodeForDataAttr(translation);

                        if (hideFilterEnabled) {
                            // When hide filter is active, don't add display styles - let PHP handle it
                            tokenSpans += '<span class="translate lang-' + lang + '" data-translation="' + encoded_content + '" data-original-text="' + originalToken + '">' + translation + '</span>';
                        } else {
                            // When hide filter is not active, use display styles
                            tokenSpans += '<span class="translate lang-' + lang + '" data-translation="' + encoded_content + '" data-original-text="' + originalToken + '" style="display: ' + display + ';">' + translation + '</span>';
                        }
                    });

                    result += tokenSpans || token;
                } else {
                    result += token;
                }

                lastIndex = idx + token.length;
            });

            if (lastIndex < text.length) result += text.slice(lastIndex);
            
            // Cache the result before returning
            var finalResult = hasAnyTranslation ? result : text;
            translationCache[text] = finalResult;
            return finalResult;
        }

        function wrapTextNodes(element) {
            const skipTags = ['SCRIPT', 'STYLE', 'CODE', 'PRE'];
            if (skipTags.includes(element.tagName)) return;
            if (excludedSelectors.some(sel => element.closest(sel))) {
                return; // don't process this element or children
            }
            if (
                element.classList.contains('token') ||
                String(element.className).toLowerCase().includes('code')
            ) return;

            // CRITICAL FIX: Skip if we're already inside a multilang-wrapper or translate element
            if (element.closest('.multilang-wrapper') || element.closest('.translate')) {
                return;
            }

            // Convert to array to prevent live NodeList issues
            Array.from(element.childNodes).forEach(function(node) {
                if (node.nodeType === Node.TEXT_NODE) {
                    var originalText = node.textContent.trim();
                    if (!originalText) return; // Skip empty text nodes

                    var translated = translateText(originalText, sectionTranslations);
                    if (translated !== originalText) {
                        
                        // Skip WooCommerce buttons - they'll be handled separately
                        if (element.classList.contains('wc-block-components-button') || 
                            element.closest('.wc-block-components-button')) {
                            return;
                        }
                        
                        var wrapper = document.createElement('span');
                        wrapper.innerHTML = translated;
                        
                        // For partial translations, use the first child translate span's data-original-text
                        // Otherwise use the full originalText
                        var firstTranslateSpan = wrapper.querySelector('.translate[data-original-text]');
                        var wrapperOriginalText = firstTranslateSpan ? 
                            firstTranslateSpan.getAttribute('data-original-text') : 
                            originalText;
                        
                        wrapper.setAttribute('data-original-text', wrapperOriginalText);
                        // Always set data-default-text, even if empty
                        var defaultLang = window.defaultLanguage || 'en';
                        var defaultText = '';
                        if (sectionTranslations && sectionTranslations[defaultLang]) {
                            // Try direct key
                            if (sectionTranslations[defaultLang][wrapperOriginalText]) {
                                defaultText = sectionTranslations[defaultLang][wrapperOriginalText];
                            } else {
                                // Try to find translation in nested categories
                                for (var cat in sectionTranslations[defaultLang]) {
                                    if (sectionTranslations[defaultLang].hasOwnProperty(cat) && typeof sectionTranslations[defaultLang][cat] === 'object') {
                                        if (sectionTranslations[defaultLang][cat][wrapperOriginalText]) {
                                            defaultText = sectionTranslations[defaultLang][cat][wrapperOriginalText];
                                            break;
                                        }
                                    }
                                }
                            }
                        }
                        wrapper.setAttribute('data-default-text', defaultText);
                        wrapper.className = 'multilang-wrapper';

                        // Store original text on all child translation spans too
                        wrapper.querySelectorAll('.translate[class*="lang-"]').forEach(function(span) {
                            // IMPORTANT: Only set data-original-text if it's not already set
                            // Token-based translations already have the correct word-level data-original-text
                            if (!span.hasAttribute('data-original-text')) {
                                span.setAttribute('data-original-text', wrapperOriginalText);
                            }
                            
                            // Get the span's own data-original-text for data-default-text lookup
                            var spanOriginalText = span.getAttribute('data-original-text') || wrapperOriginalText;
                            
                            // Always set or update data-default-text on each span
                            var defaultLang = window.defaultLanguage || 'en';
                            var defaultText = '';
                            if (sectionTranslations && sectionTranslations[defaultLang]) {
                                if (sectionTranslations[defaultLang][spanOriginalText]) {
                                    defaultText = sectionTranslations[defaultLang][spanOriginalText];
                                } else {
                                    for (var cat in sectionTranslations[defaultLang]) {
                                        if (sectionTranslations[defaultLang].hasOwnProperty(cat) && typeof sectionTranslations[defaultLang][cat] === 'object') {
                                            if (sectionTranslations[defaultLang][cat][spanOriginalText]) {
                                                defaultText = sectionTranslations[defaultLang][cat][spanOriginalText];
                                                break;
                                            }
                                        }
                                    }
                                }
                            }
                            span.setAttribute('data-default-text', defaultText);
                            // Check if hide filter is active
                            const isHideFilterActive = document.body.hasAttribute('data-multilang-hide-filter') ||
                                document.body.classList.contains('multilang-hide-filter-active');
                            if (isHideFilterActive) {
                                // Add data-translation attribute with matching PHP encoding and clear non-current spans
                                if (span.innerHTML && span.innerHTML.trim()) {
                                    var encoded_content = encodeForDataAttr(span.innerHTML.trim());
                                    span.setAttribute('data-translation', encoded_content);
                                    // Get current language and span language
                                    var currentLang = document.documentElement.getAttribute('data-lang') ||
                                        document.documentElement.getAttribute('lang') ||
                                        document.body.getAttribute('lang') ||
                                        'en';
                                    var spanLang = getElementLanguage(span);
                                    // If this span is NOT the current language, empty its content
                                    if (spanLang && spanLang !== currentLang) {
                                        span.innerHTML = '';
                                    }
                                }
                            }
                        });

                        node.replaceWith(wrapper);
                        // console.log('Created translation wrapper for:', originalText.substring(0, 30));
                    }
                } else if (node.nodeType === Node.ELEMENT_NODE) {
                    // Don't recurse into elements that are already translated
                    if (!node.closest('.multilang-wrapper') && !node.closest('.translate')) {
                        wrapTextNodes(node);
                    }
                    ['title', 'data-title', 'alt'].forEach(function(attr) {
                        if (node.hasAttribute(attr)) {
                            node.setAttribute(attr, translateText(node.getAttribute(attr), sectionTranslations));
                        }
                    });
                }
            });
        }

        // Process all elements at once for faster translation
        elements.forEach(function(el) {
            el.setAttribute('data-multilang-processed', 'true');
            wrapTextNodes(el);
            // Override CSS visibility rule after translation
            el.style.setProperty('visibility', 'visible', 'important');
        });
        
        // Handle WooCommerce buttons after all elements are processed
        wrapWooCommerceButtons(elements, sectionTranslations);
    }
    
    // Special handling for WooCommerce buttons to avoid React conflicts
    function wrapWooCommerceButtons(elements, sectionTranslations) {
        elements.forEach(function(el) {
            var buttons = el.querySelectorAll('.wc-block-components-button');
            
            buttons.forEach(function(button) {
                if (button.hasAttribute('data-multilang-button-wrapped')) return;
                
                var buttonText = button.textContent.trim();
                if (!buttonText) return;
                
                var currentLang = document.documentElement.getAttribute('data-lang') || 'en';
                var availableLanguages = sectionTranslations ? Object.keys(sectionTranslations) : [];
                
                var allTranslations = {};
                availableLanguages.forEach(function(lang) {
                    var langTranslations = sectionTranslations[lang] || {};
                    if (langTranslations[buttonText]) {
                        allTranslations[lang] = langTranslations[buttonText];
                    }
                });
                
                if (Object.keys(allTranslations).length === 0) return;
                
                // Remove any multilang spans
                button.querySelectorAll('.multilang-wrapper, .translate').forEach(function(span) {
                    span.replaceWith(document.createTextNode(span.textContent));
                });
                
                // Store translations in data attributes
                button.setAttribute('data-multilang-button-wrapped', 'true');
                button.setAttribute('data-original-text', buttonText);
                
                availableLanguages.forEach(function(lang) {
                    if (allTranslations[lang]) {
                        button.setAttribute('data-text-' + lang, allTranslations[lang]);
                    }
                });
                
                // Set current language text
                if (allTranslations[currentLang]) {
                    button.textContent = allTranslations[currentLang];
                }
            });
        });
    }

    // Function to clean up nested multilang-wrapper structures
    function cleanupNestedWrappers() {
        const nestedWrappers = document.querySelectorAll('.multilang-wrapper .multilang-wrapper');

        nestedWrappers.forEach(function(nestedWrapper) {
            // Get the original text from the outermost wrapper
            const outerWrapper = nestedWrapper.closest('.multilang-wrapper');
            const originalText = outerWrapper.getAttribute('data-original-text');

            // Replace the nested structure with just the original text
            if (originalText) {
                const textNode = document.createTextNode(originalText);
                nestedWrapper.replaceWith(textNode);
            }
        });

        // Also clean up any nested translate spans within translate spans
        const nestedTranslateSpans = document.querySelectorAll('.translate .translate');
        nestedTranslateSpans.forEach(function(nestedSpan) {
            const parent = nestedSpan.parentElement;
            if (parent.classList.contains('translate')) {
                // Remove the nested span and keep only its text content
                nestedSpan.replaceWith(document.createTextNode(nestedSpan.textContent));
            }
        });
    }

    // Observe new <translate-*> tags
    function observeLangTags(root) {
        root = root || document.body;
        var observer = new MutationObserver(function(mutations) {
            mutations.forEach(function(mutation) {
                mutation.addedNodes.forEach(function(node) {
                    if (node.nodeType !== Node.ELEMENT_NODE) return;
                    var newTags = node.querySelectorAll('*');
                    var tagsToConvert = Array.from(newTags).filter(function(el) {
                        return el.tagName.toLowerCase().indexOf('translate-') === 0 && !jQuery(el).data('converted');
                    });
                    if (node.tagName.toLowerCase().indexOf('translate-') === 0 && !jQuery(node).data('converted')) {
                        tagsToConvert.unshift(node);
                    }
                    if (tagsToConvert.length) convertLangTags(tagsToConvert);
                });
            });
        });
        observer.observe(root, {
            childList: true,
            subtree: true
        });
    }

    // Observe new elements and make them visible (for AJAX-loaded content)
    function observeNewElements() {
        var structureData = window.multilangLangBar && window.multilangLangBar.structureData ? window.multilangLangBar.structureData : {};
        var jsSelectors = [];
        
        // Get all selectors that use JavaScript translation
        Object.keys(structureData).forEach(function(sectionName) {
            var sectionConfig = structureData[sectionName];
            if (sectionConfig && typeof sectionConfig === 'object') {
                var sectionMethod = sectionConfig['_method'] || 'server';
                if (sectionMethod === 'javascript' && sectionConfig['_selectors']) {
                    var selectors = sectionConfig['_selectors'];
                    if (Array.isArray(selectors)) {
                        jsSelectors = jsSelectors.concat(selectors);
                    }
                }
            }
        });
        
        if (jsSelectors.length === 0) return;
        
        var observer = new MutationObserver(function(mutations) {
            mutations.forEach(function(mutation) {
                mutation.addedNodes.forEach(function(node) {
                    if (node.nodeType !== Node.ELEMENT_NODE) return;
                    
                    // Check if this node or its children match any JS selectors
                    jsSelectors.forEach(function(selector) {
                        try {
                            var elements = [];
                            
                            // Check if the node itself matches
                            if (node.matches && node.matches(selector)) {
                                elements.push(node);
                            }
                            
                            // Find matching children
                            if (node.querySelectorAll) {
                                var children = node.querySelectorAll(selector);
                                elements = elements.concat(Array.from(children));
                            }
                            
                            // Make them visible
                            elements.forEach(function(el) {
                                el.style.setProperty('visibility', 'visible', 'important');
                            });
                        } catch (e) {
                            // Invalid selector, skip
                        }
                    });
                });
            });
        });
        
        observer.observe(document.body, {
            childList: true,
            subtree: true
        });
    }
    
    // Initialize the observer after DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', observeNewElements);
    } else {
        observeNewElements();
    }

    // Remove invalid excerpts without translation
    function processExcerpt(p) {
        if (p.dataset.checked === "true") return;
        var hasTranslateClass = p.querySelector('.translate');
        var hasTranslateTag = Array.from(p.querySelectorAll('*')).some(function(el) {
            return el.tagName.toLowerCase().indexOf('translate-') === 0;
        });
        if (!hasTranslateClass && !hasTranslateTag) p.remove();
        else p.dataset.checked = "true";
    }

})();