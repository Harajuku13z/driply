# Driply — Documentation API pour l’app iOS

Ce document décrit comment l’application **iOS (Swift / SwiftUI)** interagit avec l’API REST **Driply** (Laravel + Sanctum).

**Sur le site déployé** (remplace par ton domaine) :

- Page d’accueil : `/`
- Doc interactive OpenAPI (Redoc) : `/docs`
- Fichier **OpenAPI 3** importable (Postman, Stoplight, etc.) : `/openapi.yaml`
- Ce guide en Markdown : `/docs/guide-ios`

**URL de base (production)** : `https://api.driply.app/api` (adapter selon ton `APP_URL`).

### Vérifier que le serveur est prêt

Ouvre dans un navigateur : **`{APP_URL sans /api}/api-verif`** (ex. `https://driplyapp.fr/api-verif`). Même diagnostic sous **`{APP_URL}/api/verif`** si tu préfères le préfixe `/api`. La page liste PHP, base de données, cache, stockage, Sanctum, SMTP, clés optionnelles (SerpAPI, OpenAI, Fast Server). Format JSON : `.../api-verif?format=json` ou `.../api/verif?format=json`. En production, définis `API_VERIF_TOKEN` dans `.env` et appelle `...?token=TON_TOKEN` (sinon la page est publique).

Toutes les réponses JSON suivent l’enveloppe :

```json
{
  "success": true,
  "data": {},
  "message": "",
  "meta": {}
}
```

En cas d’erreur de validation (`422`) :

```json
{
  "success": false,
  "message": "...",
  "errors": { "champ": ["..."] }
}
```

---

## Authentification (Sanctum)

Après **inscription** ou **connexion**, l’API renvoie un **Bearer token** (`data.token`).

**Requêtes authentifiées** :

```http
Authorization: Bearer {token}
Accept: application/json
```

---

## Vérification de l’e-mail

À l’**inscription**, un e-mail est envoyé (SMTP Hostinger : `contact@driplyapp.fr` comme expéditeur, configuré sur le serveur via `.env`, **sans commiter le mot de passe**).

### Champs utiles dans `user`

| Champ | Description |
|--------|--------------|
| `email_verified` | `true` / `false` |
| `email_verified_at` | Date ISO8601 ou `null` |

### Lien dans l’e-mail

L’e-mail Laravel contient un **lien signé** vers :

`GET /api/email/verify/{user_id}/{hash}?expires=...&signature=...`

- **Pas besoin** d’en-tête `Authorization` : la signature URL suffit.
- Réponse succès typique : `success: true`, `data.verified: true`, `data.user` à jour.
- Si le lien est **expiré** ou **falsifié** : `403`, message du type *« Lien invalide ou expiré. »*

**Intégration iOS recommandée**

1. **Universal Links** (ou lien personnalisé) : faire pointer le domaine vers ton API ou une petite page web qui redirige vers l’app avec les paramètres complets (y compris `signature` et `expires`).
2. Ou ouvrir l’URL **dans `SFSafariViewController` / navigateur** : au retour, l’utilisateur peut se reconnecter ou appeler `GET /api/me` pour voir `email_verified`.

### Renvoyer l’e-mail de vérification

Utilisateur **connecté** (Sanctum) :

```http
POST /api/email/resend
```

ou (alias Laravel) :

```http
POST /api/email/verification-notification
```

Limite : **6 requêtes par minute** (throttle).

---

## Endpoints principaux (rappel)

| Méthode | Chemin | Auth |
|--------|--------|------|
| POST | `/register` | Non |
| POST | `/login` | Non |
| POST | `/logout` | Oui |
| GET | `/me` | Oui |
| PUT | `/me` | Oui |
| PUT | `/me/password` | Oui |
| POST | `/forgot-password` | Non |
| POST | `/reset-password` | Non |
| GET | `/email/verify/{id}/{hash}` | Non (URL signée) |
| POST | `/email/resend` | Oui |
| … | outfits, search, lens, media, scan, tags, dashboard | Oui |

La liste complète des routes est dans `routes/api.php` du dépôt.

---

## Mot de passe oublié (e-mail + page web)

1. L’app appelle **`POST /api/forgot-password`** avec `{ "email": "…" }` (réponse JSON, voir ci-dessous).
2. Laravel envoie un e-mail dont le bouton pointe vers une **route web** nommée **`password.reset`** (obligatoire pour le framework) :
   - **`GET {APP_URL}/reset-password/{token}?email=…`**
3. L’utilisateur saisit le nouveau mot de passe sur cette page (thème Driply), puis **`POST {APP_URL}/reset-password`** (formulaire, jeton CSRF).
4. À la fin, redirection vers **`GET /reset-password/success`** avec un lien **« Ouvrir l’application »** (`DRIPLY_IOS_OPEN_APP_AFTER_PASSWORD_RESET` dans `.env`, défaut `driply://`).

Pour les clients qui réinitialisent **sans navigateur** (ex. intégration future), l’API **`POST /api/reset-password`** accepte toujours le JSON : `token`, `email`, `password`, `password_confirmation`.

**Configuration** : `APP_URL` doit être l’URL publique exacte du site (comme pour la vérification e-mail), sinon le lien dans l’e-mail sera incorrect.

---

## Pagination

Les listes paginées exposent `meta.pagination` :

- `current_page`, `per_page`, `total`, `last_page`
- Paramètre `?per_page=` (max **100** par défaut côté API).

---

## Fichiers et URL signées

- Images **outfits / avatars** : disque `public`, URL via `/storage/...` après `php artisan storage:link`.
- Médias importés : `local_url` et `frame_urls` dans les réponses sont des **URL signées** (routes `web.php`), valides ~1 h.

---

## Configuration e-mail (Hostinger) — côté serveur uniquement

À renseigner dans **`.env` sur le serveur** (ne jamais versionner le mot de passe) :

| Variable | Exemple Hostinger |
|----------|-------------------|
| `MAIL_MAILER` | `smtp` |
| `MAIL_HOST` | `smtp.hostinger.com` |
| `MAIL_PORT` | `465` |
| `MAIL_ENCRYPTION` | `ssl` |
| `MAIL_USERNAME` | `contact@driplyapp.fr` |
| `MAIL_PASSWORD` | *(mot de passe boîte mail — uniquement dans `.env`)* |
| `MAIL_FROM_ADDRESS` | `contact@driplyapp.fr` |
| `MAIL_FROM_NAME` | `Driply` |

Autres réglages utiles :

- `APP_URL` : URL **exacte** publique de l’API (nécessaire pour les **liens signés** dans les e-mails).
- `APP_LOCALE=fr` : locale applicative (emails / textes Laravel selon traductions installées).
- `EMAIL_VERIFICATION_EXPIRE` : minutes de validité du lien (défaut **60**).

En production, exécute un **worker de file d’attente** si tu mets les notifications en queue :

```bash
php artisan queue:work
```

(Pour l’instant, l’envoi est généralement **synchrone** sauf configuration contraire.)

---

## Rate limiting

- Recherches (`/search/images`, `/search/images/attach`, `/search/lens`) : **30 requêtes / minute / utilisateur**.
- Vérification e-mail + renvoi : **6 / minute** (routes dédiées).

---

## Support

Dépôt : [github.com/Harajuku13z/driply](https://github.com/Harajuku13z/driply)
