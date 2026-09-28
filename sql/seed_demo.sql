-- ============================================================================
-- OBSOLÈTE depuis le 23/09/2026 : conçu pour sql/schema.sql, lui-même remplacé
-- par le nouveau schéma de production. Ne pas exécuter. Conservé à titre
-- d'historique. Pour des comptes de démonstration sur le schéma actuel, voir
-- scripts/migrate_legacy_data.php ou créer des comptes via auth/register.php.
-- ============================================================================
--
-- SmartAutoTrack - Données de démonstration (OPTIONNEL, base neuve uniquement) [ANCIEN]
-- À exécuter après sql/schema.sql, sur une base VIDE.
--
-- Tous les comptes sont VERROUILLÉS (aucun mot de passe valide). Avant de vous
-- connecter, définissez un mot de passe fort :
--   php scripts/set_password.php admin@smartautotrack.com
-- ============================================================================

SET NAMES utf8mb4;

INSERT INTO users (nom, prenom, email, telephone, role, mot_de_passe, statut, statut_validation) VALUES
('Admin', 'SmartAutoTrack', 'admin@smartautotrack.com', '0123456789', 'admin', '!COMPTE_VERROUILLE', 'actif', 'valide');

INSERT INTO users (nom, prenom, email, telephone, role, mot_de_passe, competences, experience, statut, statut_validation) VALUES
('Martin', 'Pierre', 'pierre.martin@email.com', '0145678901', 'technicien', '!COMPTE_VERROUILLE', 'Mécanique générale, Électronique automobile, Diagnostic OBD', '5 ans d''expérience chez Renault', 'actif', 'valide'),
('Dubois', 'Marie', 'marie.dubois@email.com', '0167890123', 'technicien', '!COMPTE_VERROUILLE', 'Carrosserie, Peinture, Réparation plastique', '3 ans d''expérience', 'pending', 'en_attente');

INSERT INTO users (nom, prenom, email, telephone, role, mot_de_passe, statut, statut_validation) VALUES
('Durand', 'Jean', 'jean.durand@email.com', '0189012345', 'client', '!COMPTE_VERROUILLE', 'actif', 'valide'),
('Moreau', 'Sophie', 'sophie.moreau@email.com', '0201234567', 'client', '!COMPTE_VERROUILLE', 'actif', 'valide');

-- Les identifiants ci-dessous supposent une base vide : admin=1, techniciens=2-3, clients=4-5.
INSERT INTO vehicles (client_id, marque, modele, immatriculation, annee, couleur, kilometrage) VALUES
(4, 'Renault', 'Clio V', 'AB-123-CD', 2022, 'Blanc', 25000),
(5, 'Peugeot', '308', 'EF-456-GH', 2021, 'Gris', 32000);

INSERT INTO anomalies (vehicle_id, type, description, niveau, statut) VALUES
(1, 'Batterie', 'Niveau de charge de la batterie faible (35%)', 'moyen', 'detectee'),
(2, 'Frein', 'Usure importante des plaquettes de frein avant', 'critique', 'detectee');

INSERT INTO interventions (vehicle_id, technicien_id, admin_id, type_intervention, description, priorite, date_planifiee) VALUES
(1, 2, 1, 'Maintenance préventive', 'Remplacement batterie + contrôle général', 'moyenne', '2024-02-15 09:00:00'),
(2, 2, 1, 'Réparation urgente', 'Remplacement plaquettes de frein', 'haute', '2024-02-14 14:30:00');

INSERT INTO reparations (vehicle_id, technicien_id, intervention_id, anomalie_id, titre, description, cout, statut) VALUES
(1, 2, 1, 1, 'Remplacement de la batterie', 'Remplacement de la batterie', 180.00, 'planifiee'),
(2, 2, 2, 2, 'Remplacement des plaquettes de frein', 'Remplacement des plaquettes de frein avant', 120.00, 'planifiee');

INSERT INTO notifications (user_id, type, titre, message) VALUES
(4, 'anomalie', 'Nouvelle anomalie détectée', 'Votre véhicule Renault Clio V présente un problème de batterie'),
(5, 'anomalie', 'Nouvelle anomalie détectée', 'Votre véhicule Peugeot 308 présente un problème de frein'),
(2, 'intervention', 'Nouvelle intervention assignée', 'Vous avez été assigné à une intervention sur Renault Clio V'),
(2, 'intervention', 'Nouvelle intervention assignée', 'Vous avez été assigné à une intervention sur Peugeot 308');
