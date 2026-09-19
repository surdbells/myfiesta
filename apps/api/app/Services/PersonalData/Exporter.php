<?php

namespace App\Services\PersonalData;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Everything held about one person, as a file they can read.
 *
 * Built from config/personal_data.php rather than from a hand-written list of
 * queries, so a table added to the schema and to the map is in the next export
 * without anybody remembering to add it here. A map entry that has drifted
 * from the schema fails a test, not a person's request.
 *
 * What is left out, deliberately: anything that is somebody else's. A ticket
 * transferred away names the person it went to, and a guest list names the
 * other guests — this returns the rows that are about the subject, not the
 * rows they can see.
 */
class Exporter
{
    /** Columns that would hand somebody a working credential. */
    private const NEVER = ['token', 'access_token', 'password', 'remember_token', 'token_hash', 'secret', 'code'];

    /** @return array<string, mixed> the export, as it is written to the file */
    public function build(Subject $subject): array
    {
        $data = [];

        foreach (['by_user', 'by_email'] as $section) {
            $key = $subject->keyFor($section);

            if ($key === null) {
                continue;
            }

            foreach (config("personal_data.$section") as $table => $spec) {
                $rows = $this->rows($table, $spec['key'], $key);

                if ($rows !== []) {
                    $data[$table] = array_merge($data[$table] ?? [], $rows);
                }
            }
        }

        return [
            'about' => [
                'email' => $subject->email,
                'account' => $subject->user !== null,
            ],
            'prepared_at' => now()->toIso8601String(),
            'what_this_is' => 'Everything myFiesta holds that is about you. Ticket codes and security '
                .'credentials are left out on purpose: they open doors and accounts, and an emailed file is not '
                .'the place for them. Your tickets are always in the link from your confirmation email.',
            'data' => $data,
        ];
    }

    /**
     * Write it to the private disk and say where.
     *
     * Private, always: a data export is the single most sensitive file this
     * platform produces, and a public disk is one misconfigured bucket away
     * from being the worst breach it could have.
     */
    public function write(Subject $subject, string $requestId): string
    {
        $path = "exports/{$requestId}.json";

        Storage::disk('private')->put(
            $path,
            json_encode($this->build($subject), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        );

        return $path;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(string $table, string $column, string|int $value): array
    {
        $query = DB::table($table);

        // Addresses are stored as they were typed; matched as they are meant.
        is_string($value) && str_contains($value, '@')
            ? $query->whereRaw("lower({$column}) = ?", [$value])
            : $query->where($column, $value);

        return $query->get()->map(function ($row) {
            $fields = (array) $row;

            foreach (array_keys($fields) as $field) {
                if (in_array($field, self::NEVER, true)) {
                    $fields[$field] = '[not included]';
                }
            }

            return $fields;
        })->all();
    }
}
