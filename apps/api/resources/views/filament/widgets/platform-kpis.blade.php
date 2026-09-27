<x-filament-widgets::widget>
    <h2 class="mf-sr">{{ $heading }}</h2>
    <div class="mf-kpis">
        @foreach ($tiles as $tile)
            <x-charts.stat
                :label="$tile['label']"
                :value="$tile['value']"
                :change="$tile['change'] ?? null"
                :up-is-good="$tile['upIsGood'] ?? true"
                :comparison="array_key_exists('change', $tile) ? $comparison : null"
                :trend="$tile['trend'] ?? null"
                :hint="$tile['hint'] ?? null"
                :href="$tile['href'] ?? null"
            />
        @endforeach
    </div>
</x-filament-widgets::widget>
