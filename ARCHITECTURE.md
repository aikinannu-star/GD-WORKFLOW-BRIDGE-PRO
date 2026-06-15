# GD Workflow Bridge Pro v3.4.0 — WooCommerce Service Workflow Engine

## Premium Service Delivery Platform

### Product Categories (WooCommerce Integration)
- **Physical Products** — Standard tangible goods, independent workflow
- **Digital Products** — Downloadable items, independent workflow
- **Services** — Custom services category; orders with service products auto-create projects
- Only service products trigger project creation on order completion
- Project inherits customer from WooCommerce order

### WooCommerce Analytics & Dashboard
- **Stats API** – GET /wp-json/gdwb/v1/stats returns aggregated metrics:
  - Total projects (service-based)
  - Total files uploaded
  - Total orders (all types)
  - Total revenue (WooCommerce integration)
  - This month's orders
- User-specific stats (filter by user_id) for customer dashboard
- Admin stats for site-wide reporting

### Services Project System
- Creates projects only for orders containing "Services" category products
- Project meta tracks: order_id, product_ids (services purchased), status
- Service projects appear in admin and customer portals
- Projects linked to WooCommerce customers for permissions

### Files Vault (50MB Secure Storage)
- **REST endpoints**: GET/POST/DELETE files for each project
- Drag-and-drop file upload with validation (PDF, images, docs, ZIP)
- File metadata: uploader, size, timestamps
- Permission checks: project author, admin, order customer
- Stores in custom gdwb_files table with attachment_id reference

### Live Chat System
- **REST endpoints**: GET messages, POST send message
- Private messages (flagged for internal staff communication)
- Real-time polling (5-second interval) for message updates
- Message author, timestamp, and privacy flag
- Stored in gdwb_chat table for audit trail

### Live Notifications
- **REST endpoint**: GET notifications, POST mark-read
- Auto-triggered on: project created, updated, file uploaded, message received
- Project-specific and user-specific notifications
- Notification types: project_created, project_updated, file_uploaded, message_received
- Stored in gdwb_notifications table with payload serialization

### Service Forms (Built-in)
- **Revision Requests**
  - Title, detailed description, priority (low/medium/high)
  - Stored as timeline entries with event type "revision_request"
  - Fires gdwb_revision_requested action

- **Requirements Upload**
  - Requirements text, deadline, optional attachment
  - Stored as timeline entries with event type "requirements_submitted"
  - Fires gdwb_requirements_submitted action

- REST endpoints: POST /forms/{project_id}/revision-request, /requirements
- Retrieve forms via GET /forms/{project_id}/submissions

### Project Client Dashboard
- **Shortcode**: [gdwb_project_dashboard] or [gdwb_project_dashboard project_id="123"]
- **Features**:
  - Files Vault with upload area (drag-and-drop support)
  - Live chat with staff (toggle private messages)
  - Revision request form
  - Requirements submission form
  - Project timeline activity feed
  - Live status indicator
  - Responsive grid layout (2 columns on desktop, 1 on mobile)

- **Permission checks**: Project author, admin, or order customer can access
- **Assets**:
  - assets/js/project-dashboard.js — Live polling, form submission, file upload
  - assets/css/project-dashboard.css — Modern card UI, animations, responsive design

### Database Schema (8 Tables)
1. **gdwb_projects** – post_id, order_id, status, data
2. **gdwb_timeline** – project_id, event_type, message, user_id (audit trail)
3. **gdwb_analytics** – metric_name, metric_value, recorded_at
4. **gdwb_audit_log** – project_id, action, user_id, ip_address, data
5. **gdwb_files** – project_id, attachment_id, file_name, file_size, uploaded_by
6. **gdwb_chat** – project_id, user_id, message, is_private
7. **gdwb_notifications** – user_id, type, project_id, payload
8. (WordPress posts table links to WooCommerce orders via post_meta)

### REST API Endpoints (18 total)
**Projects**: GET/POST /projects, GET /projects/{id}
**Stats**: GET /stats
**Files Vault**: GET/POST /vault/{project_id}/files, DELETE /vault/{file_id}/delete
**Chat**: GET /chat/{project_id}/messages, POST /chat/{project_id}/send
**Notifications**: GET /notifications, POST /notifications/mark-read
**Forms**: POST /forms/{project_id}/revision-request, POST /forms/{project_id}/requirements, GET /forms/{project_id}/submissions
**Webhooks**: Registered events for external integrations

### Modules (22 total)
1. Shortcodes
2. Dashboard (admin stats)
3. Project_Client_Dashboard (frontend client portal)
4. Project_Manager (WooCommerce integration)
5. Upload_Manager (AJAX file uploads)
6. Timeline_Manager (event tracking)
7. Chat_Manager (live messaging)
8. Forms_Manager (revision/requirements)
9. License_Manager (premium activation)
10. Admin_Menu (admin interface)
11. Capabilities_Manager (custom roles/perms)
12. Audit_Logger (change tracking)
13. Webhook_Manager (event webhooks)
14. Rest_API (project CRUD)
15. Stats_API (analytics endpoint)
16. Email_Manager (notifications)
17. Live_Notifications (real-time alerts)
18. ActionSchedulerIntegration (background jobs)
19. Analytics (metrics collection)
20. Files_Vault (file management)
21. WooCommerce Order_Handler (service detection)
22. CLI Commands (WP-CLI tools)

### Frontend Assets
- **assets/js/admin.js** – Admin panel functions
- **assets/js/project-dashboard.js** – Live polling, form handling, file upload
- **assets/css/admin.css** – Admin styling
- **assets/css/project-dashboard.css** – Client dashboard styling (6000+ lines)
- **templates/forms/** – Revision request and requirements form templates

### Tests (18 test files, 2000+ lines)
- test-rest-api.php – REST endpoints
- test-stats-endpoint.php – Analytics API
- test-services-category.php – WooCommerce category creation
- test-chat.php – Message storage
- test-files-vault.php – File storage
- test-live-notifications.php – Notification creation
- test-forms.php – Form submissions
- test-license.php – License validation
- test-permissions.php – Capability checks
- test-audit.php – Audit trail
- Plus 8 more covering timeline, uploads, analytics, etc.

### Security & Permissions
- Nonce validation on all AJAX/forms
- User capability checks (read, edit, manage_gdwb_projects)
- Permission callbacks on all REST endpoints
- IP logging on audit events
- HMAC-SHA256 signatures on webhooks
- Sanitization/escaping on all output
- WooCommerce customer isolation (customers see only their projects)

### Live Features (Real-Time with 5s Polling)
- Messages update instantly in chat
- Files appear immediately after upload
- Notifications pop in upper right
- Form submissions confirmed
- Status changes reflected across dashboard
- No page refresh required

### Deployment Checklist
1. Run `composer install` for dependencies
2. Activate plugin in WordPress
3. Enter license key in GD Workflow → License
4. Create WooCommerce product under "Services" category
5. Configure email templates in /templates/emails/
6. Enable WP-Cron or external cron task
7. Test: Place order with service product → project auto-created
8. Share [gdwb_project_dashboard] link with customer
9. Monitor analytics at /wp-admin/?page=gdwb-analytics

### Hard to Ignore Modern Features ✨
✅ **Service-to-Project automation** – Zero-click project creation on paid orders  
✅ **WooCommerce integration** – Revenue tracking, analytics, customer isolation  
✅ **Live chat** – Private staff/client messaging in real-time  
✅ **Files vault** – 50MB secure storage with drag-and-drop  
✅ **Forms framework** – Built-in revision requests and requirements  
✅ **Live notifications** – Alerts for all project events  
✅ **Client dashboard** – Complete self-service portal for customers  
✅ **Analytics dashboard** – Revenue, projects, files metrics  
✅ **Admin panel** – Professional UX with license, settings, projects  
✅ **Webhooks** – Send events to external services  
✅ **Audit trail** – Complete change history with IP logging  
✅ **REST API** – 18 endpoints for SPAs, mobile apps, integrations  
✅ **WP-CLI** – DevOps tooling for automation  
✅ **GitHub Actions CI** – Automated testing and code quality  
✅ **Responsive UI** – Mobile-first design, no framework bloat  
✅ **Zero external JS frameworks** – jQuery + vanilla JS only  
✅ **50-500MB scale** – Optimized for thousands of projects  

### Competitive Advantages
1. **Service-first architecture** – Built for service delivery, not generic projects
2. **WooCommerce-native** – Seamless shop integration (products → projects)
3. **Live features** – Chat, notifications, uploads without websockets
4. **Client-facing** – Customer portal reduces support tickets
5. **Audit-ready** – Full change history for compliance
6. **Extensible** – Modular design enables custom features
7. **Performance** – Custom DB tables (not postmeta bloat)
8. **No vendor lock-in** – 100% open, no SaaS fees
9. **Developer-friendly** – REST API, WP-CLI, hooks galore
10. **Production-ready** – Tested, documented, secure

### Optional Future Enhancements
- Email notifications when files uploaded / messages received
- Advanced search/filtering in project list
- Team collaboration (assign project members)
- Time tracking and billing
- Integration with Stripe for payments
- Slack bot integration
- Custom workflows (multi-step approval)
- Project templates
- File versioning
- Advanced analytics (charts, reports)
- Mobile app (React Native)
