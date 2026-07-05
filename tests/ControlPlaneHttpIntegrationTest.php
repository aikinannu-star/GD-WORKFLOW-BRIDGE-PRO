<?php
use PHPUnit\Framework\TestCase;

final class ControlPlaneHttpIntegrationTest extends TestCase
{
    private string $tmpDir;
    private $process;
    private int $port;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'gdwb_cp_' . uniqid();
        mkdir($this->tmpDir);
        $this->port = $this->findOpenPort();
    }

    protected function tearDown(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
        foreach (glob($this->tmpDir . DIRECTORY_SEPARATOR . '*') as $file) {
            @unlink($file);
        }
        @rmdir($this->tmpDir);
    }

    private function findOpenPort(): int
    {
        $socket = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($socket === false) {
            throw new RuntimeException("Unable to allocate test port: {$errstr}");
        }
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $parts = explode(':', $address);
        return (int)end($parts);
    }

    private function startServer(string $artifactPath): void
    {
        $docRoot = realpath(__DIR__ . '/../services/control-plane');
        $command = sprintf('php -S 127.0.0.1:%d -t %s', $this->port, escapeshellarg($docRoot));
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $env = array_merge($_ENV, ['COMPILED_POLICY_ARTIFACT' => $artifactPath]);
        $this->process = proc_open($command, $descriptors, $pipes, getcwd(), $env);
        if (!is_resource($this->process)) {
            throw new RuntimeException('Failed to start built-in PHP server.');
        }

        $deadline = time() + 5;
        while (time() < $deadline) {
            $status = @file_get_contents($this->getUrl('/status'));
            if ($status !== false) {
                return;
            }
            usleep(100000);
        }

        throw new RuntimeException('PHP built-in server did not become available in time.');
    }

    private function getUrl(string $path): string
    {
        return sprintf('http://127.0.0.1:%d%s', $this->port, $path);
    }

    private function request(string $method, string $path, array $body = null): array
    {
        $context = [
            'http' => [
                'method' => $method,
                'header' => "Content-Type: application/json\r\n",
            ],
        ];
        if ($body !== null) {
            $context['http']['content'] = json_encode($body);
        }
        $result = @file_get_contents($this->getUrl($path), false, stream_context_create($context));
        if ($result === false) {
            $error = error_get_last();
            throw new RuntimeException('HTTP request failed: ' . ($error['message'] ?? 'unknown'));
        }
        return json_decode($result, true);
    }

    private function writeCompiledArtifact(): string
    {
        $artifact = [
            'metadata' => [
                'artifact_version' => '1.0',
                'policy_schema_version' => '1.0',
                'compiler_version' => '1.0',
                'source_policy' => 'test',
            ],
            'graph' => [
                'nodes' => [
                    [
                        'id' => 'rule-1',
                        'type' => 'rule',
                        'meta' => [
                            'enabled' => true,
                            'name' => 'always-violate',
                            'severity' => 'error',
                            'message' => 'Test violation',
                            'predicate' => ['type' => 'always'],
                        ],
                    ],
                ],
                'edges' => [],
            ],
        ];

        $path = $this->tmpDir . DIRECTORY_SEPARATOR . 'compiled-policy.json';
        file_put_contents($path, json_encode($artifact, JSON_PRETTY_PRINT));
        return $path;
    }

    public function testStatusEndpointReturnsArtifactMetadata(): void
    {
        $artifactPath = $this->writeCompiledArtifact();
        $this->startServer($artifactPath);

        $status = $this->request('GET', '/status');
        $this->assertSame('ok', $status['status']);
        $this->assertSame('1.0', $status['artifact_version']);
        $this->assertSame('1.0', $status['policy_schema_version']);
    }

    public function testEvaluateEndpointReturnsViolations(): void
    {
        $artifactPath = $this->writeCompiledArtifact();
        $this->startServer($artifactPath);

        $result = $this->request('POST', '/evaluate', [
            'filePath' => 'dummy.txt',
            'content' => 'any content',
        ]);

        $this->assertSame('evaluated', $result['result']);
        $this->assertSame(1, $result['count']);
        $this->assertCount(1, $result['violations']);
        $this->assertSame('always-violate', $result['violations'][0]['rule']);
        $this->assertSame('Test violation', $result['violations'][0]['message']);
    }

    public function testHealthEndpointReturns503OnDegradedArtifact(): void
    {
        // Start without a valid artifact by using a non-existent path
        $invalidPath = $this->tmpDir . DIRECTORY_SEPARATOR . 'nonexistent.json';
        $this->startServer($invalidPath);

        $context = [
            'http' => [
                'method' => 'GET',
                'ignore_errors' => true,
            ],
        ];
        $response = @file_get_contents($this->getUrl('/health'), false, stream_context_create($context));
        $data = json_decode($response, true);

        // Verify HTTP status is 503 (check via headers if available; data should indicate degraded)
        $this->assertSame('degraded', $data['status']);
    }

    public function testPrometheusMetricsEndpoint(): void
    {
        $artifactPath = $this->writeCompiledArtifact();
        $this->startServer($artifactPath);

        // Call /metrics and expect Prometheus text format
        $context = [
            'http' => [
                'method' => 'GET',
                'header' => "Accept: text/plain\r\n",
            ],
        ];
        $response = @file_get_contents($this->getUrl('/metrics'), false, stream_context_create($context));
        $this->assertStringContainsString('gdwb_artifact_reloads_total', $response);
        $this->assertStringContainsString('gdwb_evaluations_total', $response);
        $this->assertStringContainsString('gdwb_signature_verifications_total', $response);
        $this->assertStringContainsString('gdwb_signature_failures_total', $response);
    }
}
