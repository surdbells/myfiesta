<?php

namespace App\Services\Backups;

/**
 * Which backups to keep: the newest of each of the last few days, weeks and
 * months.
 *
 * Seven daily, four weekly and six monthly by default — a bad row found this
 * morning can be taken from last night, one found at the end of the month
 * from a Sunday, and one found at the end of a quarter from the first of a
 * month. A day, week or month with no backup in it is simply skipped rather
 * than counted, so a fortnight of failed runs does not quietly use up the
 * weeks that are still there.
 *
 * The newest backup is always kept, whatever the numbers say.
 */
final class Retention
{
    /**
     * @param  list<StoredBackup>  $backups
     * @return array{keep: list<StoredBackup>, remove: list<StoredBackup>}
     */
    public static function apply(array $backups, int $daily, int $weekly, int $monthly): array
    {
        usort($backups, fn (StoredBackup $a, StoredBackup $b) => $b->takenAt <=> $a->takenAt);

        $kept = [];

        foreach ([
            [$daily, 'Y-m-d'],
            // ISO week-numbering year and week, so the last days of December
            // fall in the right week.
            [$weekly, 'o-\WW'],
            [$monthly, 'Y-m'],
        ] as [$limit, $period]) {
            $seen = [];

            foreach ($backups as $backup) {
                if (count($seen) >= $limit) {
                    break;
                }

                $key = $backup->takenAt->utc()->format($period);

                if (! isset($seen[$key])) {
                    $seen[$key] = true;
                    $kept[$backup->name] = true;
                }
            }
        }

        if ($backups !== []) {
            $kept[$backups[0]->name] = true;
        }

        return [
            'keep' => array_values(array_filter($backups, fn (StoredBackup $b) => isset($kept[$b->name]))),
            'remove' => array_values(array_filter($backups, fn (StoredBackup $b) => ! isset($kept[$b->name]))),
        ];
    }
}
