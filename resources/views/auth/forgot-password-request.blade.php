@extends('layouts.driply-verify', ['pageTitle' => 'Driply — Mot de passe oublié'])

@section('content')
    <div class="wordmark">Driply</div>
    <h1>Réinitialiser le mot de passe</h1>
    <p>
        Indique l’e-mail de ton compte Driply. Si elle existe, tu recevras un message avec un
        <strong style="color: var(--text);">lien sécurisé</strong> (vérifie aussi les spams / courrier indésirable).
        Le lien ouvre cette page pour choisir un nouveau mot de passe, puis tu pourras te connecter dans l’app.
    </p>

    @if (session('status'))
        <p style="color: rgba(100, 220, 160, 0.95); font-size: 0.95rem; margin-bottom: 1.25rem;">
            {{ session('status') }}
        </p>
    @endif

    <form method="post" action="{{ route('password.email') }}" style="margin-top: 0.5rem;">
        @csrf
        <div class="field">
            <label for="email_web">E-mail du compte</label>
            <input id="email_web" name="email" type="email" value="{{ old('email') }}" required autocomplete="email">
            @error('email')
                <div class="error-msg" role="alert">{{ $message }}</div>
            @enderror
        </div>
        <button type="submit" class="btn-open-app">Envoyer le lien</button>
    </form>

    <p class="hint">
        Tu préfères l’appli ? Sur l’écran de connexion Driply, après une erreur de mot de passe,
        utilise <strong style="color: var(--muted);">« Mot de passe oublié ? »</strong> — même e-mail envoyé depuis les serveurs Driply.
    </p>
    <p class="hint">
        <a class="hint-link" href="{{ url('/') }}">Accueil</a>
        ·
        <a class="hint-link" href="{{ url('/api-verif') }}">Diagnostic serveur</a>
    </p>
@endsection
