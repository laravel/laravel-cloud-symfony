<?php

namespace Laravel\Cloud\Symfony\Tests;

/**
 * Encrypts like Laravel's Encrypter::encryptString, as Cloud does failed-job responses.
 */
trait EncryptsLikeLaravel
{
    private static function encryptLikeLaravel(string $value, string $key, string $cipher = 'aes-256-cbc'): string
    {
        $iv = random_bytes(openssl_cipher_iv_length($cipher));
        $aead = str_ends_with($cipher, '-gcm');
        $tag = '';

        $encrypted = $aead
            ? openssl_encrypt($value, $cipher, $key, 0, $iv, $tag)
            : openssl_encrypt($value, $cipher, $key, 0, $iv);

        $iv = base64_encode($iv);

        return base64_encode(json_encode([
            'iv' => $iv,
            'value' => $encrypted,
            'mac' => $aead ? '' : hash_hmac('sha256', $iv.$encrypted, $key),
            'tag' => base64_encode($tag),
        ], JSON_UNESCAPED_SLASHES));
    }
}
