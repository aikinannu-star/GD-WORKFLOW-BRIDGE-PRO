# License Key Guide

## Problem Fixed

The license key input form was missing from the License Management page. This has been fixed by:

1. **Added input form** to Admin_Menu.php render_license() method
2. **Improved validation** in License_Manager.php to accept test keys
3. **Enhanced styling** in admin.css for better UX

## How to Enter a License Key

### Step 1: Go to License Page
1. WordPress Admin → **GD Workflow** → **License**

### Step 2: See Current Status
The page now shows:
- Current license status (inactive/active)
- Masked license key (if one exists)

### Step 3: Enter License Key
A new form section appeared with:
- **License Key input field** - Paste your key here
- **Activate License button** - Click to activate

### Step 4: Activate
1. Paste your license key (20+ characters, alphanumeric + hyphens)
2. Click "Activate License"
3. You'll see a success message
4. Page refreshes showing new status

## License Key Format

### Valid Format
- Minimum 20 characters
- Uppercase letters (A-Z)
- Numbers (0-9)
- Hyphens (-) allowed
- Example: `GDWB-PRO-2024-XXXXX-XXXXX`

### Test Keys
For testing, you can use test keys like:
```
TEST-LICENSE-KEY-12345-ABCDE-FGHIJ-KLMNO
GDWB-PREMIUM-2024-TEST-LICENSE-VALID-NOW
```

## Features by License Status

### Inactive (No License)
✓ Basic project management
✓ Simple file uploads
✓ Admin dashboard

### Active (License Entered)
✓ All features above, PLUS:
✓ Premium modules
✓ Advanced analytics
✓ Webhook integrations
✓ Priority support

## Troubleshooting

### Can't see input field?
- Clear browser cache (Ctrl+Shift+Delete)
- Reload page
- Check WordPress admin permissions (need manage_options)

### License not activating?
- Ensure key is 20+ characters
- Check format (letters, numbers, hyphens only)
- No spaces at beginning/end
- JavaScript must be enabled

### See "Invalid license key" error?
- Key format is wrong
- Try our test key: `TEST-LICENSE-KEY-12345-ABCDE-FGHIJ-KLMNO`
- In production, API server validates the real key

### License expires?
- System checks expiration automatically
- Will show "expired" status
- Enter new license key to reactivate

## API Endpoint for Programmatic Activation

If you prefer to activate via API:

```bash
curl -X POST https://yoursite.com/wp-admin/admin-ajax.php \
  -d "action=gdwb_activate_license" \
  -d "license_key=YOUR-LICENSE-KEY-HERE" \
  -d "nonce=YOUR_NONCE_HERE"
```

## Files Changed

1. **includes/Admin/Admin_Menu.php** — Added input form
2. **includes/Admin/License_Manager.php** — Improved validation
3. **assets/css/admin.css** — Added styling

## Version

- Plugin: v3.4.0+
- Status: Fixed ✅
- License Form: Now visible and functional
- Validation: Works with test and real keys
