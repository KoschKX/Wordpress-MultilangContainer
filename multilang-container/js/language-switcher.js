(function() {
	// Helper to mark all internal links for processing
	function markInternalLinks() {
		var siteUrl = window.location.hostname;
		var links = document.querySelectorAll('a:not([data-multilang-link]):not([data-multilang-external])');
		links.forEach(function(link) {
			var href = link.getAttribute('href');
			if (!href) return;
			
			// Check if internal link (relative or same domain)
			if (href.startsWith('/') || href.startsWith('./') || href.startsWith('?') || href.startsWith('#')) {
				link.setAttribute('data-multilang-link', 'true');
			} else if (href.startsWith('http')) {
				try {
					var url = new URL(href);
					if (url.hostname === siteUrl) {
						link.setAttribute('data-multilang-link', 'true');
					} else {
						link.setAttribute('data-multilang-external', 'true');
					}
				} catch (e) {
					link.setAttribute('data-multilang-external', 'true');
				}
			}
		});
	}

	// Helper to update all internal links with current ?lang=xx
	function updateInternalLinks(lang) {
		var flags = document.querySelector('.multilang-flags');
		var defaultLang = window.multilangLangBar && window.multilangLangBar.defaultLang ? window.multilangLangBar.defaultLang : 'en';
		
		// First, mark any new links
		markInternalLinks();
		
		var links = document.querySelectorAll("a[data-multilang-link]");
		links.forEach(function(link) {
			// Skip links inside the language bar
			if (link.closest('.multilang-flags')) return;
			var href = link.getAttribute('href');
			if (!href) return;
			try {
				var url = new URL(href, window.location.origin);
				if (lang === defaultLang) {
					// Remove lang from query string
					url.searchParams.delete('lang');
					var cleanUrl = url.pathname + url.search + url.hash;
					cleanUrl = cleanUrl.replace(/([&?])lang=[^&#]*(&)?/, function(match, p1, p2) {
						if (p1 === '?' && p2) return '?';
						if (p1 === '&' && p2) return '&';
						return '';
					});
					cleanUrl = cleanUrl.replace(/\?$/, '');
					cleanUrl = cleanUrl.replace(/\?&/, '?');
					if (cleanUrl.endsWith('?')) {
						cleanUrl = cleanUrl.slice(0, -1);
					}
					link.setAttribute('href', cleanUrl);
				} else {
					url.searchParams.set('lang', lang);
					link.setAttribute('href', url.pathname + url.search + url.hash);
				}
			} catch (e) {
				// Ignore invalid URLs
			}
		});
	}
	// Periodically update internal links to catch dynamically added content
	// Use MutationObserver to update internal links when DOM changes
	function startLinkObserver() {
		var flags = document.querySelector('.multilang-flags');
		var useQueryString = flags && flags.getAttribute('data-use-query-string') === '1';
		var defaultLang = window.multilangLangBar && window.multilangLangBar.defaultLang ? window.multilangLangBar.defaultLang : 'en';
		var params = new URLSearchParams(window.location.search);
		var cookieLang = window.getLangCookie ? window.getLangCookie() : defaultLang;
		var currentLang = params.get('lang') || cookieLang || defaultLang;
		if (!useQueryString) return;
		var observer = new MutationObserver(function(mutations) {
			// Check if new links were added
			var hasNewLinks = false;
			mutations.forEach(function(mutation) {
				if (mutation.addedNodes.length > 0) {
					mutation.addedNodes.forEach(function(node) {
						if (node.nodeType === 1) { // Element node
							if (node.tagName === 'A' || node.querySelector('a')) {
								hasNewLinks = true;
							}
						}
					});
				}
			});
			if (hasNewLinks) {
				markInternalLinks();
				updateInternalLinks(currentLang);
			}
		});
		observer.observe(document.body, { childList: true, subtree: true });
	}
	// On every page load, if query string switching is enabled, enforce ?lang=xx in the address bar
	document.addEventListener('DOMContentLoaded', function () {
		// On page load, update all internal links to include current lang
		var params = new URLSearchParams(window.location.search);
		var flags = document.querySelector('.multilang-flags');
		var useQueryString = flags && flags.getAttribute('data-use-query-string') === '1';
		var defaultLang = window.multilangLangBar && window.multilangLangBar.defaultLang ? window.multilangLangBar.defaultLang : 'en';
		var cookieLang = window.getLangCookie ? window.getLangCookie() : defaultLang;
		var currentLang = params.get('lang') || cookieLang || defaultLang;
		// Only add ?lang=xx if not default language
		if (useQueryString && !params.get('lang') && currentLang !== defaultLang) {
			window.location.replace(window.location.pathname + '?lang=' + currentLang);
		}
		// If ?lang=en is present and en is default, remove it from the address bar
		if (useQueryString && params.get('lang') === defaultLang) {
			var url = new URL(window.location.href);
			url.searchParams.delete('lang');
			var cleanUrl = url.pathname + url.search + url.hash;
			cleanUrl = cleanUrl.replace(/\?$/, '');
			cleanUrl = cleanUrl.replace(/\?&/, '?');
			if (cleanUrl.endsWith('?')) {
				cleanUrl = cleanUrl.slice(0, -1);
			}
			history.replaceState(null, '', cleanUrl);
		}
		// Mark and update all internal links
		markInternalLinks();
		updateInternalLinks(currentLang);
		// Start observer to catch dynamic content
		startLinkObserver();
	});

    document.addEventListener('DOMContentLoaded', function () {
        setTimeout(() => {
            document.querySelectorAll('.multilang-flags').forEach(el => {
                el.classList.add('ready');
            });
        }, 100);
    });

	// INIT LANG COOKIE
	const params = new URLSearchParams(window.location.search);

	// Make cookie helpers globally available
	window.getLangCookie = function() {
		const match = document.cookie.match(new RegExp('(?:^|;\s*)lang=([^;]+)'));
		return match ? decodeURIComponent(match[1]) : null;
	};
	window.setLangCookie = function(value) {
		const d = new Date();
		d.setFullYear(d.getFullYear() + 1);
		document.cookie = 'lang=' + encodeURIComponent(value) +
						';expires=' + d.toUTCString() +
						';path=/';
	};
	window.normalizeLang = function(code) {
		if (!code) return null;
		return code.split("-")[0].toLowerCase();
	};

	// Get switcher config
	let useQueryString = false;
	let defaultLang = 'en';
	if (window.multilangLangBar) {
		const flags = document.querySelector('.multilang-flags');
		useQueryString = flags && flags.getAttribute('data-use-query-string') === '1';
		defaultLang = window.multilangLangBar.defaultLang || 'en';
	}

	// INIT LANG COOKIE
	let lng = params.get("lang") || window.getLangCookie();
	if (!lng) { lng = document.documentElement.getAttribute("lang"); }
	if (!lng) { lng = navigator.language || navigator.userLanguage || defaultLang; }
	lng = normalizeLang(lng) || defaultLang;

	// If query string switching is enabled and no ?lang is present, reload to default language (?lang=en)
	if (useQueryString && !params.get('lang')) {
		window.location.replace(window.location.pathname + '?lang=' + defaultLang);
		return;
	}

	// Remove all language classes and add current one
	document.body.className = document.body.className.replace(/\blang-[a-z]{2}\b/g, '').trim();
	document.body.classList.add('lang-' + lng);

	// Set attributes
	document.querySelector('html').setAttribute("data-lang", lng);
	document.querySelector('html').setAttribute("lang", lng);
	document.body.setAttribute("lang", lng);
	window.setLangCookie(lng);

	// Add language switcher bar from PHP-generated HTML
	if (window.multilangLangBar && window.multilangLangBar.html) {
		document.body.insertAdjacentHTML('beforeend', window.multilangLangBar.html);
	}

	// Language switcher click
	document.querySelectorAll('.multilang-flags li').forEach(function(li) {
		li.addEventListener('click', function(e) {
			var a = li.querySelector('a');
			var href = a.getAttribute('href');
			var flags = li.closest('.multilang-flags');
			var useQueryString = flags && flags.getAttribute('data-use-query-string') === '1';
			var refreshOnSwitch = flags && flags.getAttribute('data-refresh-on-switch') === '1';
			var defaultLang = window.multilangLangBar && window.multilangLangBar.defaultLang ? window.multilangLangBar.defaultLang : 'en';
			var lang = a.getAttribute('hreflang');
			lang = window.normalizeLang(lang);

			// Always update links immediately after switching
			setTimeout(function() {
				updateInternalLinks(lang);
			}, 50);

			// Update the query string in the address bar
			var url = new URL(window.location.href);
			if (lang === defaultLang) {
				url.searchParams.delete('lang');
				var cleanUrl = url.pathname + url.search + url.hash;
				cleanUrl = cleanUrl.replace(/\?$/, '');
				cleanUrl = cleanUrl.replace(/\?&/, '?');
				if (cleanUrl.endsWith('?')) {
					cleanUrl = cleanUrl.slice(0, -1);
				}
				history.replaceState(null, '', cleanUrl);
			} else {
				// Always add ?lang=xx for non-default language
				url.searchParams.set('lang', lang);
				var newUrl = url.pathname + url.search + url.hash;
				if (!url.searchParams.get('lang')) {
					// If for some reason lang is missing, add it
					newUrl += (url.search ? '&' : '?') + 'lang=' + lang;
				}
				history.replaceState(null, '', newUrl);
			}

			if (lang === defaultLang && useQueryString) {
				e.preventDefault();
				if (refreshOnSwitch) {
					window.location.href = href;
				} else {
					document.body.className = document.body.className.replace(/\blang-[a-z]{2}\b/g, '').trim();
					document.body.classList.add('lang-' + lang);
					document.querySelector('html').setAttribute('data-lang', lang);
					document.querySelector('html').setAttribute('lang', lang);
					document.body.setAttribute('lang', lang);
					window.setLangCookie(lang);
					updatePageTitle(lang);
					updateInternalLinks(lang);
				}
				return;
			}
			if (href === '#' || !useQueryString || (href === '/' && !useQueryString)) {
				e.preventDefault();
				document.body.className = document.body.className.replace(/\blang-[a-z]{2}\b/g, '').trim();
				document.body.classList.add('lang-' + lang);
				document.querySelector('html').setAttribute('data-lang', lang);
				document.querySelector('html').setAttribute('lang', lang);
				document.body.setAttribute('lang', lang);
				window.setLangCookie(lang);
				updatePageTitle(lang);
				updateInternalLinks(lang);
				return;
			}
			if (useQueryString && href && href.indexOf('?lang=') === 0) {
				e.preventDefault();
				if (refreshOnSwitch) {
					window.location.href = href;
				} else {
					document.body.className = document.body.className.replace(/\blang-[a-z]{2}\b/g, '').trim();
					document.body.classList.add('lang-' + lang);
					document.querySelector('html').setAttribute('data-lang', lang);
					document.querySelector('html').setAttribute('lang', lang);
					document.body.setAttribute('lang', lang);
					window.setLangCookie(lang);
					updatePageTitle(lang);
					updateInternalLinks(lang);
				}
				return;
			}
			document.body.className = document.body.className.replace(/\blang-[a-z]{2}\b/g, '').trim();
			document.body.classList.add('lang-' + lang);
			document.querySelector('html').setAttribute('data-lang', lang);
			document.querySelector('html').setAttribute('lang', lang);
			document.body.setAttribute('lang', lang);
			window.setLangCookie(lang);
			updatePageTitle(lang);
			updateInternalLinks(lang);
		});
	});

	// Function to update page title based on language
	function updatePageTitle(lang) {
		var titles = window.multilangPageTitles || (window.multilangLangBar && window.multilangLangBar.pageTitles);
		var taglines = window.multilangPageTaglines || (window.multilangLangBar && window.multilangLangBar.pageTaglines);
		var siteName = window.multilangLangBar && window.multilangLangBar.siteName;
		
		var newTitle = '';
		if (titles && titles[lang]) {
			newTitle = titles[lang];
		}
		else if (titles && lang !== 'en' && titles['en']) {
			newTitle = titles['en'];
		}
		else if (titles && titles['original']) {
			newTitle = titles['original'];
		}
		
		var newTagline = '';
		if (taglines) {
			if (taglines[lang]) {
				newTagline = taglines[lang];
			}
			else if (lang !== 'en' && taglines['en']) {
				newTagline = taglines['en'];
			}
			else if (taglines['original']) {
				newTagline = taglines['original'];
			}
		}
		
		// Build document title
		var titleParts = [];
		if (newTitle) {
			titleParts.push(newTitle);
		}
		if (newTagline) {
			titleParts.push(newTagline);
		}
		if (siteName) {
			titleParts.push(siteName);
		}
		
		if (titleParts.length > 0) {
			document.title = titleParts.join(' - ');
		}
		
		updatePageHeadings(newTitle, newTagline);
	}

	// Function to update page headings (h1, h2 with post titles)
	function updatePageHeadings(newTitle, newTagline) {
		// Update title elements
		var markedElements = document.querySelectorAll('[data-multilang-title="true"]');
		if (markedElements.length > 0) {
			markedElements.forEach(function(element) {
				element.textContent = newTitle;
			});
		}
		
		// Update tagline elements
		if (newTagline) {
			var taglineElements = document.querySelectorAll('.site-description, .site-tagline, [data-multilang-tagline="true"]');
			if (taglineElements.length > 0) {
				taglineElements.forEach(function(element) {
					element.textContent = newTagline;
				});
			}
		}
	}

	// Initialize page title on page load
	var currentLang = window.getLangCookie() || 'en';

	// Load CSS (style will be applied instantly since flags are full color by default)
	var link = document.createElement('link');
	link.rel = 'stylesheet';
	link.href = window.multilangLangBar.pluginPath+'/css/language-switcher.css';
	document.head.appendChild(link);
})();

