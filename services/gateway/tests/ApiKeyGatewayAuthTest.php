<?php
require_once __DIR__ . '/../AuthHelpers.php';

function assertTrue($cond, $msg) {
    if (!$cond) {
        fwrite(STDERR, "Assertion failed: $msg\n");
        exit(1);
    }
}

// Test: valid key -> allowed for assistant
$headers = ['X-API-Key' => 'valid-key-1'];
$info = GatewayAuthHelpers::getApiKeyInfoFromHeaders($headers);
assertTrue(!empty($info), 'valid-key-1 should be found');
$res = GatewayAuthHelpers::apiKeyAllowedForService($info, 'assistant');
assertTrue(!empty($res['ok']), 'valid-key-1 should be allowed for assistant');

// Test: revoked key -> rejected
$headers = ['X-API-Key' => 'revoked-key'];
$info = GatewayAuthHelpers::getApiKeyInfoFromHeaders($headers);
assertTrue(!empty($info), 'revoked-key should be found');
$res = GatewayAuthHelpers::apiKeyAllowedForService($info, 'assistant');
assertTrue(empty($res['ok']) && ($res['status'] === 401), 'revoked-key should be rejected with 401');

// Test: unknown key -> not found
$headers = ['X-API-Key' => 'no-such-key'];
$info = GatewayAuthHelpers::getApiKeyInfoFromHeaders($headers);
assertTrue(empty($info), 'no-such-key should not be found');

// Test: missing scope -> create temp key object
$temp = ['id' => 'tmp', 'key' => 'tmp', 'scopes' => ['cms:upload']];
$res = GatewayAuthHelpers::apiKeyAllowedForService($temp, 'assistant');
assertTrue(empty($res['ok']) && $res['status'] === 403, 'key without assistant scope should be 403');

echo "ApiKeyGatewayAuthTest passed\n";
exit(0);
