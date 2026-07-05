<?php
require_once __DIR__ . '/RuntimeBootstrap.php';
$runtimeData = RuntimeBootstrap::bootstrap(['dispatcher_plugins_path' => __DIR__ . '/../dispatcher/plugins']);
$toolRegistry = $runtimeData['toolRegistry'];
$runtime = $runtimeData['runtime'];
echo "Tool registry keys: \n";
print_r($toolRegistry->listTools());
$assistant = $runtime->assistantManager->getAssistant('support-assistant');
if ($assistant) {
    echo "Support assistant tools: \n";
    print_r($assistant->tools());
    // If it's SupportAssistant, inspect its pipeline's tool registry via reflection
    $reflection = new ReflectionObject($assistant);
    $prop = $reflection->getProperty('service');
    $prop->setAccessible(true);
    $service = $prop->getValue($assistant);
    $pipelineProp = (new ReflectionObject($service))->getProperty('pipeline');
    $pipelineProp->setAccessible(true);
    $pipeline = $pipelineProp->getValue($service);
    if ($pipeline) {
        $execPipelineProp = (new ReflectionObject($pipeline))->getProperty('executionPipeline');
        $execPipelineProp->setAccessible(true);
        $execPipeline = $execPipelineProp->getValue($pipeline);
        if ($execPipeline) {
            $srvProp = (new ReflectionObject($execPipeline))->getProperty('serviceRegistry');
            $srvProp->setAccessible(true);
            $srv = $srvProp->getValue($execPipeline);
            if ($srv) {
                $toolServices = $srv->toolServices();
                $toolReg = $toolServices->getToolRegistry();
                echo "Pipeline tool registry keys: \n";
                print_r($toolReg->listTools());
            }
        }
    }
}
