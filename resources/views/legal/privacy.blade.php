<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="index, follow">
    <title>Politique de confidentialité — Driply</title>
    <style>
        :root {
            --bg: #faf8f5;
            --card: #ffffff;
            --text: #1a1a1a;
            --muted: #5c5c5c;
            --accent: #c9a96e;
            --border: #e8e4dc;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: ui-sans-serif, system-ui, -apple-system, sans-serif;
            background: var(--bg);
            color: var(--text);
            line-height: 1.65;
            min-height: 100vh;
        }
        .wrap { max-width: 40rem; margin: 0 auto; padding: 2rem 1.25rem 4rem; }
        h1 { font-size: 1.75rem; font-weight: 700; letter-spacing: -0.02em; margin: 0 0 0.5rem; }
        .meta { color: var(--muted); font-size: 0.9rem; margin-bottom: 2rem; }
        section { margin-bottom: 1.75rem; }
        h2 { font-size: 1rem; font-weight: 600; margin: 0 0 0.5rem; color: var(--text); }
        p, ul { margin: 0 0 0.75rem; color: var(--muted); font-size: 0.95rem; }
        ul { padding-left: 1.25rem; }
        a { color: var(--accent); text-decoration: underline; text-underline-offset: 2px; }
        a:hover { opacity: 0.85; }
        .note {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 1rem 1.1rem;
            font-size: 0.9rem;
            color: var(--muted);
        }
        footer { margin-top: 3rem; padding-top: 1.5rem; border-top: 1px solid var(--border); font-size: 0.85rem; color: var(--muted); }
    </style>
</head>
<body>
    <div class="wrap">
        <h1>Politique de confidentialité</h1>
        <p class="meta">Driply — dernière mise à jour : {{ date('d/m/Y') }}</p>

        <div class="note">
            Ce document est un <strong>modèle de base</strong>. Remplacez-le par la politique validée par votre entreprise (mentions du responsable du traitement, finalités, bases légales, durées de conservation, droits RGPD, sous-traitants, transferts hors UE, etc.).
        </div>

        <section>
            <h2>1. Responsable du traitement</h2>
            <p>Les données personnelles collectées via l’application et les services Driply sont traitées par l’éditeur du service Driply. Pour toute question : contactez-nous via les canaux indiqués sur le site <a href="{{ url('/') }}">{{ parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'driply' }}</a>.</p>
        </section>

        <section>
            <h2>2. Données traitées</h2>
            <p>Selon votre utilisation du service, peuvent être traitées notamment : identifiants de compte (nom, adresse e-mail), contenus que vous importez ou créez (photos, tenues), données techniques de connexion et journaux nécessaires au bon fonctionnement et à la sécurité du service.</p>
        </section>

        <section>
            <h2>3. Finalités</h2>
            <ul>
                <li>Fourniture et amélioration des fonctionnalités Driply ;</li>
                <li>Authentification et sécurité ;</li>
                <li>Obligations légales le cas échéant.</li>
            </ul>
        </section>

        <section>
            <h2>4. Vos droits</h2>
            <p>Conformément au RGPD, vous disposez d’un droit d’accès, de rectification, d’effacement, de limitation, d’opposition et de portabilité lorsque cela s’applique. Vous pouvez introduire une réclamation auprès de la CNIL.</p>
        </section>

        <footer>
            <a href="{{ url('/') }}">← Retour à l’accueil</a>
        </footer>
    </div>
</body>
</html>
