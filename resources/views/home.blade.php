<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Driply — API pour organiser tes tenues, médias et inspiration mode. Documentation OpenAPI et ressources développeurs.">
    <title>Driply — API &amp; développeurs</title>
    <style>
        :root {
            --bg: #0f172a;
            --card: #1e293b;
            --border: #334155;
            --text: #e2e8f0;
            --muted: #94a3b8;
            --accent: #38bdf8;
            --accent-dim: rgba(56, 189, 248, 0.15);
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: ui-sans-serif, system-ui, -apple-system, sans-serif;
            background: var(--bg);
            color: var(--text);
            line-height: 1.6;
            min-height: 100vh;
        }
        .wrap { max-width: 52rem; margin: 0 auto; padding: 2rem 1.5rem 4rem; }
        header { margin-bottom: 2.5rem; }
        .logo {
            font-size: 1.75rem;
            font-weight: 700;
            letter-spacing: -0.02em;
            margin: 0 0 0.5rem;
        }
        .tagline { color: var(--muted); font-size: 1.05rem; margin: 0; max-width: 36rem; }
        section { margin-bottom: 2rem; }
        h2 {
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: var(--muted);
            margin: 0 0 1rem;
            font-weight: 600;
        }
        .card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 0.75rem;
            padding: 1.25rem 1.5rem;
            margin-bottom: 1rem;
        }
        .card p { margin: 0 0 1rem; color: var(--muted); font-size: 0.95rem; }
        .card p:last-child { margin-bottom: 0; }
        ul.features { margin: 0; padding-left: 1.25rem; color: var(--muted); font-size: 0.95rem; }
        ul.features li { margin-bottom: 0.35rem; }
        .links { display: grid; gap: 0.75rem; }
        @media (min-width: 40rem) {
            .links { grid-template-columns: 1fr 1fr; }
        }
        a.btn {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
            padding: 1rem 1.25rem;
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 0.75rem;
            color: var(--text);
            text-decoration: none;
            transition: border-color 0.15s, background 0.15s;
        }
        a.btn:hover {
            border-color: var(--accent);
            background: var(--accent-dim);
        }
        a.btn strong { font-size: 1rem; color: var(--accent); }
        a.btn span { font-size: 0.85rem; color: var(--muted); }
        code.url {
            display: block;
            font-size: 0.8rem;
            margin-top: 0.75rem;
            padding: 0.5rem 0.75rem;
            background: #0c1222;
            border-radius: 0.375rem;
            color: #a5b4fc;
            word-break: break-all;
        }
        footer {
            margin-top: 3rem;
            padding-top: 1.5rem;
            border-top: 1px solid var(--border);
            font-size: 0.8rem;
            color: var(--muted);
        }
        footer a { color: var(--accent); }
    </style>
</head>
<body>
    <div class="wrap">
        <header>
            <p class="logo">Driply</p>
            <p class="tagline">
                API backend pour l’app : tenues, médias, recherche visuelle (Lens), tags, tableau de bord.
                Authentification Sanctum, prête pour iOS et les outils clients standard.
            </p>
        </header>

        <section>
            <h2>L’application</h2>
            <div class="card">
                <p>
                    Driply t’aide à centraliser tes <strong style="color: var(--text);">outfits</strong>, importer des visuels
                    (y compris depuis les réseaux via Fast Server lorsqu’il est configuré), détecter des doublons,
                    enrichir avec la recherche image / Lens et estimer des références prix via OpenAI lorsque les clés sont renseignées.
                </p>
                <ul class="features">
                    <li>Auth e-mail + vérification, jetons Bearer</li>
                    <li>REST JSON sous le préfixe <code style="background:#334155;padding:0.1rem 0.35rem;border-radius:0.25rem;color:var(--text);">/api</code></li>
                    <li>Santé infra : <a href="{{ url('/api-verif') }}" style="color:var(--accent);">{{ url('/api-verif') }}</a></li>
                </ul>
            </div>
        </section>

        <section>
            <h2>Documentation</h2>
            <div class="links">
                <a class="btn" href="{{ url('/docs') }}">
                    <strong>Documentation interactive (Redoc)</strong>
                    <span>Parcours des endpoints à partir de la spécification OpenAPI 3 — même rendu que sur les portails docs.</span>
                </a>
                <a class="btn" href="{{ url('/openapi.yaml') }}">
                    <strong>Fichier OpenAPI brut (YAML)</strong>
                    <span>À coller dans Postman « Import », Stoplight, Insomnia, ReadMe, ou un générateur de SDK.</span>
                    <code class="url">{{ url('/openapi.yaml') }}</code>
                </a>
                <a class="btn" href="{{ url('/docs/guide-ios') }}">
                    <strong>Guide intégration iOS (Markdown)</strong>
                    <span>Flux auth, e-mail, pagination, médias signés — lisible dans le navigateur ou dans l’IDE.</span>
                </a>
                <a class="btn" href="{{ url('/up') }}">
                    <strong>Santé Laravel</strong>
                    <span>Endpoint léger <code style="background:#334155;padding:0.1rem 0.35rem;border-radius:0.25rem;">GET /up</code> pour monitoring.</span>
                </a>
            </div>
        </section>

        <footer>
            {{ config('app.name') }} — Laravel {{ app()->version() }}.
            API : <code style="background:#334155;padding:0.15rem 0.4rem;border-radius:0.25rem;">{{ rtrim(config('app.url'), '/') }}/api</code>
        </footer>
    </div>
</body>
</html>
