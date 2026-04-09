<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="dark">
    <meta name="supported-color-schemes" content="dark">
    <title>{{ __('Verify Email Address') }}</title>
    <!--[if mso]>
    <style type="text/css">
        body, table, td { font-family: Arial, Helvetica, sans-serif !important; }
    </style>
    <![endif]-->
</head>
<body style="margin:0;padding:0;background-color:#0a0a0a;-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%;">
    <span style="display:none !important;visibility:hidden;opacity:0;color:transparent;height:0;width:0;">{{ __('Please click the button below to verify your email address.') }}</span>
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background-color:#0a0a0a;background:linear-gradient(180deg, rgba(123,97,255,0.12) 0%, #0a0a0a 45%, rgba(0,194,255,0.08) 100%);">
        <tr>
            <td align="center" style="padding:40px 16px;">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width:520px;background-color:#14141a;border-radius:16px;border:1px solid rgba(255,255,255,0.08);overflow:hidden;">
                    <tr>
                        <td style="padding:32px 28px 8px 28px;font-family:system-ui,-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif;">
                            <p style="margin:0 0 8px 0;font-size:22px;font-weight:700;letter-spacing:-0.02em;">
                                <span style="color:#7b61ff;">Driply</span>
                            </p>
                            <h1 style="margin:0;font-size:20px;font-weight:700;line-height:1.3;color:#ffffff;">
                                {{ __('Verify Email Address') }}
                            </h1>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:8px 28px 24px 28px;font-family:system-ui,-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif;">
                            @if(!empty($userName))
                                <p style="margin:0 0 16px 0;font-size:15px;line-height:1.6;color:rgba(255,255,255,0.82);">
                                    {{ __('Hello :name,', ['name' => $userName]) }}
                                </p>
                            @endif
                            <p style="margin:0 0 24px 0;font-size:15px;line-height:1.6;color:rgba(255,255,255,0.62);">
                                {{ __('Please click the button below to verify your email address.') }}
                            </p>
                            <table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin:0 auto;">
                                <tr>
                                    <td align="center" style="border-radius:12px;background-color:#7b61ff;background-image:linear-gradient(90deg,#7b61ff 0%,#00c2ff 100%);">
                                        <a href="{{ $verificationUrl }}" target="_blank" rel="noopener" style="display:inline-block;padding:14px 28px;font-family:system-ui,-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;border-radius:12px;">
                                            {{ __('Verify Email Address') }}
                                        </a>
                                    </td>
                                </tr>
                            </table>
                            <p style="margin:28px 0 0 0;font-size:13px;line-height:1.5;color:rgba(255,255,255,0.42);">
                                {{ __('If you did not create an account, no further action is required.') }}
                            </p>
                            <p style="margin:20px 0 0 0;font-size:12px;line-height:1.5;color:rgba(255,255,255,0.35);word-break:break-all;">
                                <span style="color:rgba(255,255,255,0.45);">{{ __('Link not working?') }}</span><br>
                                <a href="{{ $verificationUrl }}" style="color:#00c2ff;text-decoration:underline;">{{ $verificationUrl }}</a>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:16px 28px 28px 28px;border-top:1px solid rgba(255,255,255,0.06);">
                            <p style="margin:0;font-family:system-ui,-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif;font-size:11px;line-height:1.4;color:rgba(255,255,255,0.35);">
                                © {{ date('Y') }} Driply
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
