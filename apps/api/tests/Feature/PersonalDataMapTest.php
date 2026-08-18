<?php

namespace Tests\Feature;

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
                . implode(', ', $suspects)
        );
    }
}
