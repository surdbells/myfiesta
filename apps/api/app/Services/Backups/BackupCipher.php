<?php

namespace App\Services\Backups;

use RuntimeException;

/**
 * Encrypting a backup on its way out, and opening one on its way back.
 *
 * libsodium's secretstream (XChaCha20-Poly1305), a megabyte at a time, so a
 * dump of any size passes through in constant memory and never sits anywhere
 * unencrypted. Each piece is authenticated, and the last one says it is the
 * last: a backup cut short in transit fails to open rather than restoring
 * half a database that looks whole.
 *
 * The file: MAGIC, the stream's header, then each piece as a four-byte length
 * and the sealed bytes.
 */
final class BackupCipher
{
    public const MAGIC = "MFBK\x01";

    private const CHUNK = 1048576;

    /**
     * The configured key as raw bytes, or null when there is none.
     *
     * A key that is there and wrong is refused outright, never treated as
     * "no key": that would quietly start storing plain dumps.
     */
    public static function key(?string $configured): ?string
    {
        if ($configured === null || trim($configured) === '') {
            return null;
        }

        $key = base64_decode(preg_replace('/^base64:/', '', trim($configured)), true);

        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES) {
            throw new RuntimeException('BACKUP_ENCRYPTION_KEY is not 32 bytes of base64. Make one with `php artisan backup:key`.');
        }

        return $key;
    }

    public static function generateKey(): string
    {
        return base64_encode(sodium_crypto_secretstream_xchacha20poly1305_keygen());
    }

    /**
     * Something to write a dump into, which seals it on the way when there is
     * a key, and counts and hashes exactly the bytes that end up stored.
     *
     * @param  resource  $out
     */
    public static function writer($out, ?string $key): BackupWriter
    {
        return new BackupWriter($out, $key, self::CHUNK);
    }

    /**
     * Open a sealed backup from $in into $out.
     *
     * @param  resource  $in
     * @param  resource  $out
     */
    public static function decrypt($in, $out, string $key): void
    {
        if (self::read($in, strlen(self::MAGIC)) !== self::MAGIC) {
            throw new RuntimeException('This is not an encrypted myFiesta backup.');
        }

        $header = self::read($in, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);

        if (strlen($header) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES) {
            throw new RuntimeException('The backup ends before its header does.');
        }

        $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $key);
        $finished = false;

        while (($prefix = self::read($in, 4)) !== '') {
            if ($finished) {
                throw new RuntimeException('The backup carries bytes after its last piece.');
            }

            if (strlen($prefix) !== 4) {
                throw new RuntimeException('The backup is cut short.');
            }

            $length = unpack('N', $prefix)[1];
            $sealed = self::read($in, $length);

            if (strlen($sealed) !== $length) {
                throw new RuntimeException('The backup is cut short.');
            }

            $opened = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $sealed);

            if ($opened === false) {
                throw new RuntimeException('The backup does not open with this key, or has been altered.');
            }

            [$plain, $tag] = $opened;
            fwrite($out, $plain);

            $finished = $tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL;
        }

        if (! $finished) {
            throw new RuntimeException('The backup is cut short: its last piece never arrived.');
        }
    }

    /**
     * Exactly $length bytes, or fewer only at the end of the stream.
     *
     * @param  resource  $in
     */
    private static function read($in, int $length): string
    {
        $bytes = '';

        while ($length > 0 && ! feof($in)) {
            $piece = fread($in, $length);

            // Every stream this reads blocks until it has something, so
            // nothing back is the end of it.
            if ($piece === false || $piece === '') {
                break;
            }

            $bytes .= $piece;
            $length -= strlen($piece);
        }

        return $bytes;
    }
}
