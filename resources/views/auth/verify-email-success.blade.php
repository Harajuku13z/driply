@extends('layouts.driply-verify', ['pageTitle' => 'Driply — E-mail confirmé'])

@section('content')
    <div class="wordmark">Driply</div>
    <div class="badge" aria-hidden="true">✓</div>
    <h1>E-mail confirmé</h1>
    <p>
        Merci {{ $user->name }}. Ton adresse <strong style="color: var(--text);">{{ $user->email }}</strong> est bien vérifiée.
    </p>
    <p>
        Ouvre l’app pour continuer — ton compte sera mis à jour automatiquement.
    </p>
    <a class="btn-open-app" href="{{ config('driply.ios_open_app_url') }}">Ouvrir l’application</a>
    <p class="hint">
        Besoin d’aide ? Consulte la <a class="hint-link" href="{{ url('/') }}">page d’accueil</a> ou la documentation API.
    </p>
@endsection
