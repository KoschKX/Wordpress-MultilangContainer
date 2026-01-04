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

    document.addEventListener('DOMContentLoaded', () => {
        setTimeout(function(){
            jQuery('body').addClass('multilang-ready');
        }, 0);
    });

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
        // Use requestAnimationFrame to batch all DOM changes in a single frame
        requestAnimationFrame(function() {
            // Update display FIRST, then attributes
            updateLanguageDisplay(lang);
            
            document.documentElement.setAttribute("data-lang", lang);
            document.documentElement.setAttribute("lang", lang);
            document.body.setAttribute("lang", lang);

            if (window.setLangCookie) {
                window.setLangCookie(lang);
            }
        });
    }

    /**
     * Get current page path and slug for page-specific translation filtering
     */
    function getCurrentPageInfo() {
        var path = window.location.pathname;
        // Remove trailing slash but keep leading slash
        path = path.replace(/\/+$/g, '') || '/';
        
        // If just /, it's the home page
        if (path === '/') {
            return {
                path: '/',
                slug: 'home'
            };
        }
        
        // Get the last segment of the path for slug
        var segments = path.split('/').filter(function(s) { return s; });
        var slug = segments[segments.length - 1] || 'home';
        
        // Remove .html or .php extensions if present
        slug = slug.replace(/\.(html|php)$/i, '');
        
        return {
            path: path,
            slug: slug
        };
    }

    /**
     * Check if a section should be applied to the current page
     */
    function shouldApplySection(sectionPages) {
        // If no pages setting or *, apply to all pages
        if (!sectionPages || sectionPages === '*') {
            return true;
        }
        
        var pageInfo = getCurrentPageInfo();
        var currentPath = pageInfo.path;
        var currentSlug = pageInfo.slug;
        
        // Split pages by comma and trim
        var allowedPages = sectionPages.split(',').map(function(p) {
            return p.trim();
        });
        
        // Check each allowed page
        for (var i = 0; i < allowedPages.length; i++) {
            var page = allowedPages[i];
            
            // Strip http://, https://, and domain if present
            page = page.replace(/^https?:\/\/[^\/]+/i, '');
            page = page.trim();
            
            // Ensure it starts with / if not empty
            if (page && page[0] !== '/') {
                page = '/' + page;
            }
            
            // Match full path first (e.g., /about/team)
            if (page === currentPath) {
                return true;
            }
            
            // Match slug (e.g., team)
            var pageSlug = page.replace(/^\/+|\/+$/g, '');
            if (pageSlug === currentSlug) {
                return true;
            }
        }
        
        return false;
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
            // Skip disabled sections
            if (sectionConfig && sectionConfig._disabled) return;
            // Skip sections that don't apply to current page
            var sectionPages = sectionConfig && sectionConfig._pages ? sectionConfig._pages : '*';
            if (!shouldApplySection(sectionPages)) return;
            
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
        // Get structureData for disabled check
        var structureData = window.multilangLangBar && window.multilangLangBar.structureData ? window.multilangLangBar.structureData : {};
        Object.keys(selectorGroups).forEach(function(selectorKey) {
            var group = selectorGroups[selectorKey];
            var selectors = group.selectors;
            var sections = group.sections;
            // Merge translations from all sections in this group, but skip disabled sections
            var mergedTranslations = {};
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
                    var sectionConfig = structureData[sectionName];
                    if (sectionConfig && sectionConfig._disabled) return; // skip disabled
                    // Skip sections that don't apply to current page
                    var sectionPages = sectionConfig && sectionConfig._pages ? sectionConfig._pages : '*';
                    if (!shouldApplySection(sectionPages)) return;
                    
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

    // Function to update language display when switching languages (ultra-optimized v5 - instant switch)
    function updateLanguageDisplay(newLang) {
        // Process everything in one synchronous pass for instant switching
        const allElements = document.querySelectorAll('.translate[class*="lang-"], [class*="lang-"], .translate [class*="lang-"]');
        const fragment = document.createDocumentFragment();
        
        // First pass: show/hide and prepare content updates
        for (let i = 0; i < allElements.length; i++) {
            const element = allElements[i];
            const match = element.className.match(/lang-([a-z]{2})/);
            if (!match) continue;
            const elementLang = match[1];

            // Handle wrapper default text
            const wrapper = element.closest('.multilang-wrapper');
            if (wrapper && !element.hasAttribute('data-default-text')) {
                let defaultText = wrapper.getAttribute('data-default-text') || '';
                if (!defaultText) {
                    const originalText = wrapper.getAttribute('data-original-text');
                    const defaultLang = window.defaultLanguage || 'en';
                    if (window.multilangLangBar && window.multilangLangBar.languageFiles && window.multilangLangBar.languageFiles[defaultLang]) {
                        const langData = window.multilangLangBar.languageFiles[defaultLang];
                        if (langData[originalText]) {
                            defaultText = langData[originalText];
                        } else {
                            for (const cat in langData) {
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
                if (defaultText) {
                    element.setAttribute('data-default-text', defaultText);
                }
            }

            // Decode translation data if needed BEFORE showing/hiding
            const dataTranslation = element.getAttribute('data-translation');
            let contentRestored = false;
            
            if (dataTranslation && elementLang === newLang) {
                let decodedContent = null;
                
                if (hideFilterEnabled) {
                    decodedContent = decodeDataAttr(dataTranslation);
                    if (!decodedContent || decodedContent === dataTranslation) {
                        decodedContent = dataTranslation.replace(/\\u([0-9a-fA-F]{4})/g, function(match, hex) {
                            return String.fromCharCode(parseInt(hex, 16));
                        });
                    }
                } else {
                    decodedContent = decodeDataAttr(dataTranslation);
                    if (!decodedContent || decodedContent === dataTranslation) {
                        decodedContent = dataTranslation.replace(/\\u([0-9a-fA-F]{4})/g, function(match, hex) {
                            return String.fromCharCode(parseInt(hex, 16));
                        });
                    }
                }
                
                if (decodedContent) {
                    element.innerHTML = decodedContent;
                    element.setAttribute('data-decoded', 'true');
                    contentRestored = true;
                }
            }

            // Show/hide based on language
            if (element.classList.contains('translate') || element.closest('.translate')) {
                if (elementLang === newLang) {
                    // Restore content if empty and not already restored
                    if (!contentRestored && !element.innerHTML.trim()) {
                        const defaultText = element.getAttribute('data-default-text');
                        if (defaultText) {
                            element.innerHTML = defaultText;
                        }
                    }
                    element.style.display = '';
                } else {
                    element.style.display = 'none';
                }
            }
        }

        // Update buttons
        document.querySelectorAll('[data-multilang-button-wrapped]').forEach(function(button) {
            const translationKey = 'data-text-' + newLang;
            if (button.hasAttribute(translationKey)) {
                const newText = button.getAttribute(translationKey);
                if (button.textContent !== newText) {
                    button.textContent = newText;
                }
            }
        });

        // Update inputs
        document.querySelectorAll('input[value][value-' + newLang + ']').forEach(function(input) {
            const newValue = input.getAttribute('value-' + newLang);
            if (typeof newValue === 'string' && input.value !== newValue) {
                input.value = newValue;
            }
        });
        
        document.querySelectorAll('input[value]').forEach(function(input) {
            if (!input.hasAttribute('value-' + newLang)) {
                const defaultValue = input.getAttribute('value');
                if (typeof defaultValue === 'string' && input.value !== defaultValue) {
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
            console.warn('[Multilang] No section translations provided');
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

        // Per-selector processed marker: use a unique attribute for each selector group
        const selectorMarker = 'data-multilang-processed-' + btoa(JSON.stringify(targets)).replace(/[^a-z0-9]/gi, '').toLowerCase();
        
        // Remove old generic processed marker to allow reprocessing
        elements.forEach(function(el) {
            if (el.hasAttribute('data-multilang-processed')) {
                el.removeAttribute('data-multilang-processed');
            }
        });
        
        elements = elements.filter(el => {
            // Exclude if matches any excluded selectors
            if (excludedSelectors.some(sel => el.closest(sel))) return false;
            if (el.closest('.multilang-wrapper')) return false;
            if (el.classList.contains('translate')) return false;
            if (el.hasAttribute(selectorMarker)) {
                return false;
            }

            // If el matches any selector, or any child matches, include it
            let matchesSelector = false;
            for (let t of targets) {
                if (typeof t === 'string') {
                    if (el.matches && el.matches(t)) {
                        matchesSelector = true;
                        break;
                    }
                    if (el.querySelector && el.querySelector(t)) {
                        matchesSelector = true;
                        break;
                    }
                }
            }
            return matchesSelector;
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
            
            // CRITICAL: Don't translate text that already contains translation spans
            if (text.includes('<span') || text.includes('data-translation')) {
                return text;
            }
            
            if (translationCache[text]) return translationCache[text];

            // 1. Direct full-text translation (try exact match first, including HTML)
            const allTranslations = {};
            for (const lang of availableLanguages) {
                const langTranslations = sectionTranslations[lang] || {};
                // Try exact match with HTML
                if (langTranslations[text]) {
                    allTranslations[lang] = langTranslations[text];
                } else {
                    // Try stripping HTML from text and matching against keys
                    const textWithoutHTML = text.replace(/<[^>]+>/g, '').trim();
                    if (langTranslations[textWithoutHTML]) {
                        allTranslations[lang] = langTranslations[textWithoutHTML];
                    }
                }
            }
            if (Object.keys(allTranslations).length) {
                const defaultLangTranslation = allTranslations[defaultLang] || text;
                const allSpans = availableLanguages.map(lang => {
                    const display = !hideFilterEnabled ? (lang === currentLang ? '' : 'none') : '';
                    const translation = allTranslations[lang] || defaultLangTranslation;
                    const encoded = encodeForDataAttr(translation);
                    return hideFilterEnabled
                        ? `<span class="translate lang-${lang}" data-translation="${encoded}">${translation}</span>`
                        : `<span class="translate lang-${lang}" data-translation="${encoded}" style="display: ${display};">${translation}</span>`;
                }).join('');
                return allSpans || text;
            }

            // 2. Phrase-based partial translation
            // Gather all possible phrases from translation data (for all languages)
            const phraseSet = new Set();
            // Strip HTML tags for comparison but keep original text for replacement
            const textForComparison = text.replace(/<[^>]+>/g, '').trim().replace(/\s+/g, ' ');
            
            for (const lang of availableLanguages) {
                const langTranslations = sectionTranslations[lang] || {};
                for (const key in langTranslations) {
                    // Normalize key for comparison
                    const normalizedKey = key.trim().replace(/\s+/g, ' ');
                    if (normalizedKey && textForComparison.includes(normalizedKey)) {
                        phraseSet.add(key); // Keep original key for replacement
                    }
                }
            }
            
            // Sort phrases by length (longest first)
            const phrases = Array.from(phraseSet).sort((a, b) => b.length - a.length);
            let replaced = false;
            let replacedText = text;
            const escapeRegExp = s => s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
            
            for (const phrase of phrases) {
                // Check if the phrase exists in the current text (accounting for already replaced portions)
                const phrasePattern = new RegExp(escapeRegExp(phrase), 'g');
                if (!phrasePattern.test(replacedText)) {
                    continue;
                }
                
                const phraseTranslations = {};
                for (const lang of availableLanguages) {
                    const langTranslations = sectionTranslations[lang] || {};
                    if (langTranslations[phrase]) phraseTranslations[lang] = langTranslations[phrase];
                }
                
                if (!Object.keys(phraseTranslations).length) {
                    continue;
                }
                
                const defaultPhraseTranslation = phraseTranslations[defaultLang] || phrase;
                const phraseSpans = availableLanguages.map(lang => {
                    const display = !hideFilterEnabled ? (lang === currentLang ? '' : 'none') : '';
                    const translation = phraseTranslations[lang] || defaultPhraseTranslation;
                    const encoded = encodeForDataAttr(translation);
                    
                    return hideFilterEnabled
                        ? `<span class="translate lang-${lang}" data-translation="${encoded}" data-original-text="${phrase}">${translation}</span>`
                        : `<span class="translate lang-${lang}" data-translation="${encoded}" data-original-text="${phrase}" style="display: ${display};">${translation}</span>`;
                }).join('');
                replacedText = replacedText.replace(new RegExp(escapeRegExp(phrase), 'g'), phraseSpans);
                replaced = true;
            }

            // 3. Translate all parenthesized content after phrase and token replacements
            replacedText = replacedText.replace(/\(([^)]+)\)/g, (match, inner) => {
                // Remove any HTML tags from inner (if already replaced by phrase logic)
                const plainInner = inner.replace(/<[^>]+>/g, '').trim();
                const innerTranslations = {};
                for (const lang of availableLanguages) {
                    const langTranslations = sectionTranslations[lang] || {};
                    if (langTranslations[plainInner]) innerTranslations[lang] = langTranslations[plainInner];
                }
                if (Object.keys(innerTranslations).length) {
                    const defaultInnerTranslation = innerTranslations[defaultLang] || plainInner;
                    const innerSpans = availableLanguages.map(lang => {
                        const display = !hideFilterEnabled ? (lang === currentLang ? '' : 'none') : '';
                        const translation = innerTranslations[lang] || defaultInnerTranslation;
                        const encoded = encodeForDataAttr(translation);
                        return hideFilterEnabled
                            ? `<span class="translate lang-${lang}" data-translation="${encoded}" data-original-text="${plainInner}">${translation}</span>`
                            : `<span class="translate lang-${lang}" data-translation="${encoded}" data-original-text="${plainInner}" style="display: ${display};">${translation}</span>`;
                    }).join('');
                    return `(${innerSpans})`;
                }
                return match;
            });
            if (replaced) {
                translationCache[text] = replacedText;
                return replacedText;
            }

            // 4. Token-based fallback - check individual words but only in section translations
            const tokens = text.match(/[\p{L}\p{N}]+|[^\p{L}\p{N}\s]+/gu) || [];
            let result = '';
            let lastIndex = 0;
            let hasAnyTranslation = false;
            for (const token of tokens) {
                const idx = text.indexOf(token, lastIndex);
                if (idx > lastIndex) result += text.slice(lastIndex, idx);
                const tokenTranslations = {};
                for (const lang of availableLanguages) {
                    const langTranslations = sectionTranslations[lang] || {};
                    if (langTranslations[token]) tokenTranslations[lang] = langTranslations[token];
                }
                if (Object.keys(tokenTranslations).length) {
                    hasAnyTranslation = true;
                    const defaultTokenTranslation = tokenTranslations[defaultLang] || token;
                    const tokenSpans = availableLanguages.map(lang => {
                        const display = !hideFilterEnabled ? (lang === currentLang ? '' : 'none') : '';
                        const translation = tokenTranslations[lang] || defaultTokenTranslation;
                        const encoded = encodeForDataAttr(translation);
                        return hideFilterEnabled
                            ? `<span class=\"translate lang-${lang}\" data-translation=\"${encoded}\" data-original-text=\"${token}\">${translation}</span>`
                            : `<span class=\"translate lang-${lang}\" data-translation=\"${encoded}\" data-original-text=\"${token}\" style=\"display: ${display};\">${translation}</span>`;
                    }).join('');
                    result += tokenSpans;
                } else {
                    result += token;
                }
                lastIndex = idx + token.length;
            }
            if (hasAnyTranslation) {
                translationCache[text] = result;
                return result;
            }
            return text;
        // (no-op: removed old code after refactor)
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

            // Skip if this element's direct children already contain translation spans (server-side translated)
            // But only check direct children, not descendants, to allow processing siblings
            let hasDirectTranslationChild = false;
            for (let i = 0; i < element.children.length; i++) {
                const child = element.children[i];
                if (child.classList.contains('multilang-wrapper') || child.classList.contains('translate')) {
                    hasDirectTranslationChild = true;
                    break;
                }
            }
            if (hasDirectTranslationChild) {
                return;
            }

            // Per-selector processed marker: prevent infinite recursion
            if (typeof selectorMarker !== 'undefined' && element.hasAttribute(selectorMarker)) {
                return;
            }
            
            // Track if any changes were made to this element
            var elementModified = false;

            // FIRST: Try to match the entire element's innerHTML (for HTML content translations)
            // This handles cases where the translation key includes HTML markup
            if (element.children.length > 0) {
                var elementHTML = element.innerHTML.trim();
                var currentLang = document.documentElement.getAttribute('data-lang') ||
                    document.documentElement.getAttribute('lang') ||
                    document.body.getAttribute('lang') ||
                    'en';
                var availableLanguages = sectionTranslations ? Object.keys(sectionTranslations) : [];
                
                // Check if this exact HTML exists in translations
                var htmlTranslations = {};
                for (var lang of availableLanguages) {
                    var langTranslations = sectionTranslations[lang] || {};
                    if (langTranslations[elementHTML]) {
                        htmlTranslations[lang] = langTranslations[elementHTML];
                    }
                }
                
                // If we found translations for the HTML content, wrap the entire element
                if (Object.keys(htmlTranslations).length > 0) {
                    var defaultLang = window.defaultLanguage || 'en';
                    var defaultTranslation = htmlTranslations[defaultLang] || elementHTML;
                    
                    var allSpans = availableLanguages.map(function(lang) {
                        var display = !hideFilterEnabled ? (lang === currentLang ? '' : 'none') : '';
                        var translation = htmlTranslations[lang] || defaultTranslation;
                        var encoded = encodeForDataAttr(translation);
                        return hideFilterEnabled
                            ? '<span class="translate lang-' + lang + '" data-translation="' + encoded + '" data-original-text="' + encodeForDataAttr(elementHTML) + '">' + translation + '</span>'
                            : '<span class="translate lang-' + lang + '" data-translation="' + encoded + '" data-original-text="' + encodeForDataAttr(elementHTML) + '" style="display: ' + display + ';">' + translation + '</span>';
                    }).join('');
                    
                    // Create wrapper with translation spans
                    var wrapper = document.createElement('span');
                    wrapper.className = 'multilang-wrapper';
                    wrapper.setAttribute('data-original-text', elementHTML);
                    wrapper.setAttribute('data-default-text', defaultTranslation);
                    wrapper.innerHTML = allSpans;
                    
                    // Replace element's content with wrapped translations
                    element.innerHTML = '';
                    element.appendChild(wrapper);
                    
                    // Mark as processed and return early - don't process child nodes
                    elementModified = true;
                    if (typeof selectorMarker !== 'undefined') {
                        element.setAttribute(selectorMarker, 'true');
                    }
                    return;
                }
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
                        
                        // CRITICAL: Strip any HTML tags from wrapperOriginalText to prevent recursive issues
                        wrapperOriginalText = wrapperOriginalText.replace(/<[^>]+>/g, '').trim();
                        
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
                        elementModified = true;
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
            
            // Only add the processed marker if we actually modified the element
            if (elementModified && typeof selectorMarker !== 'undefined') {
                element.setAttribute(selectorMarker, 'true');
            }
        }

        // Process all elements at once for faster translation
        elements.forEach(function(el) {
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
            // Skip disabled sections
            if (sectionConfig && sectionConfig._disabled) return;
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

        // Remove duplicates and trim whitespace
        jsSelectors = Array.from(new Set(jsSelectors.map(function(sel){ return sel.trim(); }))).filter(Boolean);

        if (jsSelectors.length === 0) return;

        var observer = new MutationObserver(function(mutations) {
            var shouldRunTranslations = false;
            var processedNodes = new Set();
            mutations.forEach(function(mutation) {
                // Handle new nodes being added
                mutation.addedNodes.forEach(function(node) {
                    if (node.nodeType !== Node.ELEMENT_NODE) return;

                    jsSelectors.forEach(function(selector) {
                        try {
                            var elements = [];
                            if (node.matches && node.matches(selector)) {
                                elements.push(node);
                            }
                            if (node.querySelectorAll) {
                                var children = node.querySelectorAll(selector);
                                elements = elements.concat(Array.from(children));
                            }
                            elements.forEach(function(el) {
                                if (!processedNodes.has(el)) {
                                    el.style.setProperty('visibility', 'visible', 'important');
                                    processedNodes.add(el);
                                    shouldRunTranslations = true;
                                }
                            });
                        } catch (e) {
                            // Invalid selector, skip
                        }
                    });
                });

                // Handle text/attribute changes (WooCommerce dynamic updates)
                if (mutation.type === 'characterData' || mutation.type === 'childList') {
                    var target = mutation.target.nodeType === Node.TEXT_NODE ? mutation.target.parentElement : mutation.target;
                    if (target && target.nodeType === Node.ELEMENT_NODE) {
                        jsSelectors.forEach(function(selector) {
                            try {
                                if (target.matches && target.matches(selector)) {
                                    // Remove the processed marker so it can be re-translated
                                    var allMarkerAttrs = Array.from(target.attributes).filter(function(attr) {
                                        return attr.name.startsWith('data-multilang-processed-');
                                    });
                                    allMarkerAttrs.forEach(function(attr) {
                                        target.removeAttribute(attr.name);
                                    });
                                    
                                    if (!processedNodes.has(target)) {
                                        target.style.setProperty('visibility', 'visible', 'important');
                                        processedNodes.add(target);
                                        shouldRunTranslations = true;
                                    }
                                }
                            } catch (e) {
                                // Invalid selector, skip
                            }
                        });
                    }
                }
            });
            if (shouldRunTranslations) {
                runTranslations();
            }
        });

        observer.observe(document.body, {
            childList: true,
            subtree: true,
            characterData: true,
            characterDataOldValue: false
        });
    }
    

    // Initialize the observer after DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() {
            observeNewElements();
        });
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