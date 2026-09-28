-- ============================================================================
-- OBSOLÈTE depuis le 23/09/2026 : la base `charles` a été remplacée par un
-- nouveau schéma (utilisateur/client/technicien/vehicule/intervention/...,
-- camelCase), conçu et créé indépendamment de ce fichier. Ce schéma-ci
-- (users/vehicles/interventions, snake_case) N'EST PLUS CELUI EN PRODUCTION —
-- ne pas l'utiliser pour créer ou mettre à jour la base actuelle.
-- Conservé uniquement à titre d'historique.
-- ============================================================================
--
-- SmartAutoTrack - SCHÉMA FINAL (site PHP principal) [ANCIEN, voir note ci-dessus]
-- ============================================================================
-- Source de vérité unique pour la base du site (par défaut : "Charles").
-- Remplace : db_init.sql, update_database.sql, update_reparations_table.sql,
--            fix_database_simple.sql (conservés à titre d'historique, obsolètes).
-- NB : sql/init.sql est le schéma de l'ANCIENNE API (base "smartautotrack",
--      colonnes id_user/id_vehicle...) : c'est un autre schéma, non touché ici.
--
-- Installation neuve :
--   CREATE DATABASE IF NOT EXISTS Charles CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
--   mysql -u root -p Charles < sql/schema.sql
--   mysql -u root -p Charles < sql/seed_demo.sql        (optionnel : données de démonstration)
--
-- Base existante (quel que soit son état) :
--   php scripts/migrate_schema.php            (simulation, ne modifie rien)
--   php scripts/migrate_schema.php --apply    (applique)
--
-- Ce fichier est idempotent : CREATE TABLE IF NOT EXISTS, aucune donnée supprimée.
-- ============================================================================

SET NAMES utf8mb4;

-- Utilisateurs (clients, techniciens, admins)
CREATE TABLE IF NOT EXISTS users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    nom VARCHAR(100) NOT NULL,
    prenom VARCHAR(100) NOT NULL,
    email VARCHAR(255) UNIQUE NOT NULL,
    telephone VARCHAR(20),
    role ENUM('client', 'technicien', 'admin') NOT NULL,
    mot_de_passe VARCHAR(255) NOT NULL,
    competences TEXT,
    experience TEXT,
    statut ENUM('actif', 'pending', 'rejete') DEFAULT 'pending',
    statut_validation ENUM('valide', 'en_attente', 'rejete') DEFAULT 'en_attente',
    theme_preference VARCHAR(20) DEFAULT 'light',          -- ajax/save_theme.php, */settings.php
    photo_profil VARCHAR(255),                             -- réservé (non utilisé par le code actuel)
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_users_theme (theme_preference)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Véhicules
CREATE TABLE IF NOT EXISTS vehicles (
    id INT PRIMARY KEY AUTO_INCREMENT,
    client_id INT NOT NULL,
    marque VARCHAR(100) NOT NULL,
    modele VARCHAR(100) NOT NULL,
    immatriculation VARCHAR(20) UNIQUE NOT NULL,
    annee INT,
    couleur VARCHAR(50),
    kilometrage INT DEFAULT 0,
    statut ENUM('actif', 'en_panne', 'en_entretien', 'hors_service') DEFAULT 'actif',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (client_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Anomalies détectées
CREATE TABLE IF NOT EXISTS anomalies (
    id INT PRIMARY KEY AUTO_INCREMENT,
    vehicle_id INT NOT NULL,
    type VARCHAR(100) NOT NULL,
    description TEXT NOT NULL,
    niveau ENUM('faible', 'moyen', 'critique') NOT NULL,
    statut ENUM('detectee', 'en_cours', 'resolue') DEFAULT 'detectee',
    date_detection TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    date_resolution TIMESTAMP NULL,
    FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Interventions planifiées par l'admin
CREATE TABLE IF NOT EXISTS interventions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    vehicle_id INT NOT NULL,
    technicien_id INT NOT NULL,
    admin_id INT NOT NULL,
    type_intervention VARCHAR(100) NOT NULL,
    description TEXT,
    priorite ENUM('basse', 'moyenne', 'haute') NOT NULL DEFAULT 'moyenne',  -- filtre technicien/tasks.php
    date_planifiee DATETIME NOT NULL,
    statut ENUM('planifiee', 'en_cours', 'terminee', 'annulee') DEFAULT 'planifiee',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE,
    FOREIGN KEY (technicien_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (admin_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Réparations = rapports d'intervention rédigés par les techniciens
CREATE TABLE IF NOT EXISTS reparations (
    id INT PRIMARY KEY AUTO_INCREMENT,
    vehicle_id INT NOT NULL,
    technicien_id INT NOT NULL,
    intervention_id INT NULL,
    anomalie_id INT NULL,
    description TEXT NOT NULL,
    titre VARCHAR(255),
    diagnostic TEXT,
    travaux_effectues TEXT,
    pieces_utilisees TEXT,
    recommandations TEXT,
    cout DECIMAL(10,2) DEFAULT 0,
    duree_intervention DECIMAL(5,2) DEFAULT 0,             -- en heures
    -- Valeurs utilisées par le code : 'en_attente' (technicien/reports.php à la création),
    -- 'terminee' (technicien/new_report.php), 'valide' (technicien/*) et 'validee' (client/*).
    -- 'valide' et 'validee' coexistent car le code PHP emploie les deux orthographes.
    statut ENUM('planifiee', 'en_cours', 'terminee', 'validee', 'valide', 'en_attente') DEFAULT 'planifiee',
    date_debut TIMESTAMP NULL,
    date_fin TIMESTAMP NULL,
    rapport TEXT,                                          -- hérité, non utilisé par le site PHP (voir SCHEMA_NOTES)
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE,
    FOREIGN KEY (technicien_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_reparations_intervention FOREIGN KEY (intervention_id) REFERENCES interventions(id) ON DELETE SET NULL,
    FOREIGN KEY (anomalie_id) REFERENCES anomalies(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Messagerie interne
CREATE TABLE IF NOT EXISTS messages (
    id INT PRIMARY KEY AUTO_INCREMENT,
    expediteur_id INT NOT NULL,
    destinataire_id INT NOT NULL,
    sujet VARCHAR(255) NOT NULL,
    contenu TEXT NOT NULL,
    lu ENUM('oui', 'non') DEFAULT 'non',
    date_envoi TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (expediteur_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (destinataire_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Notifications
CREATE TABLE IF NOT EXISTS notifications (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    type ENUM('anomalie', 'intervention', 'message', 'rapport', 'validation') NOT NULL,
    titre VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    lu ENUM('oui', 'non') DEFAULT 'non',
    date_creation TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Documents des techniciens (diplômes, certificats...) ; fichiers servis par ajax/download_document.php
CREATE TABLE IF NOT EXISTS technician_documents (
    id INT PRIMARY KEY AUTO_INCREMENT,
    technicien_id INT NOT NULL,
    type_document ENUM('diplome', 'certificat', 'photo', 'autre') NOT NULL,
    nom_fichier VARCHAR(255) NOT NULL,
    chemin_fichier VARCHAR(500) NOT NULL,
    taille_fichier INT,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (technicien_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
