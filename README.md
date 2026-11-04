# DOBI

DOBI est une plateforme pour créer des questionnaires, les publier, collecter les réponses et les analyser. Le projet est né d'un besoin simple : pouvoir mener une enquête sur le terrain, même avec une connexion instable, sans dépendre d'un outil étranger payant.

Le projet est en deux parties :

- **API** : Laravel 13 (PHP 8.4), base MariaDB/MySQL, authentification par jeton (Sanctum). Dépôt actuel.
- **Front** : React 18 + Vite + Tailwind, hébergé sur Vercel. Il parle à l'API en JSON.

Production : front sur https://dobi-sand.vercel.app, API sur https://dobi.alwaysdata.net.

## Ce que fait DOBI

- Création de questionnaires par étapes, avec 15 types de questions, des règles d'affichage (une étape peut dépendre d'une réponse) et l'option « Autre ».
- Publication par lien public ou QR code, avec réponse unique par personne si on le souhaite.
- Collecte sur le terrain par des enquêteurs, avec file d'attente hors ligne (les réponses sont envoyées au retour du réseau).
- Résultats, tableau de bord et suivi de la participation ; export CSV et Excel avec une colonne « Enquêteur ».
- Espaces de travail (organisations) avec cinq rôles : propriétaire, administrateur, éditeur, enquêteur, analyste. Chaque rôle n'accède qu'à ce qui le concerne.
- Invitations de membres par e-mail, attribution des enquêteurs par étude, transfert de propriété d'une organisation.
- Modèles de questionnaires réutilisables (trois modèles intégrés : satisfaction, ménages, événement).
- Compte : inscription avec confirmation de l'e-mail, mot de passe oublié, changement d'adresse, déconnexion des autres appareils, suppression du compte.

## Lancer le projet en local

### API

Prérequis : PHP 8.4, Composer, MySQL ou MariaDB.

```bash
composer install
cp .env.example .env
php artisan key:generate
# renseigner la base de données dans .env, puis :
php artisan migrate
php artisan serve --port=8001
```

Variables importantes du `.env` :

| Variable | Rôle |
|---|---|
| `APP_URL` | URL de l'API (sert aux liens signés des e-mails) |
| `FRONTEND_URL` | URL du front, sans `/` final (liens des e-mails et CORS) |
| `MAIL_MAILER` et `MAIL_*` | Envoi des e-mails (`log` en local, `smtp` en production) |
| `TRUSTED_PROXIES` | À mettre à `*` derrière un proxy en production |

Ne jamais ajouter le `.env` au dépôt.

### Front

Prérequis : Node 22.

```bash
npm install
# créer un fichier .env avec :
# VITE_API_URL=http://127.0.0.1:8001/api/v1
npm run dev
```

## Tests

```bash
php artisan config:clear
php artisan test      # API : tests sur SQLite en mémoire

npm test              # front
```

Les tests de l'API refusent de tourner sur une autre base que SQLite, pour ne pas toucher aux vraies données. Une intégration continue GitHub lance les tests à chaque push.

## Déploiement

- **Front** : Vercel redéploie automatiquement à chaque push sur `main`.
- **API** (Alwaysdata) : en SSH dans le dossier du projet.

```bash
git pull origin main
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Contrôle rapide : `curl -i https://dobi.alwaysdata.net/api/v1/ping`. Avant une mise à jour qui change la base, faire une sauvegarde.

## Organisation du code (API)

```
app/Http/Controllers/Api/V1   contrôleurs de l'API
app/Policies                  droits par rôle
app/Services                  logique des questionnaires (versions, import/export)
app/Support                   modèles de questionnaires intégrés
database/migrations           structure de la base
tests/Feature                 tests de l'API
```

Un questionnaire est versionné : chaque publication fige une version, et les réponses restent rattachées à la version à laquelle elles ont répondu.

## Limites connues

- Les résultats n'affichent que les questions de la version actuelle du questionnaire.
- Les modèles ne peuvent pas encore être renommés ni partagés entre organisations.
- Une réponse envoyée hors ligne n'est attribuée à un enquêteur que s'il est connecté au moment de l'envoi.

## Auteur

Osiax Tcheoubi, Licence Professionnelle en Informatique de Gestion, IUT de Parakou (Bénin).