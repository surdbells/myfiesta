<?php

namespace App\Services\Backups;

use HashContext;
use LogicException;
use RuntimeException;

/**
 * Where pg_dump's output goes: into a file, sealed on the way when there is a
 * key. See BackupCipher for the format.
 *
 * Counts and hashes the bytes as they are written, so the manifest's checksum
 * is of exactly what was stored, and a restore can tell a damaged copy from a
 * good one before it touches a database.
 */
final class BackupWriter
{
    private string $pending = '';

    /** @var string|null secretstream state; null when writing in the clear */
    private ?string $state = null;

    private HashContext $hash;

    private int $bytes = 0;

    private bool $finished = false;

    /** @param  resource  $out */
    public function __construct(private $out, ?string $key, private readonly int $chunk)
    {
        $this->hash = hash_init('sha256');

        if ($key !== null) {
            [$this->state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
            $this->emit(BackupCipher::MAGIC.$header);
        }
    }

    public function write(string $data): void
    {
        if ($this->finished) {
            throw new LogicException('The backup has already been finished.');
        }

        if ($this->state === null) {
            $this->emit($data);

            return;
        }

        $this->pending .= $data;

        while (strlen($this->pending) >= $this->chunk) {
            $this->seal(substr($this->pending, 0, $this->chunk), SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE);
            $this->pending = substr($this->pending, $this->chunk);
        }
    }

    /** @return array{bytes: int, sha256: string} */
    public function finish(): array
    {
        if (! $this->finished && $this->state !== null) {
            // Always a last piece, even an empty one: it is what says the
            // backup was not cut short.
            $this->seal($this->pending, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL);
            $this->pending = '';
        }

        $this->finished = true;
        fflush($this->out);

        return ['bytes' => $this->bytes, 'sha256' => hash_final(hash_copy($this->hash))];
    }

    private function seal(string $plain, int $tag): void
    {
        $sealed = sodium_crypto_secretstream_xchacha20poly1305_push($this->state, $plain, '', $tag);

        $this->emit(pack('N', strlen($sealed)).$sealed);
    }

    private function emit(string $bytes): void
    {
        if ($bytes === '') {
            return;
        }

        if (fwrite($this->out, $bytes) !== strlen($bytes)) {
            throw new RuntimeException('The backup could not be written: the disk it is being written to may be full.');
        }

        hash_update($this->hash, $bytes);
        $this->bytes += strlen($bytes);
    }
}
