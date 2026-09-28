# Schéma de base de données — notes [OBSOLÈTE depuis le 23/09/2026]

> Ce document analyse l'ancien schéma `Charles` (tables `users`/`vehicles`/...,
> snake_case), remplacé depuis par un nouveau schéma créé indépendamment de ces
> fichiers. **Pour le schéma actuellement en production, voir
> [SCHEMA_ACTUEL.md](SCHEMA_ACTUEL.md).** Conservé ici à titre d'historique.

## Quel fichier utiliser ? [ancien schéma, ne s'applique plus]

| Besoin | Commande |
|---|---|
| Nouvelle base | `CREATE DATABASE Charles ...` puis `sql/schema.sql` (+ `sql/seed_demo.sql` en option) |
| Base existante | `php scripts/migrate_schema.php` (simulation) puis `php scripts/migrate_schema.php --apply` |

`sql/schema.sql` est la seule source de vérité du site PHP. `sql/init.sql` est le schéma de l'ancienne API
(base `smartautotrack`, colonnes `id_user`, `id_vehicle`...) : autre schéma, autre base.

## Historique reconstitué (ordre logique des anciens fichiers)

1. `db_init.sql` : création de la base `Charles`, 8 tables (`users`, `vehicles`, `anomalies`, `reparations`,
   `interventions`, `messages`, `notifications`, `technician_documents`) + données de test.
2. `update_database.sql` : ajoute `users.theme_preference` + index `idx_users_theme`.
3. `update_reparations_table.sql` **et** `fix_database_simple.sql` : font la même chose (doublon) — ajoutent
   `reparations.titre, diagnostic, travaux_effectues, pieces_utilisees, recommandations, duree_intervention,
   updated_at, intervention_id` (+ clé étrangère) et élargissent `reparations.statut` à `valide`.
   Exécuter les deux à la suite provoque « Duplicate column name ». Le premier injectait aussi de fausses données
   (`RAND()`), ce qui n'est pas repris.

## Écarts code / base corrigés par le schéma final

| Écart | Effet avant correction | Correction |
|---|---|---|
| `technicien/reports.php` insère `reparations.statut = 'en_attente'`, valeur absente de l'ENUM | En mode strict MySQL : « Data truncated », **création de rapport impossible** | `en_attente` ajouté à l'ENUM |
| `technicien/tasks.php` filtre sur `interventions.priorite`, colonne inexistante | Erreur SQL « Unknown column » dès qu'on filtre par priorité | colonne `priorite ENUM('basse','moyenne','haute') DEFAULT 'moyenne'` |

## Colonnes de `reparations` : usage vérifié dans le code PHP

| Colonne | Utilisée par |
|---|---|
| `titre` | technicien/{dashboard,new_report,recent_repairs,reports,tasks}, client/{reparations,vehicle_details}, admin/interventions |
| `diagnostic`, `travaux_effectues`, `pieces_utilisees`, `recommandations` | technicien/{dashboard,new_report,recent_repairs,reports,tasks}, client/reparations, ajax/download_report |
| `duree_intervention` | mêmes fichiers |
| `intervention_id` | technicien/{new_report,reports}, admin/interventions, ajax/get_task_details |
| `anomalie_id` | client/{reparations,vehicle_details}, technicien/{recent_repairs,reports}, ajax/get_reparation_details |
| `date_debut`, `date_fin` | ajax/download_report ; `date_fin` aussi technicien/dashboard |
| `rapport` | **aucun usage SQL dans le site PHP** (uniquement l'ancienne API, autre base) — conservée, candidate à suppression |
| `updated_at` | aucun usage — conservée (audit automatique, sans coût) |

Autres colonnes sans usage PHP mais conservées : `users.photo_profil`.

## Points à traiter plus tard (nécessitent de modifier le code, hors périmètre ici)

- **`valide` / `validee`** : le code technicien écrit/lit `valide`, le code client lit `validee`. Les deux sont
  dans l'ENUM pour que tout fonctionne, mais il faudra en choisir une et migrer les lignes.
- `reparations.rapport` : à supprimer quand la décision est prise (aucune ligne ne l'utilise dans le site).
- `reparations.statut` mélange statut de réparation (`planifiee`, `en_cours`, `terminee`) et statut de validation
  du rapport (`en_attente`, `valide`) : à séparer en deux colonnes lors d'une prochaine refonte.
