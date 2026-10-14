@props(['current'])
<nav class="platform-request-tabs" aria-label="{{ __('platform-access.navigation') }}">
    <a href="{{ route('platform.access.index') }}" @if($current === 'users') aria-current="page" @endif>{{ __('platform-access.users') }}</a>
    @if(auth('platform')->user()->isPlatformOwner())
        <a href="{{ route('platform.access.roles.index') }}" @if($current === 'roles') aria-current="page" @endif>{{ __('platform-access.roles') }}</a>
    @endif
</nav>
