@props(['variant' => 'outline'])

@php
    if ($variant !== 'outline') {
        throw new \Exception('The supplied courses icon supports the outline variant only.');
    }

    $classes = Flux::classes('inline-block shrink-0 bg-current')->add('[:where(&)]:size-6');
    $iconUrl = asset('images/sidebar/courses.png');
@endphp

<span
    {{ $attributes->class($classes) }}
    data-flux-icon
    data-slot="icon"
    data-courses-icon="supplied-artwork"
    aria-hidden="true"
    style="-webkit-mask: url('{{ $iconUrl }}') center / contain no-repeat; mask: url('{{ $iconUrl }}') center / contain no-repeat;"
></span>
