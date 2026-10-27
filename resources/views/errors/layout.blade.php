<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') · CareVMA</title>
    <link rel="icon" type="image/png" href="{{ asset('Care_VMA-favicon.png') }}">
    <style>
        *{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;padding:32px;background:#f5f8fb;color:#12233f;font-family:Inter,Arial,sans-serif}.card{width:min(620px,100%);background:#fff;border:1px solid #dce5ef;border-radius:22px;padding:42px;box-shadow:0 22px 60px rgba(22,44,78,.1);text-align:center}.brand{max-width:190px;max-height:54px;object-fit:contain;margin:0 auto 26px}.code{display:inline-flex;align-items:center;justify-content:center;min-width:72px;height:38px;padding:0 16px;border-radius:999px;background:#e9f8fd;color:#087a9d;font-weight:800;letter-spacing:.08em}.card h1{font-size:30px;margin:18px 0 10px}.card p{margin:0 auto 26px;max-width:500px;line-height:1.65;color:#5c6b7d}.actions{display:flex;gap:12px;justify-content:center;flex-wrap:wrap}.btn{display:inline-flex;align-items:center;justify-content:center;min-height:44px;padding:0 20px;border-radius:11px;text-decoration:none;font-weight:700}.btn-primary{background:#12a8cf;color:white}.btn-secondary{background:#eef3f7;color:#243b5a}@media(max-width:560px){.card{padding:30px 22px}.card h1{font-size:25px}}
    </style>
</head>
<body>
<main class="card">
    @if(file_exists(public_path('Care_VMA.webp')))
        <img class="brand" src="{{ asset('Care_VMA.webp') }}" alt="CareVMA">
    @else
        <strong>CareVMA</strong>
    @endif
    <div><span class="code">@yield('code')</span></div>
    <h1>@yield('heading')</h1>
    <p>@yield('message')</p>
    <div class="actions">
        <a class="btn btn-primary" href="{{ url('/') }}">Go to CareVMA</a>
        <a class="btn btn-secondary" href="javascript:history.back()">Go back</a>
    </div>
</main>
</body>
</html>
