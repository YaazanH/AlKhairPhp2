@props(['current'])
<nav class="platform-request-tabs" aria-label="{{ __('platform-billing.title') }}">@foreach(['subscriptions'=>'platform.billing.index','payments'=>'platform.billing.payments','vouchers'=>'platform.vouchers.index'] as $key=>$route)<a href="{{ route($route) }}" @if($current === $key) aria-current="page" @endif>{{ __('platform-billing.'.$key) }}</a>@endforeach</nav>
