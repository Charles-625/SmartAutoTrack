-- ============================================================================
-- OBSOLÈTE : conservé uniquement à titre d'historique. NE PLUS EXÉCUTER.
-- Source de vérité : sql/schema.sql (nouvelle base) ou php scripts/migrate_schema.php (base existante).
-- ============================================================================
-- Script SQL simple et compatible pour corriger la table reparations
-- Exécuter ligne par ligne dans phpMyAdmin si nécessaire

USE Charles;

-- Ajouter la colonne titre
ALTER TABLE reparations ADD COLUMN titre VARCHAR(255) AFTER description;

-- Ajouter la colonne diagnostic
ALTER TABLE reparations ADD COLUMN diagnostic TEXT AFTER titre;

-- Ajouter la colonne travaux_effectues
ALTER TABLE reparations ADD COLUMN travaux_effectues TEXT AFTER diagnostic;

-- Ajouter la colonne pieces_utilisees
ALTER TABLE reparations ADD COLUMN pieces_utilisees TEXT AFTER travaux_effectues;

-- Ajouter la colonne recommandations
ALTER TABLE reparations ADD COLUMN recommandations TEXT AFTER pieces_utilisees;

-- Ajouter la colonne duree_intervention
ALTER TABLE reparations ADD COLUMN duree_intervention DECIMAL(5,2) DEFAULT 0 AFTER cout;

-- Ajouter la colonne updated_at
ALTER TABLE reparations ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at;

-- Ajouter la colonne intervention_id
ALTER TABLE reparations ADD COLUMN intervention_id INT AFTER technicien_id;

-- Ajouter la clé étrangère (peut échouer si elle existe déjà, c'est normal)
ALTER TABLE reparations ADD CONSTRAINT fk_reparations_intervention FOREIGN KEY (intervention_id) REFERENCES interventions(id) ON DELETE SET NULL;

-- Mettre à jour le statut pour inclure toutes les valeurs
ALTER TABLE reparations MODIFY COLUMN statut ENUM('planifiee', 'en_cours', 'terminee', 'validee', 'valide') DEFAULT 'planifiee';
