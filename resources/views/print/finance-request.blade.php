<x-layouts.app>
    <div class="page-stack">
        <section class="page-hero p-6 lg:p-8">
            <div class="eyebrow">{{ __('ui.nav.finance') }}</div>
            <h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">{{ __('finance.print.title') }}</h1>
            <p class="mt-4 max-w-3xl text-base leading-7 text-neutral-200">{{ __('finance.print.subtitle') }}</p>
        </section>

        <section class="surface-panel p-5 lg:p-6">
            <div class="admin-toolbar">
                <div>
                    <div class="admin-toolbar__title">{{ $request->request_no }}</div>
                    <p class="admin-toolbar__subtitle">
                        {{ ucfirst($request->type) }} |
                        {{ number_format((float) $request->accepted_amount, 2) }}
                        {{ $request->acceptedCurrency?->code }}
                    </p>
                </div>
            </div>

            @if ($templates->isEmpty())
                <div class="mt-5 rounded-2xl border border-amber-400/20 bg-amber-500/10 px-4 py-3 text-sm text-amber-100">
                    {{ __('finance.empty.no_templates') }}
                </div>
            @else
                <form method="POST" action="{{ route('print-templates.print.preview') }}" target="_blank" class="mt-5 grid gap-4 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-end">
                    @csrf

                    <input type="hidden" name="sources[finance_request][single]" value="{{ $request->id }}">
                    <input type="hidden" name="copy_count" value="1">

                    @foreach (['page_width_mm', 'page_height_mm', 'margin_top_mm', 'margin_right_mm', 'margin_bottom_mm', 'margin_left_mm', 'gap_x_mm', 'gap_y_mm'] as $field)
                        <input type="hidden" name="{{ $field }}" value="{{ $defaults[$field] }}">
                    @endforeach

                    <div>
                        <label class="mb-1 block text-sm font-medium">{{ __('finance.print.template') }}</label>
                        <select name="template_id" class="w-full rounded-xl px-4 py-3 text-sm">
                            @foreach ($templates as $template)
                                <option value="{{ $template->id }}" @selected($defaultTemplate?->id === $template->id)>{{ $template->name }} | {{ number_format($template->width_mm, 2) }} x {{ number_format($template->height_mm, 2) }} mm</option>
                            @endforeach
                        </select>
                    </div>

                    <button type="submit" class="pill-link pill-link--accent">{{ __('finance.actions.preview_print') }}</button>
                </form>
            @endif
        </section>
    </div>
</x-layouts.app>
