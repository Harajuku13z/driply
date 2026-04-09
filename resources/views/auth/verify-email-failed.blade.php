@extends('layouts.driply-verify', ['pageTitle' => 'Driply — Lien invalide'])

@section('content')
    <div class="wordmark">Driply</div>
    <div class="badge err" aria-hidden="true">!</div>
    @if(($reason ?? '') === 'signature')
        <h1>Lien expiré ou invalide</h1>
        <p>
            Ce lien de vérification n’est plus valide (durée limitée) ou a été modifié. Demande un nouvel e-mail depuis l’app Driply : écran de vérification → « Renvoyer l’e-mail ».
        </p>
    @elseif(($reason ?? '') === 'invalid')
        <h1>Lien non valide</h1>
        <p>
            Ce lien ne correspond pas à ton compte. Utilise le dernier message reçu de Driply ou renvoie un e-mail de vérification depuis l’app.
        </p>
    @else
        <h1>Impossible de confirmer</h1>
        <p>
            Une erreur technique s’est produite. Réessaie plus tard ou renvoie un e-mail de vérification depuis l’application.
        </p>
    @endif
    <p class="hint">
        <a class="hint-link" href="{{ url('/') }}">Retour à l’accueil Driply</a>
    </p>
@endsection
