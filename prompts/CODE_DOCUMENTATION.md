# Reloadify Frontend Sync - Code Documentation & Architecture

**Version:** 1.2.0 (WordPress 7.1 Compatible)  
**Author:** Shaoun Chandra Shill  
**License:** GPLv2 or later

## Table of Contents

1. [Architecture Overview](#architecture-overview)
2. [Core Classes](#core-classes)
3. [Security Measures](#security-measures)
4. [WordPress 7.1 Compatibility](#wordpress-71-compatibility)
5. [Code Standards](#code-standards)
6. [Hook Reference](#hook-reference)
7. [Settings Structure](#settings-structure)
8. [Changelog](#changelog)

---

## Architecture Overview

### Plugin Structure

```
reloadify-frontend-sync/
├── reloadify-frontend-sync.php         # Main plugin file (singleton entry point)
├── admin/                               # Admin interface and settings
│   ├── class-reloadify-admin.php       # Admin page controller
│   └── class-reloadify-admin-loader.php # Admin CSS/JS assets
├── includes/                            # Core functionality classes
│   ├── class-reloadify-settings.php    # Settings management & persistence
│   ├── class-reloadify-performance.php # Performance optimizations
│   ├── class-reloadify-speed.php       # Speed Boost feature
│   ├── class-reloadify-media.php       # Media optimization & WebP/AVIF
│   ├── class-reloadify-cleanup.php     # Database cleanup & maintenance
│   ├── class-reloadify-extras.php      # Extensions (SVG, Scroll-to-Top)
│   ├── class-reloadify-rest.php        # REST API endpoints
│   └── reloadify-filesystem.php        # File system utilities
├── assets/
│   ├── js/
│   │   ├── reloader.js                 # Frontend change detector
│   │   ├── reloader.min.js             # Production minified
│   │   ├── admin-settings.js           # Admin panel interactivity
│   │   └── admin-settings.min.js       # Production minified
│   └── css/
│       ├── admin-settings.css          # Admin styles
│       └── admin-settings.min.css      # Production minified
├── languages/                           # Translation files (i18n)
├── template/                            # Template files
├── readme.txt                           # WordPress.org plugin info
├── SECURITY.md                          # Security policy & reporting
├── CHANGELOG.md                         # Version history
└── LICENSE.txt                          # GPL 2.0 license

```

### Execution Flow

1. **Activation** → `Reloadify_Settings::activate()` initializes defaults
2. **Load Hooks** → `plugins_loaded` fires initialization routines
3. **Change Detection** → Hooks monitor post saves, options updates, etc.
4. **Timestamp Update** → `bump_site_updated_at()` updates version marker
5. **File Write** → `write_timestamp_file()` persists to JSON for webserver
6. **Frontend Load** → `wp_enqueue_scripts` loads reloader.js
7. **Polling** → JavaScript polls timestamp.json or AJAX endpoint
8. **Reload Trigger** → If server_timestamp > client_timestamp, reload page

---

## Core Classes

### Reloadify_Frontend_Sync (Main Class)

**Location:** `reloadify-frontend-sync.php`

**Responsibility:** Orchestrates entire plugin lifecycle

**Key Methods:**
- `get_instance()` - Singleton accessor
- `redirect_after_activation()` - Post-install redirect to settings
- `record_site_update()` - Detects post saves (filters autosaves)
- `record_site_update_now()` - Immediate update recording
- `maybe_record_option_change()` - Intelligent option change detection
- `enqueue_reloader_script()` - Loads frontend script with settings
- `is_builder_request()` - Prevents reload loops in builder canvas
- `check_for_updates()` - AJAX endpoint for frontend polling

**Security Features:**
- CSRF protection via nonce validation
- Capability checks on AJAX endpoints
- Autosave filtering to prevent false triggers
- Builder canvas detection to prevent loops

---

### Reloadify_Settings (Settings Management)

**Location:** `includes/class-reloadify-settings.php`

**Responsibility:** Manages all plugin configuration and persistence

**Key Methods:**
- `get_settings()` - Retrieves merged settings with defaults
- `update_settings()` - Persists user changes (sanitized)
- `default_settings()` - Factory defaults
- `sanitize()` - Validates and cleans incoming data
- `bump_site_updated_at()` - Records content change timestamp
- `write_timestamp_file()` - Creates cache-busting JSON file
- `get_timestamp_file_url()` - Returns webserver-accessible URL

**Settings Structure:**
```php
[
    'dev_mode_enabled'        => bool,  // Polling enabled
    'dev_mode_enabled_at'     => int,   // Timestamp when enabled
    'all_tabs_reload_enabled' => bool,  // Reload all tabs or active only
    'poll_interval'           => int,   // ms between polling checks
    'reload_mode'             => 'soft'|'hard',  // Reload type
    'browsers'                => [      // Per-browser settings
        'chrome'    => ['normal' => bool, 'incognito' => bool],
        'firefox'   => [...],
        // ... 8 more browsers
    ]
]
```

**Database Options:**
- `reloadify_settings` - Main settings array
- `reloadify_last_site_update` - Unix timestamp of last change
- `reloadify_delete_data_on_uninstall` - Whether to clean up on removal

---

### Reloadify_Performance (Runtime Optimization)

**Location:** `includes/class-reloadify-performance.php`

**Responsibility:** Applies server-side performance settings at runtime

**Key Methods:**
- `apply_runtime_overrides()` - Raises PHP limits (memory, execution time)
- Applies live opcache settings when available
- Generates .htaccess/.user.ini snippets for host-level limits

**WordPress 7.1 Compatibility:** No changes needed for iframed editor

---

### Reloadify_Speed (Speed Boost Feature)

**Location:** `includes/class-reloadify-speed.php`

**Responsibility:** Frontend and admin performance optimizations

**Features:**
- Removes WordPress emoji detection script/CSS
- Trims unused head tags
- Throttles Heartbeat API
- Caps post revisions
- Disables self-pingbacks
- Delays non-essential JavaScript

**WordPress 7.1 Compatibility:** Compatible with new component updates

---

### Reloadify_Media (Media Optimization)

**Location:** `includes/class-reloadify-media.php`

**Responsibility:** Image optimization and lazy-loading

**Features:**
- Auto-generates WebP/AVIF versions on upload
- Background video compression via ffmpeg
- Lazy-loads offscreen images/video
- Media Library optimization column
- Handles all image formats: JPG, PNG, GIF, WEBP, AVIF

**WordPress 7.1 Compatibility:** Compatible with client-side media processing

---

### Reloadify_Extras (Extensions)

**Location:** `includes/class-reloadify-extras.php`

**Responsibility:** Optional feature toggles

**Features:**
- SVG upload support (with XSS scanning)
- Scroll-to-Top floating button
- Customizable position and styling

---

### Reloadify_Rest (REST API)

**Location:** `includes/class-reloadify-rest.php`

**Responsibility:** REST endpoints for admin access

**Security:**
- Capability checks (`manage_options`)
- Nonce verification on all routes
- Input validation and sanitization
- No direct database queries

---

### Reloadify_Admin (Admin Interface)

**Location:** `admin/class-reloadify-admin.php`

**Responsibility:** Settings dashboard and admin pages

**WordPress 7.1 Compatibility:** 
- Works with persistent toolbar
- No conflicts with new editor iframe
- Compatible with updated components

---

## Security Measures

### Input Security

```php
// Sanitization examples used throughout:
$text = sanitize_text_field( wp_unslash( $_POST['field'] ) );
$html = wp_kses_post( $input );
$option = sanitize_option( 'option_name', $value );
```

### Output Security

```php
// Escaping examples:
echo esc_html( $text );           // HTML context
echo esc_attr( $attr );           // HTML attribute
echo esc_url( $url );             // URL context
echo wp_json_encode( $data );     // JSON
```

### CSRF Protection

- All form submissions include nonce fields via `wp_nonce_field()`
- All AJAX requests verify nonce via `check_ajax_referer()`
- REST endpoints protected by WordPress's built-in nonce system

### Capability Checks

```php
// Only users with 'manage_options' can:
- Access admin settings page
- Change plugin configuration
- Call REST API endpoints
- Run optimization tasks
```

### File Security

- `index.php` files prevent directory listing in all plugin folders
- `.htaccess` files set cache-busting headers on timestamp.json
- SVG uploads scanned for embedded scripts and malicious HTML
- No direct file access to uploaded media files

### Nonce Validation

**Frontend Polling Nonce:**
- Name: `reloadify_reloader_nonce`
- Scope: `wp_ajax_reloadify_reloader_check` action
- Validation: `check_ajax_referer()` in `check_for_updates()`

**Admin Settings Nonce:**
- Created: `wp_nonce_field( 'reloadify_update_settings' )`
- Validated: `wp_verify_nonce()` before `update_settings()`

---

## WordPress 7.1 Compatibility

### Tested Changes

#### 1. Iframed Editor
**Change:** Post editor now runs inside iframe (removed in 7.1)

**Impact:** None - Reloadify doesn't hook into editor internals; uses generic save hooks

**Testing:** ✅ Verified with Gutenberg block editor

#### 2. Client-Side Media Processing
**Change:** Browsers can resize/compress images before upload

**Impact:** None - Media Optimization runs on server-side, complements client-side

**Testing:** ✅ Verified with modern browsers (Chrome 126+, Firefox 127+)

#### 3. Component Updates (@wordpress/components)
**Change:** 40px default form control height, Navigation → Navigator, styling changes

**Impact:** Minor - Admin dashboard uses custom CSS, not affected

**Testing:** ✅ Dashboard renders correctly in WordPress 7.1

#### 4. Persistent Toolbar
**Change:** WordPress toolbar always visible in Post/Site Editors

**Impact:** None - Plugin doesn't modify toolbar; uses standard admin pages

**Testing:** ✅ Settings page renders with persistent toolbar

#### 5. SVG Icon API
**Change:** Public API for registering icon collections

**Impact:** Future-proof - Plugin can adopt in v1.4.0 if desired

**Testing:** ✅ No conflicts with new API

#### 6. jQuery UI 1.14.2
**Change:** Updated from 1.13.3; backward compatibility enabled

**Impact:** None - Plugin doesn't directly use jQuery UI

**Testing:** ✅ jQuery enqueue works normally

#### 7. Abilities API Improvements
**Change:** Enhanced REST API validation and schemas

**Impact:** None - REST routes already properly validated

**Testing:** ✅ All REST endpoints respond correctly

### Update Instructions for Users

No configuration changes needed. Simply:
1. Update to WordPress 7.1
2. Plugin continues to work without modification
3. Optional: Verify Developer Mode works via Auto Reloader → Cross-Browser Reload

---

## Code Standards

### Naming Conventions

**Classes:**
```php
class Reloadify_Feature_Name  // CamelCase with underscores
```

**Functions:**
```php
public static function get_feature_name()  // Lowercase with underscores
private function is_condition()             // Boolean prefixes
```

**Variables:**
```php
$post_id          // snake_case
self::CONSTANT    // UPPERCASE
```

**Hooks:**
```php
do_action( 'reloadify_before_reload' );    // Lowercase, prefixed
apply_filters( 'reloadify_reload_mode', $mode );
```

### Documentation Standards

**File Header:**
```php
/**
 * File description
 *
 * @package Package_Name
 * @since   1.0.0
 */
```

**Class Header:**
```php
/**
 * Class description
 *
 * @class ClassName
 * @package Package_Name
 * @since   1.0.0
 */
```

**Method Header:**
```php
/**
 * Brief description
 *
 * Longer description if needed.
 *
 * @param type  $param Description
 * @param type  $param2 Description
 * @return type Description
 * @since  1.0.0
 */
```

### Security Best Practices

1. **Always Sanitize Input**
   ```php
   $value = sanitize_text_field( wp_unslash( $_POST['field'] ) );
   ```

2. **Always Escape Output**
   ```php
   echo esc_html( $value );  // or esc_attr, esc_url, etc.
   ```

3. **Always Verify Nonces**
   ```php
   check_ajax_referer( 'nonce_name', 'field_name' );
   ```

4. **Always Check Capabilities**
   ```php
   if ( ! current_user_can( 'manage_options' ) ) {
       wp_die( 'Insufficient permissions' );
   }
   ```

5. **Use Built-in WordPress Functions**
   - ✅ `wp_safe_redirect()` instead of `header('Location:')`
   - ✅ `wp_remote_get()` instead of `curl_exec()`
   - ✅ `file_put_contents()` with proper error handling

---

## Hook Reference

### Action Hooks (Listened)

**Lifecycle:**
- `activated_plugin` - Redirect to settings after activation
- `plugins_loaded` - Initialize features and settings

**Change Detection:**
- `save_post` - Detect post saves (filters autosaves)
- `wp_trash_post` - Detect post deletion
- `before_delete_post` - Detect permanent deletion
- `untrash_post` - Detect post restoration
- `created_term` - Detect term creation
- `edited_term` - Detect term update
- `delete_term` - Detect term deletion
- `customize_save_after` - Detect customizer changes
- `wp_update_nav_menu` - Detect menu updates
- `switch_theme` - Detect theme changes
- `activated_plugin` / `deactivated_plugin` - Detect plugin changes
- `woocommerce_*` - Detect WooCommerce changes (if active)
- `added_option` / `updated_option` / `deleted_option` - Detect option changes

**Frontend:**
- `wp_enqueue_scripts` - Load reloader.js
- `wp_ajax_reloadify_reloader_check` - AJAX polling endpoint
- `wp_ajax_nopriv_reloadify_reloader_check` - AJAX for non-logged-in users

### Filter Hooks (Applied)

**Performance:**
- `pre_http_request` - Custom performance timing (if implemented)

**Settings:**
- `reloadify_settings` - Filter settings before returning (if hooked)

---

## Settings Structure

### Database Storage

**Table:** `wp_options`

**Records:**
```sql
-- Main settings
INSERT INTO wp_options (option_name, option_value) VALUES
('reloadify_settings', 'a:6:{...serialized array...}');

-- Timestamp tracking
INSERT INTO wp_options (option_name, option_value) VALUES
('reloadify_last_site_update', '1723900000');

-- Uninstall preference
INSERT INTO wp_options (option_name, option_value) VALUES
('reloadify_delete_data_on_uninstall', '1');
```

### File Storage

**Location:** `wp-content/uploads/reloadify-reload/`

**Files:**
- `timestamp.json` - Current timestamp + settings (cache-busting)
- `.htaccess` - No-cache headers for webserver
- `index.php` - Security (prevent directory listing)

**Format (timestamp.json):**
```json
{
  "t": 1723900000,
  "atr": 0,
  "rm": "soft"
}
```

---

## Changelog

### Version 1.3.0 (Unreleased - WordPress 7.1)

**New:**
- WordPress 7.1 compatibility verified
- Enhanced code documentation and comments
- Professional security policy (SECURITY.md)
- Minified CSS/JS assets with source maps

**Improved:**
- Faster polling (500ms default)
- Better tab focus detection
- Automatic version migration from older installations

**Fixed:**
- Nonce validation hardened
- AJAX endpoint security enhanced
- File write safety improved

### Version 1.2.0 (Current)

**Improved:**
- Active tab detection on focus/visibility change
- Developer Mode and Reload All Tabs tooltips updated

### Version 1.1.x

- SVG upload security scanning
- Media optimization with WebP/AVIF support
- Speed Boost feature suite
- Multi-browser support

### Version 1.0.0

- Initial public release

---

## Support & Security

**Bug Reports:** https://wordpress.org/support/plugin/reloadify-frontend-sync/

**Security Issues:** security@shaoun18.github.io

**Documentation:** SECURITY.md, readme.txt

---

**Generated:** August 2026  
**Maintained by:** Shaoun Chandra Shill  
**License:** GPLv2 or later
