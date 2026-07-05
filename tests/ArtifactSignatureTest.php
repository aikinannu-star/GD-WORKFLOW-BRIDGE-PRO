<?php
use PHPUnit\Framework\TestCase;

final class ArtifactSignatureTest extends TestCase
{
    private $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'gdwb_sign_' . uniqid();
        mkdir($this->tmpDir);
    }

    protected function tearDown(): void
    {
        $files = glob($this->tmpDir . DIRECTORY_SEPARATOR . '*');
        foreach ($files as $f) @unlink($f);
        @rmdir($this->tmpDir);
    }

    private function writeArtifact()
    {
        $path = $this->tmpDir . DIRECTORY_SEPARATOR . 'compiled-policy.json';
        file_put_contents($path, json_encode(['hello' => 'world']));
        return $path;
    }

    private function genKeys()
    {
        $res = openssl_pkey_new(['private_key_bits'=>2048, 'private_key_type'=>OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($res, $priv);
        $pub = openssl_pkey_get_details($res)['key'];
        $privPath = $this->tmpDir . DIRECTORY_SEPARATOR . 'private.pem';
        $pubPath = $this->tmpDir . DIRECTORY_SEPARATOR . 'public.pem';
        file_put_contents($privPath, $priv);
        file_put_contents($pubPath, $pub);
        return [$privPath, $pubPath];
    }

    public function testSignAndVerifyPasses()
    {
        [$priv, $pub] = $this->genKeys();
        $artifact = $this->writeArtifact();
        $sigOut = $this->tmpDir . DIRECTORY_SEPARATOR . 'compiled-policy.json.sig';

        $cmdSign = escapeshellcmd("php tools/sign-artifact.php --artifact={$artifact} --key={$priv} --out={$sigOut}");
        exec($cmdSign, $o1, $rc1);
        $this->assertSame(0, $rc1, 'Signing should exit 0');

        $cmdVerify = escapeshellcmd("php tools/verify-artifact-signature.php --artifact={$artifact} --sig={$sigOut} --pub={$pub}");
        exec($cmdVerify, $o2, $rc2);
        $this->assertSame(0, $rc2, 'Verification should exit 0');
        $this->assertStringContainsString('PASS', implode("\n", $o2));
    }

    public function testVerifyFailsWhenArtifactTampered()
    {
        [$priv, $pub] = $this->genKeys();
        $artifact = $this->writeArtifact();
        $sigOut = $this->tmpDir . DIRECTORY_SEPARATOR . 'compiled-policy.json.sig';

        exec(escapeshellcmd("php tools/sign-artifact.php --artifact={$artifact} --key={$priv} --out={$sigOut}"), $o1, $rc1);
        $this->assertSame(0, $rc1, 'Signing should exit 0');

        // Tamper artifact
        file_put_contents($artifact, json_encode(['hello' => 'tampered']));

        exec(escapeshellcmd("php tools/verify-artifact-signature.php --artifact={$artifact} --sig={$sigOut} --pub={$pub}"), $o2, $rc2);
        $this->assertNotSame(0, $rc2, 'Verification should fail for tampered artifact');
    }
}
