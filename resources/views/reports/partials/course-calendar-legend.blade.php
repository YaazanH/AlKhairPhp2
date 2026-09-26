<div class="calendar-legend" data-calendar-event-legend>
    <h2 class="calendar-legend__heading">{{ __('course_calendar.events') }}</h2>
    <table class="calendar-legend__table">
        @foreach (array_chunk($entries, 2) as $row)
            <tr>
                @foreach ($row as $event)
                    <td width="50%" class="calendar-legend__entry">
                        <table class="calendar-legend__item"><tr>
                            <td class="calendar-legend__badge" width="6mm" style="background-color: {{ $event['color'] }};">&nbsp;</td>
                            <td class="calendar-legend__text">
                                <div class="calendar-legend__name">{{ $event['name'] }}</div>
                                <table class="calendar-legend__dates" dir="ltr"><tr><td>{{ $event['starts_on']->format('d-m-Y') }}@if(! $event['starts_on']->isSameDay($event['ends_on'])) &ndash; {{ $event['ends_on']->format('d-m-Y') }}@endif</td></tr></table>
                            </td>
                        </tr></table>
                    </td>
                @endforeach
                @if (count($row) === 1)<td width="50%"></td>@endif
            </tr>
        @endforeach
    </table>
</div>
