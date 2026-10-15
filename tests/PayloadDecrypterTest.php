<?php

namespace Laravel\Cloud\Symfony\Tests;

use Laravel\Cloud\Symfony\Queue\Retry\PayloadDecrypter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class PayloadDecrypterTest extends TestCase
{
    use EncryptsLikeLaravel;

    // A stock Symfony APP_SECRET.
    private const SECRET = '0123456789abcdef0123456789abcdef';

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function keys(): iterable
    {
        yield 'a Symfony APP_SECRET with AES-256-CBC' => [self::SECRET, self::SECRET, 'aes-256-cbc'];
        yield 'a Symfony APP_SECRET with AES-256-GCM' => [self::SECRET, self::SECRET, 'aes-256-gcm'];
        yield 'a Laravel-style base64: key' => ['base64:'.base64_encode(str_repeat('k', 32)), str_repeat('k', 32), 'aes-256-cbc'];
        yield 'a 16-byte key with AES-128-CBC' => ['abcdefghijklmnop', 'abcdefghijklmnop', 'aes-128-cbc'];
    }

    #[DataProvider('keys')]
    public function testItDecryptsWhatLaravelEncrypts(string $secret, string $key, string $cipher): void
    {
        $plaintext = json_encode(['data' => [['id' => 'job-1', 'payload' => '{"ü":1}']]]);

        $decrypter = new PayloadDecrypter($secret);

        $this->assertSame($plaintext, $decrypter->decrypt(self::encryptLikeLaravel($plaintext, $key, $cipher)));
    }

    public function testItAsksForTheCbcCipherTheKeyFits(): void
    {
        $this->assertSame('AES-256-CBC', (new PayloadDecrypter(self::SECRET))->cipherHeader());
        $this->assertSame('AES-128-CBC', (new PayloadDecrypter('abcdefghijklmnop'))->cipherHeader());
    }

    public function testItRejectsAPayloadEncryptedWithAnotherSecret(): void
    {
        $payload = self::encryptLikeLaravel('secret', strrev(self::SECRET));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('MAC is invalid');

        (new PayloadDecrypter(self::SECRET))->decrypt($payload);
    }

    public function testItRejectsATamperedGcmPayload(): void
    {
        $payload = json_decode(base64_decode(self::encryptLikeLaravel('secret', self::SECRET, 'aes-256-gcm')), true);
        $payload['tag'] = base64_encode(str_repeat("\0", 16));

        $this->expectException(RuntimeException::class);

        (new PayloadDecrypter(self::SECRET))->decrypt(base64_encode(json_encode($payload)));
    }

    public function testItRejectsAMalformedPayload(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('invalid');

        (new PayloadDecrypter(self::SECRET))->decrypt('not-a-payload');
    }

    public function testItNeedsASecret(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('APP_SECRET is not set');

        new PayloadDecrypter(null);
    }

    public function testItRejectsASecretOfTheWrongLength(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('16- or 32-byte key');

        new PayloadDecrypter('too-short');
    }
}
