# GD Workflow Bridge Pro — Quick Start Guide

## 5-Minute Setup

### Step 1: Activate Plugin
1. Go to WordPress Admin → Plugins
2. Find "GD Workflow Bridge Pro"
3. Click "Activate"
4. You'll see new menu: "GD Workflow"

### Step 2: Set Up WooCommerce Categories
1. Go to Products → Categories
2. Confirm these exist:
   - Physical Products
   - Digital Products
   - Services ← (This is the key one!)
3. If not, go back to GD Workflow → License and re-activate

### Step 3: Create a Service Product
1. Go to Products → Add New
2. Set Product Name: "Web Design Service"
3. Set Price: $500
4. Go to Product Data → Categories
5. Check "Services" (the category we set up in Step 2)
6. Publish

### Step 4: Test the Automation
1. Create a test order (or place real order if live):
   - Customer: John Doe (john@example.com)
   - Add: Web Design Service product
   - Mark order as Completed
2. Check: GD Workflow → Projects
3. You should see: "Service Project - Order #123"

### Step 5: Share Client Portal
1. Go to Pages → Add New
2. Title: "My Project Dashboard"
3. In content editor, add shortcode:
   ```
   [gdwb_project_dashboard]
   ```
4. Publish
5. Give this page URL to your customer
6. They can now upload files, chat, and request revisions!

## Admin Dashboard

### GD Workflow Menu
- **Dashboard** – Overview stats and quick actions
- **Projects** – All service projects
- **Analytics** – Revenue, projects, files metrics
- **Settings** – Configure email, webhooks, general
- **License** – Activate premium (if purchased)

### What Happens Automatically
✅ Customer purchases service product → Project created  
✅ Project created → Customer gets email  
✅ Customer views [gdwb_project_dashboard] → Can upload files  
✅ Customer requests revision → Staff gets notified  
✅ All activity logged → Audit trail in dashboard  

## Customer Experience

When customer visits `[gdwb_project_dashboard]`:

### Files Tab
- Drag and drop files to upload
- See all project files with uploader info
- Download files (if permissions allow)

### Chat Tab
- Send messages to your staff
- Check "Private message" for internal-only notes
- See full message history
- Messages update every 5 seconds

### Forms Tab
- **Request a Revision** – Describe what to change
- **Submit Requirements** – Upload project specs

### Timeline Tab
- See all project activity
- Who did what and when
- Complete audit trail

## Behind the Scenes

### Database
Plugin creates these automatically:
- `wp_gdwb_projects` – Project records
- `wp_gdwb_chat` – Message history
- `wp_gdwb_files` – Uploaded files
- `wp_gdwb_notifications` – Alerts
- `wp_gdwb_timeline` – Activity log
- `wp_gdwb_audit_log` – Admin changes
- (Plus analytics and other tables)

### REST API
Developers can integrate via:
```bash
curl https://yoursite.com/wp-json/gdwb/v1/projects
curl https://yoursite.com/wp-json/gdwb/v1/stats
curl https://yoursite.com/wp-json/gdwb/v1/chat/123/messages
```

### WP-CLI
Power users can automate:
```bash
wp gdwb project list
wp gdwb project create "New Project"
wp gdwb analytics
```

## Customization

### Customize Email Templates
1. Open: `/wp-content/plugins/gd-workflow-bridge-pro/templates/emails/`
2. Edit `created.php` and `updated.php`
3. Add your company branding, custom message

### Customize Dashboard Look
1. Go to Appearance → Customize
2. Add custom CSS for `.gdwb-project-dashboard` class
3. Or edit `/assets/css/project-dashboard.css`

### Add Custom Forms
1. Use the Forms Manager module
2. Add new form type in `/includes/Projects/Forms_Manager.php`
3. Create REST endpoint
4. Add to dashboard template

## Troubleshooting

### Projects Not Creating
- Check: Product is in "Services" category
- Check: Order status is "Completed"
- Check: WooCommerce is active
- Fix: Go to GD Workflow → License → Reactivate

### Chat Not Working
- Check: JavaScript is enabled
- Check: Browser console for errors
- Check: 5-second polling is active
- Fix: Refresh page

### Files Not Uploading
- Check: Max file size is 50MB
- Check: File type is allowed (PDF, images, docs, ZIP)
- Check: File permissions on /uploads
- Fix: Check PHP error logs

### Customers Can't See Dashboard
- Check: Page published with [gdwb_project_dashboard]
- Check: Customer is logged in
- Check: Customer owns the order
- Fix: Verify customer ID in order

## Performance Tips

### For Large Teams
- Enable WP-Cron for background jobs
- Use Memcached for faster queries
- Monitor dashboard stats monthly

### For Busy Shops
- Archive old projects after 1 year
- Clean up old notifications quarterly
- Monitor file vault disk space

### For Mobile Users
- Dashboard is responsive (tested on all devices)
- Polling interval is 5 seconds (battery friendly)
- Files load progressively (large vault safe)

## Support & Docs

Full Documentation:
- `ARCHITECTURE.md` – Technical reference
- `IMPLEMENTATION_SUMMARY.md` – Feature overview
- Code comments in PHP files

## What's Next?

### Immediate
1. ✅ Create test order
2. ✅ Share dashboard with customer
3. ✅ Monitor analytics

### This Week
1. Set up email templates
2. Create 3-5 service products
3. Test with real customer

### This Month
1. White-label admin branding
2. Set up webhooks for Slack
3. Train team on admin features

---

**Congratulations!** You now have a professional service delivery platform. 🎉

Questions? Check ARCHITECTURE.md or review the admin dashboard for hints.
