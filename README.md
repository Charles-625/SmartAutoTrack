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
