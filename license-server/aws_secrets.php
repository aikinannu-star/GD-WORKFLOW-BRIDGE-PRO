<?php
// Helper: load secrets from AWS Secrets Manager
// Usage: set AWS_SECRET_ID (or AWS_SECRETS_PREFIX), AWS_REGION. Requires either AWS SDK for PHP or AWS CLI available.
if (!function_exists('aws_load_secrets')) {
    function aws_load_secrets(): bool {
        $secretId = getenv('AWS_SECRET_ID') ?: getenv('AWS_SECRETS_PREFIX') ?: getenv('AWS_SECRET_NAME');
        if (empty($secretId)) return false;
        $region = getenv('AWS_REGION') ?: 'us-east-1';

        $secretString = null;

        // Prefer AWS SDK for PHP if available
        if (class_exists('\\Aws\\SecretsManager\\SecretsManagerClient')) {
            try {
                $client = new \Aws\SecretsManager\SecretsManagerClient([
                    'version' => '2017-10-17',
                    'region' => $region,
                ]);
                $result = $client->getSecretValue(['SecretId' => $secretId]);
                if (!empty($result['SecretString'])) {
                    $secretString = $result['SecretString'];
                } elseif (!empty($result['SecretBinary'])) {
                    $secretString = base64_decode($result['SecretBinary']);
                }
            } catch (Throwable $e) {
                error_log('aws_secrets: SDK error: ' . $e->getMessage());
                return false;
            }
        } else {
            // Fallback: use AWS CLI if installed
            $cmd = 'aws secretsmanager get-secret-value --secret-id ' . escapeshellarg($secretId) . ' --region ' . escapeshellarg($region) . ' --output json 2>/dev/null';
            $out = null; $rc = 0;
            exec($cmd, $out, $rc);
            if ($rc !== 0) {
                error_log('aws_secrets: aws cli failed rc=' . $rc);
                return false;
            }
            $json = implode("\n", $out);
            $arr = json_decode($json, true);
            if (is_array($arr) && isset($arr['SecretString'])) {
                $secretString = $arr['SecretString'];
            } elseif (is_array($arr) && isset($arr['SecretBinary'])) {
                $secretString = base64_decode($arr['SecretBinary']);
            }
        }

        if (empty($secretString)) return false;

        // If secret is JSON, map keys to env vars; otherwise store under AWS_SECRET_VALUE
        $data = json_decode($secretString, true);
        if (is_array($data)) {
            foreach ($data as $k => $v) {
                $val = (is_array($v) || is_object($v)) ? json_encode($v) : (string)$v;
                if (getenv($k) === false || getenv($k) === '') {
                    putenv($k . '=' . $val);
                    if (isset($_ENV)) $_ENV[$k] = $val;
                }
            }
        } else {
            $envname = getenv('AWS_SECRET_ENVNAME') ?: 'AWS_SECRET_VALUE';
            if (getenv($envname) === false || getenv($envname) === '') {
                putenv($envname . '=' . $secretString);
                if (isset($_ENV)) $_ENV[$envname] = $secretString;
            }
        }

        return true;
    }
}
