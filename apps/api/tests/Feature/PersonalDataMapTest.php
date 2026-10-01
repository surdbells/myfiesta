<?php

namespace Tests\Feature;

use App\Services\PersonalData\Exporter;
use App\Services\PersonalData\Rows;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Keeps config/personal_data.php honest.
 *
 * The map is what the export and erasure jobs read to answer a PIPEDA or NDPR
 * request. A map that has drifted from the schema fails silently and in the
 * worst possible way — by quietly missing someone's data — so it is checked
 * mechanically rather than reviewed by eye.
 */
class PersonalDataMapTest extends TestCase
{
    use RefreshDatabase;

    public static function mapSections(): array
    {
        return [
            'by_user' => ['by_user'],
            'by_email' => ['by_email'],
            'by_phone' => ['by_phone'],
            'organization_scoped' => ['organization_scoped'],
        ];
    }

    #[DataProvider('mapSections')]
    public function test_every_mapped_table_and_column_exists(string $section): void
    {
        $entries = config("personal_data.$section");
        $this->assertNotEmpty($entries, "Section '$section' is empty — that is almost certainly wrong.");

        foreach ($entries as $table => $spec) {
            $this->assertTrue(
                Schema::hasTable($table),
                "personal_data.$section lists table '$table', which does not exist."
            );

            $columns = array_merge(
                [$spec['key']],
                $spec['columns'] ?? [],
                $spec['encrypted'] ?? [],
                $spec['files'] ?? [],
            );

            foreach ($columns as $column) {
                $this->assertTrue(
                    Schema::hasColumn($table, $column),
                    "personal_data.$section.$table references column '$column', which does not exist."
                );
            }
        }
    }

    /**
     * A table reached through another, a filter on what is erased, and files
     * on a disk: each names more of the schema than its own key, and a name
     * gone wrong there misses somebody's data just as quietly.
     */
    #[DataProvider('mapSections')]
    public function test_every_table_reached_through_another_and_every_file_is_mapped_to_something_real(string $section): void
    {
        $entries = config("personal_data.$section");
        $this->assertNotEmpty($entries);

        foreach ($entries as $table => $spec) {
            if (isset($spec['via'])) {
                $this->assertNotEmpty(Rows::parents($spec), "personal_data.$section.$table is reached via nothing.");
            }

            foreach (Rows::parents($spec) as $via) {
                $this->assertTrue(Schema::hasTable($via['table']), "personal_data.$section.$table is reached via '{$via['table']}', which does not exist.");

                foreach ([$via['column'] ?? 'id', $via['key']] as $column) {
                    $this->assertTrue(
                        Schema::hasColumn($via['table'], $column),
                        "personal_data.$section.$table is reached via '{$via['table']}.$column', which does not exist."
                    );
                }

                $through = $via['through'] ?? $spec['key'];
                $this->assertTrue(
                    Schema::hasColumn($table, $through),
                    "personal_data.$section.$table points at '{$via['table']}' by '$through', which does not exist."
                );

                // The parent is what holds the person, so it has to be in the
                // map itself, in the same section, keyed the same way — and
                // not itself reached through another, or the Eraser would
                // blank it in the same pass as this.
                $this->assertSame(
                    $via['key'],
                    config("personal_data.$section.{$via['table']}.key"),
                    "personal_data.$section.$table is reached via '{$via['table']}', which this section does not map by '{$via['key']}'."
                );
                $this->assertArrayNotHasKey(
                    'via',
                    config("personal_data.$section.{$via['table']}"),
                    "personal_data.$section.$table is reached via '{$via['table']}', which is itself reached through another."
                );
            }

            foreach ($spec['erase_where'] ?? [] as $column => $through) {
                $this->assertTrue(Schema::hasColumn($table, $column), "personal_data.$section.$table erases by '$column', which does not exist.");
                $this->assertTrue(
                    Schema::hasColumn($through['table'], $through['column']),
                    "personal_data.$section.$table erases by '{$through['table']}.{$through['column']}', which does not exist."
                );
                $this->assertNotEmpty($through['in'], "personal_data.$section.$table erases by a filter that matches nothing.");
            }

            if (($spec['files'] ?? []) !== []) {
                $this->assertNotNull(
                    config('filesystems.disks.'.($spec['disk'] ?? '')),
                    "personal_data.$section.$table has files but names no disk they are on."
                );
            }
        }
    }

    #[DataProvider('mapSections')]
    public function test_anonymise_entries_declare_what_to_clear(string $section): void
    {
        $entries = config("personal_data.$section");
        $this->assertNotEmpty($entries);

        foreach ($entries as $table => $spec) {
            if (($spec['strategy'] ?? null) !== 'anonymise') {
                continue;
            }

            $this->assertNotEmpty(
                $spec['columns'] ?? [],
                "'$table' is marked anonymise but lists no columns to clear, so erasure would do nothing."
            );
        }
    }

    #[DataProvider('mapSections')]
    public function test_strategies_are_known_and_retention_is_justified(string $section): void
    {
        foreach (config("personal_data.$section") as $table => $spec) {
            $strategy = $spec['strategy'] ?? null;

            $this->assertContains(
                $strategy,
                ['delete', 'anonymise', 'retain'],
                "'$table' declares unknown strategy '$strategy'."
            );

            if ($strategy === 'retain') {
                $this->assertNotEmpty(
                    $spec['reason'] ?? null,
                    "'$table' is retained through an erasure request but gives no legal basis."
                );
            }
        }
    }

    /**
     * Nothing an export carries lets anybody in. A ticket's code, a link's
     * token, under whatever name a table gives it, is left out by name
     * (Exporter::NEVER); a new column that looks like one fails here until it
     * is added there, or listed below as not being one.
     */
    public function test_no_export_carries_a_code_or_a_token(): void
    {
        // Columns named like a credential that are not one ('table.column').
        // None yet; a postal code would be the first.
        $notCredentials = [];
        $checked = 0;

        // The sections an export is built from.
        foreach (['by_user', 'by_email', 'by_phone'] as $section) {
            foreach (array_keys(config("personal_data.$section")) as $table) {
                foreach (Schema::getColumnListing($table) as $column) {
                    if (! preg_match('/(^|_)(code|token|secret|password)$/', $column) || in_array("$table.$column", $notCredentials, true)) {
                        continue;
                    }

                    $this->assertContains($column, Exporter::NEVER, "An export of personal_data.$section.$table would carry '$column'.");
                    $checked++;
                }
            }
        }

        $this->assertGreaterThan(0, $checked);
    }

    /**
     * Tables holding an email or a person's name should be accounted for
     * somewhere in the map. This will not catch everything, but it catches the
     * common case of adding a table and forgetting the map entirely.
     */
    public function test_no_obvious_personal_columns_are_unmapped(): void
    {
        $mapped = collect(config('personal_data'))
            ->only(['by_user', 'by_email', 'organization_scoped'])
            ->flatMap(fn ($section) => array_keys($section))
            ->all();

        $exempt = ['migrations', 'cache', 'cache_locks', 'jobs', 'job_batches',
            'failed_jobs', 'personal_access_tokens'];

        $suspects = [];

        foreach (Schema::getTableListing() as $qualified) {
            // Postgres returns names schema-qualified, e.g. "public.orders".
            $table = str_contains($qualified, '.')
                ? substr($qualified, strrpos($qualified, '.') + 1)
                : $qualified;

            if (in_array($table, $mapped, true) || in_array($table, $exempt, true)) {
                continue;
            }

            foreach (['email', 'buyer_email', 'owner_email', 'holder_name', 'buyer_name'] as $column) {
                if (Schema::hasColumn($table, $column)) {
                    $suspects[] = "$table.$column";
                }
            }
        }

        $this->assertSame(
            [],
            $suspects,
            'These columns look personal but are not in config/personal_data.php: '
                .implode(', ', $suspects)
        );
    }
}
