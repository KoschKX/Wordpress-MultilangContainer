var langData = multilangVars.langData;
var pluginUrl = multilangVars.pluginUrl;

console.log('langData:', langData);

function updateDefaultLanguageDropdown() {
    var dropdown = document.getElementById("multilang_default_language");
    var checkedLanguages = [];
    var currentSelected = dropdown.value;
    
    document.querySelectorAll('input[name="multilang_languages[]"]').forEach(function(checkbox) {
        if (checkbox.checked) {
            checkedLanguages.push(checkbox.value);
        }
    });
    
    dropdown.innerHTML = "";
    
    checkedLanguages.forEach(function(langCode) {
        var langInfo = langData.find(function(lang) { return lang.code === langCode; });
        var langName = langInfo ? langInfo.name : langCode.toUpperCase();
        var flag = langInfo ? langInfo.flag : "img/flags/" + langCode + ".svg";
        
        // Fix flag filename
        if (langCode === "zh") flag = "img/flags/cn.svg";
        if (langCode === "ja") flag = "img/flags/jp.svg";
        if (langCode === "ko") flag = "img/flags/kr.svg";
        if (langCode === "he") flag = "img/flags/il.svg";
        if (langCode === "uk") flag = "img/flags/ua.svg";
        if (langCode === "ar") flag = "img/flags/sa.svg";
        if (langCode === "sv") flag = "img/flags/se.svg";
        if (langCode === "da") flag = "img/flags/dk.svg";
        if (langCode === "cs") flag = "img/flags/cz.svg";
        if (langCode === "el") flag = "img/flags/gr.svg";
        
        var option = document.createElement("option");
        option.value = langCode;
        option.textContent = langName + " (" + langCode.toUpperCase() + ")";
        option.setAttribute("data-flag", pluginUrl + "/" + flag);
        
        if (langCode === currentSelected) {
            option.selected = true;
        }
        
        dropdown.appendChild(option);
    });
    
    if (checkedLanguages.length > 0 && (!currentSelected || !checkedLanguages.includes(currentSelected))) {
        dropdown.selectedIndex = 0;
    }
    
    if (checkedLanguages.length > 0 && dropdown.selectedIndex === -1) {
        dropdown.selectedIndex = 0;
    }
    
    updateDropdownFlag();
}

function updateDropdownFlag() {
    var dropdown = document.getElementById("multilang_default_language");
    var selectedOption = dropdown.options[dropdown.selectedIndex];
    if (selectedOption) {
        var flagUrl = selectedOption.getAttribute("data-flag");
        if (flagUrl) {
            dropdown.style.backgroundImage = "url(" + flagUrl + ")";
        }
    }
}

function validateLanguageSelection(changedCheckbox) {
    var checkedBoxes = document.querySelectorAll('input[name="multilang_languages[]"]:checked');
    
    if (checkedBoxes.length === 0) {
        changedCheckbox.checked = true;
        alert("At least one language must be selected.");
        return false;
    }
    return true;
}


document.addEventListener('DOMContentLoaded', function() {
    // Store selected languages in window.selectedLanguages
        function updateSelectedLanguages() {
            var langs = [];
            document.querySelectorAll('input[name="multilang_languages[]"]:checked').forEach(function(checkbox) {
                langs.push(checkbox.value);
            });
            window.selectedLanguages = langs;
            window.dispatchEvent(new Event('selectedLanguagesChanged'));
        }
        updateSelectedLanguages();
        document.querySelectorAll('input[name="multilang_languages[]"]').forEach(function(checkbox) {
            checkbox.addEventListener("change", function() {
                if (validateLanguageSelection(this)) {
                    updateDefaultLanguageDropdown();
                }
                updateSelectedLanguages();
            });
        });
    // Intercept Languages tab form submit
    var langForm = document.querySelector('#tab-languages form');
    if (langForm) {
        langForm.addEventListener('submit', function(e) {
            e.preventDefault();
            var formData = new FormData(langForm);
            formData.append('action', 'save_languages_ajax');
            var xhr = new XMLHttpRequest();
            xhr.open('POST', multilangVars.ajaxUrl, true);
            xhr.onreadystatechange = function() {
                if (xhr.readyState === 4) {
                    if (xhr.status === 200) {
                        try {
                            var resp = JSON.parse(xhr.responseText);
                            if (resp.success) {
                                // Show success message
                                //alert('Languages saved!');
                                // Optionally reload or update UI
                                //window.location.reload();
                            } else {
                                alert('Error: ' + (resp.data && resp.data.message ? resp.data.message : 'Unknown error'));
                            }
                        } catch (e) {
                            alert('Error saving languages.');
                        }
                    } else {
                        alert('Error saving languages.');
                    }
                }
            };
            xhr.send(formData);
        });
    }

    document.querySelectorAll('input[name="multilang_languages[]"]').forEach(function(checkbox) {
        checkbox.addEventListener("change", function() {
            if (validateLanguageSelection(this)) {
                updateDefaultLanguageDropdown();
            }
        });
    });

    document.getElementById("multilang_default_language").addEventListener("change", updateDropdownFlag);
    updateDropdownFlag();
});

function switchTab(tabName, clickedElement) {
    var tabContents = document.querySelectorAll(".multilang-tab-content");
    tabContents.forEach(function(tab) {
        tab.style.display = "none";
    });
    
    var selectedTab = document.getElementById("tab-" + tabName);
    if (selectedTab) {
        selectedTab.style.display = "block";
    }
    
    var navTabs = document.querySelectorAll(".nav-tab");
    navTabs.forEach(function(tab) {
        tab.classList.remove("nav-tab-active");
    });
    
    if (clickedElement) {
        clickedElement.classList.add("nav-tab-active");
    }
    
    return false;
}

