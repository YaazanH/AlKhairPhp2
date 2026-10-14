@props(['current'])
<nav class="platform-request-tabs" aria-label="{{ __('platform-operations.navigation') }}">
    @foreach([
        ['metrics', 'platform.metrics', 'view.dashboard'],
        ['storage', 'platform.storage.index', 'view.storage'],
        ['backups', 'platform.backups.index', 'view.backups'],
    ] as [$key, $route, $permission])
        @if(auth('platform')->user()->hasPlatformPermission($permission))
            <a href="{{ route($route) }}" @if($current === $key) aria-current="page" @endif>{{ __('platform-workspace.destinations.'.$key) }}</a>
        @endif
    @endforeach
</nav>
