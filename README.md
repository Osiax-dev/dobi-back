# DOBI — API (Laravel)

DOBI est une plateforme pour **créer, publier, collecter et analyser des questionnaires** (études). Ce dépôt est l'**API** ; l'interface React est dans le dépôt `dobi-front`.

- Production : `https://dobi.alwaysdata.net` (Alwaysdata, MariaDB)
- Front : `https://dobi-sand.vercel.app` (Vercel)

## Ce que fait DOBI

- Comptes, vérification de l'e-mail, organisations, membres et invitations, rôles.
- Questionnaires par étapes (sections), nombreux types de questions, règles d'affichage, versions publiées figées.
- Collecte publique (lien, QR code), collecte par enquêteurs, études anonymes ou identifiées avec consentement.
- Résultats et statistiques, exports, modèles d'études, notifications, historique d'activité.
- **Rapports & synthèses** : génération depuis les vraies données, éditeur, exports PDF et Word, historique, comparaisons, assistance IA (voir `docs/RAPPORTS.md`).

## Technologies

PHP 8.3 ou plus · Laravel 13 · Laravel Sanctum (jetons d'API) · MySQL / MariaDB · PHPUnit.
L'extension PHP `zip` est nécessaire (export Word).

## Installation en local

```bash
git clone https://github.com/Osiax-dev/dobi-back.git
cd dobi-back
composer install
cp .env.example .env
php artisan key:generate
```

Ouvre `.env` et règle la base de données (voir le tableau plus bas), puis :

```bash
php artisan migrate
php artisan db:seed        # crée le compte administrateur (DOBI_ADMIN_EMAIL / DOBI_ADMIN_PASSWORD)
php artisan serve --port=8001
```

L'API répond alors sur `http://127.0.0.1:8001/api/v1` (test : `GET /api/v1/ping`).
Le front attend cette adresse par défaut.

## Variables d'environnement

| Variable | Rôle |
|---|---|
| `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | base de données |
| `APP_URL` | adresse de l'API |
| `FRONTEND_URL` | adresse(s) du front, séparées par des virgules (sert aux liens des e-mails et au CORS) |
| `MAIL_*` | envoi des e-mails (`MAIL_MAILER=log` en local : les e-mails s'écrivent dans `storage/logs`) |
| `DOBI_MIN_GROUP` | nombre minimum de réponses pour afficher une statistique (1 pour tester, 5 en production) |
| `DOBI_TOKEN_DAYS` | durée de vie des jetons de connexion |
| `DOBI_ADMIN_EMAIL`, `DOBI_ADMIN_PASSWORD` | compte créé par `db:seed` (choisis un vrai mot de passe) |
| `GROQ_API_KEY`, `GROQ_MODEL` | assistance IA par Groq (gratuit avec limites). Modèle par défaut : `openai/gpt-oss-120b` |
| `ANTHROPIC_API_KEY`, `DOBI_AI_MODEL` | assistance IA par Anthropic (alternative) |
| `DOBI_AI_PROVIDER` | `groq` ou `anthropic` pour forcer le choix (par défaut : Groq si `GROQ_API_KEY` existe) |
| `DOBI_AI_TIMEOUT` | délai maximum d'une demande à l'IA, en secondes (40 par défaut) |

Sans clé d'IA, l'assistance est simplement désactivée ; le reste fonctionne. Ne mets jamais une clé dans Git ni dans le front.

## Tests

```bash
php artisan config:clear
php artisan test
php artisan test --filter=ReportsTest      # un seul fichier
```

Les tests utilisent des appels réseau simulés : ils ne contactent jamais Groq ni Anthropic, quelle que soit ta configuration locale.
Une collection Postman (`DOBI.postman_collection.json`) couvre l'API de base ; les routes des rapports n'y figurent pas encore.

## Rôles

`owner`, `admin`, `editor`, `surveyor`, `analyst` (règles dans `app/Policies`).
Les rapports sont réservés au propriétaire, à l'administrateur et à l'analyste de l'organisation.

## Règles importantes

- Les réponses sont validées côté serveur contre la **version figée** de l'étude (`study_versions.snapshot`).
- Étude anonyme : aucune identité ni adresse IP stockée. Étude identifiée : identité dans une table séparée, avec consentement.
- Les statistiques sont masquées sous `DOBI_MIN_GROUP` réponses, pour protéger la confidentialité.
- Les données sont isolées entre organisations.
- Limitation de débit : connexion, soumission publique et API sont limitées par minute.
- Les réponses des participants ne sont jamais modifiées par les rapports.

## Rapports & synthèses

Routes, historique, aperçu, comparaisons et configuration de l'IA : voir [`docs/RAPPORTS.md`](docs/RAPPORTS.md).

## Structure

```
app/Http/Controllers/Api/V1/   contrôleurs de l'API
app/Models/                    modèles
app/Policies/                  droits d'accès
app/Services/                  logique métier (questionnaire, rapports, IA)
app/Support/                   classes utilitaires pures (mise en page, Word, prompts IA)
database/migrations/           tables
routes/api.php                 routes /api/v1
tests/                         tests PHPUnit
docs/                          documentation
```

## Déploiement (Alwaysdata)

Le dossier du projet sur le serveur est `~/dobi-back`. Après un `git push`, en SSH :

```bash
cd ~/dobi-back
git status                    # doit être propre
git pull
php artisan migrate --force   # seulement s'il y a de nouvelles migrations
php artisan config:cache
php artisan route:cache
```

Fais une sauvegarde de la base avant une migration. Ne modifie le `.env` du serveur qu'à la main.

## Notes

- Sans nom de domaine propre (SPF, DKIM, DMARC), les e-mails envoyés depuis `alwaysdata.net` peuvent arriver dans les spams.
- Les questions de type « fichier » sont désactivées.

## Limites connues

- Les résultats n'affichent que les questions de la version actuelle du questionnaire.
- Les modèles ne peuvent pas encore être renommés ni partagés entre organisations.
- Une réponse envoyée hors ligne n'est attribuée à un enquêteur que s'il est connecté au moment de l'envoi.

## Auteur

Osiax Tcheoubi,Développeur web full-stack et intégrateur web
Licence Professionnelle en Informatique de Gestion, (Bénin).
