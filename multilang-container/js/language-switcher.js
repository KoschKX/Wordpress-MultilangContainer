(function() {

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
		const match = document.cookie.match(new RegExp('(?:^|;\\s*)lang=([^;]+)'));
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

	// INIT LANG COOKIE
	let lng = params.get("lng") || window.getLangCookie();
	if (!lng) { lng = document.documentElement.getAttribute("lang"); }
	if (!lng) { lng = navigator.language || navigator.userLanguage || "en"; }
	lng = normalizeLang(lng) || "en";
	
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
		li.addEventListener('click', function() {
			var lang = li.querySelector('a').getAttribute('hreflang');
			lang = window.normalizeLang(lang); 
			if (!lang) return; 
			
			// Remove all language classes from body
			document.body.className = document.body.className.replace(/\blang-[a-z]{2}\b/g, '').trim();
			
			// Add new language class
			document.body.classList.add('lang-' + lang);
			
			// Set attributes
			document.querySelector('html').setAttribute('data-lang', lang);
			document.querySelector('html').setAttribute('lang', lang);
			document.body.setAttribute('lang', lang);
			window.setLangCookie(lang);
			
			// Update page title if translations are available
			updatePageTitle(lang);
		});
	});

	// Function to update page title based on language
	function updatePageTitle(lang) {
		// Use the more reliable data source from wp_head
		var titles = window.multilangPageTitles || (window.multilangLangBar && window.multilangLangBar.pageTitles);
		
		if (!titles) {
			return;
		}
		var newTitle = '';
		
		// Try to get title in selected language
		if (titles[lang]) {
			newTitle = titles[lang];
		}
		// Fallback to English
		else if (lang !== 'en' && titles['en']) {
			newTitle = titles['en'];
		}
		// Fallback to original title
		else if (titles['original']) {
			newTitle = titles['original'];
		}
		
		// Update document title if we found a translation
		if (newTitle) {
			document.title = newTitle;
			
			// Also update any h1 elements that contain the title
			updatePageHeadings(newTitle);
		}
	}

	// Function to update page headings (h1, h2 with post titles)
	function updatePageHeadings(newTitle) {
		// First try elements with our data attribute
		var markedElements = document.querySelectorAll('[data-multilang-title="true"]');
		if (markedElements.length > 0) {
			markedElements.forEach(function(element) {
				element.textContent = newTitle;
			});
			return;
		}
		
		// Fallback to common title selectors
		var titleSelectors = [
			'h1.entry-title',
			'h1.page-title', 
			'h1.post-title',
			'.entry-header h1',
			'article h1:first-child',
			'h1.wp-block-post-title',
			'.post-title h1',
			'.page-header h1'
		];
		
		var originalTitle = (window.multilangPageTitles && window.multilangPageTitles.original) || 
		                   (window.multilangLangBar && window.multilangLangBar.pageTitles && window.multilangLangBar.pageTitles.original) || '';
		var updated = false;
		
		titleSelectors.forEach(function(selector) {
			if (updated) return; // Only update the first match
			
			var elements = document.querySelectorAll(selector);
			elements.forEach(function(element) {
				var currentText = element.textContent.trim();
				
				// Check if this element contains the original title
				if (originalTitle && (currentText === originalTitle || currentText.includes(originalTitle))) {
					element.textContent = newTitle;
					element.setAttribute('data-multilang-title', 'true'); // Mark for future updates
					updated = true;
				}
			});
		});
	}

	// Initialize page title on page load
	var currentLang = window.getLangCookie() || 'en';
	updatePageTitle(currentLang);

	// Load CSS (style will be applied instantly since flags are full color by default)
	var link = document.createElement('link');
	link.rel = 'stylesheet';
	link.href = window.multilangLangBar.pluginPath+'/css/language-switcher.css';
	document.head.appendChild(link);
})();

