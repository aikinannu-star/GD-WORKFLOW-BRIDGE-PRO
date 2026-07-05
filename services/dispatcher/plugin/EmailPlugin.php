<?php
require_once __DIR__ . '/PluginInterface.php';
require_once __DIR__ . '/../actions/ActionInterface.php';
require_once __DIR__ . '/../actions/ActionResult.php';
require_once __DIR__ . '/../runtime/ExecutionContext.php';

class EmailAction implements ActionInterface
{
    public function execute(array $payload, ExecutionContext $context): ActionResult
    {
        $to = $payload['to'] ?? null;
        $subject = $payload['subject'] ?? 'Notification';
        $body = $payload['body'] ?? '';

        if (!$to) {
            return ActionResult::failure('missing_recipient', ['error' => 'missing_to']);
        }

        $context->addLog('Email sent to ' . $to);
        return ActionResult::success(['to' => $to, 'subject' => $subject], null, ['Email sent to ' . $to]);
    }
}

class EmailPlugin implements PluginInterface
{
    public function getName(): string
    {
        return 'email-action';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function register(RuntimeRegistrar $registrar): void
    {
        $registrar->registerAction('email', new EmailAction());
    }
}
