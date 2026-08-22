# Security Policy

## Reporting a Vulnerability

If you discover a security vulnerability in Reloadify Frontend Sync, please report it responsibly by emailing **security@shaoun18.github.io** instead of using the public issue tracker.

**Please include:**

- A clear description of the vulnerability
- Steps to reproduce the issue
- Potential impact assessment
- Any proof-of-concept code (if applicable)

We will acknowledge your report within 48 hours and work on a fix as quickly as possible.

## Security Practices

### Code Security

- **Input Validation & Sanitization**: All user input is sanitized using WordPress functions (`sanitize_text_field()`, `wp_kses_post()`, etc.)
- **Output Escaping**: All output to HTML/JavaScript is properly escaped using appropriate functions
- **CSRF Protection**: All admin forms use WordPress nonces via `wp_nonce_field()` and `wp_verify_nonce()`
- **Capability Checks**: All admin functionality is protected with WordPress capability checks (`current_user_can()`)
- **Prepared Statements**: Database queries use proper escaping and prepared statements where applicable

### Frontend Script Security

- Reloader script validates timestamps before triggering page reloads
- AJAX requests include nonce verification
- No eval() or dynamic script injection
- Cross-origin requests handled securely via established WordPress practices

### File Upload Security

- **SVG Upload Protection**: SVG files are scanned for embedded scripts and malicious HTML before acceptance
- Uploads are validated against file type whitelists
- Uploaded files are moved to secure upload directories

### Admin Panel Security

- All admin settings pages require appropriate user capabilities
- Settings are validated and sanitized before storage
- Direct file access is prevented via index.php files in plugin directories

## Supported Versions

| Version | Status              | Security Updates |
| ------- | ------------------- | ---------------- |
| 1.2.x   | ✅ Current          | Yes              |
| 1.1.2   | ⚠️ End of Support | Limited          |
| 1.0.2   | ❌ Deprecated       | No               |

## WordPress & PHP Compatibility

- **Minimum WordPress**: 6.4
- **Tested up to**: 7.1
- **Minimum PHP**: 7.4
- **Recommended PHP**: 8.0+

We recommend keeping WordPress and PHP versions up to date for security patches.

## Dependency Security

This plugin uses only core WordPress functions. No external dependencies or third-party libraries are included.

## Data Privacy

- Settings are stored securely in WordPress options table
- Timestamps are logged to a cache-busting JSON file in wp-content/uploads
- No personal user data is collected or transmitted
- All data is deleted on plugin uninstallation (if enabled in settings)

## Security Audit History

- **v1.2.0** (Aug 2026): Enhanced validation, WordPress 7.1 compatibility testing
- **v1.1.0** (July 2026): SVG security scanning added, AJAX security hardened
- **v1.0.0** (May 2026): Initial release with core security measures

## Best Practices for Users

1. **Update Regularly**: Keep the plugin updated to receive security patches
2. **Use Latest WordPress**: Run the latest stable WordPress version
3. **Strong Admin Passwords**: Use strong passwords for WordPress admin accounts
4. **Limit Developer Access**: Only enable Developer Mode for development environments
5. **Regular Backups**: Maintain regular WordPress backups
6. **Security Plugins**: Consider running WordPress security plugins alongside this plugin

## Contact

For security concerns, contact: **Shaoun Chandra Shill**
Email: cse.engrshaounchandrashill@tutanota.de
Website: https://shaoun18.github.io/
