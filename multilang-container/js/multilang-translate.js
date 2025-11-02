/* -------------------- LANGUAGE TRANSLATION -------------------- */

(() => {
    'use strict';

    // Check if hide filter is active - this determines how we handle display styles
    var hideFilterEnabled = false;

    function checkHideFilterStatus() {
        return document.body && (
            document.body.hasAttribute('data-multilang-hide-filter') ||
            document.body.classList.contains('multilang-hide-filter-active')
        );
    }

    // Set initial status and update when body becomes available
    if (document.body) {
        hideFilterEnabled = checkHideFilterStatus();
    } else {
        document.addEventListener('DOMContentLoaded', function() {
            hideFilterEnabled = checkHideFilterStatus();
        });
    }

    // Check if JavaScript translation is enabled for any section
    if (window.multilangLangBar && window.multilangLangBar.translationMethod === 'server') {
        return;
    }

    var translations = window.translations || (window.multilangLangBar && window.multilangLangBar.translations) || {};
    var currentLangTranslations = {};
    var defaultLangTranslations = {};
    var languageCache = {}; // Cache for loaded language files
    var isTranslationInProgress = false; // Flag to prevent concurrent translations

    // Performance optimization - global caches
    var elementCache = new Map(); // Cache DOM elements
    var languageSpansCache = new Map(); // Cache language spans
    var performanceCache = {
        hideFilterActive: null,
        hideFilterCheckedAt: 0,
        hasWrappers: null,
        wrappersCheckedAt: 0
    };

    // Cache invalidation helper (optimized)
    function invalidateCache() {
        // Only invalidate what's necessary
        performanceCache.hideFilterActive = null;
        performanceCache.hideFilterCheckedAt = 0;
        // Keep wrapper cache and element cache for performance
        languageSpansCache.delete('allTranslateElements'); // Only clear element cache
    }

    // Fast hide filter check with caching
    function isHideFilterActive() {
        const now = Date.now();
        if (performanceCache.hideFilterActive !== null && (now - performanceCache.hideFilterCheckedAt) < 1000) {
            return performanceCache.hideFilterActive;
        }

        // Use our global variable instead of checking DOM each time
        performanceCache.hideFilterActive = hideFilterEnabled;
        performanceCache.hideFilterCheckedAt = now;
        return performanceCache.hideFilterActive;
    }

    const excludedSelectors = [
        ".no-translate",
        '[class*=code-block]',
        '.fusion-syntax-highlighter-container'
    ];

    // Helper function to encode content for data attributes (matches PHP encoding from _new plugin)
    function encodeForDataAttr(text) {
        if (!text) return '';

        // Decode any existing HTML entities first
        var decoded = text.replace(/&quot;/g, '"').replace(/&#39;/g, "'").replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&amp;/g, '&');

        // Check if text is simple (no special chars or quotes)
        if (!/[\u0080-\uFFFF]/.test(decoded) && !/["']/.test(decoded)) {
            // Simple HTML escaping for basic text
            return decoded.replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/'/g, '&#39;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
        }

        // For complex content, use JSON encoding (matches PHP JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_QUOT | JSON_HEX_APOS)
        var json = JSON.stringify(decoded);
        // Remove surrounding quotes and apply hex encoding for quotes/apostrophes
        var result = json.slice(1, -1).replace(/"/g, '\\u0022').replace(/'/g, '\\u0027');
        return result;
    }

    // Function to decode data attribute content (matches PHP encoding from hide filter)
    function decodeDataAttr(dataStr) {
        if (!dataStr) return '';

        // Use our global variable instead of checking DOM
        if (hideFilterEnabled) {
            // Hide filter uses htmlspecialchars, so decode HTML entities
            const textarea = document.createElement('textarea');
            textarea.innerHTML = dataStr;
            return textarea.value;
        } else {
            // Use the _new plugin style decoding for JavaScript-generated content
            try {
                // First try direct JSON parse
                const decoded = JSON.parse('"' + dataStr + '"');
                return decoded;
            } catch (e) {
                // Fallback: manually decode HTML entities and Unicode escapes
                const textarea = document.createElement('textarea');
                textarea.innerHTML = dataStr;
                let result = textarea.value;

                // Decode Unicode escapes like \u0022
                result = result.replace(/\\u([0-9a-fA-F]{4})/g, function(match, code) {
                    return String.fromCharCode(parseInt(code, 16));
                });

                // Decode HTML entities properly
                result = result.replace(/&quot;/g, '"')
                    .replace(/&lt;/g, '<')
                    .replace(/&gt;/g, '>')
                    .replace(/&amp;/g, '&');

                return result;
            }
        }
    }

    // Helper function to detect if content contains HTML
    function isHTMLContent(content) {
        if (!content || typeof content !== 'string') return false;

        // Check for HTML tags or encoded HTML
        return content.includes('<') ||
            content.includes('&lt;') ||
            content.includes('&gt;') ||
            content.includes('&quot;') ||
            content.includes('&#');
    }

    // Helper function to safely insert content as HTML or text
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

    // Function to get individual language file from pre-loaded data with caching
    function loadLanguageFile(lang, callback) {
        // Check cache first
        if (languageCache[lang]) {
            // console.log('Using cached translations for', lang);
            callback(languageCache[lang]);
            return;
        }

        var languageFiles = window.multilangLangBar ? window.multilangLangBar.languageFiles : {};
        if (!languageFiles) {
            callback({});
            return;
        }

        if (languageFiles[lang]) {
            // console.log('Loaded translations for', lang + ':', languageFiles[lang]);
            // Cache the language data
            languageCache[lang] = languageFiles[lang];
            callback(languageFiles[lang]);
        } else {
            // console.log('No translations found for language:', lang);
            // Cache empty object to avoid repeated lookups
            languageCache[lang] = {};
            callback({});
        }
    }

    // Initialize translations immediately without waiting for DOMContentLoaded to prevent flashing
    function initializeImmediately() {
        // Preload all available language files into cache
        var languageFiles = window.multilangLangBar && window.multilangLangBar.languageFiles ? window.multilangLangBar.languageFiles : {};
        if (languageFiles) {
            Object.keys(languageFiles).forEach(function(lang) {
                languageCache[lang] = languageFiles[lang];
            });
        }

        // Load individual language files instead of using window.translations
        var currentLang = document.documentElement.getAttribute('data-lang') ||
            document.documentElement.getAttribute('lang') ||
            document.body.getAttribute('lang') ||
            'en';
        var defaultLang = window.defaultLanguage || 'en';

        // Set current language translations from cache
        currentLangTranslations = languageCache[currentLang] || {};
        defaultLangTranslations = languageCache[defaultLang] || {};

        setupLanguageSwitching();
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', runTranslations);
        } else {
            runTranslations();
        }

        // Listen for language bar ready event
        document.addEventListener('multilangBarReady', function(e) {
            setupLanguageSwitching();
        });

        // Also add a longer timeout to catch language bar creation
        setTimeout(function() {
            var flags = document.querySelectorAll('.multilang-flags');
            if (flags.length > 0) {
                setupLanguageSwitching();
            }
        }, 2000);
    }

    // Run initialization immediately
    setTimeout(initializeImmediately, 100);

    function setupLanguageSwitching() {
        // Don't create our own language bar - use the one from multilang-container.js
        // Just add our click handlers to the existing language bar
        setTimeout(function() {
            // Wait a bit for multilang-container.js to create the language bar

            // If we still don't find anything, let's try a more aggressive search
            if (jQuery('.multilang-flags li').length === 0) {
                // Look for ULs that might contain language links
                jQuery('ul').each(function() {
                    var links = jQuery(this).find('a[hreflang]');
                    if (links.length > 0) {
                        jQuery(this).addClass('multilang-flags');
                    }
                });
            }
            var handlerCount = 0;
            jQuery('.multilang-flags li').each(function() {
                // Check if our handler is already attached
                if (jQuery(this).data('translate-handler-attached')) {
                    return;
                }

                // Remove existing click handlers to avoid conflicts
                jQuery(this).off('click.translate');

                // Add our enhanced click handler
                jQuery(this).on('click.translate', function(e) {
                    // Prevent default link behavior
                    e.preventDefault();

                    // Prevent multiple concurrent translations
                    if (isTranslationInProgress) {
                        return;
                    }

                    var lang = jQuery(this).find('a').attr('hreflang');

                    // Add fallback if normalizeLang doesn't exist
                    if (typeof window.normalizeLang === 'function') {
                        lang = window.normalizeLang(lang);
                    } else {
                        // Simple normalization
                        lang = lang ? lang.toLowerCase() : null;
                    }

                    if (!lang) return;

                    isTranslationInProgress = true;

                    // Update current language data from cache
                    currentLangTranslations = languageCache[lang] || {};
                    var defaultLang = window.defaultLanguage || 'en';
                    defaultLangTranslations = languageCache[defaultLang] || {};

                    // Switch language (this will just show/hide existing spans)
                    switchToLanguage(lang);
                    isTranslationInProgress = false;
                });

                // Mark as having our handler attached
                jQuery(this).data('translate-handler-attached', true);
                handlerCount++;
            });

            // If no language bar found, let's check if multilang-container script is working
            if (handlerCount === 0) {
                setTimeout(function() {
                    // Try again after more time
                    var flags = document.querySelectorAll('.multilang-flags');
                    if (flags.length > 0) {
                        setupLanguageSwitching();
                    }
                }, 2000);
            }
        }, 100);

        observeLangTags();
    }

    // This function is no longer needed as we've moved the logic to setupLanguageSwitching and initializeImmediately

    function switchToLanguage(lang) {
        // Update document attributes
        document.documentElement.setAttribute("data-lang", lang);
        document.documentElement.setAttribute("lang", lang);
        document.body.setAttribute("lang", lang);

        // Set cookie
        if (window.setLangCookie) {
            window.setLangCookie(lang);
        }

        // Update language display
        updateLanguageDisplay(lang);

        // Always run translations to ensure JavaScript sections are processed
        runTranslations();
    }

    // Run translations immediately when script loads, not waiting for DOMContentLoaded
    function runTranslations() {
        if (!currentLangTranslations && !defaultLangTranslations) {
            return;
        }

        convertAllLangTags();

        // Get structure data from localized data
        var structureData = window.multilangLangBar && window.multilangLangBar.structureData ? window.multilangLangBar.structureData : {};

        // Process each section individually with only its own translation data
        Object.keys(structureData).forEach(function(sectionName) {
            var sectionConfig = structureData[sectionName];
            if (sectionConfig && typeof sectionConfig === 'object' && sectionConfig['_selectors']) {
                // Check if this section should use JavaScript (default to server if not set)
                var sectionMethod = sectionConfig['_method'] || 'server';
                if (sectionMethod === 'javascript') {
                    // Get this section's selectors
                    var selectors = sectionConfig['_selectors'];
                    if (Array.isArray(selectors) && selectors.length > 0) {

                        // Get translations ONLY for this specific section across all languages
                        var sectionTranslations = {};

                        // Get all available languages from the language files
                        var availableLanguages = [];
                        if (window.multilangLangBar && window.multilangLangBar.languageFiles) {
                            availableLanguages = Object.keys(window.multilangLangBar.languageFiles);
                        }

                        // For each language, extract translations only for this section
                        availableLanguages.forEach(function(lang) {
                            var langData = languageCache[lang] || {};
                            if (langData[sectionName] && typeof langData[sectionName] === 'object') {
                                // Initialize language object if not exists
                                if (!sectionTranslations[lang]) {
                                    sectionTranslations[lang] = {};
                                }

                                // Copy section-specific translations for this language
                                var sectionData = langData[sectionName];
                                Object.keys(sectionData).forEach(function(key) {
                                    if (!key.startsWith('_')) {
                                        sectionTranslations[lang][key] = sectionData[key];
                                    }
                                });
                            }
                        });

                        if (Object.keys(sectionTranslations).length > 0) {
                            // Debug: Check if selectors match any elements
                            selectors.forEach(function(selector) {
                                var matchingElements = document.querySelectorAll(selector);
                            });

                            translateLang(sectionTranslations, selectors);
                        }
                    }
                }
            }
        });

        // Set initial language display
        var currentLang = document.documentElement.getAttribute('data-lang') ||
            document.documentElement.getAttribute('lang') ||
            document.body.getAttribute('lang') ||
            'en';
        // console.log('Setting language display to:', currentLang);
        updateLanguageDisplay(currentLang);
    }

    // Convert <translate-*> tags to <span> with classes
    function convertAllLangTags() {
        // PERFORMANCE FIX: Instead of scanning ALL elements with jQuery('*'), 
        // use a targeted selector to find only translate- tags
        var translateElements = document.querySelectorAll('[class*="translate-"], translate-en, translate-de, translate-fr, translate-es, translate-pt, translate-it, translate-nl, translate-pl, translate-ru, translate-zh, translate-ja, translate-ko, translate-ar, translate-hi');
        var tags = new Set();

        // Process only the elements that might be translate tags
        for (let i = 0; i < translateElements.length; i++) {
            var tagName = translateElements[i].tagName.toLowerCase();
            if (tagName.indexOf('translate-') === 0) {
                tags.add(tagName);
            }
        }

        // Also check for any custom translate tags using a more specific approach
        var customTranslateTags = document.querySelectorAll('*');
        for (let i = 0; i < customTranslateTags.length; i++) {
            var tagName = customTranslateTags[i].tagName.toLowerCase();
            if (tagName.indexOf('translate-') === 0) {
                tags.add(tagName);
                break; // Stop after finding first one to avoid full scan
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

    // Translate a specific widget when it appears
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
                // If not present, try to compute from translations
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

            // Always decode data-translation attribute if it exists (for both server and JS entries)
            const dataTranslation = element.getAttribute('data-translation');
            if (dataTranslation && !element.hasAttribute('data-decoded')) {
                // When hide filter is active, only set innerHTML for current language elements
                if (hideFilterEnabled) {
                    if (elementLang === newLang) {
                        // Only decode and set for current language when hide filter is active
                        let decodedContent = decodeDataAttr(dataTranslation);
                        if (!decodedContent || decodedContent === dataTranslation) {
                            decodedContent = dataTranslation.replace(/\\u([0-9a-fA-F]{4})/g, function(match, hex) {
                                return String.fromCharCode(parseInt(hex, 16));
                            });
                        }
                        element.innerHTML = decodedContent;
                        // Mark as decoded so we don't decode it again
                        element.setAttribute('data-decoded', 'true');
                    }
                    // Don't set innerHTML for non-current language elements when hide filter is active
                } else {
                    // When hide filter is NOT active, set innerHTML for all elements
                    let decodedContent = decodeDataAttr(dataTranslation);
                    if (!decodedContent || decodedContent === dataTranslation) {
                        decodedContent = dataTranslation.replace(/\\u([0-9a-fA-F]{4})/g, function(match, hex) {
                            return String.fromCharCode(parseInt(hex, 16));
                        });
                    }
                    element.innerHTML = decodedContent;
                    // Mark as decoded so we don't decode it again
                    element.setAttribute('data-decoded', 'true');
                }
            }

            // Apply display styles to elements with the translate class OR elements nested inside .translate
            if (element.classList.contains('translate') || element.closest('.translate')) {
                if (elementLang === newLang) {
                    // Show this element
                    element.style.display = '';
                } else {
                    // Hide other language elements
                    element.style.display = 'none';
                }
            }
        }

        // Check for elements that don't have a translation for the current language
        // and fall back to showing the original text
        const multilingualWrappers = document.querySelectorAll('.multilang-wrapper');

        for (let i = 0; i < multilingualWrappers.length; i++) {
            const wrapper = multilingualWrappers[i];
            const currentLangElement = wrapper.querySelector('.translate.lang-' + newLang);
            const visibleElements = wrapper.querySelectorAll('.translate[class*="lang-"]:not([style*="display: none"])');

            // If no element is visible for the current language, show original text
            if (!currentLangElement || visibleElements.length === 0) {
                const originalText = wrapper.getAttribute('data-original-text');
                const defaultText = wrapper.getAttribute('data-default-text');
                if (originalText) {
                    // Hide all translation spans
                    const allTranslateSpans = wrapper.querySelectorAll('.translate[class*="lang-"]');
                    for (let j = 0; j < allTranslateSpans.length; j++) {
                        allTranslateSpans[j].style.display = 'none';
                    }

                    // Create or show a fallback span with original text
                    let fallbackSpan = wrapper.querySelector('.translate-fallback');
                    if (!fallbackSpan) {
                        fallbackSpan = document.createElement('span');
                        fallbackSpan.className = 'translate-fallback';
                        
                        // Find the last translate span and insert after it
                        const translateSpans = wrapper.querySelectorAll('.translate[class*="lang-"]');
                        if (translateSpans.length > 0) {
                            const lastTranslateSpan = translateSpans[translateSpans.length - 1];
                            // Insert after the last translate span
                            lastTranslateSpan.parentNode.insertBefore(fallbackSpan, lastTranslateSpan.nextSibling);
                        } else {
                            // No translate spans, insert at the beginning
                            wrapper.insertBefore(fallbackSpan, wrapper.firstChild);
                        }
                    }
                    if (defaultText) {
                        fallbackSpan.textContent = defaultText;
                    } else {
                        fallbackSpan.textContent = originalText;
                    }
                    fallbackSpan.style.display = '';
                } else {
                    // Hide fallback if we have translations for current language
                    const fallbackSpan = wrapper.querySelector('.translate-fallback');
                    if (fallbackSpan) {
                        fallbackSpan.style.display = 'none';
                    }
                }
            } else {
                // Hide fallback if we have translations for current language
                const fallbackSpan = wrapper.querySelector('.translate-fallback');
                if (fallbackSpan) {
                    fallbackSpan.style.display = 'none';
                }
            }
        }
    }

    // Helper function to find translation in language data structure (make it available globally)
    function findTranslationInData(text, translationData) {
        if (!translationData || typeof translationData !== 'object') return null;

        // For section-specific translations, directly check for the key
        if (translationData[text]) {
            return translationData[text];
        }

        // Fallback: if it's still the old structure, search through categories
        for (var category in translationData) {
            if (translationData.hasOwnProperty(category) && typeof translationData[category] === 'object') {
                if (translationData[category][text]) {
                    return translationData[category][text];
                }
            }
        }

        return null;
    }

    // Core translation function
    function translateLang(sectionTranslations, targets) {
        targets = targets || ['body'];
        if (!sectionTranslations) {
            return;
        }

        // Clean up any nested multilang-wrapper structures first
        cleanupNestedWrappers();

        var elements = [];
        targets.forEach(function(t) {
            if (typeof t === 'string') {
                var found = Array.from(document.querySelectorAll(t));
                elements = elements.concat(found);
            } else if (t instanceof Element) elements.push(t);
            else if (t instanceof NodeList || Array.isArray(t)) elements = elements.concat(Array.from(t));
        });

        // Filter out excluded selectors and already processed elements
        elements = elements.filter(el => {
            return !excludedSelectors.some(sel => el.closest(sel)) &&
                !el.closest('.multilang-wrapper') &&
                !el.classList.contains('translate') &&
                !el.hasAttribute('data-multilang-processed');
        });

        function translateText(text, sectionTranslations) {
            if (!text.trim()) return text;

            // Get current language from document attributes or default to 'en'
            var currentLang = document.documentElement.getAttribute('data-lang') ||
                document.documentElement.getAttribute('lang') ||
                document.body.getAttribute('lang') ||
                'en';

            // Get default language
            var defaultLang = window.defaultLanguage || 'en';

            // Get all available languages from the section translations
            var availableLanguages = sectionTranslations ? Object.keys(sectionTranslations) : [];

            // Look for translations in this section's data across all languages
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

                // Create spans for all available languages that have translations
                availableLanguages.forEach(function(lang) {
                    if (allTranslations[lang]) {
                        var display = '';
                        if (!hideFilterEnabled) {
                            // Only add display styles if hide filter is NOT active
                            display = (lang === currentLang) ? '' : 'none';
                        }

                        if (lang === currentLang) {
                            hasCurrentLangTranslation = true;
                        }

                        if (hideFilterEnabled) {
                            // When hide filter is active, don't add display styles - let PHP handle it
                            var encoded_content = encodeForDataAttr(allTranslations[lang]);
                            allSpans += '<span class="translate lang-' + lang + '" data-translation="' + encoded_content + '">' + allTranslations[lang] + '</span>';
                        } else {
                            // When hide filter is not active, use display styles
                            var encoded_content = encodeForDataAttr(allTranslations[lang]);
                            allSpans += '<span class="translate lang-' + lang + '" data-translation="' + encoded_content + '" style="display: ' + display + ';">' + allTranslations[lang] + '</span>';
                        }
                    }
                });

                // If no translation for current language, show default language or fallback to original
                if (!hasCurrentLangTranslation) {
                    if (allTranslations[defaultLang]) {
                        // Update display to show default language
                        allSpans = allSpans.replace(
                            'lang-' + defaultLang + '" style="display: none;"',
                            'lang-' + defaultLang + '" style=""'
                        );
                    } else {
                        // No translation for current or default language - add fallback span
                        allSpans += '<span class="translate-fallback" style="">' + text + '</span>';
                    }
                }

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

                    // Create spans for all languages that have this token
                    availableLanguages.forEach(function(lang) {
                        if (tokenTranslations[lang]) {
                            var display = '';
                            if (!hideFilterEnabled) {
                                // Only add display styles if hide filter is NOT active
                                display = (lang === currentLang) ? '' : 'none';
                            }

                            if (lang === currentLang) {
                                hasCurrentLangTokenTranslation = true;
                            }

                            if (hideFilterEnabled) {
                                // When hide filter is active, don't add display styles - let PHP handle it
                                var encoded_content = encodeForDataAttr(tokenTranslations[lang]);
                                tokenSpans += '<span class="translate lang-' + lang + '" data-translation="' + encoded_content + '" data-original-text="' + originalToken + '">' + tokenTranslations[lang] + '</span>';
                            } else {
                                // When hide filter is not active, use display styles  
                                var encoded_content = encodeForDataAttr(tokenTranslations[lang]);
                                tokenSpans += '<span class="translate lang-' + lang + '" data-translation="' + encoded_content + '" data-original-text="' + originalToken + '" style="display: ' + display + ';">' + tokenTranslations[lang] + '</span>';
                            }
                        }
                    });

                    // If no translation for current language, show default language or fallback to original
                    if (!hasCurrentLangTokenTranslation) {
                        if (tokenTranslations[defaultLang]) {
                            tokenSpans = tokenSpans.replace(
                                'lang-' + defaultLang + '" style="display: none;"',
                                'lang-' + defaultLang + '" style=""'
                            );
                        } else {
                            // No translation for current or default language - add fallback
                            tokenSpans += '<span class="translate-fallback" style="">' + token + '</span>';
                        }
                    }

                    result += tokenSpans || token;
                } else {
                    result += token;
                }

                lastIndex = idx + token.length;
            });

            if (lastIndex < text.length) result += text.slice(lastIndex);
            return hasAnyTranslation ? result : text;
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

            element.childNodes.forEach(function(node) {
                if (node.nodeType === Node.TEXT_NODE) {
                    var originalText = node.textContent.trim();
                    if (!originalText) return; // Skip empty text nodes

                    var translated = translateText(originalText, sectionTranslations);
                    if (translated !== originalText) {
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

        elements.forEach(function(el) {
            // Mark as processed to avoid double-processing
            el.setAttribute('data-multilang-processed', 'true');
            wrapTextNodes(el);
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