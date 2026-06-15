# License System - Debug Session Summary

## Issues Found & Fixed

### 1. REST API 403 Permission Error
**Problem:** `/wp-json/gdwb/v1/stats` endpoint returning 403 Forbidden

**Root Cause:** 
- Stats endpoint permission callback was too strict
- Admin users not properly identified in permission check

**Solution:**
- Updated `Stats_API.php` - Changed permission callback to check `manage_options` first
- Changed REST nonce from `gdwb_admin_nonce` to standard `wp_rest`
- Added error handler to dashboard to show when stats can't load

### 2. License Activation Flow
**Files Updated:**
- `includes/Admin/License_Manager.php` - Full implementation with validation, expiry, hash verification
- `includes/Admin/Admin_Menu.php` - Better nonce creation and REST nonce setup
- `assets/js/admin.js` - Improved AJAX handling with error messages

**Key Improvements:**
- License key format validation (20+ chars, alphanumeric + hyphens)
- Automatic expiry calculation (30 days for TEST-, 1 year for normal)
- Hash-based verification to prevent tampering
- Deactivation support
- Better error handling and user messages

### 3. AJAX Communication
**Changes:**
- Licensed both `admin.js` to use correct AJAX URL from localized script
- Added `ajax_url` to `wp_localize_script()` output
- Implemented helper function `licenseRequest()` for consistent AJAX calls

## How License Activation Works Now

### Flow:
1. Admin loads `/wp-admin/admin.php?page=gdwb-license`
2. Page displays current license status (masked key, expiry date, activated timestamp)
3. Admin enters license key and clicks "Activate License"
4. JavaScript validates the key format (20+ characters, alphanumeric + hyphens)
5. AJAX POST to `wp_ajax_gdwb_activate_license` with nonce verification
6. License_Manager verifies nonce and admin capability
7. License key is validated via `validate_license_format()`
8. Options are stored:
   - `gdwb_license_key`
   - `gdwb_license_status` = "active"
   - `gdwb_license_expiry` (calculated based on key type)
   - `gdwb_license_activated_at`
   - `gdwb_license_domain`
   - `gdwb_license_hash`
9. Page reloads and displays new status

### Automatic License Checking:
- `check_license()` hooked to both `init` and `admin_init`
- Checks if license has expired
- Validates hash hasn't been tampered with
- Updates status to "expired" or "invalid" if issues found

## Testing Recommended

1. **Test License Activation:**
   ```
   Key: GDWB-PRO-2026-TEST-LICENSE-KEY
   Expected: Status becomes "active", expires in 30 days
   ```

2. **Test License Deactivation:**
   - Click "Deactivate License" button
   - Expected: Status becomes "inactive"

3. **Test with Expired License:**
   - Set expiry to past date manually in database
   - Reload page
   - Expected: Status becomes "expired"

4. **REST API Stats Call:**
   - Open browser console on dashboard
   - Should see stats loading without 403 error
   - Check Network tab shows successful response

## Files with Changes

```
includes/Admin/License_Manager.php
├── Added deactivate_license() method
├── Added license hash verification
├── Improved check_license() logic
├── Added metadata tracking (activation date, domain)
└── Better expiry calculation

includes/Admin/Admin_Menu.php
├── Updated enqueue_admin_assets() with wp_rest nonce
├── Improved render_license() UI
├── Added deactivate button for active/expired licenses
└── Added message container for AJAX responses

includes/API/Stats_API.php
├── Simplified stats_permission() callback
├── Admin check moved first
└── Better is_user_logged_in() handling

assets/js/admin.js
├── Added showLicenseMessage() function
├── Added clearLicenseMessage() function
├── Added licenseRequest() helper
├── Deactivation AJAX handler
└── Better error handling with console logging

tests/test-license.php
├── Added format validation test
├── Added expiry transition test
└── Improved test key format
```

## Next Phase: Premium Feature Gating

After license system is confirmed working:

1. Use `is_license_active()` to gate premium features
2. Example in templates/features:
   ```php
   if ($license_manager->is_license_active()) {
       // Show premium feature
   } else {
       // Show upgrade prompt
   }
   ```

3. Track which features are premium vs free
4. Consider time-based upgrades (e.g., anniversary licenses)
