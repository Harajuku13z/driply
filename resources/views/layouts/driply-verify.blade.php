<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark">
    <title>{{ $pageTitle ?? 'Driply' }}</title>
    <style>
        :root {
            --void: #0a0a0a;
            --card: #14141a;
            --stroke: rgba(255, 255, 255, 0.08);
            --purple: #7b61ff;
            --blue: #00c2ff;
            --text: #ffffff;
            --muted: rgba(255, 255, 255, 0.62);
            --faint: rgba(255, 255, 255, 0.38);
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: var(--void);
            color: var(--text);
            -webkit-font-smoothing: antialiased;
        }
        .bg {
            position: fixed;
            inset: 0;
            z-index: 0;
            background: var(--void);
            background-image:
                radial-gradient(ellipse 80% 50% at 50% -20%, rgba(123, 97, 255, 0.22), transparent),
                radial-gradient(ellipse 60% 40% at 100% 100%, rgba(0, 194, 255, 0.12), transparent);
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
            background: var(--card);
            border: 1px solid var(--stroke);
            border-radius: 1rem;
            padding: 2rem 1.75rem;
            box-shadow: 0 24px 48px rgba(0, 0, 0, 0.45);
        }
        .wordmark {
            font-size: 1.5rem;
            font-weight: 800;
            letter-spacing: -0.03em;
            margin: 0 0 1.5rem;
            background: linear-gradient(90deg, var(--purple) 0%, var(--blue) 100%);
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
            background: rgba(123, 97, 255, 0.15);
            border: 1px solid rgba(123, 97, 255, 0.35);
        }
        .badge.err {
            background: rgba(255, 80, 100, 0.12);
            border-color: rgba(255, 80, 100, 0.35);
        }
        .hint {
            margin-top: 1.25rem;
            padding-top: 1.25rem;
            border-top: 1px solid var(--stroke);
            font-size: 0.85rem;
            color: var(--faint);
        }
        a.hint-link {
            color: var(--blue);
            text-decoration: none;
            font-weight: 500;
        }
        a.hint-link:hover {
            text-decoration: underline;
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
