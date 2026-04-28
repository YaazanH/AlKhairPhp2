@php
    $pageTitle = isset($title) && $title ? $title.' | '.__('ui.app.name') : __('ui.app.name');
    $pageDescription = $metaDescription ?? __('ui.app.workspace_tagline');
    $pageUrl = $metaUrl ?? url()->current();
    $pageImage = $metaImage ?? null;
    $faviconImage = $faviconUrl ?? $pageImage ?? app(\App\Services\WebsiteService::class)->siteSettings()['logo_url'] ?? null;

    if (is_string($pageImage) && filled($pageImage) && ! str_starts_with($pageImage, 'http://') && ! str_starts_with($pageImage, 'https://')) {
        $pageImage = url($pageImage);
    }

    if (is_string($faviconImage) && filled($faviconImage) && ! str_starts_with($faviconImage, 'http://') && ! str_starts_with($faviconImage, 'https://')) {
        $faviconImage = url($faviconImage);
    }
@endphp

<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta name="theme-color" content="{{ $themeColor ?? '#17120e' }}" />
<meta name="description" content="{{ $pageDescription }}" />

<meta property="og:type" content="{{ $metaType ?? 'website' }}" />
<meta property="og:site_name" content="{{ $metaSiteName ?? __('ui.app.name') }}" />
<meta property="og:title" content="{{ $pageTitle }}" />
<meta property="og:description" content="{{ $pageDescription }}" />
<meta property="og:url" content="{{ $pageUrl }}" />
@if ($pageImage)
<meta property="og:image" content="{{ $pageImage }}" />
<meta property="og:image:secure_url" content="{{ $pageImage }}" />
<meta property="og:image:alt" content="{{ $metaImageAlt ?? $pageTitle }}" />
@endif

<meta name="twitter:card" content="{{ $pageImage ? 'summary_large_image' : 'summary' }}" />
<meta name="twitter:title" content="{{ $pageTitle }}" />
<meta name="twitter:description" content="{{ $pageDescription }}" />
@if ($pageImage)
<meta name="twitter:image" content="{{ $pageImage }}" />
<meta name="twitter:image:alt" content="{{ $metaImageAlt ?? $pageTitle }}" />
@endif

<title>{{ $pageTitle }}</title>

@if ($faviconImage)
<link rel="icon" href="{{ $faviconImage }}" />
<link rel="shortcut icon" href="{{ $faviconImage }}" />
<link rel="apple-touch-icon" href="{{ $faviconImage }}" />
@endif

<link rel="preconnect" href="https://fonts.bunny.net">
<link href="https://fonts.bunny.net/css?family=cairo:400,500,600,700|fraunces:600,700|plus-jakarta-sans:400,500,600,700" rel="stylesheet" />

@vite(['resources/css/app.css', 'resources/js/app.js'])
@fluxAppearance
