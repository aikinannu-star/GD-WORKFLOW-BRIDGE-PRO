<?php
require_once __DIR__ . '/../plugin/RuntimeRegistrar.php';
require_once __DIR__ . '/../plugin/PluginManager.php';
require_once __DIR__ . '/../plugin/EmailPlugin.php';
require_once __DIR__ . '/../container/RuntimeContainer.php';
require_once __DIR__ . '/../config/RuntimeConfig.php';
require_once __DIR__ . '/../actions/ActionRegistry.php';
require_once __DIR__ . '/../middleware/MiddlewarePipeline.php';
require_once __DIR__ . '/../workers/WorkerRegistry.php';
require_once __DIR__ . '/../events/RuntimeEventEmitter.php';

$container = new RuntimeContainer();
$config = RuntimeConfig::fromEnv();

$actionRegistry = new ActionRegistry();
$middlewarePipeline = new MiddlewarePipeline();
$workerRegistry = new WorkerRegistry();
$eventEmitter = new RuntimeEventEmitter();

$registrar = new RuntimeRegistrar($actionRegistry, $middlewarePipeline, $workerRegistry, $eventEmitter);
$pluginManager = new PluginManager();
$pluginManager->register(new EmailPlugin());
$pluginManager->loadAll($registrar);

if (!$actionRegistry->hasAction('email')) {
    fwrite(STDERR, "Plugin system did not register email action" . PHP_EOL);
    exit(1);
}

$container->set('config', $config);
$container->set('registrar', $registrar);
$container->set('pluginManager', $pluginManager);

if (!$container->has('config')) {
    fwrite(STDERR, "Container did not store config" . PHP_EOL);
    exit(1);
}

$retrieved = $container->get('config');
if ($retrieved !== $config) {
    fwrite(STDERR, "Container did not return stored config" . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, "Plugin SDK and dependency injection test passed" . PHP_EOL);
