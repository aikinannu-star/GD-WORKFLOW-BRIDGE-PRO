<?php
use PHPUnit\Framework\TestCase;

final class ArtifactCompatibilityTest extends TestCase
{
    private $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'gdwb_test_' . uniqid();
        mkdir($this->tmpDir);
    }

    protected function tearDown(): void
    {
        $files = glob($this->tmpDir . DIRECTORY_SEPARATOR . '*');
        foreach ($files as $f) @unlink($f);
        @rmdir($this->tmpDir);
    }

    private function writeArtifact(array $meta)
    {
        $artifact = [
            'metadata' => $meta,
            'graph' => [ 'nodes' => [['id' => 'n1']], 'edges' => [] ],
        ];
        $path = $this->tmpDir . DIRECTORY_SEPARATOR . 'compiled-policy.json';
        file_put_contents($path, json_encode($artifact, JSON_PRETTY_PRINT));
        return $path;
    }

    public function testCompatibilityPassesWithMatchingMinimums()
    {
        $path = $this->writeArtifact(['artifact_version' => '1.2', 'policy_schema_version' => '1.0']);
        $cmd = escapeshellcmd("php tools/artifact-compatibility-check.php --artifact={$path} --min-artifact-version=1.0 --min-policy-schema-version=1.0");
        exec($cmd, $out, $rc);
        $this->assertSame(0, $rc, 'Expected exit code 0 for compatible artifact');
        $this->assertStringContainsString('PASS', implode("\n", $out));
    }

    public function testCompatibilityFailsWhenBelowMinimum()
    {
        $path = $this->writeArtifact(['artifact_version' => '1.0', 'policy_schema_version' => '1.0']);
        $cmd = escapeshellcmd("php tools/artifact-compatibility-check.php --artifact={$path} --min-artifact-version=2.0");
        exec($cmd, $out, $rc);
        $this->assertNotSame(0, $rc, 'Expected non-zero exit code when artifact is below required version');
    }
}
