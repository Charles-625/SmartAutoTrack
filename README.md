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
# SmartAutoTrack

Application web PHP de suivi automobile (gestion des véhicules, anomalies, interventions, réparations et messagerie). Ce dépôt contient le backend PHP et les interfaces front pour les administrateurs, clients, garages et techniciens.

## Contenu principal
- `api/` : services backend
- `admin/`, `client/`, `garage/`, `technicien/` : interfaces utilisateurs
- `sql/` : schémas et scripts SQL
- `assets/` : CSS / JS / images

## Prérequis
- PHP >= 7.4 avec `pdo_mysql`, `fileinfo` et `curl`
- MySQL / MariaDB
- XAMPP (facultatif, pratique sous Windows)
- Composer (pour les dépendances et tests)

## Installation rapide (local / XAMPP)
1. Copier le dossier du projet dans `C:\xampp\htdocs\HCH`.
2. Démarrer Apache et MySQL dans XAMPP.
3. Importer le schéma (exemple) via phpMyAdmin : `sql/SCHEMA_ACTUEL.md` ou le script fourni.
4. Copier `config/local.example.php` vers `config/local.php` et renseigner les paramètres de connexion (ou définir les variables d'environnement : `HCH_DB_HOST`, `HCH_DB_NAME`, `HCH_DB_USER`, `HCH_DB_PASS`).
5. Ouvrir l'application : `http://localhost/HCH/`.

## Configuration
- Les paramètres d'environnement peuvent être définis via Apache (`SetEnv`) ou dans `config/local.php` (ne pas versionner ce fichier).
- Variables utiles : `HCH_DEBUG`, `SAT_FCM_SERVER_KEY`, `CAMPAY_*`.

## Commandes utiles
- Générer/verrouiller mot de passe administrateur :
  ```bash
  php scripts/set_password.php admin@example.com
  php scripts/set_password.php admin@example.com --lock
  ```
- Appliquer des migrations (simulation puis apply) :
  ```bash
  php scripts/migrate_structure.php
  php scripts/migrate_structure.php --apply
  ```

## Tests
Si des tests sont fournis, installez les dépendances via Composer, puis lancez :
```bash
composer install
php composer.phar test
```

## Développement
- Les assets JS/CSS se trouvent dans `assets/`.
- Respecter le fichier `config/local.example.php` pour les valeurs locales.

## Résolution de problèmes
- Veillez à utiliser toujours la même adresse locale (`localhost` ou `127.0.0.1`).
- Vérifiez les permissions sur `uploads/` quand vous testez l'upload de fichiers.

## Licence
Ce projet n'a pas de licence précisée. Si vous souhaitez en ajouter une, créez un fichier `LICENSE` à la racine (ex.: `MIT`) et indiquez-le ici.

---

Pour toute information détaillée, consultez les documents dans `sql/` et les scripts d'administration dans `scripts/`.
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
