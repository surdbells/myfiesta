<?php

namespace App\Services\Backups;

use Carbon\CarbonImmutable;

/**
 * One nightly backup on the target: the dump, and the manifest beside it.
 *
 * Everything about it is read from its name — when it was taken and whether
 * it is encrypted — so listing the target is one directory listing, and a file
 * that does not match the pattern is never taken for a backup, or deleted as
 * an old one.
 */
final class StoredBackup
{
    /** myfiesta-20260927T063000Z.dump, or .dump.enc when encrypted. */
    public const PATTERN = '/^myfiesta-(\d{8}T\d{6}Z)\.dump(\.enc)?$/';

    public function __construct(
        public readonly string $name,
        public readonly CarbonImmutable $takenAt,
        public readonly bool $encrypted,
    ) {}

    public static function named(CarbonImmutable $takenAt, bool $encrypted): self
    {
        $takenAt = $takenAt->utc()->startOfSecond();

        return new self('myfiesta-'.$takenAt->format('Ymd\THis\Z').'.dump'.($encrypted ? '.enc' : ''), $takenAt, $encrypted);
    }

    public static function fromName(string $name): ?self
    {
        if (! preg_match(self::PATTERN, $name, $match)) {
            return null;
        }

        $takenAt = CarbonImmutable::createFromFormat('Ymd\THis\Z', $match[1], 'UTC');

        // A name for a moment that never was — month 13, the 30th of February,
        // hour 25 — is not a backup. createFromFormat does not refuse one: it
        // rolls it over into some other date, and a stray file dated in the
        // year 10007 would be the newest backup for good, the one the health
        // check measures and the drill restores. So the moment has to read
        // back exactly as the name wrote it.
        if ($takenAt === null || $takenAt->format('Ymd\THis\Z') !== $match[1]) {
            return null;
        }

        return new self($name, $takenAt, ($match[2] ?? '') !== '');
    }

    /** The manifest's name: the same stem, so the two sort and travel together. */
    public function manifestName(): string
    {
        return preg_replace('/\.dump(\.enc)?$/', '.json', $this->name);
    }
}
