<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../license-server/jwks_lib.php';
require_once __DIR__ . '/../../license-server/jwt_verify.php';

final class JwksPruneTest extends TestCase
{
    private $tmpDir;
    private $indexFile;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/gdwb_test_keys_' . uniqid();
        mkdir($this->tmpDir, 0755, true);
        $this->indexFile = $this->tmpDir . '/keys_index.json';
        // override globals used by jwks_lib
        global $keysDir, $keysIndexFile;
        $keysDir = $this->tmpDir;
        $keysIndexFile = $this->indexFile;
    }

    protected function tearDown(): void
    {
        // cleanup files
        foreach (glob($this->tmpDir . '/*') as $f) @unlink($f);
        @rmdir($this->tmpDir);
    }

    public function testPruneExpiredKeysRemovesFilesAndIndex()
    {
        // create fake keys and index with retire_at in the past
        $kidOld = 'kid_old_'.uniqid();
        $pub = $this->tmpDir . '/public_' . $kidOld . '.pem';
        $priv = $this->tmpDir . '/private_' . $kidOld . '.pem';
        file_put_contents($pub, "PUBLIC");
        file_put_contents($priv, "PRIVATE");

        $index = [
            'current_kid' => null,
            'keys' => [$kidOld => ['kid' => $kidOld, 'alg' => 'RS256']],
            'keys_meta' => [$kidOld => ['created_at' => date('c', time()-3600), 'retire_at' => date('c', time()-10)]],
            'rotation_history' => []
        ];
        saveKeysIndex($index);

        $loaded = getKeysIndex();
        $this->assertArrayHasKey($kidOld, $loaded['keys']);

        $removed = pruneExpiredKeys($loaded);
        $this->assertContains($kidOld, $removed);
        $this->assertFileDoesNotExist($pub);
        $this->assertFileDoesNotExist($priv);

        $final = getKeysIndex();
        $this->assertArrayNotHasKey($kidOld, $final['keys']);
    }
}
