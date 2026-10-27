<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#ffffff">
    <title>CareVMA</title>
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('Care_VMA-favicon.png') }}?v=20261026-admin" />
    <link rel="shortcut icon" type="image/png" href="{{ asset('Care_VMA-favicon.png') }}?v=20261026-admin" />
    <link rel="apple-touch-icon" href="{{ asset('Care_VMA-favicon.png') }}?v=20261026-admin" />
@viteReactRefresh
    @vite(['resources/css/app.css', 'resources/js/app.jsx'])
</head>
<body>
    <div id="app"></div>
</body>
</html>
