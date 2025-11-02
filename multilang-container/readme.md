# Translation Manager for Multilang Container

A comprehensive PHP settings page that allows you to load, edit, and manage translations stored in a JSON file. This system provides an easy-to-use interface for managing multilingual content in your WordPress site.

## Features

- ✅ **User-friendly Admin Interface** - Manage translations through WordPress admin
- ✅ **JSON File Management** - Automatically reads and writes to `data/translations.json`
- ✅ **Category Organization** - Organize translations by categories (ui, footer, calendar, etc.)
- ✅ **Multi-language Support** - Supports all languages configured in your plugin settings
- ✅ **Add/Delete Keys** - Dynamically add new translation keys or remove existing ones
- ✅ **PHP Helper Functions** - Easy-to-use functions for accessing translations in themes/plugins
- ✅ **JavaScript Integration** - Translations available on frontend via JavaScript
- ✅ **Shortcode Support** - Use `[translate]` shortcode in content
- ✅ **Fallback System** - Automatic fallback to English if translation missing

## How to Access

1. **WordPress Admin** → **Settings** → **Translation Manager**
2. Or navigate to: `/wp-admin/options-general.php?page=multilang-translations`

## File Structure

```
wp-content/plugins/multilang-container/
├── translations-settings.php    # Main settings page
├── demo-usage.php              # Usage examples and shortcode
├── data/
│   └── translations.json       # Your translation data
└── multilang-container.php     # Main plugin file (modified)
```

## JSON File Format

```json
{
  "ui": {
    "Submit": { "en": "Submit", "de": "Absenden", "it": "Invia" },
    "Next": { "en": "Next", "de": "Weiter", "it": "Successivo" }
  },
  "footer": {
    "Contact": { "en": "Contact", "de": "Kontakt", "it": "Contatto" },
    "Email": { "en": "Email", "de": "E-Mail", "it": "Email" }
  }
}
```

## Usage Examples

### 1. PHP Function (Recommended)

```php
// Get translation with category
echo multilang_get_translation('Submit', 'ui');

// Get translation without category (searches all)
echo multilang_get_translation('Contact');

// Get translation in specific language
echo multilang_get_translation('Submit', 'ui', 'de');
```

### 2. In Theme Templates

```php
<!-- Form example -->
<form>
    <input type="text" placeholder="<?php echo esc_attr(multilang_get_translation('Search...', 'footer')); ?>">
    <input type="submit" value="<?php echo esc_attr(multilang_get_translation('Search', 'footer')); ?>">
</form>

<!-- Footer example -->
<footer>
    <h4><?php echo multilang_get_translation('Contact', 'footer'); ?></h4>
    <p><?php echo multilang_get_translation('Phone', 'footer'); ?>: +1 234 567 890</p>
</footer>
```

### 3. Shortcode in Content

```
[translate key="Submit" category="ui"]
[translate key="Contact" category="footer" lang="de"]
[translate key="Next"]
```

### 4. JavaScript (Frontend)

```javascript
// Function is automatically loaded on frontend
function getTranslation(key, category, lang) {
    // Implementation provided in demo-usage.php
}

// Usage
const submitText = getTranslation('Submit', 'ui');
const contactText = getTranslation('Contact', 'footer', 'de');
```

## Admin Interface Features

### Adding New Translations
1. Enter **Category** (e.g., "ui", "footer", "calendar")
2. Enter **Translation Key** (e.g., "Submit", "Contact", "Next")
3. Click **Add Key**
4. Fill in translations for all languages
5. Click **Save All Translations**

### Managing Existing Translations
- **Edit**: Modify any translation text in the textarea fields
- **Delete**: Click "Delete" button next to any translation key
- **Organization**: Translations are grouped by category with collapsible sections

### File Management
- **Auto-creation**: JSON file is created automatically on first save
- **Permissions**: Displays file status and location
- **Backup**: Always backup your `translations.json` before major changes

## Language Configuration

Languages are managed in **Settings** → **Multilang Container**. The Translation Manager automatically uses the languages you've configured there.

## API Reference

### PHP Functions

#### `multilang_get_translation($key, $category = null, $lang = null)`
- **$key** (string, required): Translation key to look up
- **$category** (string, optional): Category to search in, searches all if null
- **$lang** (string, optional): Language code, uses current language if null
- **Returns**: Translated string or original key if not found

#### `load_translations()`
- **Returns**: Array of all translations from JSON file

#### `save_translations($translations)`
- **$translations** (array): Full translations array to save
- **Returns**: Boolean success status

### JavaScript Objects

#### `multilangLangBar.translations`
- Contains the full translations object
- Available after page load
- Updated automatically when JSON file changes

### Shortcode

#### `[translate key="" category="" lang=""]`
- **key** (required): Translation key
- **category** (optional): Category to search in
- **lang** (optional): Specific language code

## Security Features

- ✅ Nonce verification for all form submissions
- ✅ Input sanitization for all fields
- ✅ File path validation
- ✅ Admin capability checks (`manage_options`)
- ✅ XSS protection with proper escaping

## Troubleshooting

### Common Issues

1. **"Failed to save translations"**
   - Check file permissions on `wp-content/plugins/multilang-container/data/`
   - Ensure WordPress can write to the directory

2. **"Translations not showing"**
   - Verify the JSON file exists and contains valid JSON
   - Check that languages are properly configured in plugin settings
   - Clear any caching plugins

3. **"JavaScript translations not working"**
   - Ensure `multilang-container.js` is loading
   - Check browser console for JavaScript errors
   - Verify the page has finished loading before accessing translations

### File Permissions
```bash
# Set proper permissions (if needed)
chmod 755 wp-content/plugins/multilang-container/data/
```

## Best Practices

1. **Organize by Purpose**: Use logical categories like "ui", "footer", "navigation"
2. **Consistent Naming**: Use clear, descriptive keys like "Submit" not "btn1"
3. **Backup Regularly**: Keep backups of your `translations.json` file
4. **Test All Languages**: Verify translations work for all configured languages
5. **Use Fallbacks**: The system falls back to English, so always provide English translations

## Integration with Existing Systems

This Translation Manager works alongside the existing Multilang Container plugin features:

- **Blocks**: Continue using multilang blocks for content
- **Language Switching**: Existing language switching functionality remains unchanged
- **CSS**: Existing language-specific CSS rules continue to work
- **Cookies**: Same language detection system via cookies

## Support

For issues or questions:
1. Check the demo file: `demo-usage.php`
2. Verify your JSON file structure
3. Ensure proper file permissions
4. Test with a simple translation first

---

**File Location**: `/wp-content/plugins/multilang-container/data/`
**Admin Page**: **Settings** → **Translation Manager**
**Version**: 1.0