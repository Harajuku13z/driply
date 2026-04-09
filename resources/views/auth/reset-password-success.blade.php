@extends('layouts.driply-verify', ['pageTitle' => 'Driply — Mot de passe mis à jour'])

@section('content')
    <div class="wordmark">Driply</div>
    <div class="badge" aria-hidden="true">✓</div>
    <h1>Mot de passe mis à jour</h1>
    <p>
        Tu peux te connecter dans l’application avec ton nouveau mot de passe.
    </p>
    <a class="btn-open-app" href="{{ config('driply.ios_open_app_after_password_reset_url') }}">Ouvrir l’application</a>
    <p class="hint">
        <a class="hint-link" href="{{ url('/') }}">Retour à l’accueil Driply</a>
    </p>
@endsection
