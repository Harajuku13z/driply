<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light">
    <meta name="supported-color-schemes" content="light">
    <title>{{ __('Reset Password') }} — Driply</title>
    <!--[if mso]>
    <style type="text/css">
        body, table, td { font-family: Arial, Helvetica, sans-serif !important; }
    </style>
    <![endif]-->
</head>
{{-- Charte Driply : crème, or, brun texte (aligné DriplyTheme.swift) --}}
<body style="margin:0;padding:0;background-color:#F5F0E8;-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%;">
    <span style="display:none !important;visibility:hidden;opacity:0;color:transparent;height:0;width:0;">{{ __('You are receiving this email because we received a password reset request for your account.') }}</span>
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background-color:#F5F0E8;background:linear-gradient(180deg,#EDE8DE 0%,#F5F0E8 35%,#EDE8DE 100%);">
        <tr>
            <td align="center" style="padding:40px 16px;">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width:520px;background-color:#EDE8DE;border-radius:16px;border:1px solid #DDD5C4;overflow:hidden;">
                    <tr>
                        <td style="padding:32px 28px 8px 28px;font-family:system-ui,-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif;">
                            <p style="margin:0 0 8px 0;font-size:22px;font-weight:700;letter-spacing:-0.02em;">
                                <span style="color:#C9A96E;">Driply</span>
                            </p>
                            <h1 style="margin:0;font-size:20px;font-weight:700;line-height:1.3;color:#2C2622;">
                                {{ __('Reset Password') }}
                            </h1>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:8px 28px 24px 28px;font-family:system-ui,-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif;">
                            @if(!empty($userName))
                                <p style="margin:0 0 16px 0;font-size:15px;line-height:1.6;color:#2C2622;">
                                    {{ __('Hello :name,', ['name' => $userName]) }}
                                </p>
                            @endif
                            <p style="margin:0 0 16px 0;font-size:15px;line-height:1.6;color:#8C7B6B;">
                                {{ __('You are receiving this email because we received a password reset request for your account.') }}
                            </p>
                            <p style="margin:0 0 24px 0;font-size:14px;line-height:1.5;color:#8C7B6B;">
                                {{ __('This password reset link will expire in :count minutes.', ['count' => $expireMinutes]) }}
                            </p>
                            <table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin:0 auto;">
                                <tr>
                                    <td align="center" style="border-radius:12px;background-color:#2C2622;">
                                        <a href="{{ $resetUrl }}" target="_blank" rel="noopener" style="display:inline-block;padding:14px 28px;font-family:system-ui,-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif;font-size:15px;font-weight:600;color:#F5F0E8;text-decoration:none;border-radius:12px;">
                                            {{ __('Reset Password') }}
                                        </a>
                                    </td>
                                </tr>
                            </table>
                            <p style="margin:28px 0 0 0;font-size:13px;line-height:1.5;color:#8C7B6B;">
                                {{ __('If you did not request a password reset, no further action is required.') }}
                            </p>
                            <p style="margin:20px 0 0 0;font-size:12px;line-height:1.5;color:#8C7B6B;word-break:break-all;">
                                <span style="color:#6B5E52;">{{ __('Link not working?') }}</span><br>
                                <a href="{{ $resetUrl }}" style="color:#8C6B3D;text-decoration:underline;">{{ $resetUrl }}</a>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:16px 28px 28px 28px;border-top:1px solid #DDD5C4;">
                            <p style="margin:0;font-family:system-ui,-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif;font-size:11px;line-height:1.4;color:#8C7B6B;">
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
