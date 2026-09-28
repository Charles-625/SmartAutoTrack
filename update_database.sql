-- ============================================================================
-- OBSOLÈTE : conservé uniquement à titre d'historique. NE PLUS EXÉCUTER.
-- Source de vérité : sql/schema.sql (nouvelle base) ou php scripts/migrate_schema.php (base existante).
-- ============================================================================
-- Script de mise à jour de la base de données pour ajouter la gestion des thèmes
-- À exécuter dans phpMyAdmin ou via la ligne de commande MySQL

USE Charles;

-- Ajouter la colonne theme_preference à la table users
ALTER TABLE users 
ADD COLUMN theme_preference VARCHAR(20) DEFAULT 'light' AFTER statut_validation;

-- Mettre à jour les utilisateurs existants avec le thème par défaut
UPDATE users SET theme_preference = 'light' WHERE theme_preference IS NULL;

-- Ajouter un index pour optimiser les requêtes
CREATE INDEX idx_users_theme ON users(theme_preference);

-- Vérifier la structure de la table
DESCRIBE users;
