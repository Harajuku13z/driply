@extends('layouts.driply-verify', ['pageTitle' => 'Driply — E-mail confirmé'])

@section('content')
    <div class="wordmark">Driply</div>
    <div class="badge" aria-hidden="true">✓</div>
    <h1>E-mail confirmé</h1>
    <p>
        Merci {{ $user->name }}. Ton adresse <strong style="color: var(--text);">{{ $user->email }}</strong> est bien vérifiée.
    </p>
    <p>
        Tu peux retourner dans l’application Driply : appuie sur « J’ai cliqué sur le lien — actualiser » sur l’écran de confirmation, ou ouvre à nouveau l’app.
    </p>
    <p class="hint">
        Besoin d’aide ? Consulte la <a class="hint-link" href="{{ url('/') }}">page d’accueil</a> ou la documentation API.
    </p>
@endsection
