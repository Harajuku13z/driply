# Laravel Driply sur Hostinger (éviter la 404)

## Cause habituelle

Le domaine pointe vers **`public_html`** (racine du site) alors que Laravel ne répond que depuis le dossier **`public/`**. Sans réécriture, `/api-verif` cherche un fichier inexistant → **404 Hostinger**.

## Solution 0 — Fichier `.htaccess` à la racine du projet (souvent suffisant)

Le dépôt contient un **`.htaccess` à la racine** (à côté de `app/`, `public/`, `artisan`).  
Si tu as déployé **tout le projet dans `public_html`**, ce fichier doit être bien **dans `public_html`** (pas seulement dans un sous-dossier oublié).

1. **`git pull`** (ou upload) pour récupérer ce `.htaccess` à la racine.
2. Vérifie dans le **Gestionnaire de fichiers** : `public_html/.htaccess` existe.
3. Vide le **cache Hostinger** si proposé, réessaie `https://driplyapp.fr/api-verif`.

Si ton projet est dans un sous-dossier (`public_html/driply/`), mets ce `.htaccess` dans **`public_html/driply/`** et configure la **racine du domaine** vers ce dossier (ou vers `driply/public` avec la solution 1).

## Solution 1 — Document Root (recommandé)

1. **hPanel** → **Sites web** → **Gérer** à côté de `driplyapp.fr`.
2. Cherche **Répertoire racine** / **Document Root** / **Racine du document**.
3. Passe de `public_html` à **`public_html/public`**  
   (si tout le projet Laravel est directement dans `public_html` avec les dossiers `app`, `bootstrap`, `public`, `vendor`).

Si le projet est dans un sous-dossier, par ex. `public_html/driply-api`, alors la racine doit être :  
**`public_html/driply-api/public`**.

4. Enregistre, attends 1–2 minutes, teste :  
   `https://driplyapp.fr/api-verif`

## Solution 2 — Contenu de `public` à la racine

Si tu ne peux pas changer la racine :

1. Copie **tout le contenu** du dossier Laravel **`public/`** (`index.php`, `.htaccess`, `favicon.ico`, etc.) **dans** `public_html`.
2. Édite **`public_html/index.php`** : remplace les chemins `__DIR__.'/../` par `__DIR__.'/../` vers le bon parent.  
   Exemple si l’app est **au-dessus** de `public_html` :

   ```php
   require __DIR__.'/../driply-api/vendor/autoload.php';
   $app = require_once __DIR__.'/../driply-api/bootstrap/app.php';
   ```

   Adapte **`driply-api`** au vrai nom du dossier qui contient `vendor` et `bootstrap`.

## À vérifier aussi

- **PHP** : 8.2 ou 8.3 (hPanel → PHP avancé).
- **Fichier `.env`** présent à la racine du projet (pas dans `public` seulement), avec `APP_URL=https://driplyapp.fr` si tu es en HTTPS.
- **`vendor`** : après un clone, lancer `composer install --no-dev` **sur le serveur** (SSH ou terminal Hostinger) depuis la racine du projet.

## Test minimal

- `https://driplyapp.fr/` → page Laravel (welcome) ou redirection.
- `https://driplyapp.fr/up` → réponse de santé.
- `https://driplyapp.fr/api-verif` → tableau de diagnostic.
