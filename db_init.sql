-- ============================================================================
-- OBSOLÈTE : conservé uniquement à titre d'historique. NE PLUS EXÉCUTER.
-- Source de vérité : sql/schema.sql (nouvelle base) ou php scripts/migrate_schema.php (base existante).
-- ============================================================================
-- SmartAutoTrack - Base de données MySQL
-- Les comptes de démonstration ci-dessous sont VERROUILLÉS (aucun mot de passe valide).
-- Définir un mot de passe fort avec : php scripts/set_password.php <email>

CREATE DATABASE IF NOT EXISTS Charles CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE Charles;

-- Table des utilisateurs (clients, techniciens, admin)
CREATE TABLE users (
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
    photo_profil VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Table des véhicules
CREATE TABLE vehicles (
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
);

-- Table des anomalies détectées
CREATE TABLE anomalies (
    id INT PRIMARY KEY AUTO_INCREMENT,
    vehicle_id INT NOT NULL,
    type VARCHAR(100) NOT NULL,
    description TEXT NOT NULL,
    niveau ENUM('faible', 'moyen', 'critique') NOT NULL,
    statut ENUM('detectee', 'en_cours', 'resolue') DEFAULT 'detectee',
    date_detection TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    date_resolution TIMESTAMP NULL,
    FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE
);

-- Table des réparations
CREATE TABLE reparations (
    id INT PRIMARY KEY AUTO_INCREMENT,
    vehicle_id INT NOT NULL,
    technicien_id INT NOT NULL,
    anomalie_id INT,
    description TEXT NOT NULL,
    cout DECIMAL(10,2) DEFAULT 0,
    statut ENUM('planifiee', 'en_cours', 'terminee', 'validee') DEFAULT 'planifiee',
    date_debut TIMESTAMP NULL,
    date_fin TIMESTAMP NULL,
    rapport TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE,
    FOREIGN KEY (technicien_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (anomalie_id) REFERENCES anomalies(id) ON DELETE SET NULL
);

-- Table des interventions (planifiées par l'admin)
CREATE TABLE interventions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    vehicle_id INT NOT NULL,
    technicien_id INT NOT NULL,
    admin_id INT NOT NULL,
    type_intervention VARCHAR(100) NOT NULL,
    description TEXT,
    date_planifiee DATETIME NOT NULL,
    statut ENUM('planifiee', 'en_cours', 'terminee', 'annulee') DEFAULT 'planifiee',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE,
    FOREIGN KEY (technicien_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (admin_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Table des messages (messagerie interne)
CREATE TABLE messages (
    id INT PRIMARY KEY AUTO_INCREMENT,
    expediteur_id INT NOT NULL,
    destinataire_id INT NOT NULL,
    sujet VARCHAR(255) NOT NULL,
    contenu TEXT NOT NULL,
    lu ENUM('oui', 'non') DEFAULT 'non',
    date_envoi TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (expediteur_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (destinataire_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Table des notifications
CREATE TABLE notifications (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    type ENUM('anomalie', 'intervention', 'message', 'rapport', 'validation') NOT NULL,
    titre VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    lu ENUM('oui', 'non') DEFAULT 'non',
    date_creation TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Table des documents des techniciens
CREATE TABLE technician_documents (
    id INT PRIMARY KEY AUTO_INCREMENT,
    technicien_id INT NOT NULL,
    type_document ENUM('diplome', 'certificat', 'photo', 'autre') NOT NULL,
    nom_fichier VARCHAR(255) NOT NULL,
    chemin_fichier VARCHAR(500) NOT NULL,
    taille_fichier INT,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (technicien_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Données de test (seed)
INSERT INTO users (nom, prenom, email, telephone, role, mot_de_passe, statut, statut_validation) VALUES
('Admin', 'SmartAutoTrack', 'admin@smartautotrack.com', '0123456789', 'admin', '!COMPTE_VERROUILLE', 'actif', 'valide');

INSERT INTO users (nom, prenom, email, telephone, role, mot_de_passe, competences, experience, statut, statut_validation) VALUES
('Martin', 'Pierre', 'pierre.martin@email.com', '0145678901', 'technicien', '!COMPTE_VERROUILLE', 'Mécanique générale, Électronique automobile, Diagnostic OBD', '5 ans d\'expérience chez Renault', 'actif', 'valide'),
('Dubois', 'Marie', 'marie.dubois@email.com', '0167890123', 'technicien', '!COMPTE_VERROUILLE', 'Carrosserie, Peinture, Réparation plastique', '3 ans d\'expérience', 'pending', 'en_attente');

INSERT INTO users (nom, prenom, email, telephone, role, mot_de_passe, statut, statut_validation) VALUES
('Durand', 'Jean', 'jean.durand@email.com', '0189012345', 'client', '!COMPTE_VERROUILLE', 'actif', 'valide'),
('Moreau', 'Sophie', 'sophie.moreau@email.com', '0201234567', 'client', '!COMPTE_VERROUILLE', 'actif', 'valide');

INSERT INTO vehicles (client_id, marque, modele, immatriculation, annee, couleur, kilometrage) VALUES
(4, 'Renault', 'Clio V', 'AB-123-CD', 2022, 'Blanc', 25000),
(5, 'Peugeot', '308', 'EF-456-GH', 2021, 'Gris', 32000);

INSERT INTO anomalies (vehicle_id, type, description, niveau, statut) VALUES
(1, 'Batterie', 'Niveau de charge de la batterie faible (35%)', 'moyen', 'detectee'),
(2, 'Frein', 'Usure importante des plaquettes de frein avant', 'critique', 'detectee');

INSERT INTO reparations (vehicle_id, technicien_id, anomalie_id, description, cout, statut) VALUES
(1, 2, 1, 'Remplacement de la batterie', 180.00, 'planifiee'),
(2, 2, 2, 'Remplacement des plaquettes de frein avant', 120.00, 'planifiee');

INSERT INTO interventions (vehicle_id, technicien_id, admin_id, type_intervention, description, date_planifiee) VALUES
(1, 2, 1, 'Maintenance préventive', 'Remplacement batterie + contrôle général', '2024-02-15 09:00:00'),
(2, 2, 1, 'Réparation urgente', 'Remplacement plaquettes de frein', '2024-02-14 14:30:00');

INSERT INTO notifications (user_id, type, titre, message) VALUES
(4, 'anomalie', 'Nouvelle anomalie détectée', 'Votre véhicule Renault Clio V présente un problème de batterie'),
(5, 'anomalie', 'Nouvelle anomalie détectée', 'Votre véhicule Peugeot 308 présente un problème de frein'),
(2, 'intervention', 'Nouvelle intervention assignée', 'Vous avez été assigné à une intervention sur Renault Clio V'),
(2, 'intervention', 'Nouvelle intervention assignée', 'Vous avez été assigné à une intervention sur Peugeot 308');
