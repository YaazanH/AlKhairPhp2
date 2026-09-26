@php
    $timelineDirection = app()->getLocale() === 'ar' ? 'rtl' : 'ltr';
@endphp
<section class="surface-table student-timeline" data-student-timeline dir="{{ $timelineDirection }}" aria-labelledby="student-timeline-title"
    wire:key="student-timeline-{{ $studentRecord->id }}"
    x-data="{
        current: {{ $timelineDefaultIndex }},
        init() { this.$nextTick(() => requestAnimationFrame(() => this.go(this.current, false))); },
        names: @js($timeline->pluck('name')->values()),
        tip: null, tipLeft: 0, tipBottom: 0, tipMaxHeight: 0, tipTimer: null,
        showTip(event, trigger) {
            clearTimeout(this.tipTimer);
            if (!event.stats.length) { this.tip = null; return; }
            const bounds = trigger.getBoundingClientRect();
            const width = Math.min(21 * parseFloat(getComputedStyle(document.documentElement).fontSize), innerWidth - 24);
            this.tipLeft = Math.max(12, Math.min(innerWidth - width - 12, bounds.left + bounds.width / 2 - width / 2));
            this.tipBottom = innerHeight - bounds.top + 10;
            this.tipMaxHeight = Math.max(0, bounds.top - 22);
            this.tip = event;
        },
        hideTip() { clearTimeout(this.tipTimer); this.tipTimer = setTimeout(() => this.tip = null, 60); },
        rtl: {{ $timelineDirection === 'rtl' ? 'true' : 'false' }},
        offset(page) {
            const viewport = this.$refs.rail.getBoundingClientRect();
            const bounds = page.getBoundingClientRect();
            return this.rtl ? bounds.right - viewport.right : bounds.left - viewport.left;
        },
        count: {{ $timeline->count() }},
        go(index, animate = true) {
            this.tip = null;
            const rail = this.$refs.rail;
            const page = rail?.children[index];
            if (!page) return;
            this.current = index;
            rail.scrollBy({ left: this.offset(page), behavior: !animate || matchMedia('(prefers-reduced-motion: reduce)').matches ? 'instant' : 'smooth' });
        },
        sync() {
            const rail = this.$refs.rail;
            let nearest = Infinity;
            Array.from(rail.children).forEach((page, index) => {
                const distance = Math.abs(this.offset(page));
                if (distance < nearest) { nearest = distance; this.current = index; }
            });
        }
    }" @click.window="if (!$event.target.closest('.student-timeline__dot, .student-timeline__tooltip')) tip = null" @keydown.escape.window="tip = null" @resize.window="tip = null" @scroll.window="tip = null">
    <div class="admin-grid-meta">
        <div>
            <h2 id="student-timeline-title" class="admin-grid-meta__title">{{ __('student_timeline.title') }}</h2>
            <p class="student-timeline__course-name" x-text="names[current] || ''">{{ $timeline->get($timelineDefaultIndex)['name'] ?? '' }}</p>
        </div>
        @if ($timeline->count() > 1)
            <div class="student-timeline__navigation">
                <button type="button" class="app-pagination__icon" :class="{ 'app-pagination__icon--disabled': current === 0 }" @click="go(current - 1)" :disabled="current === 0" title="{{ __('student_timeline.previous') }}" aria-label="{{ __('student_timeline.previous') }}" data-modal-action-icon-ignore><svg class="h-4 w-4" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path fill-rule="evenodd" d="{{ $timelineDirection === 'rtl' ? 'M7.293 5.293a1 1 0 011.414 0l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414-1.414L10.586 10 7.293 6.707a1 1 0 010-1.414z' : 'M12.707 5.293a1 1 0 010 1.414L9.414 10l3.293 3.293a1 1 0 01-1.414 1.414l-4-4a1 1 0 010-1.414l4-4a1 1 0 011.414 0z' }}" clip-rule="evenodd" /></svg></button>
                <span class="student-timeline__counter" dir="ltr" aria-live="polite"><span x-text="current + 1">{{ $timelineDefaultIndex + 1 }}</span> / {{ $timeline->count() }}</span>
                <button type="button" class="app-pagination__icon" :class="{ 'app-pagination__icon--disabled': current === count - 1 }" @click="go(current + 1)" :disabled="current === count - 1" title="{{ __('student_timeline.next') }}" aria-label="{{ __('student_timeline.next') }}" data-modal-action-icon-ignore><svg class="h-4 w-4" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path fill-rule="evenodd" d="{{ $timelineDirection === 'rtl' ? 'M12.707 5.293a1 1 0 010 1.414L9.414 10l3.293 3.293a1 1 0 01-1.414 1.414l-4-4a1 1 0 010-1.414l4-4a1 1 0 011.414 0z' : 'M7.293 5.293a1 1 0 011.414 0l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414-1.414L10.586 10 7.293 6.707a1 1 0 010-1.414z' }}" clip-rule="evenodd" /></svg></button>
            </div>
        @endif
    </div>
    @if ($timeline->isNotEmpty())
        <div class="student-timeline__rail" dir="{{ $timelineDirection }}" x-ref="rail"
            @keydown.left.self.prevent="go(current + (rtl ? 1 : -1))"
            @keydown.right.self.prevent="go(current + (rtl ? -1 : 1))" @scroll.debounce.80ms="sync()" tabindex="0" role="region" aria-label="{{ __('student_timeline.title') }}">
            @foreach ($timeline as $course)
                <article class="student-timeline__course" data-timeline-course="{{ $course['id'] }}" aria-label="{{ $course['name'] }}">
                    <div class="student-timeline__track-scroll" @scroll="tip = null" tabindex="0" aria-label="{{ $course['name'] }}">
                        <ol class="student-timeline__milestones {{ $course['complete'] ? '' : 'student-timeline__milestones--open' }}">
                            @foreach ($course['milestones'] as $event)
                                @php
                                    $tip = ['stats' => $event['stats'] ?? []];
                                    $eventId = 'timeline-event-'.$course['id'].'-'.$loop->index;
                                @endphp
                                <li data-milestone-kind="{{ $event['kind'] }}">
                                    <div class="student-timeline__milestone">
                                        <button type="button" class="student-timeline__dot" data-modal-action-icon-ignore
                                            @mouseenter="showTip(@js($tip), $el)" @mouseleave="hideTip()"
                                            @focus="showTip(@js($tip), $el)" @blur="hideTip()"
                                            @click="showTip(@js($tip), $el)"
                                            aria-label="{{ $event['title'] }}" aria-describedby="{{ $eventId }}"></button>
                                        <strong>{{ $event['title'] }}</strong>
                                        @if (filled($event['highlight'] ?? null))<span class="student-timeline__highlight">{{ $event['highlight'] }}</span>@endif
                                        @if ($event['date'])<time datetime="{{ $event['date'] }}">{!! \App\Support\DateDisplay::html($event['date']) !!}</time>@endif
                                    </div>
                                    <span class="sr-only" id="{{ $eventId }}">@foreach ($event['stats'] ?? [] as $stat) {{ $stat['label'] }}: {{ $stat['value'] }}. @endforeach</span>
                                </li>
                            @endforeach
                            @unless ($course['complete'])
                                <li class="student-timeline__open-end" aria-hidden="true"></li>
                            @endunless
                        </ol>
                    </div>
                </article>
            @endforeach
        </div>
    @else
        <div class="admin-empty-state">{{ __('student_timeline.empty') }}</div>
    @endif
    <template x-teleport="body">
        <div x-show="tip" x-cloak x-ref="tooltip" class="student-timeline__tooltip" dir="{{ $timelineDirection }}" role="tooltip"
            :style="{ left: tipLeft + 'px', bottom: tipBottom + 'px', maxHeight: tipMaxHeight + 'px' }"
            @mouseenter="clearTimeout(tipTimer)" @mouseleave="hideTip()" >
            <dl x-show="tip?.stats?.length">
                <template x-for="stat in (tip?.stats || [])" :key="stat.label">
                    <div><dt x-text="stat.label"></dt><dd x-text="stat.value"></dd></div>
                </template>
            </dl>
        </div>
    </template>
</section>
