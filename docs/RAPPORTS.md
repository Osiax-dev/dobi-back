# Module Rapports & synthèses

Génère un rapport structuré à partir des **vraies réponses** d'une étude. Rien n'est inventé : une information absente est signalée « À compléter ». Les réponses originales ne sont jamais modifiées.

## Accès
Propriétaire, administrateur et analyste de l'organisation (mêmes droits que les résultats de l'étude). Les autres rôles reçoivent 403. Un rapport d'une autre organisation est inaccessible. Suppression : propriétaire, administrateur, ou auteur du rapport.

## Les 11 sections
Couverture, Informations générales, Contexte, Objectifs, Méthodologie, Échantillon, Résultats, Analyse, Conclusions, Recommandations, Annexes. L'enquêteur peut réordonner, masquer, ajouter des sections libres, masquer des questions et réécrire les explications.

## Points d'API (préfixe `/api/v1`, authentification Sanctum)
| Méthode | Route | Rôle |
|---|---|---|
| GET | `studies/{id}/reports` | liste |
| POST | `studies/{id}/reports` | génère et enregistre. Corps : `title?`, `compare_by?` (entier ou liste de 1 à 3 identifiants de questions) |
| POST | `studies/{id}/reports/preview` | **aperçu** : même calcul, **rien n'est enregistré** |
| GET | `reports/{id}` | détail (contenu, textes, mise en page, `history_count`) |
| PUT | `reports/{id}` | enregistre titre, textes, mise en page, statut. `revision` obligatoire (409 `report_conflict` si le rapport a changé entre-temps) |
| GET | `reports/{id}/revisions` | historique (30 dernières versions) |
| GET | `reports/{id}/revisions/{rev}` | relire une ancienne version |
| POST | `reports/{id}/revisions/{rev}/restore` | restaurer. Corps : `revision` (révision actuelle). L'état actuel est d'abord conservé |
| GET | `reports/{id}/document` | document composé (sert à l'export PDF) |
| GET | `reports/{id}/export/docx` | export Word |
| POST | `reports/{id}/assist` | suggestion IA d'un texte (rien n'est enregistré) |
| DELETE | `reports/{id}` | suppression |

## Historique et restauration
- À chaque enregistrement qui change le titre, les textes ou la mise en page, l'**ancien état** est rangé dans `report_revisions` (30 conservés par rapport). Un enregistrement sans changement n'ajoute rien.
- Restaurer remet titre, textes et mise en page d'une ancienne version ; l'état remplacé reste dans l'historique : on peut donc revenir en arrière. Impossible sur un rapport finalisé (rouvrir d'abord).
- Les calculs (chiffres) ne changent pas : ils restent figés à la génération.

## Comparaisons
Jusqu'à **3 questions** (choix unique, liste déroulante, Oui/Non) pour comparer les résultats par groupe. Les groupes de moins de `DOBI_MIN_GROUP` réponses sont masqués. Anciens rapports (une seule comparaison) toujours lisibles.

## Assistance IA (facultative)
Fournisseur : Groq (gratuit avec limites) ou Anthropic. Variables : `GROQ_API_KEY` (+ `GROQ_MODEL`, défaut `llama-3.3-70b-versatile`) ou `ANTHROPIC_API_KEY` (+ `DOBI_AI_MODEL`) ; `DOBI_AI_PROVIDER=groq|anthropic` pour forcer. Sans clé, l’IA est désactivée. `DOBI_AI_TIMEOUT` (40 s).

## Déploiement
```
git pull
composer install --no-dev --optimize-autoloader
php artisan migrate --force      # nouvelle migration : report_revisions + reports.updated_by
php artisan config:cache && php artisan route:cache
```

## Tests
`php artisan config:clear && php artisan test --filter=ReportsTest` ; front : `npm test`.