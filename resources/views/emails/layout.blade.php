<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('subject', 'Ginnva Shield Indonesia')</title>
</head>
<body style="margin:0;padding:0;background-color:#f2f5fa;font-family:Arial,Helvetica,sans-serif;">

<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f2f5fa;">
    <tr>
        <td align="center" style="padding:32px 16px;">

            <table width="100%" cellpadding="0" cellspacing="0" border="0"
                   style="max-width:520px;background:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 2px 16px rgba(0,0,0,0.07);">

                {{-- Header merah brand --}}
                <tr>
                    <td style="background:#ed1651;padding:28px 32px;text-align:center;">
                        <span style="color:#ffffff;font-size:22px;font-weight:800;letter-spacing:0.5px;">
                            Ginnva Shield Indonesia
                        </span>
                        <br>
                        <span style="color:rgba(255,255,255,0.75);font-size:12px;letter-spacing:1px;">
                            AUTOMOTIVE PROTECTION FILM
                        </span>
                    </td>
                </tr>

                {{-- Konten --}}
                <tr>
                    <td style="padding:32px;">
                        @yield('content')
                    </td>
                </tr>

                {{-- Footer --}}
                <tr>
                    <td style="background:#f2f5fa;padding:20px 32px;text-align:center;border-top:1px solid #e6e6e6;">
                        <p style="margin:0;color:#999999;font-size:12px;line-height:1.6;">
                            Email ini dikirim otomatis oleh sistem Ginnva Shield Indonesia.<br>
                            Jika Anda tidak merasa melakukan permintaan ini, abaikan email ini.
                        </p>
                        <p style="margin:8px 0 0;color:#cccccc;font-size:11px;">
                            &copy; {{ date('Y') }} PT Ginnva Shield Indonesia &middot; Jakarta
                        </p>
                    </td>
                </tr>

            </table>
        </td>
    </tr>
</table>

</body>
</html>