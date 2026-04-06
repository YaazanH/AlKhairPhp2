@props([
    'name',
])

@php
    $translated = __('ui.roles.'.$name);
    $label = $translated === 'ui.roles.'.$name
        ? \Illuminate\Support\Str::of($name)->replace('_', ' ')->headline()->toString()
        : $translated;
@endphp

{{ $label }}
