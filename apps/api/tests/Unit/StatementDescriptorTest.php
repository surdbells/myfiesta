<?php

namespace Tests\Unit;

use App\Services\Payments\StatementDescriptor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The night's name on a bank statement, within Stripe's rules.
 *
 * Stripe refuses the whole checkout over a suffix that breaks them, so a
 * title nobody thought about — an apostrophe, an accent, an emoji, a long
 * name — must come out as something Stripe takes, or as nothing at all.
 */
class StatementDescriptorTest extends TestCase
{
    public function test_a_short_name_is_the_suffix_as_it_is(): void
    {
        $this->assertSame('AFRO FEST', StatementDescriptor::suffix('Afro Fest', 'MYFIESTA'));
        $this->assertSame('MYFIESTA* AFRO FEST', StatementDescriptor::line('Afro Fest', 'MYFIESTA'));
    }

    public function test_a_long_name_is_cut_at_a_word_to_fit_twenty_two_characters(): void
    {
        // MYFIESTA and "* " leave twelve.
        $this->assertSame('AFROBEATS', StatementDescriptor::suffix('Afrobeats & Amapiano: Summer Rooftop Session 2026', 'MYFIESTA'));
        $this->assertSame('FETE DE LA', StatementDescriptor::suffix('Fête de la Musique', 'MYFIESTA'));
    }

    public function test_a_single_long_word_is_cut_short_rather_than_dropped(): void
    {
        $this->assertSame('SUPERCALIFRA', StatementDescriptor::suffix('Supercalifragilistic', 'MYFIESTA'));
    }

    public function test_only_letters_digits_and_spaces_survive(): void
    {
        // MF and "* " leave eighteen; LIVE would make nineteen.
        $suffix = StatementDescriptor::suffix('Ada\'s "Big" <Night> *Live* \\ Party', 'MF');

        $this->assertSame('ADAS BIG NIGHT', $suffix);

        foreach (['<', '>', '\\', "'", '"', '*'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, (string) $suffix);
        }
    }

    public function test_accents_become_their_plain_letters(): void
    {
        $this->assertSame('SOIREE A MONTREAL', StatementDescriptor::suffix('Soirée à Montréal', 'MF'));
    }

    public function test_a_name_with_no_letter_left_gives_no_suffix(): void
    {
        // Stripe needs a letter in the suffix itself; the prefix alone is
        // still a line it accepts.
        $this->assertNull(StatementDescriptor::suffix('2026', 'MYFIESTA'));
        $this->assertNull(StatementDescriptor::suffix('🎉🎉🎉', 'MYFIESTA'));
        $this->assertNull(StatementDescriptor::suffix('   ', 'MYFIESTA'));
        $this->assertSame('MYFIESTA', StatementDescriptor::line('🎉', 'MYFIESTA'));
    }

    public function test_a_prefix_nobody_configured_is_taken_as_the_longest_stripe_allows(): void
    {
        // Ten, and "* ", leave ten: short rather than refused.
        $this->assertSame('AFRO FEST', StatementDescriptor::suffix('Afro Fest Toronto', null));
        $this->assertSame('AFRO FEST', StatementDescriptor::suffix('Afro Fest Toronto', 'A PREFIX TOO LONG FOR STRIPE'));
    }

    /** @return array<string, array{0: string}> */
    public static function titles(): array
    {
        return [
            'plain' => ['Afro Fest'],
            'long' => ['The Very Long Name Of A Night That Goes On And On'],
            'punctuated' => ['R&B Night — "Live" @ The Rex (18+)'],
            'accented' => ['Fête Nationale du Québec'],
            'digits first' => ['2026 New Year\'s Eve Countdown'],
            'one word' => ['Extraordinarilylongword'],
        ];
    }

    #[DataProvider('titles')]
    public function test_every_line_is_within_stripes_rules_for_every_prefix_length(string $title): void
    {
        foreach (['AB', 'ABC', 'MYFEST', 'MYFIESTA', 'MYFIESTA12'] as $prefix) {
            $suffix = StatementDescriptor::suffix($title, $prefix);
            $line = StatementDescriptor::line($title, $prefix);

            $this->assertLessThanOrEqual(StatementDescriptor::MAX, strlen($line), "{$line} is too long");
            $this->assertMatchesRegularExpression('/^[A-Z0-9 ]+$/', (string) $suffix);
            $this->assertMatchesRegularExpression('/[A-Z]/', (string) $suffix);
            $this->assertSame(trim((string) $suffix), $suffix);
            $this->assertStringNotContainsString('  ', (string) $suffix);
        }
    }
}
