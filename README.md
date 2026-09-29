# SmartAutoTrack

Application web PHP de suivi automobile : véhicules, anomalies, interventions,
réparations et messagerie, avec quatre espaces selon le rôle de l'utilisateur :
`admin/`, `client/`, `garage/` et `technicien/`.

## Prérequis

- XAMPP (Apache + MySQL/MariaDB), PHP >= 7.4 avec les extensions `pdo_mysql` et `fileinfo`
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

## Tests

```
php composer.phar install
php composer.phar test
```

Les tests d'intégration sont ignorés si la base n'est pas joignable.
