# License Activation Debug Guide

## Quick Start

1. Go to **WordPress Admin → GD Workflow → License**
2. Enter a test license key (20+ characters, letters/numbers/hyphens only):
   - `GDWB-PRO-2026-TEST-LICENSE-KEY`
   - `TEST-LICENSE-KEY-123456-ABCDE-FGHIJ`
3. Click **Activate License**
4. Should see success message and page reloads

## Troubleshooting

### Issue: 403 Forbidden on Stats Endpoint

**Symptoms:**
- Error in browser console: `/wp-json/gdwb/v1/stats:1 Failed to load resource: the server responded with a status of 403`
- Dashboard doesn't show stats

**Solution (FIXED):**
- Updated `includes/API/Stats_API.php` to allow admin users
- Updated REST API nonce in `includes/Admin/Admin_Menu.php` to use standard `wp_rest` nonce
- Ensure WordPress REST API is accessible at `/wp-json/`

### Issue: License Not Activating

**Symptoms:**
- Form submits but nothing happens
- No success/error message

**Debug Steps:**

1. **Check Browser Console (F12 → Console)**
   - Look for JavaScript errors
   - Check network tab to see if AJAX request is sent
   - Verify `gdwb_admin.license_nonce` is available

2. **Check PHP Error Log**
   - WordPress errors should appear in `wp-content/debug.log`
   - Enable debug mode in `wp-config.php`:
     ```php
     define('WP_DEBUG', true);
     define('WP_DEBUG_LOG', true);
     define('WP_DEBUG_DISPLAY', false);
     ```

3. **Test License Key Format**
   - Must be 20+ characters
   - Uppercase letters (A-Z), numbers (0-9), hyphens (-) only
   - Examples that work:
     - `GDWB-PRO-2026-TEST-LICENSE-KEY`
     - `TEST-LICENSE-KEY-123456-ABCDE-FGHIJ-KLMNO`

4. **Verify Admin Capabilities**
   - Must be logged in as administrator
   - Must have `manage_options` capability
   - Check via WordPress admin or `wp-admin/users.php`

5. **Check License Page Render**
   - Go to License page and right-click → View Page Source
   - Look for `<input type="text" name="license_key"...`
   - Verify nonce field exists: `<input type="hidden" name="nonce"...`
   - Check that `gdwb_admin` object is available in `<script>` tags

## Files Modified for License System

- `includes/Admin/License_Manager.php` - Core license logic
- `includes/Admin/Admin_Menu.php` - License page UI and nonce setup
- `assets/js/admin.js` - License form submission and validation
- `includes/API/Stats_API.php` - Fixed permission checks
- `tests/test-license.php` - Unit tests

## Testing Manually

### Via WordPress Admin

1. Navigate to **GD Workflow → License**
2. Enter `GDWB-PRO-2026-TEST-LICENSE-KEY`
3. Click "Activate License"
4. Verify page shows "Status: active"
5. Verify "Expires" date is shown
6. Verify "Activated on" timestamp is shown

### Via Database Query

After activation, run in phpMyAdmin or CLI:

```sql
SELECT * FROM wp_options WHERE option_name LIKE 'gdwb_license%';
```

Should return:
- `gdwb_license_key` - Your license key
- `gdwb_license_status` - Should be "active"
- `gdwb_license_expiry` - Timestamp (1 year from now for normal keys, 30 days for TEST- keys)
- `gdwb_license_activated_at` - Current timestamp
- `gdwb_license_domain` - Your site URL
- `gdwb_license_hash` - HMAC-SHA256 hash for validation

### Via WP-CLI

```bash
wp option get gdwb_license_status
wp option get gdwb_license_key
wp option get gdwb_license_expiry
```

## Next Steps

After license is activated:
- Premium features unlock (based on `is_license_active()` checks)
- Visit individual feature pages to verify access
- Check admin pages for premium UI elements
