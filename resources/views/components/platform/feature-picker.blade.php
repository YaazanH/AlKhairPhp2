@props(['catalog', 'selected' => [], 'locked' => [], 'enabled' => [], 'readonly' => false, 'reference' => false, 'tenant' => false])
@php
    $presentation = app(\App\Support\PlatformFeaturePresentation::class);
    $groups = collect($catalog)->groupBy(fn($module) => $presentation->group($module['code']));
    $pickerId = 'feature-search-'.($reference ? 'reference' : ($locked ? 'tenant' : 'package'));
@endphp
<div class="platform-feature-picker" data-feature-picker data-reference="{{ $reference ? 'true' : 'false' }}" data-readonly="{{ $readonly || $reference ? 'true' : 'false' }}">
    <div class="platform-feature-toolbar">
        <label for="{{ $pickerId }}">{{ __('platform-features.search') }}<input type="search" id="{{ $pickerId }}" class="rounded-xl border p-3" placeholder="{{ __('platform-features.search_help') }}" data-feature-search></label>
        <div class="platform-feature-legend">@if(!$reference)<span>{{ __('platform-features.selected') }}</span><span>{{ __('platform-features.dependency') }}</span>@else<span>{{ __('platform-features.available') }}</span>@endif<span>{{ __('platform-features.unavailable') }}</span></div>
    </div>
    @if(!$reference)<p class="platform-feature-summary" role="status" aria-live="polite" data-feature-summary>{{ __('platform-features.selection_help') }}</p>@endif
    @foreach($groups as $group => $modules)
        <fieldset class="platform-feature-group" data-feature-group><legend>{{ __('platform-features.groups.'.$group) }}</legend><div class="platform-feature-grid">
            @foreach($modules as $module)
                @php
                    $code = $module['code'];
                    $fromPackage = in_array($code, $locked, true);
                    $checked = $fromPackage || in_array($code, $selected, true);
                    $name = $presentation->name($code);
                    $requires = array_map(fn($dependency) => $presentation->name($dependency), $module['requires']);
                    $requiresAny = array_map(fn($dependency) => $presentation->name($dependency), $module['requires_any'] ?? []);
                @endphp
                <label class="platform-feature-card platform-module-option {{ $fromPackage ? 'platform-feature-card--locked' : '' }}" data-feature-card data-code="{{ $code }}" data-search="{{ $name }} {{ $module['name'] }} {{ $code }}" data-requires='@json($module["requires"])' data-requires-any='@json($module["requires_any"] ?? [])' data-locked="{{ $fromPackage ? 'true' : 'false' }}" data-available="{{ $module['is_available'] ? 'true' : 'false' }}" @if($fromPackage) data-package-module-card="{{ $code }}" @endif>
                    <span class="flex items-start gap-3">@if(!$reference)<input type="checkbox" name="modules[]" value="{{ $code }}" @checked($checked) @disabled($fromPackage || !$module['is_available'] || $readonly) @if($fromPackage) data-package-module="{{ $code }}" @endif>@endif<span><strong>{{ $name }}</strong><small class="platform-feature-code" dir="ltr">{{ $code }}</small></span></span>
                    <span class="platform-feature-state" data-feature-state>{{ $reference ? __('platform-features.'.($module['is_available'] ? 'available' : 'unavailable')) : ($fromPackage ? __('platform.ui.modules.included') : ($checked ? __('platform-features.selected') : ($tenant ? __('platform.ui.modules.optional') : __('platform-features.optional')))) }}</span>
                    @if(!$reference && in_array($code, $enabled, true))<small>{{ __('platform-features.enabled_now') }}</small>@endif
                    <p>{{ $requires ? __('platform-features.requires', ['names' => implode(', ', $requires)]) : __('platform-features.independent') }}</p>
                    @if($requiresAny)<p>{{ __('platform-features.requires_any', ['names' => implode(', ', $requiresAny)]) }}</p>@endif
                    @if(!$module['is_available'])<p>{{ __('platform-features.unavailable') }}</p>@endif
                </label>
            @endforeach
        </div></fieldset>
    @endforeach
    <p data-feature-empty hidden>{{ __('platform-features.no_matches') }}</p>
</div>
<script>
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-feature-picker]').forEach(picker => {
        const cards = [...picker.querySelectorAll('[data-feature-card]')], search = picker.querySelector('[data-feature-search]'), summary = picker.querySelector('[data-feature-summary]');
        const names = @js(['selected' => __('platform-features.selected'), 'dependency' => __('platform-features.dependency'), 'locked' => __('platform.ui.modules.included'), 'optional' => $tenant ? __('platform.ui.modules.optional') : __('platform-features.optional'), 'unavailable' => __('platform-features.unavailable')]);
        const update = () => {
            const selected = new Set(cards.filter(card => card.querySelector('input')?.checked || card.dataset.locked === 'true').map(card => card.dataset.code));
            const effective = new Set(selected), visit = code => {
                const card = cards.find(item => item.dataset.code === code);
                JSON.parse(card?.dataset.requires || '[]').forEach(required => { if(!effective.has(required)) {effective.add(required); visit(required);} });
            };
            selected.forEach(visit);
            cards.forEach(card => {
                const state = card.dataset.available === 'false' ? 'unavailable' : card.dataset.locked === 'true' ? 'locked' : selected.has(card.dataset.code) ? 'selected' : effective.has(card.dataset.code) ? 'dependency' : 'optional';
                card.dataset.state = state;
                if(picker.dataset.reference !== 'true') card.querySelector('[data-feature-state]').textContent = names[state];
            });
            if(summary) summary.textContent = @js(__('platform-features.summary')).replace(':selected', selected.size).replace(':dependencies', effective.size-selected.size);
            // Changes invalidate visible confirmations; the backend independently verifies signatures.
        };
        picker.addEventListener('change', () => {
            update(); const form = picker.closest('form');
            form?.querySelectorAll('[name=confirm_impact], [name=confirm_extras]').forEach(input => input.checked = false);
            form?.querySelector('[data-feature-impact]')?.setAttribute('data-stale', 'true');
        });
        search.addEventListener('input', () => {
            const term = search.value.trim().toLocaleLowerCase();
            cards.forEach(card => card.hidden = !card.dataset.search.toLocaleLowerCase().includes(term));
            picker.querySelectorAll('[data-feature-group]').forEach(group => group.hidden = ![...group.querySelectorAll('[data-feature-card]')].some(card => !card.hidden));
            picker.querySelector('[data-feature-empty]').hidden = cards.some(card => !card.hidden);
        });
        search.addEventListener('keydown', event => { if(event.key === 'Enter') event.preventDefault(); });
        update();
    });
});
</script>
