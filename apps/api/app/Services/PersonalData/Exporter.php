<?php

namespace App\Services\PersonalData;

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
    /**
     * Columns that would hand somebody a working credential.
     *
     * A ticket's code by any name: the one on the ticket, and the one a door
     * staffer's scans recorded — a table ticket still admitting more people
     * is let in by it. And a guest's invitation link. An export is a file,
     * and files get forwarded. PersonalDataMapTest fails a mapped column
     * that looks like one of these and is not here.
     */
    public const NEVER = [
        'token', 'access_token', 'password', 'remember_token', 'token_hash', 'secret',
        'code', 'scanned_code', 'invite_token',
    ];

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
                $rows = $this->rows($table, $spec, $key);

                if ($rows !== []) {
                    $data[$table] = array_merge($data[$table] ?? [], $rows);
                }
            }
        }

        // One section is keyed by something a person can have several of.
        foreach ($subject->phones() as $phone) {
            foreach (config('personal_data.by_phone') as $table => $spec) {
                $rows = $this->rows($table, $spec, $phone);

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
     * Every row about them, including the ones an erasure would keep or
     * leave: an answer they picked from a list is still theirs to read.
     *
     * @param  array<string, mixed>  $spec
     * @return list<array<string, mixed>>
     */
    private function rows(string $table, array $spec, string|int $value): array
    {
        return Rows::of($table, $spec, $value)->get()->map(function ($row) {
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
