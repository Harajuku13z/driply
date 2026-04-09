<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Driply — Vérification API</title>
    <style>
        :root { --ok:#166534; --warn:#a16207; --fail:#b91c1c; --bg:#0f172a; --card:#1e293b; --muted:#94a3b8; }
        * { box-sizing: border-box; }
        body { font-family: ui-sans-serif, system-ui, sans-serif; background: var(--bg); color: #e2e8f0; margin: 0; padding: 1.5rem; line-height: 1.5; }
        h1 { font-size: 1.5rem; margin: 0 0 0.25rem; }
        .sub { color: var(--muted); font-size: 0.9rem; margin-bottom: 1.5rem; }
        .meta { display: flex; flex-wrap: wrap; gap: 1rem; margin-bottom: 1.5rem; font-size: 0.85rem; color: var(--muted); }
        .badges { display: flex; gap: 0.5rem; flex-wrap: wrap; margin-bottom: 1.5rem; }
        .badge { padding: 0.25rem 0.6rem; border-radius: 0.375rem; font-size: 0.8rem; font-weight: 600; }
        .badge-ok { background: rgba(22, 101, 52, 0.35); color: #86efac; }
        .badge-warn { background: rgba(161, 98, 7, 0.35); color: #fde047; }
        .badge-fail { background: rgba(185, 28, 28, 0.35); color: #fca5a5; }
        table { width: 100%; border-collapse: collapse; background: var(--card); border-radius: 0.5rem; overflow: hidden; }
        th, td { padding: 0.75rem 1rem; text-align: left; border-bottom: 1px solid #334155; }
        th { background: #334155; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--muted); }
        tr:last-child td { border-bottom: none; }
        .status { font-weight: 700; font-size: 0.8rem; }
        .status-ok { color: #86efac; }
        .status-warn { color: #fde047; }
        .status-fail { color: #fca5a5; }
        .detail { color: var(--muted); font-size: 0.85rem; max-width: 36rem; word-break: break-word; }
        .foot { margin-top: 1.5rem; font-size: 0.8rem; color: var(--muted); }
        code { background: #334155; padding: 0.1rem 0.35rem; border-radius: 0.25rem; font-size: 0.85em; }
        a { color: #7dd3fc; }
    </style>
</head>
<body>
    <h1>Driply — diagnostic API</h1>
    <p class="sub">Contrôles automatiques des fonctionnalités et de l’infrastructure (sans appeler les API externes payantes).</p>

    <div class="meta">
        <span>Laravel <strong>{{ $appVersion }}</strong></span>
        <span>PHP <strong>{{ $phpVersion }}</strong></span>
        <span><code>APP_URL</code> {{ config('app.url') }}</span>
        @if($protected)
            <span>Page protégée par token</span>
        @else
            <span>Pour sécuriser : <code>API_VERIF_TOKEN</code> dans <code>.env</code> puis <code>?token=...</code></span>
        @endif
    </div>

    <div class="badges">
        <span class="badge badge-ok">OK {{ $summary['ok'] }}</span>
        <span class="badge badge-warn">Attention {{ $summary['warn'] }}</span>
        <span class="badge badge-fail">Échec {{ $summary['fail'] }}</span>
    </div>

    <table>
        <thead>
            <tr>
                <th>État</th>
                <th>Fonctionnalité</th>
                <th>Détail</th>
            </tr>
        </thead>
        <tbody>
            @foreach($checks as $c)
                <tr>
                    <td class="status status-{{ $c['status'] }}">
                        @if($c['status'] === 'ok') OK @endif
                        @if($c['status'] === 'warn') ATTENTION @endif
                        @if($c['status'] === 'fail') ÉCHEC @endif
                    </td>
                    <td>{{ $c['label'] }}</td>
                    <td class="detail">{{ $c['detail'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <p class="foot">
        JSON : <a href="{{ url('/api-verif?format=json' . ($protected ? '&token=' . urlencode(request('token', '')) : '')) }}">même URL avec <code>?format=json</code></a>
        · Santé Laravel : <a href="{{ url('/up') }}"><code>/up</code></a>
        · Endpoints REST : préfixe <code>/api</code> (voir doc)
    </p>
</body>
</html>
