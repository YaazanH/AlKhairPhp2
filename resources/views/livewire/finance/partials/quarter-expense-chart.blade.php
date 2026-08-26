<x-admin.modal
    :show="$showQuarterDetailsModal"
    :title="__('finance.dashboard.quarter_expense_comparison')"
    close-method="$set('showQuarterDetailsModal', false)"
    max-width="3xl"
    compact
>
    <x-slot:header-actions>
        <div class="finance-quarter-chart__legend" aria-label="{{ __('finance.dashboard.quarter_expense_comparison') }}">
            <span><i class="bg-emerald-400"></i>{{ $year }}</span>
            <span><i class="bg-sky-400"></i>{{ $year - 1 }}</span>
        </div>
    </x-slot:header-actions>

    <svg
        viewBox="0 0 500 250"
        class="finance-quarter-chart h-80 w-full overflow-visible"
        data-quarter-chart-step="{{ $quarterTickStep }}"
        data-quarter-chart-maximum="{{ $quarterExpenseMax }}"
        role="img"
        aria-label="{{ __('finance.dashboard.quarter_expense_comparison') }}"
    >
        <line x1="82" y1="45" x2="82" y2="200" stroke="rgba(255,255,255,.35)" stroke-width="1.5" />
        <line x1="82" y1="200" x2="430" y2="200" stroke="rgba(255,255,255,.35)" stroke-width="1.5" />

        @foreach ($quarterTickValues as $tickValue)
            @php($lineY = $quarterY((float) $tickValue))
            <line x1="82" y1="{{ $lineY }}" x2="430" y2="{{ $lineY }}" stroke="rgba(255,255,255,.08)" />
            <text x="64" y="{{ $lineY + 4 }}" text-anchor="end" direction="ltr" fill="#a3a3a3" font-size="10">{{ number_format($tickValue, $quarterTickDecimals) }}</text>
        @endforeach

        @foreach ([1, 2, 3, 4] as $index => $quarter)
            <line x1="{{ $quarterX($index) }}" y1="45" x2="{{ $quarterX($index) }}" y2="200" stroke="rgba(255,255,255,.08)" />
        @endforeach

        <polyline points="{{ $currentQuarterLine }}" fill="none" stroke="#34d399" stroke-width="4" stroke-linecap="round" stroke-linejoin="round" />
        <polyline points="{{ $previousQuarterLine }}" fill="none" stroke="#38bdf8" stroke-width="4" stroke-linecap="round" stroke-linejoin="round" />

        @foreach ($report['quarter_totals'] as $index => $row)
            <g class="finance-quarter-chart__point" tabindex="0" aria-label="{{ number_format((float) $row['expense'], 0) }}">
                <circle cx="{{ $quarterX($index) }}" cy="{{ $quarterY((float) $row['expense']) }}" r="6" fill="#34d399" />
                <g class="finance-quarter-chart__tooltip" transform="translate({{ $quarterX($index) }}, {{ $quarterY((float) $row['expense']) - 12 }})">
                    <rect x="-24" y="-18" width="48" height="18" rx="6" />
                    <text x="0" y="-6" text-anchor="middle">{{ number_format((float) $row['expense'], 0) }}</text>
                </g>
            </g>
        @endforeach

        @foreach ($report['previous_year_quarter_totals'] as $index => $row)
            <g class="finance-quarter-chart__point" tabindex="0" aria-label="{{ number_format((float) $row['expense'], 0) }}">
                <circle cx="{{ $quarterX($index) }}" cy="{{ $quarterY((float) $row['expense']) }}" r="6" fill="#38bdf8" />
                <g class="finance-quarter-chart__tooltip" transform="translate({{ $quarterX($index) }}, {{ $quarterY((float) $row['expense']) - 12 }})">
                    <rect x="-24" y="-18" width="48" height="18" rx="6" />
                    <text x="0" y="-6" text-anchor="middle">{{ number_format((float) $row['expense'], 0) }}</text>
                </g>
            </g>
        @endforeach

        @foreach ([1, 2, 3, 4] as $index => $quarter)
            <text x="{{ $quarterX($index) }}" y="224" text-anchor="middle" fill="#a3a3a3" font-size="12">Q{{ $quarter }}</text>
        @endforeach
    </svg>
</x-admin.modal>
