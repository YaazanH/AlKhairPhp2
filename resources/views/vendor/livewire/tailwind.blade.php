@php
if (! isset($scrollTo)) {
    $scrollTo = '.surface-table';
}

$scrollIntoViewJsSnippet = ($scrollTo !== false)
    ? <<<JS
       (\$el.closest('{$scrollTo}') || \$el.closest('section')?.querySelector('{$scrollTo}') || \$el.closest('[data-pagination-container]') || \$el.closest('section') || document.querySelector('main'))?.scrollIntoView({ behavior: 'smooth', block: 'start' })
    JS
    : '';

$isRtl = config('app.supported_locales.'.app()->getLocale().'.direction', 'ltr') === 'rtl';
@endphp


<div class="app-pagination-container">
    @if ($paginator->hasPages())
        <nav role="navigation" aria-label="Pagination Navigation" class="app-pagination">
            <div class="app-pagination__desktop">
                @foreach (['narrow', 'wide'] as $variant)
                    <div class="app-pagination__nav app-pagination__nav--{{ $variant }}">
                        <button type="button" wire:click="previousPage('{{ $paginator->getPageName() }}')" x-on:click="{{ $scrollIntoViewJsSnippet }}" wire:loading.attr="disabled" @disabled($paginator->onFirstPage()) class="app-pagination__icon {{ $paginator->onFirstPage() ? 'app-pagination__icon--disabled' : '' }}" title="{{ __('pagination.previous') }}" aria-label="{{ __('pagination.previous') }}">
                            <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $isRtl ? 'm9 6 6 6-6 6' : 'm15 6-6 6 6 6' }}" /></svg>
                        </button>
                        @foreach (\App\Support\PaginationWindow::pages($paginator->currentPage(), $paginator->lastPage(), $variant === 'narrow') as $page)
                            @if (is_string($page))
                                <span class="app-pagination__page app-pagination__page--dots" aria-hidden="true">{{ $page }}</span>
                            @elseif ($page === $paginator->currentPage())
                                <span wire:key="paginator-{{ $paginator->getPageName() }}-{{ $variant }}-page{{ $page }}" class="app-pagination__page app-pagination__page--active" aria-current="page">{{ $page }}</span>
                            @else
                                <button
                                    type="button"
                                    wire:key="paginator-{{ $paginator->getPageName() }}-{{ $variant }}-page{{ $page }}"
                                    wire:click="gotoPage({{ $page }}, '{{ $paginator->getPageName() }}')"
                                    x-on:click="{{ $scrollIntoViewJsSnippet }}"
                                    wire:loading.attr="disabled"
                                    class="app-pagination__page"
                                    aria-label="{{ __('pagination.go_to_page', ['page' => $page]) }}"
                                >{{ $page }}</button>
                            @endif
                        @endforeach
                        <button type="button" wire:click="nextPage('{{ $paginator->getPageName() }}')" x-on:click="{{ $scrollIntoViewJsSnippet }}" wire:loading.attr="disabled" @disabled(! $paginator->hasMorePages()) class="app-pagination__icon {{ ! $paginator->hasMorePages() ? 'app-pagination__icon--disabled' : '' }}" title="{{ __('pagination.next') }}" aria-label="{{ __('pagination.next') }}">
                            <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $isRtl ? 'm15 6-6 6 6 6' : 'm9 6 6 6-6 6' }}" /></svg>
                        </button>
                    </div>
                @endforeach

                <p class="app-pagination__summary">
                    <span class="app-pagination__summary-line app-pagination__summary-line--range">
                        <span>{{ __('pagination.showing') }}</span>
                        <span class="app-pagination__summary-strong">{{ $paginator->firstItem() }}</span>
                        <span>{{ __('pagination.to') }}</span>
                        <span class="app-pagination__summary-strong">{{ $paginator->lastItem() }}</span>
                    </span>
                    <span class="app-pagination__summary-line app-pagination__summary-line--total">
                        <span>{{ __('pagination.of') }}</span>
                        <span class="app-pagination__summary-strong">{{ $paginator->total() }}</span>
                        <span>{{ __('pagination.results') }}</span>
                    </span>
                </p>
            </div>
        </nav>
    @endif
</div>
