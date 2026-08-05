<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Buka Ginnva App</title>
    <meta http-equiv="refresh" content="0; url={{ $playStoreUrl }}">
    <style>
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #0b0b16;
            color: #ffffff;
            font-family: -apple-system, Roboto, Arial, sans-serif;
            text-align: center;
        }
        .card { padding: 32px; max-width: 360px; }
        .spinner {
            width: 32px; height: 32px; margin: 0 auto 20px;
            border: 3px solid rgba(255,255,255,0.2);
            border-top-color: #ed1651;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
        p { font-size: 14px; color: rgba(255,255,255,0.7); line-height: 1.5; }
        a { color: #ed1651; font-weight: 600; text-decoration: none; }
    </style>
    <script>
        window.location.replace(@json($playStoreUrl));
    </script>
</head>
<body>
    <div class="card">
        <div class="spinner"></div>
        <p>Mengarahkan ke Play Store...</p>
        <p><a href="{{ $playStoreUrl }}">Klik di sini kalau tidak otomatis terarah</a></p>
    </div>
</body>
</html>
