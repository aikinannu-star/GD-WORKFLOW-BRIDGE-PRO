=== GD Workflow Bridge Pro ===

Contributors: Aikin Annu
Version: 3.4.0
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 8.0

== Description ==

Advanced WooCommerce workflow automation plugin with live chat, file vault, and customer portal. Create service projects automatically from orders, manage files and communications in one place, and deliver exceptional client experiences.

== Features ==

- **WooCommerce Integration** – Service category auto-creates projects on order completion
- **Live Chat System** – Private staff/client messaging with real-time updates
- **File Vault** – Secure 50MB file storage with drag-and-drop upload
- **Customer Portal** – [gdwb_project_dashboard] shortcode for client self-service
- **Live Notifications** – Real-time alerts for project events
- **Service Forms** – Built-in revision requests and requirements upload
- **WooCommerce Analytics** – Dashboard stats (revenue, projects, files)
- **Audit Trail** – Complete change history with IP logging
- **REST API** – 18 endpoints for integrations and mobile apps
- **Professional Admin** – License management, settings, analytics dashboard
- **Modular Architecture** – Lazy-loading modules with dependency injection
- **Custom Database** – Optimized tables (projects, chat, files, timeline)
- **GitHub Actions CI** – Automated testing and code quality

== Usage ==

1. Create a WooCommerce "Services" product category
2. Add services to that category
3. When customers purchase services, projects are auto-created
4. Share [gdwb_project_dashboard] shortcode link with customers
5. Monitor analytics at GD Workflow → Analytics

== Changelog ==

= 3.4.0 =
- Added service-to-project automation (WooCommerce category gating)
- Added Files Vault with 50MB secure storage
- Added Live Chat system with private messages
- Added Live Notifications for all project events
- Added Service Forms (revision requests, requirements)
- Added Project Client Dashboard shortcode with real-time polling
- Added Stats API endpoint with WooCommerce analytics
- Added Forms Manager REST endpoints
- Added Chat Manager with message storage
- Added Files Vault REST API
- Added Live Notifications REST API
- Added 4 new database tables (gdwb_files, gdwb_chat, gdwb_notifications)
- Added project-dashboard.js for live polling and form handling
- Added project-dashboard.css with modern responsive UI
- Created template forms for revision requests and requirements
- Bumped version to 3.4.0

= 3.3.0 =
- Added License_Manager with API-based validation
- Added Admin_Menu with professional interface
- Added Capabilities_Manager for role-based access
- Added Audit_Logger with IP tracking
- Added Webhook_Manager for external integrations
- Added CLI Commands (WP-CLI support)
- Added gdwb_audit_log database table
- Bumped version to 3.3.0

= 3.2.0 =
- Added Email_Manager for customer notifications
- Added Analytics module for metrics collection
- Added Timeline_Manager for event tracking
- Added Upload_Manager for file uploads
- Added Frontend Dashboard shortcodes
- Added 3 custom database tables (timeline, analytics, projects)
- Added comprehensive PHPUnit test suite
- Added GitHub Actions CI/CD pipeline
- Added ARCHITECTURE.md documentation
- Bumped version to 3.2.0

= 3.1.0 =
- Added conditional WooCommerce loading
- Fixed textdomain loading path
- Added duplicate project prevention
- Improved output escaping and sanitization
- Added user capability checks
- Added plugin activation/deactivation hooks
- Added uninstall routine
- Updated PHP requirement to 8.0+
- Added Composer support with PSR-4 autoloading
- Added PHPCS configuration

== Deployment ==

1. Activate the plugin
2. Create WooCommerce product under "Services" category
3. Configure email templates in /templates/emails/
4. Enable WP-Cron for background task processing
5. Share [gdwb_project_dashboard] with customers
6. Monitor analytics and audit logs in admin panel

== Support ==

For issues and support, please visit the plugin documentation in ARCHITECTURE.md
