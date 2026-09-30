{{--
    The chart as a table: every value, exact, for anybody who cannot or would
    rather not read it off the picture — a screen reader, a keyboard, someone
    copying a figure into an email.

    headers: list<string>          column headings
    rows:    list<list<string>>    formatted cells, first column the label
--}}
@props(['caption', 'headers' => [], 'rows' => []])

<details class="mf-data">
    <summary>Data table</summary>
    {{-- Down in the outer box, which keeps its scrollbar; sideways in the
         inner one, which draws none (see .mf-table-wrap in the styles). --}}
    <div class="mf-table-wrap">
        <x-charts.scroll-region class="mf-table-x" :label="$caption.', data table'">
            <table>
                <caption class="mf-sr">{{ $caption }}</caption>
                <thead>
                    <tr>
                        @foreach ($headers as $i => $header)
                            <th scope="col" @class(['mf-num' => $i > 0])>{{ $header }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr>
                            @foreach ($row as $i => $cell)
                                @if ($i === 0)
                                    <th scope="row">{{ $cell }}</th>
                                @else
                                    <td class="mf-num">{{ $cell }}</td>
                                @endif
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-charts.scroll-region>
    </div>
</details>
