<?php

namespace Laravel\Cloud\Symfony\Queue\Retry;

use RuntimeException;

/**
 * Decrypt-only port of Laravel's Encrypter::decryptString, keyed by APP_SECRET.
 *
 * A 64-char hex secret (as Cloud generates) is hex-decoded to its 32 bytes;
 * otherwise the key is parsed like Laravel's APP_KEY ("base64:" prefix or raw).
 */
final class PayloadDecrypter
{
    private const CIPHERS = [
        'aes-128-cbc' => ['size' => 16, 'aead' => false],
        'aes-256-cbc' => ['size' => 32, 'aead' => false],
        'aes-128-gcm' => ['size' => 16, 'aead' => true],
        'aes-256-gcm' => ['size' => 32, 'aead' => true],
    ];

    private readonly string $key;

    public function __construct(#[\SensitiveParameter] ?string $secret)
    {
        if ($secret === null || $secret === '') {
            throw new RuntimeException('APP_SECRET is not set; it is needed to decrypt failed jobs retried from the Laravel Cloud dashboard.');
        }

        $this->key = match (true) {
            str_starts_with($secret, 'base64:') => (string) base64_decode(substr($secret, strlen('base64:')), true),
            // Cloud generates APP_SECRET as bin2hex(random_bytes(32)).
            strlen($secret) === 64 && ctype_xdigit($secret) => (string) hex2bin($secret),
            default => $secret,
        };

        if ($this->cipher() === null) {
            throw new RuntimeException(sprintf('APP_SECRET must be a 64-character hex string or a 16- or 32-byte key to decrypt Laravel Cloud payloads; it is %d bytes.', strlen($this->key)));
        }
    }

    /**
     * The Cloud-Encryption-Cipher header value: the CBC cipher the key fits.
     */
    public function cipherHeader(): string
    {
        return strtoupper((string) $this->cipher());
    }

    /**
     * Accepts CBC and GCM payloads; a non-empty tag means GCM.
     */
    public function decrypt(string $payload): string
    {
        $data = json_decode((string) base64_decode($payload, true), true);

        if (! is_array($data)) {
            throw new RuntimeException('The failed job payload is invalid.');
        }

        foreach (['iv', 'value', 'mac'] as $field) {
            if (! is_string($data[$field] ?? null)) {
                throw new RuntimeException('The failed job payload is invalid.');
            }
        }

        $tag = is_string($data['tag'] ?? null) && $data['tag'] !== '' ? base64_decode($data['tag'], true) : null;
        $cipher = $this->cipherFor($tag !== null);
        $iv = base64_decode($data['iv'], true);

        if ($iv === false || strlen($iv) !== openssl_cipher_iv_length($cipher)) {
            throw new RuntimeException('The failed job payload is invalid.');
        }

        if (self::CIPHERS[$cipher]['aead']) {
            if ($tag === false || strlen((string) $tag) !== 16) {
                throw new RuntimeException('Could not decrypt the failed job payload.');
            }
        } elseif (! hash_equals(hash_hmac('sha256', $data['iv'].$data['value'], $this->key), $data['mac'])) {
            throw new RuntimeException('The failed job payload MAC is invalid; is APP_SECRET the secret Laravel Cloud has for this environment?');
        }

        $decrypted = openssl_decrypt($data['value'], $cipher, $this->key, 0, $iv, $tag ?? '');

        if ($decrypted === false) {
            throw new RuntimeException('Could not decrypt the failed job payload.');
        }

        return $decrypted;
    }

    private function cipher(): ?string
    {
        foreach (self::CIPHERS as $name => $properties) {
            if (! $properties['aead'] && $properties['size'] === strlen($this->key)) {
                return $name;
            }
        }

        return null;
    }

    private function cipherFor(bool $aead): string
    {
        foreach (self::CIPHERS as $name => $properties) {
            if ($properties['aead'] === $aead && $properties['size'] === strlen($this->key)) {
                return $name;
            }
        }

        throw new RuntimeException('Could not decrypt the failed job payload.');
    }
}
