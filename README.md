# DOBI — Backend (API Laravel 12 + MySQL)

Ce zip contient **uniquement les fichiers de DOBI** à poser par-dessus un Laravel 12 neuf.
Prérequis : PHP 8.2+, Composer, MySQL (XAMPP convient), Postman.

## Installation (une seule fois)

```bash
# 1. Projet Laravel vide
composer create-project laravel/laravel dobi-api
cd dobi-api

# 2. Sanctum + fichier routes/api.php  (répondre "no" si on propose de lancer les migrations)
php artisan install:api

# 3. Copier le contenu de ce zip DANS le dossier dobi-api (accepter d'écraser les fichiers)
#    -> bootstrap/app.php, config/, routes/api.php, app/, database/ ...

# 4. Ouvrir env-a-ajouter.txt et reporter ses lignes dans .env
#    (DB_DATABASE, DOBI_ADMIN_PASSWORD, FRONTEND_URL...)

# 5. Clé + vérification de la base
php artisan key:generate
php artisan db:show

# 6. Tables + compte administrateur
php artisan migrate
php artisan db:seed

# 7. Lancer
php artisan serve
```

## Vérifications

```bash
php tests/validator_smoke.php        # règles de validation des réponses (sans base)
php artisan route:list --path=api    # toutes les routes /api/v1
```

Puis dans Postman : **Import → DOBI.postman_collection.json**, puis *Run collection* (dossiers dans l'ordre 0 → 9).
Chaque requête vérifie son code HTTP attendu (200/201/401/404/410/422). Le token est enregistré tout seul.

## Base de données

La migration `2026_11_01_000001_create_dobi_tables.php` crée les tables du cahier des charges
(organizations, organization_members, studies, study_versions, sections, questions, question_options,
question_rules, responses, answers, participant_identifiers, consents, audit_logs) et saute celles qui existent déjà.
**Si votre base `dobi` contient déjà des tables avec une autre structure, ne lancez pas `migrate` :
envoyez la structure pour adapter les modèles.**

## Règles importantes

- Réponses : validées côté serveur contre la **version figée** de l'étude (`study_versions.snapshot`).
- Étude anonyme : aucune identité stockée, aucune IP stockée. Étude identifiée : identité dans `participant_identifiers` (table séparée) + consentement.
- Statistiques masquées sous `DOBI_MIN_GROUP` réponses (1 pour tester, 5 en production).
- Rôles : owner, admin, editor, surveyor, analyst (voir `app/Policies`).
- Limitation de débit : connexion 5/min, soumission publique 10/min, API 120/min.
- Questions de type « fichier » désactivées (cahier des charges §9).

## Pas encore inclus

Vérification de l'e-mail, invitation par e-mail d'une personne non inscrite, export `.xlsx` (le CSV s'ouvre dans Excel),
traductions françaises des messages de validation Laravel, tests Pest/PHPUnit complets, mode hors ligne/PWA.
