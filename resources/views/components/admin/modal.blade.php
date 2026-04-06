@props([
    'show' => false,
    'title' => null,
    'description' => null,
    'closeMethod' => null,
    'maxWidth' => '4xl',
])

@php
    $widthClass = match ($maxWidth) {
        '2xl' => 'admin-modal__dialog--2xl',
        '3xl' => 'admin-modal__dialog--3xl',
        '5xl' => 'admin-modal__dialog--5xl',
        default => 'admin-modal__dialog--4xl',
    };
@endphp

@if ($show)
    <div class="admin-modal">
        <div class="admin-modal__backdrop"></div>
        <div class="admin-modal__viewport">
            <div class="admin-modal__dialog {{ $widthClass }}">
                <div class="admin-modal__header">
                    <div>
                        @if ($title)
                            <h2 class="admin-modal__title">{{ $title }}</h2>
                        @endif

                        @if ($description)
                            <p class="admin-modal__description">{{ $description }}</p>
                        @endif
                    </div>

                    @if ($closeMethod)
                        <button type="button" wire:click="{{ $closeMethod }}" class="admin-modal__close" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    @endif
                </div>

                <div class="admin-modal__body">
                    {{ $slot }}
                </div>
            </div>
        </div>
    </div>
@endif
