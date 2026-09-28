SmartAutoTrack - Installation et Guide rapide

1) Prérequis
- XAMPP (Apache + MySQL) installé
- PHP >= 7.4 (cURL activé pour FCM)
- Navigateur récent

2) Structure du projet
- htdocs/smartautotrack/
  - api/ (backend PHP)
  - sql/init.sql (script de base de données)
- Vos dashboards front existent déjà dans les dossiers:
  - accueil/ (index, login)
  - admin-dashboard/
  - Dashboad Technicien/
  - dashbord client/

3) Base de données
- Ouvrez phpMyAdmin → Importer → choisissez smartautotrack/sql/init.sql → Exécuter
- Cela crée la base smartautotrack et les tables:
  users, vehicles, anomalies, reparations, interventions, push_tokens

Comptes de test : ceux des scripts SQL sont VERROUILLÉS (aucun mot de passe par défaut).
Définissez un mot de passe fort avant de vous connecter :
  php scripts/set_password.php admin@sat.local        (génère et affiche un mot de passe aléatoire)
  php scripts/set_password.php <email> --lock         (verrouille un compte)
Pour la base de l'API : HCH_DB_NAME=smartautotrack php scripts/set_password.php admin@sat.local

4) Déploiement (XAMPP)
- Placez le dossier HCH dans C:\xampp\htdocs\HCH
- Démarrez Apache et MySQL dans XAMPP
- **Lancer l'application :** http://localhost/HCH/
- **Page de connexion directe :** http://localhost/HCH/auth/login.php
- **Déconnexion :** http://localhost/HCH/auth/logout.php
- Test API: http://localhost/HCH/api/ping

> Utilisez toujours la même adresse (`localhost` ou `127.0.0.1`, pas les deux en alternance).

5) Configuration (variables d’environnement facultatives)
- Base du site PHP : HCH_DB_HOST, HCH_DB_NAME, HCH_DB_USER, HCH_DB_PASS, ou fichier config/local.php (copier config/local.example.php ; non versionné)
- HCH_CORS_ORIGINS (API) : origines autorisées en plus du site lui-même, séparées par des virgules
- HCH_DEBUG=1 : afficher les erreurs PHP (développement uniquement)
- SAT_DB_HOST (par défaut 127.0.0.1)
- SAT_DB_PORT (par défaut 3306)
- SAT_DB_NAME (par défaut smartautotrack)
- SAT_DB_USER (par défaut root)
- SAT_DB_PASS (par défaut vide)
- SAT_FCM_SERVER_KEY (clé serveur Firebase Cloud Messaging pour les notifications)

Sous Windows, vous pouvez définir ces variables système ou les injecter via Apache (SetEnv) si nécessaire.

6) Endpoints principaux (API PHP)
- Authentification
  - POST /api/auth/register { nom, email, mot_de_passe, statut: client|technicien|admin }
  - POST /api/auth/login { email, mot_de_passe } → retourne { user }
  - GET  /api/auth/me
  - POST /api/auth/logout
  - POST /api/push/register { token } (enregistre un token FCM pour l’utilisateur courant)

- Véhicules
  - GET  /api/vehicles (client: ses véhicules, admin: tous)
  - POST /api/vehicles (admin)
  - PUT  /api/vehicles/{id} (admin)
  - DELETE /api/vehicles/{id} (admin)

- Anomalies
  - GET  /api/anomalies
  - POST /api/anomalies (admin|technicien) → déclenche notif FCM si SAT_FCM_SERVER_KEY défini
  - PUT  /api/anomalies/{id} (admin|technicien)
  - DELETE /api/anomalies/{id} (admin)

- Réparations
  - GET  /api/reparations
  - POST /api/reparations (admin|technicien)
  - PUT  /api/reparations/{id} (admin|technicien)
  - DELETE /api/reparations/{id} (admin)

- Interventions
  - GET  /api/interventions
  - POST /api/interventions (admin)
  - PUT  /api/interventions/{id} (admin)
  - DELETE /api/interventions/{id} (admin)

- Simulateur OBD-II
  - POST /api/obd/sim { vehicle_id, metrics?: { battery, brakes, engine_temp } }
    - Génère des anomalies selon les seuils et envoie des notifications si configuré

7) Intégration Front déjà en place
- accueil/login.html:
  - Appelle /api/auth/login et /api/auth/register
  - Redirige selon le rôle vers les dashboards
- admin-dashboard/script.js:
  - Charge les anomalies via /api/anomalies et les affiche
- Dashboad Technicien/script.js:
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
     -d '{"vehicle_id":1, "metrics":{"battery":15,"brakes":20,"engine_temp":115}}'

9) Notifications Push (Firebase)
- Renseignez SAT_FCM_SERVER_KEY dans l’environnement Apache/PHP
- Côté front, enregistrez un token FCM du navigateur/mobile puis appelez:
  POST /api/push/register { token }
- À chaque POST /api/anomalies (ou /api/obd/sim), une notif est envoyée à:
  - le client propriétaire du véhicule
  - tous les administrateurs

10) Sécurité et prod (à prévoir)
- Activer HTTPS et CORS restrictif
- Gérer les rôles côté front (masquage UI) et côté back (déjà filtré)
- Nettoyer les logs/erreurs et ajouter de la validation côté serveur

11) Adaptations à faire (selon besoin)
- Mapper précisément vehicle_id lors de la création de rapport dans le dashboard technicien
- Enregistrer les tokens FCM côté front (service worker) pour recevoir les notifications



Base de données du site PHP (base « Charles ») : depuis le 23/09/2026, le schéma
est celui décrit dans sql/SCHEMA_ACTUEL.md (tables utilisateur/client/technicien/
vehicule/intervention/reparation/..., en camelCase). Il est géré directement en
base, pas par un script de création.
- Comptes de test : verrouillés par défaut. Définir un mot de passe :
  php scripts/set_password.php <email>          (ou --lock pour reverrouiller)
- Réimporter les données historiques d'un export de l'ancien schéma :
  php scripts/migrate_legacy_data.php            (simulation) puis --apply
- OBSOLÈTES (visaient l'ancien schéma users/vehicles, avant le 23/09/2026), à ne
  plus exécuter : db_init.sql, sql/init.sql, sql/schema.sql, sql/seed_demo.sql,
  update_database.sql, update_reparations_table.sql, fix_database_simple.sql,
  scripts/migrate_schema.php.
