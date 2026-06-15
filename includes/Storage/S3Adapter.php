<?php
namespace GDWB\Storage;

use Aws\S3\S3Client;
use Aws\Exception\AwsException;

class S3Adapter
{
    private S3Client $client;

    public function __construct(array $options = [])
    {
        $endpoint = $options['endpoint'] ?? getenv('S3_ENDPOINT') ?: null;
        $key = $options['key'] ?? getenv('S3_KEY') ?: null;
        $secret = $options['secret'] ?? getenv('S3_SECRET') ?: null;
        $region = $options['region'] ?? getenv('S3_REGION') ?: 'us-east-1';
        $forcePathStyle = $options['force_path_style'] ?? true;

        $config = [
            'version' => 'latest',
            'region' => $region,
            'credentials' => [
                'key' => $key,
                'secret' => $secret,
            ],
            'http' => ['verify' => false],
            'use_path_style_endpoint' => $forcePathStyle,
        ];

        if ($endpoint) {
            $config['endpoint'] = $endpoint;
        }

        $this->client = new S3Client($config);
    }

    /**
     * Upload a binary string to S3/MinIO
     * Returns the PutObject result on success
     */
    public function putObject(string $bucket, string $key, string $body, string $contentType = 'application/octet-stream', array $opts = [])
    {
        try {
            $params = array_merge([
                'Bucket' => $bucket,
                'Key' => $key,
                'Body' => $body,
                'ContentType' => $contentType,
            ], $opts);

            return $this->client->putObject($params);
        } catch (AwsException $e) {
            throw $e;
        }
    }

    public function getObjectUrl(string $bucket, string $key): string
    {
        $endpoint = getenv('S3_ENDPOINT');
        if ($endpoint) {
            return rtrim($endpoint, '/') . '/' . rawurlencode($bucket) . '/' . ltrim($key, '/');
        }
        return $this->client->getObjectUrl($bucket, $key);
    }

    /**
     * Check whether an object exists in the bucket (HEAD request).
     */
    public function objectExists(string $bucket, string $key): bool
    {
        try {
            $this->client->headObject(['Bucket' => $bucket, 'Key' => $key]);
            return true;
        } catch (AwsException $e) {
            // Treat any AWS error as non-existence for verification purposes
            return false;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Expose the underlying client for advanced checks if needed.
     */
    public function getClient(): S3Client
    {
        return $this->client;
    }
}
