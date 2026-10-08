# SmartAutoTrack

Application web PHP de suivi automobile destinée au Cameroun. Elle couvre les véhicules, les demandes d'intervention, les anomalies, les rapports de réparation, le paiement Mobile Money (CamPay), la messagerie interne et un assistant IA.

Tout le code, l'interface et les messages sont en français.

---

## Sommaire

1. [Présentation](#1-présentation)
2. [Stack et prérequis](#2-stack-et-prérequis)
3. [Arborescence](#3-arborescence)
4. [Installation locale](#4-installation-locale)
5. [Configuration](#5-configuration)
6. [Fonctionnalités clés et règles métier](#6-fonctionnalités-clés-et-règles-métier)
7. [Sécurité](#7-sécurité)
8. [Tests](#8-tests)
9. [Dépannage](#9-dépannage)
10. [Licence](#10-licence)

---

## 1. Présentation

L'application a quatre espaces, un par rôle. Après la connexion, `dashboard.php` envoie chaque utilisateur vers le tableau de bord de son rôle. La base n'a pas de colonne « rôle » : le rôle vient de la table où l'identifiant de l'utilisateur apparaît (`administrateur`, `client`, `technicien`, ou `garage.idUtilisateur`). Voir `getUserRole()` dans `config/roles.php`.

| Rôle | Dossier | Création du compte | Ce que le rôle peut faire |
|---|---|---|---|
| **Client** (particulier ou entreprise) | `client/` | Inscription libre (`auth/register.php`) | Gérer ses véhicules (ou son parc, pour une entreprise), demander une intervention, suivre ses anomalies et réparations, payer une réparation terminée par Mobile Money, voir son journal d'activité, utiliser les messages, le SAV et l'assistant IA, consulter son abonnement (formule « Gratuit ») |
| **Technicien** | `technicien/` | Inscription (`auth/register.php`, justificatifs PDF/JPG/PNG en option), ou création par un garage ou un administrateur | Voir ses tâches, démarrer une intervention (`EN_COURS`), rédiger le rapport de réparation (ce qui clôture l'intervention), signaler et suivre des anomalies, consulter son historique et son journal |
| **Garage** | `garage/` | Créé par un administrateur (`admin/garages.php`) | Traiter les demandes d'intervention (affecter un technicien, fixer la date et la priorité, ou annuler), gérer ses techniciens, enregistrer des réparations et des anomalies, consulter son historique et son journal |
| **Administrateur** | `admin/` | Aucune page ne le crée (voir [§4.5](#45-comptes-et-mots-de-passe)) | Superviser les clients, garages, techniciens, véhicules, interventions, réparations et anomalies ; valider, rejeter ou suspendre les garages et techniciens ; consulter les abonnements, les transactions CamPay, les statistiques, le journal d'activité ; utiliser l'assistant IA sur les statistiques de la plateforme |

Un technicien ou un garage ne peut se connecter que si son statut est `VALIDE`. Les statuts `EN_ATTENTE`, `REJETE` et `SUSPENDU` bloquent la connexion avec un message clair (`isAccountUsable()` et `accountStatusMessage()` dans `config/roles.php`). Un compte supprimé, suspendu ou rejeté perd aussi ses sessions en cours dès sa requête suivante (`enforceActiveSession()` dans `config/config.php`).

Pages communes : `index.php` (vitrine publique), `profile.php` (profil de tous les rôles), `messages/index.php` (messagerie), `settings.php` (redirige vers les paramètres du rôle).

---

## 2. Stack et prérequis

- **PHP 8.2** : c'est la version de XAMPP utilisée pour le développement (8.2.12). Le code utilise des fonctions fléchées (`fn`), donc **PHP 7.4 au minimum**. Seule la 8.2 a été vérifiée.
- **MySQL 8 / MariaDB** : le dump historique vient de MySQL 8.0.41.
- **Apache**, avec `mod_headers` et `mod_authz_core`, qui appliquent les `.htaccess`.
- **Composer**, pour les tests uniquement : PHPUnit 9.6 et Guzzle 7.9 sont les seules dépendances, toutes en `require-dev`.

Extensions PHP utilisées par le code (toutes actives par défaut dans XAMPP) :

| Extension | Utilisée pour |
|---|---|
| `pdo_mysql` | toute la base de données (`config/database.php`) |
| `curl` | appels HTTP à CamPay et OpenRouter (`includes/http_client.php`) |
| `fileinfo` | vérification du type MIME réel des fichiers envoyés (`auth/register.php`, `garage/parametres.php`, `ajax/download_*.php`) |
| `mbstring` | longueur des mots de passe, découpe des textes (`includes/password_policy.php`, `includes/payments.php`, pages admin) |
| `openssl` | HTTPS vers CamPay et OpenRouter |
| `json` | réponses AJAX, webhook (intégrée à PHP 8) |

Le front utilise jQuery et du JavaScript maison (`assets/js/main.js`, `themes.js`, `assistant.js`). Il n'y a aucune étape de compilation.

---

## 3. Arborescence

```
HCH/
├── index.php               Vitrine publique (redirige vers dashboard.php si l'utilisateur est connecté)
├── dashboard.php           Aiguillage vers le tableau de bord du rôle
├── profile.php             Profil (tous rôles)
├── settings.php            Redirection vers les paramètres du rôle
├── admin/                  Espace administrateur (+ includes/sidebar.php, helpers.php)
├── client/                 Espace client
├── garage/                 Espace garage
├── technicien/             Espace technicien
├── auth/                   login.php, register.php, logout.php
├── messages/               Messagerie interne (index.php)
├── ajax/                   Points d'accès JSON appelés par les pages (session + CSRF)
│   ├── campay_collect.php  Lance un paiement Mobile Money
│   ├── campay_status.php   Suit le statut d'un paiement
│   ├── assistant_chat.php  Assistant IA
│   └── download_*.php      Téléchargement contrôlé des documents envoyés
├── webhooks/campay.php     Notification CamPay (sans session, signature vérifiée)
├── config/
│   ├── config.php          Constantes, session, CSRF, validations, appConfig()
│   ├── database.php        Classe Database (PDO)
│   ├── roles.php           Rôle, profil, statut du compte
│   ├── local.example.php   Modèle de configuration locale
│   └── local.php           Votre configuration (NON versionnée)
├── includes/
│   ├── header.php, footer.php
│   ├── payments.php        Logique métier du paiement (table paiement)
│   ├── campay.php          Client de l'API CamPay
│   ├── ai.php              Assistant IA (OpenRouter)
│   ├── http_client.php     Petit client HTTP JSON (cURL)
│   ├── login_throttle.php  Limitation des tentatives de connexion
│   ├── activity_log.php    Journal d'activité (table journalactivites)
│   └── password_policy.php Règles de mot de passe
├── assets/                 css/, js/ (main.js, themes.js, assistant.js), img/
├── uploads/                Fichiers envoyés (accès web direct interdit)
├── scripts/                Scripts CLI (migrations, mots de passe) ; accès web interdit
├── sql/                    Documentation du schéma et anciens scripts SQL
├── tests/                  PHPUnit : Unit/, Integration/, Functional/
├── composer.json, phpunit.xml
└── vendor/                 Dépendances Composer (non versionnées)
```

### Éléments hérités, non utilisés par l'application actuelle

| Élément | Nature |
|---|---|
| `api/` | Ancienne API REST (routeur `api/index.php`). Elle se connecte à une **autre base**, `smartautotrack`, via `SAT_DB_*`, et son `.htaccess` vise `/smartautotrack/api/`. Aucune page actuelle ne l'appelle. Voir [§7](#points-dattention-connus). |
| `accueil/`, `admin-dashboard/`, `Dashboad Technicien/`, `dashbord client/` | Anciennes maquettes HTML/JS statiques, remplacées par les espaces PHP |
| `sql/init.sql` | Schéma de l'ancienne API (base `smartautotrack`) |
| `sql/schema.sql`, `sql/seed_demo.sql`, `sql/SCHEMA_NOTES.md` | Ancien schéma de `charles` (tables `users`, `vehicles`…), marqué **OBSOLÈTE** |
| `db_init.sql`, `update_database.sql`, `update_reparations_table.sql`, `fix_database_simple.sql` (à la racine) | Anciens scripts SQL, **obsolètes** |
| `charles (1).sql` (à la racine) | Dump phpMyAdmin de l'ancien schéma du 21/09/2026, qui contient des données. Seule source de `scripts/migrate_legacy_data.php` |
| `scripts/migrate_schema.php` | Migration vers l'ancien schéma, **obsolète : ne plus l'exécuter** |
| `tmp_*.txt` | Fichiers de cookies de tests manuels (ignorés par Git) |

**N'importez aucun de ces fichiers `.sql`** pour installer l'application : ils créent un schéma qui ne correspond plus au code.

---

## 4. Installation locale

### 4.1 Récupérer le projet

1. Installez XAMPP (PHP 8.2), puis démarrez **Apache** et **MySQL** depuis le panneau XAMPP.
2. Placez le projet dans `C:\xampp\htdocs\HCH`. L'URL par défaut est `http://localhost/HCH/`. Le chemin de base est calculé automatiquement dans `config/config.php`, donc un autre nom de dossier fonctionne aussi.

### 4.2 Créer la base `charles`

> **Important :** aucun script du dépôt ne recrée le schéma actuel de la base `charles` (tables `utilisateur`, `client`, `technicien`, `garage`, `vehicule`, `intervention`, `reparation`, `anomalie`, `paiement`…). Ce schéma a été créé directement en base le 23/09/2026. Sa référence écrite est [`sql/SCHEMA_ACTUEL.md`](sql/SCHEMA_ACTUEL.md).

Démarche recommandée :

1. **Obtenir un export de la structure actuelle** auprès de l'équipe : dans phpMyAdmin, choisir la base `charles`, puis *Exporter*, format SQL, *structure seulement* de préférence. Importez-le ensuite dans votre MySQL local, dans une base nommée `charles`.
   Sans cet export, il faut recréer les tables à la main à partir de `sql/SCHEMA_ACTUEL.md`.
2. **Compléter le schéma** avec les colonnes et tables dont le code a besoin (`messages`, `notifications`, `technician_documents`, colonnes CamPay de `paiement`…). Commencez par une simulation, puis appliquez :
   ```bash
   php scripts/migrate_structure.php            # simulation : liste ce qui manque, n'écrit rien
   php scripts/migrate_structure.php --apply    # applique
   ```
   Le script est idempotent et ne modifie aucune donnée.
3. **Migrations ponctuelles.** Elles sont idempotentes et ne font rien si le changement est déjà en place. Attention : elles **n'ont pas de mode simulation** et écrivent dès qu'on les lance :
   ```bash
   php scripts/migrate_garage_login.php            # garage.idUtilisateur (connexion d'un garage)
   php scripts/migrate_anomalie_intervention.php   # anomalie.idIntervention
   php scripts/migrate_admin_suspension_v2.php     # statut SUSPENDU (garage, technicien)
   php scripts/migrate_journal_activites_v2.php    # journal multi-rôles
   ```
   Si votre export vient d'une base déjà à jour, ces scripts indiquent simplement « rien à faire ».
4. *(Facultatif)* Reprendre les données de l'ancien dump `charles (1).sql`, si ses tables sont chargées dans la même base :
   ```bash
   php scripts/migrate_legacy_data.php            # simulation
   php scripts/migrate_legacy_data.php --apply
   ```

`php` doit être dans le PATH. Sinon, utilisez `C:\xampp\php\php.exe`.

### 4.3 Configurer l'accès à la base

```bat
copy config\local.example.php config\local.php
```

(Sous Git Bash ou Linux : `cp config/local.example.php config/local.php`.)

Puis modifiez `config/local.php` :

```php
'db_host' => 'localhost',
'db_name' => 'charles',
'db_user' => 'root',
'db_pass' => '',
```

Le modèle indique `'Charles'`. Sous Windows, MySQL ignore la casse des noms de base, mais pas sous Linux : utilisez le nom exact de votre base.

### 4.4 Dépendances (tests uniquement)

```bash
composer install
```

L'application elle-même ne charge pas `vendor/`.

### 4.5 Comptes et mots de passe

- **Client / technicien** : inscription sur `http://localhost/HCH/auth/register.php`. Un technicien inscrit ainsi reste `EN_ATTENTE` tant qu'un administrateur ne l'a pas validé (*Techniciens*). Un technicien créé par un garage ou par un administrateur est directement `VALIDE`.
- **Garage** : créé par un administrateur dans *Garages*.
- **Administrateur** : aucune page ne le crée. Il faut une ligne dans `utilisateur` et une ligne dans `administrateur` avec le même identifiant, insérées en SQL, venant de votre export, ou reprises par `migrate_legacy_data.php`. Définissez ensuite son mot de passe en ligne de commande :

```bash
php scripts/set_password.php admin@exemple.cm                 # génère un mot de passe fort et l'affiche une seule fois
php scripts/set_password.php admin@exemple.cm 'MonMotDePasse!2026'   # mot de passe choisi
php scripts/set_password.php admin@exemple.cm --lock          # verrouille le compte (connexion impossible)
```

Le script agit sur la table `utilisateur`, repérée par l'email. Un mot de passe passé en argument doit faire **au moins 12 caractères** et respecter les règles de [§6.4](#64-règles-de-mot-de-passe).

### 4.6 Accès

| Page | URL |
|---|---|
| Accueil | `http://localhost/HCH/` |
| Connexion | `http://localhost/HCH/auth/login.php` |
| Inscription | `http://localhost/HCH/auth/register.php` |
| Déconnexion | `http://localhost/HCH/auth/logout.php` |

---

## 5. Configuration

Les paramètres CamPay et IA sont d'abord lus dans une **variable d'environnement** du même nom. S'il n'y en a pas (ou si elle est vide), ils sont lus dans le tableau renvoyé par **`config/local.php`** (fonction `appConfig()` dans `config/config.php`). La base de données suit le même principe, mais avec des noms différents (`HCH_DB_*` / `db_*`, voir ci-dessous). `HCH_DEBUG` et `HCH_THROTTLE_DIR` ne sont lues que dans l'environnement. Les variables d'environnement peuvent être posées par Apache (`SetEnv NOM valeur`) ou par le système.

### Base de données (`config/database.php`)

| Variable d'environnement | Clé dans `local.php` | Défaut |
|---|---|---|
| `HCH_DB_HOST` | `db_host` | `localhost` |
| `HCH_DB_NAME` | `db_name` | `Charles` |
| `HCH_DB_USER` | `db_user` | `root` |
| `HCH_DB_PASS` | `db_pass` | *(vide)* |

La connexion utilise PDO en `utf8mb4`, avec des exceptions et des requêtes préparées non émulées. En cas d'échec, le visiteur reçoit une erreur 503 générique et le détail va dans le log PHP.

### Paiement CamPay (`includes/campay.php`)

| Clé | Rôle | Défaut |
|---|---|---|
| `CAMPAY_USE_DEMO` | `true` : `https://demo.campay.net`, `false` : `https://www.campay.net` | `true` |
| `CAMPAY_BASE_URL` | Remplace l'URL déduite de `CAMPAY_USE_DEMO` | *(vide)* |
| `CAMPAY_TOKEN` | Jeton d'accès permanent, prioritaire | *(vide)* |
| `CAMPAY_USERNAME`, `CAMPAY_PASSWORD` | Identifiants API, utilisés si aucun jeton n'est défini | *(vide)* |
| `CAMPAY_WEBHOOK_KEY` | Clé de vérification de la signature du webhook | *(vide)* |
| `CAMPAY_SIMULATION` | `true` : aucun appel à CamPay, chaque paiement réussit. **Développement uniquement** | `false` |
| `CAMPAY_DEMO_MAX_AMOUNT` | En démo seulement : plafond du montant réellement débité (`0` = pas de plafond) | `25` |
| `CAMPAY_APP_ID` | Présent dans `local.example.php` | Jamais lu par le code |

CamPay est considéré comme configuré si `CAMPAY_SIMULATION` est vrai, ou si `CAMPAY_TOKEN` est défini, ou si `CAMPAY_USERNAME` et `CAMPAY_PASSWORD` le sont.

### Assistant IA (`includes/ai.php`)

| Clé | Rôle | Défaut |
|---|---|---|
| `OPENROUTER_API_KEY` | Clé OpenRouter (`sk-or-v1-...`, créée sur https://openrouter.ai/keys). Sans clé, l'assistant s'affiche comme indisponible | *(vide)* |
| `OPENROUTER_MODEL` | Modèle principal (identifiant de https://openrouter.ai/models ; suffixe `:free` = gratuit) | `nvidia/nemotron-3-super-120b-a12b:free` |
| `OPENROUTER_FALLBACK_MODELS` | Modèles de secours séparés par des virgules, essayés dans l'ordre si le principal est saturé (429), retiré (404), réservé (403), en panne ou muet ; une clé refusée (401) arrête tout. 4 modèles au plus | *(vide)* |
| `OPENROUTER_API_URL` | Point d'accès, compatible OpenAI | `https://openrouter.ai/api/v1/chat/completions` |

### Divers

| Variable d'environnement | Rôle |
|---|---|
| `HCH_DEBUG=1` | Affiche les erreurs PHP. **Développement uniquement** : par défaut, les erreurs vont seulement dans les logs |
| `HCH_THROTTLE_DIR` | Dossier des compteurs de tentatives de connexion. Défaut : `<temp système>/smartautotrack_login_throttle` |

Variables lues **uniquement par l'API héritée `api/`** et sans effet sur l'application : `SAT_DB_HOST`, `SAT_DB_PORT`, `SAT_DB_NAME`, `SAT_DB_USER`, `SAT_DB_PASS`, `SAT_FCM_SERVER_KEY`, `HCH_CORS_ORIGINS`.

---

## 6. Fonctionnalités clés et règles métier

### 6.1 Cycle d'une intervention

1. Le **client** demande une intervention sur l'un de ses véhicules (`client/interventions.php`).
2. Le **garage** affecte un technicien, fixe la date et la priorité, ou annule la demande (`garage/demandes.php`).
3. Le **technicien** démarre l'intervention, qui passe `EN_COURS` (`technicien/taches.php` ou `technicien/interventions.php`).
4. Le technicien ou le garage enregistre la **réparation** (`technicien/reparations.php`, `garage/reparations.php`). Le rapport est créé en `TERMINEE` et l'intervention passe en `TERMINEE`, dans une même transaction.

### 6.2 Résolution automatique des anomalies

Quand une réparation est enregistrée (point 4 ci-dessus), les anomalies de **la même intervention** qui sont au statut `NOUVELLE` ou `EN_COURS` passent à `TRAITEE`, avec `dateResolution = NOW()`. Cela se fait dans la même transaction que la réparation :

```sql
UPDATE anomalie SET statut = 'TRAITEE', dateResolution = NOW()
WHERE idIntervention = ? AND statut IN ('NOUVELLE', 'EN_COURS')
```

Seules les anomalies liées à l'intervention par `anomalie.idIntervention` sont concernées. Cette colonne est ajoutée par `scripts/migrate_anomalie_intervention.php`.

### 6.3 Paiement Mobile Money (CamPay)

Un client peut payer depuis `client/reparations.php` une réparation `TERMINEE` dont le coût est supérieur à 0. Les opérateurs sont MTN Mobile Money et Orange Money, en XAF.

1. **Lancement (collect)** : `ajax/campay_collect.php` (client connecté, CSRF), puis `paymentStart()` (`includes/payments.php`).
   - Le numéro est normalisé au format `2376XXXXXXXX` (`campayNormalizePhone()`).
   - Une ligne `paiement` est créée en `EN_ATTENTE`, avec une référence `SAT-<idReparation>-<aléatoire>`. On refuse si la réparation est déjà payée, ou si une tentative de moins de 15 minutes est encore en cours.
   - CamPay envoie une demande de validation sur le téléphone du client. En cas d'échec, le paiement passe `ECHOUE`.
2. **Suivi du statut** : la page appelle `ajax/campay_status.php`, et `paymentRefresh()` interroge CamPay tant que le paiement est `EN_ATTENTE`.
3. **Webhook** : `webhooks/campay.php`, à déclarer chez CamPay sous la forme `https://<votre-domaine>/HCH/webhooks/campay.php`.
   - La signature JWT est contrôlée avec `CAMPAY_WEBHOOK_KEY`.
   - Le statut appliqué est **toujours redemandé à l'API CamPay** : on ne fait pas confiance aux paramètres reçus.
   - En local, le webhook n'est pas joignable depuis Internet. Le suivi par la page suffit alors.
4. **Statuts** : `EN_ATTENTE`, puis `PAYE` (définitif, notification au client, entrée au journal) ou `ECHOUE`. Un montant confirmé différent du montant attendu donne `ECHOUE`, avec un message demandant une vérification manuelle.
5. Les paiements sont visibles dans `admin/transactions.php`.

**Mode démo plafonné :** la démo CamPay refuse les montants supérieurs à 25 XAF. En démo, le coût réel reste le montant dû, affiché et enregistré dans `paiement.montant`, mais seul `min(coût, CAMPAY_DEMO_MAX_AMOUNT)` est débité (`campayChargedAmount()`). En production (`www.campay.net`), le montant débité est toujours le montant réel.

Si `scripts/migrate_structure.php --apply` n'a pas été lancé, les colonnes CamPay de `paiement` manquent (`paymentsReady()`), et le paiement en ligne reste désactivé.

### 6.4 Règles de mot de passe

Tout nouveau mot de passe doit contenir **au moins 8 caractères, une minuscule, une majuscule, un chiffre et un caractère spécial**.

- **Côté serveur, ce qui fait foi** : `includes/password_policy.php` (`PASSWORD_MIN_LENGTH`, `passwordPolicyMissing()`, `passwordPolicyError()`). Ces fonctions sont appelées dans `auth/register.php`, `profile.php`, `admin/garages.php`, `admin/techniciens.php`, `garage/techniciens.php` et `scripts/set_password.php`.
- **Côté navigateur, pour le confort** : tout `<input>` portant l'attribut `data-password-policy` reçoit une liste des règles, cochée en direct (✓ / ✕), gérée dans `assets/js/main.js` avec le style `.pw-rules` de `assets/css/style.css`. Tant qu'une règle manque, le formulaire est bloqué. Un champ facultatif laissé vide reste valide, par exemple sur le profil quand on ne change pas son mot de passe.
- Les deux listes de règles doivent **rester identiques**. Si vous en modifiez une, modifiez l'autre, ainsi que `tests/Unit/PasswordPolicyTest.php`.

### 6.5 Filtres de saisie

L'attribut `data-only` d'un champ filtre la frappe et le texte collé (`assets/js/main.js`). Chaque filtre est doublé d'une validation serveur dans `config/config.php`, qui seule fait foi :

| `data-only` | Accepté | Validation serveur |
|---|---|---|
| `digits` | chiffres uniquement ; sur un `type="number"`, `e E + - . ,` sont bloqués | `validateDigitsOnly()` |
| `letters` | lettres (accents compris), espace, tiret, apostrophe (« Jean-Pierre », « O'Brien ») | `validateLettersOnly()` |
| `plate` | lettres, chiffres, espace, tiret ; **mis en majuscules** pendant la saisie | `validatePlate()` (2 à 15 caractères, commence et finit par une lettre ou un chiffre) et `normalizePlate()` avant l'enregistrement (majuscules, espaces multiples réduits) |
| `model` | lettres (accents compris), chiffres, espace et `- . + ! /` (C-HR, ID.4, Ka+, up!) | `validateModel()` (commence par une lettre ou un chiffre, 50 caractères au plus) |

`validatePlate`, `normalizePlate` et `validateModel` sont utilisées dans `auth/register.php` et `client/vehicles.php`. Seules les nouvelles saisies passent en majuscules. La détection des doublons reste fiable tant que la colonne utilise une collation insensible à la casse (`_ci`, comme `utf8mb4_unicode_ci` dans l'ancien dump) : MySQL compare alors les immatriculations sans tenir compte des majuscules. Tests : `tests/Unit/VehicleFieldsTest.php`.

### 6.6 Aide à la saisie

- **Champs obligatoires** : ils sont marqués d'une étoile rouge sur les formulaires d'authentification : `<span class="required-star">*</span>` avec la légende « Champs obligatoires » dans `auth/register.php`, et `<span class="authv2-required">*</span>` (sans légende) dans `auth/login.php`. Dans les espaces par rôle, le caractère obligatoire repose sur l'attribut HTML `required`.
- **Exemples** : les champs affichent un exemple réaliste en placeholder, en gris (« Ex. Mbarga », « Ex. Garage Central Yaoundé »…).

### 6.7 Assistant IA

Pages `client/assistant.php` et `admin/assistant.php`, appelées par `ajax/assistant_chat.php`. Le modèle ne reçoit que les données du périmètre de l'utilisateur : véhicules, anomalies et interventions pour un client, statistiques globales pour un administrateur. L'historique (10 messages) est gardé en session, pas en base. La limite est de **20 messages par tranche de 10 minutes** par utilisateur, et de 1 000 caractères par message.

### 6.8 Journal d'activité

`includes/activity_log.php` écrit dans la table `journalactivites` les actions importantes (interventions, réparations, anomalies, validations, paiements). Chaque rôle a une page *Journal d'activité* limitée à son périmètre.

---

## 7. Sécurité

- **CSRF** : jeton de session (`generateCSRFToken()` et `verifyCSRFToken()`). Les endpoints AJAX l'acceptent en champ `csrf_token` ou en en-tête `X-CSRF-Token`, que `main.js` ajoute automatiquement (`verifyRequestCSRF()`).
- **Sessions** : cookie `HCHSESSID` limité au chemin de l'application, `HttpOnly`, `SameSite=Lax`, `Secure` en HTTPS. L'identifiant est régénéré à la connexion, et le compte est revérifié à chaque requête (`enforceActiveSession()`).
- **Contrôle d'accès** : `requireAuth()` et `requireRole()` pour les pages, `requireJsonAuth()` pour les endpoints JSON (réponses 401/403).
- **Limitation des tentatives de connexion** (`includes/login_throttle.php`) : au plus 5 échecs par email et 20 par adresse IP sur 15 minutes. Les compteurs sont stockés dans des fichiers et non en session.
- **Mots de passe** : `password_hash()` (`PASSWORD_DEFAULT`) et règles de [§6.4](#64-règles-de-mot-de-passe). `--lock` rend un compte inutilisable.
- **Uploads** : type MIME réel vérifié avec `finfo` (PDF, JPEG, PNG), 5 Mo maximum. `uploads/` interdit tout accès direct (`.htaccess`), et les fichiers sont servis par `ajax/download_*.php` après contrôle de la session et des droits.
- **Affichage** : `h()` échappe toute sortie HTML. Requêtes SQL préparées.
- **En-têtes et fichiers techniques** (`.htaccess` racine) : `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, pas de cache sur les pages PHP. Les `.sql`, `.md`, `.lock`, `.phar`, `composer.json`, `composer-setup.php`, `phpunit.xml`, `.gitignore` et `tmp_*.txt` ne sont pas servis. `config/` et `scripts/` sont entièrement interdits au web, et les scripts refusent aussi une exécution hors CLI (sauf les quatre `migrate_*` ponctuels, protégés uniquement par le `.htaccess`).
- **Erreurs** : jamais affichées au visiteur, sauf avec `HCH_DEBUG=1`.
- **Secrets** : les identifiants de base, clés CamPay et clé OpenRouter se mettent dans `config/local.php` (ignoré par Git) ou dans des variables d'environnement, **jamais dans le code**.

### Points d'attention connus

- **API héritée `api/` exposée** : elle reste joignable depuis le web (aucun `.htaccess` ne la bloque) et n'est utilisée par rien. **Bloquez-la** (`Require all denied` dans `api/.htaccess`) **ou supprimez le dossier** avant toute mise en ligne.
- **Dumps SQL versionnés** : `charles (1).sql` est suivi par Git et contient des données, dont des adresses email. Ne versionnez **aucun dump contenant des données réelles**. Exportez la structure seule et envisagez de retirer ce fichier de l'historique.
- `composer.phar` est suivi par Git alors que `.gitignore` l'exclut.
- `includes/` n'a pas de `.htaccess`. Ses fichiers ne font que définir des fonctions, mais un blocage explicite serait plus sûr.
- Le webhook CamPay doit être servi en **HTTPS** en production, et `CAMPAY_SIMULATION` doit y valoir `false`.

---

## 8. Tests

Configuration : `phpunit.xml`, avec l'amorçage `tests/bootstrap.php`. Les suites sont `Unit`, `Integration` et `Functional`, et les scripts sont définis dans `composer.json`.

```bash
composer install

composer test                    # toutes les suites
composer test:unit               # tests/Unit        (aucune dépendance externe)
composer test:integration        # tests/Integration (base de données)
composer test:functional         # tests/Functional  (serveur HTTP local)
```

Sans Composer global, utilisez `php composer.phar test`, ou lancez `php vendor/bin/phpunit -c phpunit.xml --testsuite Unit`.

- **Unit** (`AiTest`, `CampayTest`, `CsrfTest`, `EscapeTest`, `LoginThrottleTest`, `PasswordPolicyTest`, `UtilTest`, `VehicleFieldsTest`) : 76 tests au 30/09/2026.
- **Integration** : ces tests se connectent à **la vraie base configurée** (`config/local.php` ou `HCH_DB_*`). `RolesTest` y **insère puis supprime** des utilisateurs temporaires (identifiants 999001 à 999005, emails `@example.invalid`). `SchemaTest` vérifie les tables et colonnes attendues, et `DatabaseConnectionTest` la connexion elle-même. Lancez-les sur une base de développement, **jamais sur la production**. Si la base n'est pas joignable, ils sont ignorés.
- **Functional** : `HomepageTest` interroge `HCH_BASE_URL` (défaut `http://localhost/HCH/`, modifiable dans `phpunit.xml`) et est ignoré si Apache ne répond pas.

---

## 9. Dépannage

| Symptôme | Piste |
|---|---|
| « Service temporairement indisponible » | Connexion MySQL impossible. Vérifiez que MySQL est démarré et que `config/local.php` est correct (nom de base, mot de passe). Le détail est dans `C:\xampp\apache\logs\error.log` ou dans le log PHP. |
| Erreurs SQL « Unknown column » ou « Table doesn't exist » | Schéma incomplet. Lancez `php scripts/migrate_structure.php` pour voir ce qui manque, puis ajoutez `--apply`, et lancez les `migrate_*` ponctuels. |
| Page blanche ou erreur 500 sans détail | Mettez `HCH_DEBUG=1` en local (par exemple `SetEnv HCH_DEBUG 1` dans la config Apache) ou consultez les logs. |
| Déconnexions inattendues, connexion « fantôme » | Utilisez toujours la même adresse (`localhost` **ou** `127.0.0.1`), et effacez les cookies du site. |
| « Session expirée, merci de recharger la page » | Jeton CSRF absent ou périmé. Rechargez la page. |
| Connexion bloquée « trop de tentatives » | Attendez 15 minutes, ou videz le dossier `smartautotrack_login_throttle` du répertoire temporaire (ou celui de `HCH_THROTTLE_DIR`). |
| Technicien ou garage ne peut pas se connecter | Son statut n'est pas `VALIDE`. Validez-le (ou réactivez-le) depuis l'espace administrateur. Un garage peut aussi réactiver un technicien de son équipe qu'il a suspendu. |
| Bouton de paiement absent | La réparation n'est pas `TERMINEE`, son coût vaut 0, ou la table `paiement` n'est pas prête (`migrate_structure.php --apply`). |
| Paiement refusé en démo | Vérifiez `CAMPAY_DEMO_MAX_AMOUNT` (25 ou moins) et les identifiants CamPay. Pour travailler hors ligne, utilisez `CAMPAY_SIMULATION=true`. |
| Assistant « indisponible » | `OPENROUTER_API_KEY` est absente ou invalide, ou aucun des modèles (`OPENROUTER_MODEL` puis `OPENROUTER_FALLBACK_MODELS`) n'a répondu : modèles gratuits saturés ou retirés, ou plus de crédits (HTTP 402) pour un modèle payant. Le détail exact s'affiche à l'administrateur dans le chat. |
| Google : « Erreur 400 : redirect_uri_mismatch » | L'URL de retour envoyée à Google n'est pas déclarée dans Google Cloud Console (Identifiants → client OAuth → « URI de redirection autorisés »). Ajoutez-y exactement `SITE_URL` + `auth/google_callback.php` (ex. `http://localhost/HCH/auth/google_callback.php`), même schéma, même hôte (`localhost` ≠ `127.0.0.1`), même port, même dossier. Ou fixez-la avec `GOOGLE_REDIRECT_URI`. |
| Envoi de documents en échec | Seuls PDF, JPG et PNG de 5 Mo au plus sont acceptés. Vérifiez que `uploads/` est accessible en écriture et que `upload_max_filesize` et `post_max_size` sont suffisants dans `php.ini`. |
| Un changement de CSS ou JS ne s'affiche pas | Rechargement forcé (Ctrl+F5). Les fichiers statiques sont revalidés par ETag. |
| `php` introuvable | Utilisez `C:\xampp\php\php.exe`, ou ajoutez `C:\xampp\php` au PATH. |

---

## 10. Licence

Aucune licence n'est précisée pour ce projet, et le dépôt ne contient pas de fichier `LICENSE`. Pour en définir une, ajoutez un fichier `LICENSE` à la racine (par exemple MIT) et mentionnez-la ici.
