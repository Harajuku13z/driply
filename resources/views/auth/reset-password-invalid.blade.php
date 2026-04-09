@extends('layouts.driply-verify', ['pageTitle' => 'Driply — Lien incomplet'])

@section('content')
    <div class="wordmark">Driply</div>
    <div class="badge err" aria-hidden="true">!</div>
    <h1>Lien incomplet ou invalide</h1>
    <p>
        Ouvre le lien « Réinitialiser le mot de passe » directement depuis l’e-mail reçu (il doit inclure ton adresse). Tu peux aussi refaire une demande depuis l’app : connexion → « Mot de passe oublié ».
    </p>
    <p class="hint">
        <a class="hint-link" href="{{ url('/') }}">Retour à l’accueil Driply</a>
    </p>
@endsection
