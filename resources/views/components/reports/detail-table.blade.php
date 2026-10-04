@props(['result', 'presentation' => [], 'compact' => false])

@php
    $density = (string) data_get($presentation, 'density', 'comfortable');
    $rows = collect($result['rows'] ?? [])->take($compact ? 3 : PHP_INT_MAX);
@endphp

@if($rows->isEmpty())
    <div class="px-6 py-12 text-center text-sm text-neutral-400">{{ __('report_designer.preview.empty') }}</div>
@else
    <div class="overflow-x-auto">
        <table class="min-w-full {{ $compact || $density === 'compact' ? 'text-xs' : 'text-sm' }}">
            <thead><tr>
                @foreach ($result['columns'] as $column)
                    <th class="{{ $compact || $density === 'compact' ? 'px-3 py-2' : 'px-5 py-4' }} text-start">{{ $column['label'] }}</th>
                @endforeach
            </tr></thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr class="border-t border-white/5">
                        @foreach (array_keys($result['columns']) as $fieldKey)
                            <td class="whitespace-nowrap {{ $compact || $density === 'compact' ? 'px-3 py-2' : 'px-5 py-4' }} text-neutral-200">{{ filled($row[$fieldKey] ?? null) ? $row[$fieldKey] : '—' }}</td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
