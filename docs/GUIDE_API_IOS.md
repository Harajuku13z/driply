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

## Mot de passe oublié — procédure (application **ou** site web)

Les deux chemins envoient le **même type d’e-mail** (lien sécurisé). La réinitialisation du mot de passe se fait **dans le navigateur** (page Driply), pas dans l’écran de login de l’app.

### Depuis l’application iOS

1. Écran **Connexion** → tente de te connecter ou utilise directement **« Mot de passe oublié ? »** (affiché après une erreur d’identifiants).
2. Saisis l’**e-mail exact** du compte → **Envoyer le lien**.
3. Ouvre l’**e-mail** sur le téléphone (ou un ordinateur), appuie sur le bouton du message.
4. Le lien ouvre **`{APP_URL}/reset-password/{token}?email=…`** : choisis le **nouveau mot de passe** deux fois, valide.
5. Retour dans l’app **Connexion** avec le nouveau mot de passe.

### Depuis le site (sans passer par l’app)

1. Ouvre **`{APP_URL}/mot-de-passe-oublie`** (ex. `https://driplyapp.fr/mot-de-passe-oublie`), aussi lié depuis la page d’accueil du projet.
2. Même suite : e-mail → lien → formulaire **`/reset-password/…`** → succès.

### Endpoints techniques

| Action | Où |
|--------|-----|
| Demander l’e-mail | **`POST /api/forgot-password`** (JSON `{ "email": "…" }`) ou **formulaire web** `POST /mot-de-passe-oublie` |
| Lien dans l’e-mail | **`GET {APP_URL}/reset-password/{token}?email=…`** (route Laravel `password.reset`) |
| Enregistrer le nouveau mot de passe | **`POST {APP_URL}/reset-password`** (formulaire navigateur) ou **`POST /api/reset-password`** (JSON : `token`, `email`, `password`, `password_confirmation`) |
| Page succès | **`GET /reset-password/success`** (bouton *Ouvrir l’application* : `DRIPLY_IOS_OPEN_APP_AFTER_PASSWORD_RESET`, défaut `driply://`) |

**Réponses API `POST /forgot-password`** : `200` + message si l’e-mail est parti ; **`422`** si l’adresse ne correspond à **aucun compte** (message du type *« Nous ne trouvons aucun compte… »* en français) ou si l’envoi est **temporise** (trop de demandes).

### Si tu ne reçois pas l’e-mail

1. **`/api-verif`** : la ligne **Envoi d’e-mails (MAIL_MAILER)** doit être **`smtp`** en production. Si tu vois **`MAIL_MAILER=log`**, **aucun mail réel n’est envoyé** (Laravel écrit seulement dans `storage/logs`) — il faut configurer SMTP (voir section Hostinger ci‑dessous).
2. Vérifie **`APP_URL`** : même domaine que l’API publique, sinon les liens dans le mail sont faux ou cassés.
3. Boîte **spam / promotions**, orthographe de l’e‑mail, et que le compte existe bien (sinon **422** côté API).
4. Sur le serveur, après une demande, consulte **`storage/logs/laravel.log`** : une ligne `driply.password_reset_request` indique le **statut** Laravel (`passwords.sent`, `passwords.user`, etc.) et le **mailer** utilisé.

---

## Outfits serveur vs fiches locales dans l’app

- **`GET /api/outfits`**, **`POST /api/outfits`**, etc. : tenues **enregistrées en base** pour l’utilisateur connecté (modèle SQL `outfits`). C’est la **source de vérité** « compte » : brouillons, titres, images, pièces rattachées via l’API.
- **Sur l’iPhone**, Core Data garde aussi des **imports Lens** (bouton *Enregistrer* après un scan) : une même fiche peut contenir **plusieurs lignes produit** (meilleures offres). Ce ne sont **pas** des `Outfit` API tant qu’ils ne sont pas créés ou liés via **`POST /api/outfits`** ou le flux *Ajouter à un outfit*.
- L’app iOS marque ces entrées avec **`localOrigin = lensImport`** et n’affiche dans la rangée **« Mes outfits » (projets)** que les tenues **`localOrigin = manualOutfit`** construites dans le simulateur avec **au moins 2 pièces**. Les résultats Lens restent dans **Ajouts récents** / **Mes inspirations**.

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
