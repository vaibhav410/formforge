<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex">
    <title>@yield('title', config('app.name'))</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />
    @vite(['resources/css/app.css', 'resources/js/public.js'])
</head>
<body class="font-sans antialiased bg-gradient-to-b from-indigo-50 via-gray-50 to-gray-100 min-h-screen">
    @yield('content')

    <footer class="py-6 text-center text-xs text-gray-400">
        Powered by <span class="font-semibold text-gray-500">FormForge</span>
    </footer>
</body>
</html>
