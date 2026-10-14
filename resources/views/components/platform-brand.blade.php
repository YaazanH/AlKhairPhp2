@props([
    'variant' => 'logo',
    'alt' => null,
])

@php
    $branding = app(\App\Support\BrandIdentity::class);
    $isIcon = $variant === 'icon';
@endphp

<img
    src="{{ $isIcon ? $branding->platformIconUrl() : $branding->platformLogoUrl() }}"
    alt="{{ $alt ?? ($isIcon ? '' : $branding->platformName()) }}"
    @if($isIcon) width="1254" height="1254" @else width="1672" height="941" @endif
    {{ $attributes }}
>
