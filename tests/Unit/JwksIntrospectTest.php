<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../license-server/jwks_lib.php';
require_once __DIR__ . '/../../license-server/jwt_verify.php';

final class JwksIntrospectTest extends TestCase
{
    private $tmpDir;
    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/gdwb_test_keys_' . uniqid();
        mkdir($this->tmpDir, 0755, true);
        global $keysDir, $keysIndexFile;
        $keysDir = $this->tmpDir;
        $keysIndexFile = $this->tmpDir . '/keys_index.json';
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/*') as $f) @unlink($f);
        @rmdir($this->tmpDir);
    }

    public function testRetiredKeyFailsAndNewKeySucceeds()
    {
        $cfg = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        $res1 = openssl_pkey_new($cfg);
        openssl_pkey_export($res1, $priv1);
        $det1 = openssl_pkey_get_details($res1);
        $pub1 = $det1['key'];
        $old_kid = 'kid_old_' . uniqid();
        file_put_contents($this->tmpDir . '/private_' . $old_kid . '.pem', $priv1);
        file_put_contents($this->tmpDir . '/public_' . $old_kid . '.pem', $pub1);

        $res2 = openssl_pkey_new($cfg);
        openssl_pkey_export($res2, $priv2);
        $det2 = openssl_pkey_get_details($res2);
        $pub2 = $det2['key'];
        $new_kid = 'kid_new_' . uniqid();
        file_put_contents($this->tmpDir . '/private_' . $new_kid . '.pem', $priv2);
        file_put_contents($this->tmpDir . '/public_' . $new_kid . '.pem', $pub2);

        $index = [
            'current_kid' => $new_kid,
            'keys' => [
                $old_kid => ['alg'=>'RS256','kid'=>$old_kid],
                $new_kid => ['alg'=>'RS256','kid'=>$new_kid]
            ],
            'keys_meta' => [
                $old_kid => ['created_at'=>date('c', time()-7200),'retire_at'=>date('c', time()-3600),'public_key_path'=>$this->tmpDir . '/public_' . $old_kid . '.pem'],
                $new_kid => ['created_at'=>date('c'), 'retire_at'=>null,'public_key_path'=>$this->tmpDir . '/public_' . $new_kid . '.pem']
            ],
            'rotation_history' => []
        ];
        saveKeysIndex($index);

        $payload = ['sub'=>'TEST','iat'=>time(),'exp'=>time()+3600,'jti'=>'jti-'.uniqid()];
        $token_old = generate_jwt($payload, $priv1, $old_kid);
        $token_new = generate_jwt($payload, $priv2, $new_kid);

        $r_old = verify_token_signature($token_old);
        $this->assertFalse($r_old['verified'], 'Old token should not verify (retired key)');

        $r_new = verify_token_signature($token_new);
        $this->assertTrue($r_new['verified'], 'New token should verify');
    }
}
