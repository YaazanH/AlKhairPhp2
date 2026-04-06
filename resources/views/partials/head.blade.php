<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta name="theme-color" content="#17120e" />
<meta name="description" content="{{ $metaDescription ?? __('ui.app.workspace_tagline') }}" />

<title>{{ isset($title) && $title ? $title.' | '.__('ui.app.name') : __('ui.app.name') }}</title>

<link rel="preconnect" href="https://fonts.bunny.net">
<link href="https://fonts.bunny.net/css?family=cairo:400,500,600,700|fraunces:600,700|plus-jakarta-sans:400,500,600,700" rel="stylesheet" />

@vite(['resources/css/app.css', 'resources/js/app.js'])
@fluxAppearance
