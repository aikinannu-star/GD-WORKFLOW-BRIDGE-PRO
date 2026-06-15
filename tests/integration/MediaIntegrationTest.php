<?php
use PHPUnit\Framework\TestCase;

final class MediaIntegrationTest extends TestCase
{
    public function testUploadAndRetrieveMedia(): void
    {
        $base = getenv('MEDIA_BASE_URL') ?: 'http://127.0.0.1:8010';
        $userId = 'integration-user';
        $tenantId = 'ci-tenant';
        $filename = 'hello.txt';
        $contentStr = 'integration test content ' . bin2hex(random_bytes(4));
        $contentB64 = base64_encode($contentStr);
        $payload = json_encode(['filename' => $filename, 'content' => $contentB64, 'tenant_id' => $tenantId]);

        $ch = curl_init($base . '/api/v1/media/upload');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'X-User-Id: ' . $userId]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        $res = curl_exec($ch);
        $info = curl_getinfo($ch);
        $errno = curl_errno($ch);
        curl_close($ch);

        $this->assertEquals(201, $info['http_code'], 'Upload expected HTTP 201. Response: ' . $res . ' CurlErr: ' . $errno);
        $json = json_decode($res, true);
        $this->assertArrayHasKey('media', $json);
        $this->assertArrayHasKey('id', $json['media']);
        $id = $json['media']['id'];

        // fetch
        $ch2 = curl_init($base . '/api/v1/media/' . $id);
        curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
        $res2 = curl_exec($ch2);
        $info2 = curl_getinfo($ch2);
        curl_close($ch2);

        $this->assertEquals(200, $info2['http_code'], 'GET expected 200. Response: ' . $res2);
        $json2 = json_decode($res2, true);
        $this->assertArrayHasKey('media', $json2);
        $this->assertEquals($id, $json2['media']['id']);
    }
}
