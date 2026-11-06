@props(['title' => 'KhataPOS'])

<!doctype html>
<html lang="en" data-theme="khata">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="min-h-dvh bg-base-200 text-base-content antialiased">
    <a href="#main-content" class="skip-link">Skip to content</a>
    {{ $slot }}
    @livewireScripts
</body>
</html>
