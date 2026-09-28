-- ============================================================================
-- OBSOLÈTE : conservé uniquement à titre d'historique. NE PLUS EXÉCUTER.
-- Source de vérité : sql/schema.sql (nouvelle base) ou php scripts/migrate_schema.php (base existante).
-- ============================================================================
-- Script pour mettre à jour la table reparations avec les colonnes manquantes
-- Exécuter ce script dans phpMyAdmin ou via MySQL

USE Charles;

-- Ajouter les colonnes manquantes à la table reparations (une par une pour éviter les erreurs)
ALTER TABLE reparations ADD COLUMN titre VARCHAR(255) AFTER description;
ALTER TABLE reparations ADD COLUMN diagnostic TEXT AFTER titre;
ALTER TABLE reparations ADD COLUMN travaux_effectues TEXT AFTER diagnostic;
ALTER TABLE reparations ADD COLUMN pieces_utilisees TEXT AFTER travaux_effectues;
ALTER TABLE reparations ADD COLUMN recommandations TEXT AFTER pieces_utilisees;
ALTER TABLE reparations ADD COLUMN duree_intervention DECIMAL(5,2) DEFAULT 0 AFTER cout;
ALTER TABLE reparations ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at;
ALTER TABLE reparations ADD COLUMN intervention_id INT AFTER technicien_id;

-- Ajouter la clé étrangère pour intervention_id
ALTER TABLE reparations ADD CONSTRAINT fk_reparations_intervention FOREIGN KEY (intervention_id) REFERENCES interventions(id) ON DELETE SET NULL;

-- Mettre à jour le statut pour correspondre aux valeurs utilisées
ALTER TABLE reparations 
MODIFY COLUMN statut ENUM('planifiee', 'en_cours', 'terminee', 'validee', 'valide') DEFAULT 'planifiee';

-- ATTENTION : ce bloc fabriquait de fausses données (durées aléatoires via RAND()) ; ne pas reproduire.
-- Mettre à jour quelques valeurs de test si nécessaire
UPDATE reparations 
SET titre = CONCAT('Réparation ', id),
    diagnostic = 'Diagnostic automatique généré',
    travaux_effectues = 'Travaux effectués selon le diagnostic',
    pieces_utilisees = 'Pièces standard',
    recommandations = 'Contrôle périodique recommandé',
    duree_intervention = FLOOR(RAND() * 8) + 1
WHERE titre IS NULL;

-- Afficher la structure mise à jour
DESCRIBE reparations;
