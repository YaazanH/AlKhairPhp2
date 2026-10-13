@if(request()->routeIs('login'))
    <x-layouts.auth.login>{{ $slot }}</x-layouts.auth.login>
@else
    <x-layouts.auth.simple>{{ $slot }}</x-layouts.auth.simple>
@endif
