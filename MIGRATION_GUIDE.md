# Service Layer Migration Guide

## What was created

### 1. Expanded CMS Service (`services/cms/server.php`)

Added native REST endpoints for:
- **Projects**: Create, list, get projects
- **Timeline**: Project activity log, add timeline entries
- **Chat**: Get/send project messages
- **File Vault**: Upload, list, delete files with MIME validation
- **Forms**: Revision requests, requirements submissions
- **Notifications**: Get user notifications, mark as read
- **Stats**: Project statistics by tenant/user

All endpoints support both:
- `/api/v1/cms/...` (gateway-routed from `http://localhost:8000`)
- Direct service routes when accessed directly

### 2. Service Bridge (`services/lib/ServiceBridge.php`)

Clean PHP adapter for WP plugin to call service APIs without database access.

**Usage in WP Plugin:**

```php
$bridge = new ServiceBridge(
    'http://gateway:8000',  // gateway URL
    get_current_user_id(),  // user ID
    $tenant_id              // optional tenant
);

// Create project
$project = $bridge->createProject('My Project', $order_id);

// Get project timeline
$timeline = $bridge->getProjectTimeline($project_id);

// Send chat message
$bridge->sendChatMessage($project_id, 'Hello team', false);

// Upload file
$bridge->uploadVaultFile($project_id, 'document.pdf', 2048000, 'application/pdf');

// Get notifications
$notifications = $bridge->getNotifications(true); // unread only
```

## Migration Strategy

### Phase 1: Service-First (Current - DONE)
- Build service layer independently ✅
- Create bridge adapter ✅
- Test service endpoints in isolation

### Phase 2: Extract Reusable Business Rules (NEXT)
Identify core logic that belongs in platform services, then design reusable service functions:

1. **Access Control Service**
   - Define permission model: admin, project_owner, order_customer, viewer, collaborator
   - Build `canAccessProject(userId, projectId, action)` function
   - Handle role-based authorization rules
   - Support tenant isolation

2. **File Management Service**
   - File type whitelist (PDF, images, ZIP, docs)
   - Max file size enforcement (50MB)
   - MIME type validation
   - Quarantine/scan logic
   - Create `validateFileUpload(fileName, size, mimeType)` function

3. **Event & Audit Service**
   - Define event taxonomy (created, updated, file_uploaded, message_sent, revision_requested, etc.)
   - Auto-trigger timeline entries from service operations
   - Create `logEvent(projectId, eventType, metadata, userId)` function
   - Immutable audit trail

4. **Notification Service**
   - Define notification types (file_uploaded, message_received, revision_requested, etc.)
   - Build notification trigger rules: who should be notified for each event?
   - Create `triggerNotification(userId, type, projectId, payload)` function
   - Support notification preferences/channels (eventually)

5. **Order Integration Service**
   - Define project→order linking logic
   - Build `linkProjectToOrder(projectId, orderId)` function
   - Extract order status → project status mapping
   - Create `createProjectFromOrder(orderId)` function

### Phase 3: Implement Service-Native Business Rules
Build the 5 extracted services as native functions in the CMS service layer:

```php
// Access control
function canAccessProject(string $userId, string $projectId, string $action = 'view'): bool
{
    // Verify user has required role or permission
    // Check tenant isolation
    // Cache result for performance
}

// File validation
function validateFileUpload(string $fileName, int $fileSize, string $mimeType): array
{
    // Return ['valid' => true] or ['valid' => false, 'reason' => '...']
    // Check whitelist, size limits, MIME types
}

// Event logging
function logEvent(string $projectId, string $eventType, array $metadata, ?string $userId): array
{
    // Create timeline entry
    // Trigger notification rules
    // Return event record
}

// Notification triggers
function triggerNotification(string $userId, string $type, string $projectId, array $payload): ?array
{
    // Create notification
    // Respect user preferences (when available)
    // Return notification record
}

// Order integration
function createProjectFromOrder(string $orderId): ?array
{
    // Auto-create project for order
    // Set order_id reference
    // Log creation event
    // Return project
}
```

Once these are implemented, expose them as new endpoints:
- `POST /api/v1/cms/authorize` — Check access
- `POST /api/v1/cms/validate-file` — Pre-validate before upload
- `POST /api/v1/cms/events` — Manual event logging
- `POST /api/v1/cms/notifications/trigger` — Trigger notifications via service rules
- `POST /api/v1/cms/orders/create-project` — Order-driven project creation

### Phase 4: Optional - WP Plugin Integration
Once business rules are stable in service layer, optionally update WP plugin to use bridge.
Plugin becomes thin UI layer that delegates all business logic to services.

## Service Data Model

Data persisted as JSON in `services/data/`:
- `cms_projects.json` — Project records
- `cms_timeline.json` — Timeline entries  
- `cms_chat.json` — Chat messages
- `cms_files.json` — Deprecated (use vault)
- `cms_vault.json` — File vault entries
- `cms_notifications.json` — User notifications

## Testing the Service

```bash
# Test project creation
curl -X POST http://localhost:8000/api/v1/cms/projects \
  -H 'Content-Type: application/json' \
  -H 'X-User-Id: user123' \
  -H 'X-Tenant-Id: tenant456' \
  -d '{"title":"Test","tenant_id":"tenant456"}'

# Test chat
curl -X POST http://localhost:8000/api/v1/cms/chat/{project_id}/send \
  -H 'Content-Type: application/json' \
  -H 'X-User-Id: user123' \
  -d '{"message":"Hello","is_private":false}'

# Test vault
curl -X POST http://localhost:8000/api/v1/cms/vault/{project_id}/upload \
  -H 'Content-Type: application/json' \
  -H 'X-User-Id: user123' \
  -d '{"file_name":"doc.pdf","file_size":2048000,"mime_type":"application/pdf"}'
```

## Next Steps

1. **Analyze WP plugin business rules** in these files (READ ONLY, don't port yet):
   - `includes/Projects/*.php` — Permission checks, project workflows
   - `includes/Notifications/*.php` — Notification logic, triggers
   - `includes/WooCommerce/*.php` — Order-to-project linking

2. **Define reusable service functions** for each of the 5 extracted services

3. **Implement service-native business rules** in CMS service layer

4. **Expose new endpoints** for authorization, validation, events, notifications

5. **Test service logic** independently via curl before WP integration

6. **Update WP plugin** (optional) to use bridge after services are stable
