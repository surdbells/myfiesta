<?php

namespace Tests\Feature;

use App\Services\Analytics\Charts\Format;
use App\Services\Analytics\Charts\Scale;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * The admin's chart kit, rendered and read back as SVG.
 *
 * A chart that renders without an error can still draw nothing, draw the
 * wrong number of bars, or produce markup no browser will parse. These render
 * each component, parse what comes out as XML — which is stricter than a
 * browser — and count the marks.
 */
class AdminChartKitTest extends TestCase
{
    /** @return array{0: DOMXPath, 1: string} */
    private function svg(string $html, int $index = 0): array
    {
        preg_match_all('/<svg\b.*?<\/svg>/s', $html, $matches);
        $this->assertArrayHasKey($index, $matches[0], 'No <svg> was rendered.');

        $document = new DOMDocument;
        $this->assertTrue(@$document->loadXML($matches[0][$index]), 'The SVG is not well-formed XML.');

        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('s', 'http://www.w3.org/2000/svg');

        return [$xpath, $matches[0][$index]];
    }

    private function marks(DOMXPath $xpath, string $class, string $element = '*'): int
    {
        return $xpath->query("//s:{$element}[contains(concat(' ', normalize-space(@class), ' '), ' {$class} ')]")->length;
    }

    public function test_a_line_chart_draws_one_line_per_series_and_a_hover_target_per_point(): void
    {
        $html = Blade::render('<x-charts.line title="Sales" :labels="$labels" :series="$series" format="money" currency="CAD" area />', [
            'labels' => ['Sep 1', 'Sep 2', 'Sep 3', 'Sep 4'],
            'series' => [
                ['name' => 'Gross', 'values' => [10_000, 25_000, 0, 12_550]],
                ['name' => 'Net', 'values' => [9_000, 20_000, 0, 10_000], 'slot' => 2],
            ],
        ]);

        [$xpath, $svg] = $this->svg($html);

        $this->assertSame(2, $this->marks($xpath, 'mf-line', 'path'));
        $this->assertSame(2, $this->marks($xpath, 'mf-area', 'path'));
        $this->assertSame(4, $this->marks($xpath, 'mf-hit', 'g'));
        $this->assertSame(8, $this->marks($xpath, 'mf-dot', 'circle'));

        // Accessible name and description, and a tooltip per point.
        $this->assertSame('img', $xpath->query('/s:svg/@role')->item(0)->nodeValue);
        $this->assertSame('Sales', $xpath->query('/s:svg/s:title')->item(0)->textContent);
        $this->assertStringContainsString('Gross: total $475.50', $xpath->query('/s:svg/s:desc')->item(0)->textContent);
        $this->assertStringContainsString('Sep 4 — Gross: $125.50; Net: $100.00', $svg);

        // Axis from zero with round money ticks.
        $this->assertStringContainsString('>$0<', $svg);
        $this->assertStringContainsString('>$300<', $svg);

        // A legend for two series, and the numbers as a table.
        $this->assertStringContainsString('class="mf-legend"', $html);
        $this->assertStringContainsString('<summary>Data table</summary>', $html);
        $this->assertSame(4, substr_count($html, '<th scope="row">'));
    }

    public function test_an_empty_series_says_so_instead_of_drawing_a_flat_line(): void
    {
        $html = Blade::render('<x-charts.line title="Sales" :labels="[\'a\', \'b\']" :series="[[\'name\' => \'Gross\', \'values\' => [0, 0]]]" empty="No sales yet." />');

        $this->assertStringNotContainsString('<svg', $html);
        $this->assertStringContainsString('No sales yet.', $html);
    }

    public function test_grouped_columns_draw_a_bar_per_non_zero_value(): void
    {
        $html = Blade::render('<x-charts.bar title="Orders and tickets" :labels="$labels" :series="$series" />', [
            'labels' => ['Mon', 'Tue', 'Wed'],
            'series' => [
                ['name' => 'Orders', 'values' => [3, 0, 5]],
                ['name' => 'Tickets', 'values' => [6, 1, 9], 'slot' => 2],
            ],
        ]);

        [$xpath] = $this->svg($html);

        $this->assertSame(5, $this->marks($xpath, 'mf-bar', 'path'));
        $this->assertSame(3, $this->marks($xpath, 'mf-band', 'g'));
        $this->assertSame(3, $this->marks($xpath, 'mf-s2', 'path'), 'Series colour follows the slot given.');
        $this->assertSame(5, $xpath->query('//s:path[contains(@class, "mf-bar")]/s:title')->length, 'Every bar carries a tooltip.');
    }

    public function test_stacked_columns_draw_a_segment_per_non_zero_value(): void
    {
        $html = Blade::render('<x-charts.bar title="Split" stacked :labels="$labels" :series="$series" format="money" currency="NGN" />', [
            'labels' => ['Aug', 'Sep'],
            'series' => [
                ['name' => 'Organizer', 'values' => [500_000, 700_000]],
                ['name' => 'Platform', 'values' => [50_000, 0]],
                ['name' => 'Tax', 'values' => [37_500, 52_500]],
            ],
        ]);

        [$xpath, $svg] = $this->svg($html);

        $this->assertSame(5, $this->marks($xpath, 'mf-bar', 'path'));
        $this->assertStringContainsString('Aug · Platform: ₦500', $svg);
        $this->assertStringContainsString('data-chart="stacked-bar"', $html);
    }

    public function test_a_ranking_draws_one_bar_per_item_with_its_value_at_the_tip(): void
    {
        $html = Blade::render('<x-charts.hbar title="Top organizers" :items="$items" format="money" currency="CAD" />', [
            'items' => [
                ['label' => 'Lagos Nights', 'value' => 250_000, 'url' => 'https://example.test/a'],
                ['label' => 'Toronto Collective With A Very Long Name Indeed', 'value' => 125_000],
                ['label' => 'Quiet Org', 'value' => 0],
            ],
        ]);

        [$xpath, $svg] = $this->svg($html);

        $this->assertSame(2, $this->marks($xpath, 'mf-bar', 'path'), 'A zero is a row with no bar.');
        $this->assertSame(3, $xpath->query('//*[contains(@class, "mf-row")]')->length);
        $this->assertSame(1, $xpath->query('//s:a[@href="https://example.test/a"]')->length, 'A row with a url is a link.');
        $this->assertStringContainsString('$2,500.00', $svg);
        $this->assertStringContainsString('Toronto Collective With A Very Long Name Indeed: $1,250.00', $svg, 'The full label is in the tooltip.');
    }

    public function test_a_ranking_against_capacity_draws_a_track_behind_each_bar(): void
    {
        $html = Blade::render('<x-charts.hbar title="Fill" :items="$items" />', [
            'items' => [
                ['label' => 'Friday', 'value' => 45, 'capacity' => 100],
                ['label' => 'Saturday', 'value' => 80, 'capacity' => null],
            ],
        ]);

        [$xpath, $svg] = $this->svg($html);

        $this->assertSame(1, $this->marks($xpath, 'mf-track', 'path'));
        $this->assertSame(2, $this->marks($xpath, 'mf-bar', 'path'));
        $this->assertStringContainsString('45 / 100 (45%)', $svg);
    }

    public function test_a_donut_draws_a_segment_per_part_and_the_parts_add_up(): void
    {
        $html = Blade::render('<x-charts.donut title="Where the money went" center-label="Gross" :segments="$segments" format="money" currency="CAD" />', [
            'segments' => [
                ['label' => 'Organizer', 'value' => 8_000],
                ['label' => 'Platform', 'value' => 1_000],
                ['label' => 'Tax', 'value' => 1_000],
                ['label' => 'Refunded', 'value' => 0],
            ],
        ]);

        [$xpath, $svg] = $this->svg($html);

        $this->assertSame(3, $this->marks($xpath, 'mf-seg', 'path'));
        $this->assertStringContainsString('Organizer: $80.00 (80.0%)', $svg);
        $this->assertStringContainsString('>$100<', $svg, 'The middle states the whole.');
        $this->assertStringContainsString('Gross $100.00', $xpath->query('/s:svg/s:desc')->item(0)->textContent);
    }

    public function test_a_whole_ring_is_still_a_closed_shape(): void
    {
        $html = Blade::render('<x-charts.donut title="All online" :segments="[[\'label\' => \'Online\', \'value\' => 5]]" />');

        [$xpath] = $this->svg($html);

        $d = $xpath->query('//s:path[contains(@class, "mf-seg")]/@d')->item(0)->nodeValue;
        $this->assertSame(4, substr_count($d, 'A'), 'Drawn as two arcs out and two back, so a full circle does not collapse.');
    }

    public function test_more_than_six_parts_fold_into_other_rather_than_a_seventh_colour(): void
    {
        $segments = array_map(fn ($i) => ['label' => "City {$i}", 'value' => 10 - $i], range(1, 8));

        $html = Blade::render('<x-charts.donut title="Cities" :segments="$segments" />', ['segments' => $segments]);

        [$xpath] = $this->svg($html);

        $this->assertSame(6, $this->marks($xpath, 'mf-seg', 'path'));
        $this->assertSame(1, $this->marks($xpath, 'mf-s0', 'path'), 'The folded remainder is gray.');
        $this->assertSame(0, $this->marks($xpath, 'mf-s7', 'path'));
    }

    public function test_a_sparkline_is_one_line_with_its_last_point_marked(): void
    {
        $html = Blade::render('<x-charts.sparkline label="Orders" :values="[1, 4, 2, 8]" />');

        [$xpath] = $this->svg($html);

        $this->assertSame(1, $this->marks($xpath, 'mf-line', 'path'));
        $this->assertSame(1, $this->marks($xpath, 'mf-end', 'circle'));
        $this->assertStringContainsString('ending higher than it started', $html);
    }

    public function test_a_stat_tile_says_which_way_and_whether_that_is_good(): void
    {
        $up = Blade::render('<x-charts.stat label="Gross sales" value="CA$1,000.00" :change="0.25" comparison="vs previous 30 days" :trend="[1, 2, 3]" />');

        $this->assertStringContainsString('mf-delta-good', $up);
        $this->assertStringContainsString('+25.0%', $up);
        $this->assertStringContainsString('up, an improvement', $up);
        $this->assertStringContainsString('data-chart="sparkline"', $up);

        $refunds = Blade::render('<x-charts.stat label="Refunds" value="CA$50.00" :change="0.5" :up-is-good="false" comparison="vs previous 30 days" />');
        $this->assertStringContainsString('mf-delta-bad', $refunds);
        $this->assertStringContainsString('a worsening', $refunds);

        $nothing = Blade::render('<x-charts.stat label="Orders" value="3" :change="null" comparison="vs previous 30 days" />');
        $this->assertStringContainsString('No change to compare', $nothing);
    }

    public function test_the_styles_theme_every_slot_in_light_and_dark(): void
    {
        $css = Blade::render('<x-charts.styles />');

        $this->assertStringContainsString('--mf-s1: var(--primary-600', $css);
        $this->assertStringContainsString(':root.dark', $css);

        foreach (range(1, 6) as $slot) {
            $this->assertSame(2, substr_count($css, "--mf-s{$slot}: "), "Slot {$slot} needs a light and a dark value.");
        }
    }

    public function test_axis_ticks_are_round_numbers_from_zero(): void
    {
        $this->assertSame([0.0, 250.0, 500.0, 750.0], Scale::nice(700, 3)['ticks']);
        $this->assertSame([0.0, 200.0, 400.0, 600.0, 800.0], Scale::nice(700, 4)['ticks']);
        $this->assertSame([0.0, 1.0, 2.0, 3.0], Scale::nice(3, 4, integer: true)['ticks']);
        $this->assertSame([0, 4, 8, 13], Scale::labelIndexes(14, 5));
        $this->assertSame([0, 1, 2], Scale::labelIndexes(3, 5));
    }

    public function test_money_is_compact_on_an_axis_and_exact_elsewhere(): void
    {
        $this->assertSame('$12.5K', Format::moneyCompact(1_250_000, 'CAD'));
        $this->assertSame('₦2.5M', Format::moneyCompact(250_000_000, 'NGN'));
        $this->assertSame('$12,500.00', Format::money(1_250_000, 'CAD'));
        $this->assertSame('12.5%', Format::percent(0.125));
        $this->assertSame('—', Format::percent(null));
        $this->assertSame('−3.0%', Format::delta(-0.03));
    }
}
