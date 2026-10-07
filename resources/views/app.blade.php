@php($publicPage = ($page['props']['consultation'] ?? false) ? 'consultation' : 'homepage')
<!DOCTYPE html>
<html lang="en" style="background:#f9fbf8;color-scheme:light">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        @if (is_file(public_path('build/'.$publicPage.'-head.html')))
            {!! file_get_contents(public_path('build/'.$publicPage.'-head.html')) !!}
        @else
            <title data-inertia>{{ $publicPage === 'consultation' ? 'Free 20-minute consultation' : 'Tailored campaigns to find your next clients' }} - findward</title>
        @endif
        <link rel="icon" href="data:,">
        @viteReactRefresh
        {{-- Explicit page CSS prevents unstyled first paint in both development and production. --}}
        @vite(['resources/css/app.css', 'resources/js/pages/welcome.css', 'resources/js/app.tsx'])
    </head>
    <body class="font-sans antialiased">
        <script data-page="app" type="application/json">{!! json_encode($page, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
        @if (is_file(public_path('build/'.$publicPage.'.html')))
            <div id="app" data-server-rendered="true">{!! file_get_contents(public_path('build/'.$publicPage.'.html')) !!}</div>
        @else
            <div id="app"></div>
        @endif
    </body>
</html>
