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
    <script>
      /* Cache-only prepaint theme: the authenticated API reconciles the DB state on load. */
      try {
        const t=JSON.parse(localStorage.getItem('carevma:white-label:theme')||'null');
        if(t&&t.palettes){const m=t.mode==='dark'?'dark':'light';const c=t.palettes[m]||{};document.documentElement.dataset.themeMode=m;
          for(const [k,v] of Object.entries(c)){if(/^[a-z_]+$/.test(k)&&/^#[a-f0-9]{6}$/i.test(v))document.documentElement.style.setProperty('--wl-'+k,v)}
          document.documentElement.style.setProperty('--wl-radius',({compact:'8px',comfortable:'14px',spacious:'22px'})[t.radius]||'14px');
        }
      } catch(e){}
    </script>
@viteReactRefresh
    @vite(['resources/css/app.css', 'resources/js/app.jsx'])
</head>
<body>
    <div id="app"></div>
</body>
</html>
