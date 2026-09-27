<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\EventPerformance;
use App\Filament\Pages\OrganizerPerformance;
use App\Filament\Resources\Disputes\DisputeResource;
use App\Filament\Resources\PayoutRequests\PayoutRequestResource;
use App\Services\Analytics\Charts\Format;
use App\Services\Analytics\Metrics;
use App\Services\Analytics\PlatformMetrics;
use Throwable;

/**
 * The platform's headline figures for one market and period.
 *
 * Flows — sales, fees, refunds — are compared with the period before and
 * carry a sparkline of the period. States — disputes open, money owed,
 * events on sale — are what they are now and have no comparison.
 */
class PlatformKpis extends AnalyticsWidget
{
    protected string $view = 'filament.widgets.platform-kpis';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 1;

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $currency = $this->currency();
        $period = $this->period();
        $metrics = app(PlatformMetrics::class);

        $overview = $metrics->overview($currency, $period);
        $trend = $metrics->trend($currency, $period);

        $now = $overview['current'];
        $before = $overview['previous'];
        $money = fn (int $amount) => Format::money($amount, $currency);
        $comparison = $period->comparisonLabel();

        $tiles = [
            [
                'label' => 'Gross sales',
                'value' => $money($now['gross']),
                'change' => Metrics::change($now['gross'], $before['gross']),
                'trend' => $trend['gross'],
                'hint' => $now['average_order'] !== null ? 'Average order '.$money($now['average_order']) : null,
            ],
            [
                'label' => 'Platform service charge',
                'value' => $money($now['service']),
                'change' => Metrics::change($now['service'], $before['service']),
                'trend' => $trend['service'],
            ],
            [
                'label' => 'Gateway fees',
                'value' => $money($now['fees']),
                'change' => Metrics::change($now['fees'], $before['fees']),
                'upIsGood' => false,
                'trend' => $trend['fees'],
                'hint' => $now['fees_pending'] > 0 ? $now['fees_pending'].' '.str('payment')->plural($now['fees_pending']).' not yet settled' : null,
            ],
            [
                'label' => 'Net platform take',
                'value' => $money($now['net_take']),
                'change' => Metrics::change($now['net_take'], $before['net_take']),
                'trend' => $trend['net_take'],
                'hint' => 'Service charge less gateway fees and refunded service charge',
            ],
            [
                'label' => 'Orders',
                'value' => number_format($now['orders']),
                'change' => Metrics::change($now['orders'], $before['orders']),
                'trend' => $trend['orders'],
                'hint' => number_format($now['door_orders']).' at the door',
            ],
            [
                'label' => 'Tickets sold',
                'value' => number_format($now['tickets']),
                'change' => Metrics::change($now['tickets'], $before['tickets']),
                'trend' => $trend['tickets'],
            ],
            [
                'label' => 'Refunds',
                'value' => $money($now['refunded']),
                'change' => Metrics::change($now['refunded'], $before['refunded']),
                'upIsGood' => false,
                'trend' => $trend['refunded'],
                'hint' => number_format($now['refunds']).' '.str('refund')->plural($now['refunds']).' · '.Format::percent($now['refund_rate']).' of gross sales',
            ],
            [
                'label' => 'Disputes open',
                'value' => number_format($overview['disputes']['open']),
                'hint' => $money($overview['disputes']['open_amount']).' under dispute · '.$overview['disputes']['opened'].' opened in period',
                'href' => self::urlIf(DisputeResource::class),
            ],
            [
                'label' => 'Payout requests waiting',
                'value' => number_format($overview['payout_requests']['count']),
                // The amount only for staff who may open the payout requests.
                'hint' => PayoutRequestResource::canViewAny() ? $money($overview['payout_requests']['amount']).' asked for' : null,
                'href' => self::urlIf(PayoutRequestResource::class),
            ],
            [
                'label' => 'Owed to organizers',
                'value' => $money($overview['owed']['owed']),
                'hint' => $overview['owed']['organizations'].' '.str('organization')->plural($overview['owed']['organizations'])
                    .($overview['owed']['overdrawn'] > 0 ? ' · '.$money($overview['owed']['overdrawn']).' overdrawn' : ''),
            ],
            [
                'label' => 'Active organizers',
                'value' => number_format($now['organizations']),
                'change' => Metrics::change($now['organizations'], $before['organizations']),
                'hint' => 'Sold at least one order in the period',
                'href' => OrganizerPerformance::getUrl(['filters' => $this->pageFilters]),
            ],
            [
                'label' => 'Events on sale',
                'value' => number_format($overview['events_on_sale']),
                'hint' => 'Published, upcoming, with a tier on sale',
                'href' => EventPerformance::getUrl(),
            ],
            [
                'label' => 'New organizers',
                'value' => number_format($overview['new_organizers']['current']),
                'change' => Metrics::change($overview['new_organizers']['current'], $overview['new_organizers']['previous']),
                'hint' => 'All markets',
            ],
        ];

        return [
            'tiles' => $tiles,
            'comparison' => $comparison,
            'heading' => 'Key figures, '.$period->label().', '.$currency,
        ];
    }

    /** @param class-string $resource */
    private static function urlIf(string $resource): ?string
    {
        try {
            return $resource::canViewAny() ? $resource::getUrl('index') : null;
        } catch (Throwable) {
            return null;
        }
    }
}
