@props(['current'])
@php($user = auth('platform')->user())
<nav class="platform-request-tabs" aria-label="{{ __('platform-requests.title') }}">
    @if($user->hasPlatformPermission('manage.support.problems'))<a href="{{ route('platform.support.index', ['type'=>'problem']) }}" @if($current === 'problem') aria-current="page" @endif>{{ __('platform-requests.problem') }}</a>@endif
    @if($user->hasPlatformPermission('manage.support.suggestions'))<a href="{{ route('platform.support.index', ['type'=>'suggestion']) }}" @if($current === 'suggestion') aria-current="page" @endif>{{ __('platform-requests.suggestion') }}</a><a href="{{ route('platform.support.groups') }}" @if($current === 'groups') aria-current="page" @endif>{{ __('platform-requests.groups') }}</a>@endif
    @if($user->hasPlatformPermission('manage.landing-page'))<a href="{{ route('platform.enquiries.index') }}" @if($current === 'enquiries') aria-current="page" @endif>{{ __('platform-requests.enquiries') }}</a>@endif
</nav>
