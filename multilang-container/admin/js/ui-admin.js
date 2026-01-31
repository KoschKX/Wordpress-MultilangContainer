document.addEventListener('DOMContentLoaded', function() {
 
});

setupTabs();

function setupTabs() {
    document.querySelectorAll('.nav-tab').forEach(function(tab) {
        tab.addEventListener('click', function(e) {
            e.preventDefault();
            if (typeof window.switchTab === 'function') {
                window.switchTab(tab.getAttribute('data-tab'), tab);
            }
        });
    });
}