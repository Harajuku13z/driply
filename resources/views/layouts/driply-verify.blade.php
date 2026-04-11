<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>{{ $pageTitle ?? 'Driply' }}</title>
    <style>
        /* Aligné sur DriplyTheme.swift (Crème & Or) */
        :root {
            --bg: #F5F0E8;
            --bg-card: #EDE8DE;
            --stroke: #DDD5C4;
            --gold: #C9A96E;
            --gold-deep: #8C6B3D;
            --text: #2C2622;
            --muted: #8C7B6B;
            --faint: #6B5E52;
            --btn: #2C2622;
            --btn-text: #F5F0E8;
            --err-bg: rgba(200, 90, 90, 0.12);
            --err-border: rgba(180, 70, 70, 0.35);
            --err-text: #a84848;
            --success: #2d6b4a;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: var(--bg);
            color: var(--text);
            -webkit-font-smoothing: antialiased;
        }
        .bg {
            position: fixed;
            inset: 0;
            z-index: 0;
            background: var(--bg);
            background-image:
                radial-gradient(ellipse 90% 55% at 50% -15%, rgba(201, 169, 110, 0.18), transparent 55%),
                radial-gradient(ellipse 70% 45% at 100% 100%, rgba(221, 213, 196, 0.6), transparent 50%);
            pointer-events: none;
        }
        .wrap {
            position: relative;
            z-index: 1;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 2rem 1.25rem 3rem;
        }
        .card {
            width: 100%;
            max-width: 420px;
            background: var(--bg-card);
            border: 1px solid var(--stroke);
            border-radius: 1rem;
            padding: 2rem 1.75rem;
            box-shadow: 0 16px 40px rgba(44, 38, 34, 0.08);
        }
        .wordmark {
            font-size: 1.5rem;
            font-weight: 800;
            letter-spacing: -0.03em;
            margin: 0 0 1.5rem;
            background: linear-gradient(90deg, var(--gold) 0%, var(--gold-deep) 100%);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }
        h1 {
            font-size: 1.35rem;
            font-weight: 700;
            line-height: 1.25;
            margin: 0 0 0.75rem;
            letter-spacing: -0.02em;
            color: var(--text);
        }
        p {
            margin: 0 0 1rem;
            font-size: 0.95rem;
            line-height: 1.55;
            color: var(--muted);
        }
        p:last-child { margin-bottom: 0; }
        .badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 3.5rem;
            height: 3.5rem;
            border-radius: 50%;
            margin-bottom: 1.25rem;
            font-size: 1.75rem;
            color: var(--gold-deep);
            background: rgba(201, 169, 110, 0.18);
            border: 1px solid rgba(201, 169, 110, 0.45);
        }
        .badge.err {
            color: var(--err-text);
            background: var(--err-bg);
            border-color: var(--err-border);
        }
        .hint {
            margin-top: 1.25rem;
            padding-top: 1.25rem;
            border-top: 1px solid var(--stroke);
            font-size: 0.85rem;
            color: var(--faint);
        }
        a.hint-link {
            color: var(--gold-deep);
            text-decoration: none;
            font-weight: 500;
        }
        a.hint-link:hover {
            text-decoration: underline;
        }
        .btn-open-app {
            display: block;
            width: 100%;
            margin: 1.25rem 0 0;
            padding: 0.9rem 1.25rem;
            text-align: center;
            font-size: 1rem;
            font-weight: 600;
            color: var(--btn-text) !important;
            text-decoration: none !important;
            border-radius: 0.75rem;
            background-color: var(--btn);
            box-shadow: 0 8px 20px rgba(44, 38, 34, 0.18);
            border: none;
            cursor: pointer;
            -webkit-tap-highlight-color: transparent;
        }
        .btn-open-app:hover {
            filter: brightness(1.05);
        }
        .btn-open-app:active {
            filter: brightness(0.96);
        }
        label {
            display: block;
            font-size: 0.85rem;
            color: var(--muted);
            margin-bottom: 0.35rem;
            font-weight: 500;
        }
        .field { margin-bottom: 1rem; }
        input[type="email"], input[type="password"], input[type="text"] {
            width: 100%;
            padding: 0.75rem 0.9rem;
            font-size: 1rem;
            border-radius: 0.6rem;
            border: 1px solid var(--stroke);
            background: var(--bg);
            color: var(--text);
        }
        input:focus {
            outline: none;
            border-color: rgba(201, 169, 110, 0.75);
            box-shadow: 0 0 0 3px rgba(201, 169, 110, 0.2);
        }
        .error-msg {
            color: var(--err-text);
            font-size: 0.85rem;
            margin-top: 0.35rem;
        }
        button.btn-open-app {
            font-family: inherit;
        }
    </style>
</head>
<body>
<div class="bg" aria-hidden="true"></div>
<div class="wrap">
    <div class="card">
        @yield('content')
    </div>
</div>
</body>
</html>
