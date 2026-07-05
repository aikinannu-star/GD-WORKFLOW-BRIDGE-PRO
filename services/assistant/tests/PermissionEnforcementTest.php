<?php

require_once __DIR__ . '/../RuntimeBootstrap.php';
require_once __DIR__ . '/../../dispatcher/plugin/PermissionEnforcer.php';
require_once __DIR__ . '/../../dispatcher/actions/ActionInterface.php';
require_once __DIR__ . '/../../dispatcher/actions/ActionResult.php';
require_once __DIR__ . '/../../dispatcher/runtime/ExecutionContext.php';

$runtimeData = RuntimeBootstrap::bootstrap([
    'dispatcher_plugins_path' => __DIR__ . '/../../dispatcher/plugins',
]);
$runtime = $runtimeData['runtime'];
$actionRegistry = $runtimeData['actionRegistry'];

class PermissionTestAction implements ActionInterface
{
    public function execute(array $payload, ExecutionContext $context): ActionResult
    {
        return ActionResult::success(['executed' => true], null, ['authorized']);
    }
}

// Authorized: plugin permissions granted
$enforcer = new PermissionEnforcer();
$enforcer->grant('test-plugin', ['network']);
$actionRegistry->setPermissionEnforcer($enforcer);
$actionRegistry->register('authorized_http', new PermissionTestAction(), ['network'], 'test-plugin');

$authContext = new ExecutionContext('perm-test', 'exec-auth', 'default', []);
$authResult = $actionRegistry->execute('authorized_http', [], $authContext);
if (!$authResult->isSuccess()) {
    fwrite(STDERR, "Authorized permission test failed\n");
    exit(1);
}

// Unauthorized: plugin permissions missing
$deniedEnforcer = new PermissionEnforcer();
$actionRegistry->setPermissionEnforcer($deniedEnforcer);
$actionRegistry->register('denied_http', new PermissionTestAction(), ['network'], 'test-plugin');

$deniedContext = new ExecutionContext('perm-test', 'exec-denied', 'default', []);
$deniedResult = $actionRegistry->execute('denied_http', [], $deniedContext);
if ($deniedResult->isSuccess()) {
    fwrite(STDERR, "Unauthorized permission test unexpectedly succeeded\n");
    exit(1);
}

if (strpos($deniedResult->getError() ?? '', 'permission_denied') === false) {
    fwrite(STDERR, "Unauthorized permission test did not return a permission_denied error\n");
    exit(1);
}

fwrite(STDOUT, "Permission enforcement test passed\n");
