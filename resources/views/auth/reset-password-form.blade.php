@extends('layouts.driply-verify', ['pageTitle' => 'Driply — Nouveau mot de passe'])

@section('content')
    <div class="wordmark">Driply</div>
    <h1>Choisis un nouveau mot de passe</h1>
    <p>
        Compte associé à <strong style="color: var(--text);">{{ $email }}</strong>.
    </p>
    <form method="post" action="{{ route('password.web.submit') }}" class="reset-form">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <input type="hidden" name="email" value="{{ $email }}">

        <div class="field">
            <label for="password">Nouveau mot de passe</label>
            <input id="password" name="password" type="password" required autocomplete="new-password" minlength="8">
            @error('password')
                <div class="error-msg">{{ $message }}</div>
            @enderror
        </div>

        <div class="field">
            <label for="password_confirmation">Confirmer le mot de passe</label>
            <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password" minlength="8">
        </div>

        @error('email')
            <div class="error-msg" role="alert">{{ $message }}</div>
        @enderror
        @error('token')
            <div class="error-msg" role="alert">{{ $message }}</div>
        @enderror

        <button type="submit" class="btn-open-app">Enregistrer le mot de passe</button>
    </form>
    <p class="hint">
        <a class="hint-link" href="{{ url('/') }}">Retour à l’accueil</a>
    </p>
@endsection
