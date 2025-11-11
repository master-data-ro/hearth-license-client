<!doctype html>
<html>
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title>Site blocked — License required</title>
    <style>
        body { font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial; background:#f7fafc; color:#2d3748; }
        .wrap { max-width:720px; margin:6rem auto; background:white; padding:2rem; border-radius:8px; box-shadow:0 6px 18px rgba(15,23,42,0.06); }
        h1 { margin:0 0 .5rem; font-size:1.4rem }
        p { margin:.5rem 0 }
        .hint { margin-top:1rem; color:#6b7280; font-size:.9rem }
    </style>
</head>
<body>
    <div class="wrap">
        <h1>Acces restricționat</h1>
        <p>{{ $message ?? 'Este necesară o licență valabilă pentru a utiliza această aplicație.' }}</p>
        <p>Pentru a instala o licență, accesați <a href="/licenta">/licenta</a>.</p>
        <div class="hint">Dacă credeți că apare această pagină din greșeală, contactați suportul tehnic și furnizați informațiile despre licență.</div>
    </div>
</body>
</html>
