<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $headline }}</title>
</head>
<body style="margin:0;padding:24px;background:#f4f7fb;font-family:Arial,Helvetica,sans-serif;color:#1f2937;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" style="max-width:640px;background:#ffffff;border-radius:16px;padding:32px;border:1px solid #e5e7eb;">
                    <tr>
                        <td>
                            <p style="margin:0 0 12px;font-size:12px;letter-spacing:0.08em;text-transform:uppercase;color:#64748b;">
                                {{ $schoolName ?: config('app.name') }}
                            </p>
                            <h1 style="margin:0 0 16px;font-size:24px;line-height:1.3;color:#0f172a;">{{ $headline }}</h1>

                            @foreach ($lines as $line)
                                <p style="margin:0 0 10px;font-size:15px;line-height:1.6;color:#334155;">{{ $line }}</p>
                            @endforeach

                            <p style="margin:20px 0 0;font-size:14px;line-height:1.6;color:#64748b;">
                                This is an automated update from {{ $schoolName ?: config('app.name') }}.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
