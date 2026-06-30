<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
</head>
<body style="font-family: Arial, sans-serif; background: #f4f4f5; padding: 32px; margin: 0;">
    <table width="100%" cellpadding="0" cellspacing="0" style="max-width: 480px; margin: 0 auto; background: #ffffff; border-radius: 12px; overflow: hidden;">
        <tr>
            <td style="background: #18181b; padding: 24px; text-align: center;">
                <span style="color: #ffffff; font-size: 20px; font-weight: bold;">Ginnva Shield Indonesia</span>
            </td>
        </tr>
        <tr>
            <td style="padding: 32px; text-align: center;">
                <p style="color: #333; font-size: 15px; margin: 0 0 16px;">Kode verifikasi Anda:</p>
                <div style="font-size: 36px; font-weight: bold; letter-spacing: 8px; color: #18181b; margin: 16px 0;">
                    {{ $code }}
                </div>
                <p style="color: #888; font-size: 13px; margin: 16px 0 0;">
                    Kode berlaku selama 10 menit. Jangan bagikan kode ini kepada siapapun.
                </p>
            </td>
        </tr>
        <tr>
            <td style="padding: 16px; text-align: center; background: #fafafa;">
                <p style="color: #aaa; font-size: 12px; margin: 0;">
                    Jika Anda tidak meminta kode ini, abaikan email ini.
                </p>
            </td>
        </tr>
    </table>
</body>
</html>
