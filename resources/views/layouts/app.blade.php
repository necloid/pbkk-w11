<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Sentinel Hub: API chat LLM terproteksi token Sanctum untuk menganalisis kerentanan kode.">
    <title>{{ $title ?? 'Sentinel Hub' }}</title>
    <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3E%3Crect width='16' height='16' fill='%23000'/%3E%3Cpath d='M8 2 13 4v4c0 3-2.2 5-5 6-2.8-1-5-3-5-6V4z' fill='none' stroke='%23ededea'/%3E%3C/svg%3E">
    <link rel="preload" href="{{ asset('fonts/ibm-plex-serif-latin-300-normal.woff2') }}" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="{{ asset('css/sentinel.css') }}">
</head>
<body>
    <header class="nav">
        <div class="wrap nav__inner">
            <a class="brand" href="{{ route('console') }}">
                <svg class="brand__mark" viewBox="0 0 16 16" aria-hidden="true"><path d="M8 1.5 13.5 3.6v4.2c0 3.2-2.3 5.4-5.5 6.7-3.2-1.3-5.5-3.5-5.5-6.7V3.6z" fill="none" stroke="currentColor"/><path d="M5.6 8.1 7.3 9.8l3.2-3.4" fill="none" stroke="currentColor"/></svg>
                Sentinel Hub
            </a>
            <nav class="nav__links" aria-label="Navigasi">
                <a href="https://github.com/necloid/pbkk-w11#api-contract" target="_blank" rel="noopener">API contract</a>
            </nav>
        </div>
    </header>

    @yield('content')

    @stack('scripts')
</body>
</html>
