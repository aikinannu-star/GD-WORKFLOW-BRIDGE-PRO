<?php

class PluginIntegrity
{
    public static function checksum(string $filePath): string
    {
        if (!file_exists($filePath)) {
            throw new RuntimeException('File not found: ' . $filePath);
        }
        return hash_file('sha256', $filePath);
    }

    public static function verify(string $filePath, string $expectedChecksum): bool
    {
        return self::checksum($filePath) === $expectedChecksum;
    }

    public static function sign(string $filePath, string $privateKey): string
    {
        $contents = file_get_contents($filePath);
        return hash_hmac('sha256', $contents, $privateKey);
    }
}
