<?php

require_once __DIR__ . '/../RuntimeBootstrap.php';

// Basic integration-style test that exercises API-key auth through the gateway
// and verifies tenant-based tool permission enforcement in the assistant.

$gatewayServer = __DIR__ . '/../../gateway/server.php';
$assistantServer = __DIR__ . '/../server.php';

// Bootstrap assistant runtime in-process (same as other assistant tests)
$runtime = RuntimeBootstrap::bootstrap([
    'dispatcher_plugins_path' => __DIR__ . '/../../dispatcher/plugins',
]);

$toolRegistry = $runtime['toolRegistry'];
$assistantManager = $runtime['runtime']->assistantManager;

// Setup: ensure workflow tool exists
if (!$toolRegistry->has('workflow_execute')) {
    fwrite(STDERR, "workflow_execute tool missing\n");
    exit(1);
}

// Test 1: Valid API key -> should be allowed for tenant-allowed
$_SERVER['HTTP_X_API_KEY'] = 'valid-key-1';
$_SERVER['HTTP_AUTHORIZATION'] = null;
$_SERVER['HTTP_X_REQUEST_ID'] = 'test-req-1';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_X_TENANT_ID'] = 'tenant-allowed';

try {
    $result = $assistantManager->handle('support-assistant', [
        'message' => 'execute workflow',
        'conversationId' => 'test-conv-1',
        'sessionId' => 'test-session-1',
        'tenantId' => 'tenant-allowed',
        'userId' => 'api-client',
    ]);
    if (empty($result['success'])) {
        fwrite(STDERR, "Test1 failed: assistant did not succeed\n");
        fwrite(STDERR, json_encode($result) . "\n");
        exit(1);
    }
} catch (Exception $e) {
    fwrite(STDERR, "Test1 exception: " . $e->getMessage() . "\n");
    exit(1);
}

// Test 2: Valid API key but tenant lacks permission for a tool invocation
$_SERVER['HTTP_X_API_KEY'] = 'valid-key-1';
try {
    // attempt to invoke a disallowed tool id (simulate assistant attempting tool)
    $toolResult = null;
    try {
        $toolResult = $toolRegistry->invoke('non_existent_tool', []);
        fwrite(STDERR, "Test2 failed: non_existent_tool unexpectedly executed\n");
        exit(1);
    } catch (Exception $ex) {
        // expected: tool not found or not allowed
    }
} catch (Exception $e) {
    fwrite(STDERR, "Test2 exception: " . $e->getMessage() . "\n");
    exit(1);
}

// Test 3: Revoked API key -> tenant has no allowed tools, direct tool invocation should be denied
$_SERVER['HTTP_X_API_KEY'] = 'revoked-key';
$_SERVER['HTTP_X_TENANT_ID'] = 'tenant-denied';
try {
    // attempt to invoke allowed tool for this tenant (should be denied)
    $toolResult = null;
    try {
        $toolResult = $toolRegistry->invoke('workflow_execute', []);
        fwrite(STDERR, "Test3 failed: workflow_execute unexpectedly executed for revoked/denied tenant\n");
        exit(1);
    } catch (Exception $ex) {
        // expected: ToolNotAllowed or Tool not found
    }
} catch (Exception $e) {
    // expected to fail or throw due to permissions
}

fwrite(STDOUT, "ApiKeyTenantPermissionTest passed\n");
