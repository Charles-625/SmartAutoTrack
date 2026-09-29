# SmartAutoTrack
SmartAutoTrack - Installation et Guide rapide
Application web PHP de suivi automobile : véhicules, anomalies, interventions,
rÉparations et messagerie, avec quatre espaces selon le rôle de l'utilisateur :
`admin/`, `client/`, `garage/` et `technicien/`.

## Prérequis
1) Prérequis
1) Prérequis
- XAMPP (Apache + MySQL) installé
- PHP >= 7.4 (cURL activé pour FCM)
- Navigateur récent
- XAMPP (Apache + MySQL) installé
- PHP >= 7.4 (cURL activé pour FCM)

2) Structure du projet
  - api/ (backend PHP)
  - sql/init.sql (script de base de données)
- Vos dashboards front existent déjà dans les dossiers:
  - accueil/ (index, login)
  - admin-dashboard/
  - Dashboad Technicien/
3) Base de données
- Ouvrez phpMyAdmin → Importer → choisissez smartautotrack/sql/init.sql → Exécuter
- Cela crée la base smartautotrack et les tables:
  users, vehicles, anomalies, reparations, interventions, push_tokens

Comptes de test : ceux des scripts SQL sont VERROUILLÉS (aucun mot de passe par défaut).
Définissez un mot de passe fort avant de vous connecter :
  php scripts/set_password.php admin@sat.local        (génère et affiche un mot de passe aléatoire)
  php scripts/set_password.php <email> --lock         (verrouille un compte)
- **Page de connexion directe :** http://localhost/HCH/auth/login.php
- **Déconnexion :** http://localhost/HCH/auth/logout.php
- Test API: http://localhost/HCH/api/ping

- SAT_DB_USER (par défaut root)
- SAT_DB_PASS (par défaut vide)
- SAT_FCM_SERVER_KEY (clé serveur Firebase Cloud Messaging pour les notifications)


6) Endpoints principaux (API PHP)
- Authentification
  - POST /api/auth/register { nom, email, mot_de_passe, statut: client|technicien|admin }
  - POST /api/auth/login { email, mot_de_passe } → retourne { user }
  - GET  /api/auth/me
  - POST /api/push/register { token } (enregistre un token FCM pour l’utilisateur courant)

- Véhicules
  - GET  /api/vehicles (client: ses véhicules, admin: tous)
  - POST /api/vehicles (admin)
  - DELETE /api/vehicles/{id} (admin)

- Anomalies
  - GET  /api/anomalies
  - POST /api/anomalies (admin|technicien) → déclenche notif FCM si SAT_FCM_SERVER_KEY défini
  - DELETE /api/anomalies/{id} (admin)

- Réparations
  - GET  /api/reparations
  - POST /api/reparations (admin|technicien)
  - DELETE /api/reparations/{id} (admin)

- Interventions
  - GET  /api/interventions
  - POST /api/interventions (admin)
  - DELETE /api/interventions/{id} (admin)

- Simulateur OBD-II
  - POST /api/obd/sim { vehicle_id, metrics?: { battery, brakes, engine_temp } }

7) Intégration Front déjà en place
  - Appelle /api/auth/login et /api/auth/register
- admin-dashboard/script.js:
  - Charge les anomalies via /api/anomalies et les affiche
  - Charge les réparations et anomalies via l’API
  - Envoi d’un rapport crée une entrée /api/reparations (vehicle_id exemple 1 à adapter)
- dashbord client/client-script.js:
  - Charge l’historique réparations via /api/reparations

8) Test rapide
1. Importez la DB (sql/init.sql)
2. Ouvrez accueil/login.html dans le navigateur
3. Définissez le mot de passe admin (voir section 3) puis connectez-vous avec admin@sat.local
4. Accédez au dashboard admin et vérifiez les anomalies
5. Simulez une anomalie:
   curl -X POST http://localhost/smartautotrack/api/obd/sim \
     -H "Content-Type: application/json" \
9) Notifications Push (Firebase)
- Côté front, enregistrez un token FCM du navigateur/mobile puis appelez:
  POST /api/push/register { token }
- À chaque POST /api/anomalies (ou /api/obd/sim), une notif est envoyée à:
  - le client propriétaire du véhicule
- Activer HTTPS et CORS restrictif
- Gérer les rôles côté front (masquage UI) et côté back (déjà filtré)
11) Adaptations à faire (selon besoin)
base, pas par un script de création.
- Comptes de test : verrouillés par défaut. Définir un mot de passe :
  php scripts/set_password.php <email>          (ou --lock pour reverrouiller)
- Réimporter les données historiques d'un export de l'ancien schéma :
  php scripts/migrate_legacy_data.php            (simulation) puis --apply
- OBSOLÈTES (visaient l'ancien schéma users/vehicles, avant le 23/09/2026), à ne
  plus exécuter : db_init.sql, sql/init.sql, sql/schema.sql, sql/seed_demo.sql,
  update_database.sql, update_reparations_table.sql, fix_database_simple.sql,
  scripts/migrate_schema.php.
=======
# SmartAutoTrack

Application web PHP de suivi automobile : véhicules, anomalies, interventions,
réparations et messagerie, avec quatre espaces selon le rôle de l'utilisateur :
`admin/`, `client/`, `garage/` et `technicien/`.

## Prérequis

- XAMPP (Apache + MySQL/MariaDB), PHP >= 7.4 avec les extensions `pdo_mysql`, `fileinfo`
  et `curl` (paiement CamPay et assistant IA)
- Composer (uniquement pour lancer les tests)

## Installation (XAMPP)

1. Copier le projet dans `C:\xampp\htdocs\HCH` (le chemin de base est détecté automatiquement).
2. Base de données : le schéma de la base `Charles` est décrit dans `sql/SCHEMA_ACTUEL.md`.
   Sur une base existante, compléter la structure avec :
   ```
   php scripts/migrate_structure.php            (simulation)
   php scripts/migrate_structure.php --apply    (applique)
   ```
3. Identifiants de connexion : variables d'environnement `HCH_DB_HOST`, `HCH_DB_NAME`,
   `HCH_DB_USER`, `HCH_DB_PASS`, ou fichier `config/local.php`
   (copier `config/local.example.php` ; ce fichier n'est pas versionné).
4. Démarrer Apache et MySQL, puis ouvrir http://localhost/HCH/
   (toujours la même adresse : `localhost` ou `127.0.0.1`, pas les deux en alternance).

## Comptes

- Clients et techniciens s'inscrivent via `auth/register.php`. Un technicien ou un garage
  doit être validé par un administrateur avant de pouvoir se connecter.
- Définir ou verrouiller le mot de passe d'un compte (ex. administrateur) :
  ```
  php scripts/set_password.php <email>            (génère et affiche un mot de passe aléatoire)
  php scripts/set_password.php <email> --lock     (verrouille le compte)
  ```

## Sécurité

- Mots de passe : 8 caractères minimum (`PASSWORD_MIN_LENGTH` dans `config/config.php`).
- Connexion : 5 échecs par email ou 20 par adresse IP en 15 minutes bloquent temporairement
  les tentatives (`includes/login_throttle.php`). Les compteurs sont stockés dans le dossier
  temporaire du système, ou dans `HCH_THROTTLE_DIR` si cette variable est définie.
- Un compte supprimé, suspendu ou rejeté perd son accès dès sa requête suivante.
- Formulaires et appels AJAX protégés par jeton CSRF (en-tête `X-CSRF-Token` posé
  automatiquement par `assets/js/main.js`).
- Les fichiers téléversés ne sont servis que par les scripts `ajax/download_*.php`,
  qui vérifient les droits.
- `HCH_DEBUG=1` affiche les erreurs PHP (développement uniquement).

## Configuration des services externes

Chaque clé se lit d'abord dans une variable d'environnement du même nom, sinon dans
`config/local.php`. Les clés et jetons ne doivent jamais être versionnés.

### Paiement Mobile Money (CamPay)

Un client peut payer en ligne (MTN Mobile Money ou Orange Money) une réparation terminée
dont le coût est renseigné, depuis `client/reparations.php`. Il reçoit une demande de
validation sur son téléphone ; la page suit le statut et affiche « Payée » une fois
la transaction confirmée. Les paiements apparaissent dans `admin/transactions.php`.

1. Renseigner `CAMPAY_BASE_URL` (`https://demo.campay.net` en test, `https://www.campay.net`
   en production), `CAMPAY_TOKEN` (ou `CAMPAY_USERNAME` + `CAMPAY_PASSWORD`) et
   `CAMPAY_WEBHOOK_KEY`, depuis le tableau de bord CamPay de l'application.
2. Préparer la table `paiement` : `php scripts/migrate_structure.php --apply`.
   Tant que ce n'est pas fait, le bouton de paiement reste masqué.
3. Déclarer l'URL de notification dans le tableau de bord CamPay :
   `https://<votre-domaine>/webhooks/campay.php`. Elle doit être joignable depuis Internet ;
   en local, le suivi du statut par la page client suffit à confirmer les paiements.

La démo CamPay refuse les montants supérieurs à 25 XAF : en démo, le client voit et
paie « officiellement » le coût réel de la réparation (c'est lui qui est enregistré),
mais seuls `CAMPAY_DEMO_MAX_AMOUNT` XAF (25 par défaut) sont débités. En production
(`https://www.campay.net`), le montant débité est toujours le montant réel.

Le statut d'un paiement est toujours revérifié auprès de l'API CamPay (le webhook n'est
qu'un signal, sa signature est contrôlée). `CAMPAY_SIMULATION=true` simule des paiements
réussis sans appeler CamPay : réservé au développement.

### Assistant IA (Hugging Face)

Les pages `client/assistant.php` et `admin/assistant.php` proposent un assistant
conversationnel qui s'appuie sur les données de l'utilisateur (véhicules, anomalies,
interventions) ou, pour l'administrateur, sur les statistiques de la plateforme.

- `HF_TOKEN` : jeton Hugging Face disposant de la permission
  « Make calls to Inference Providers » (un jeton en lecture seule est refusé).
- `HF_MODEL` : modèle utilisé, par défaut `Qwen/Qwen2.5-7B-Instruct:fastest`.

Sans jeton, l'assistant s'affiche comme indisponible. Chaque utilisateur est limité
à 20 messages par tranche de 10 minutes.

## Tests

```
php composer.phar install
php composer.phar test
```

Les tests d'intégration sont ignorés si la base n'est pas joignable.
>>>>>>> 30d6ef683a4e566fe624a0c749d83351ffb15b2f
