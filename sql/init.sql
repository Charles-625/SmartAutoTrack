-- SmartAutoTrack - Script d'initialisation MySQL
-- Prérequis: MySQL 5.7+/8.0+, sql_mode compatible pour ENUM par défaut

CREATE DATABASE IF NOT EXISTS smartautotrack CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE smartautotrack;

-- Tables
DROP TABLE IF EXISTS interventions;
DROP TABLE IF EXISTS reparations;
DROP TABLE IF EXISTS anomalies;
DROP TABLE IF EXISTS vehicles;
DROP TABLE IF EXISTS push_tokens;
DROP TABLE IF EXISTS users;

CREATE TABLE users (
  id_user INT AUTO_INCREMENT PRIMARY KEY,
  nom VARCHAR(100) NOT NULL,
  email VARCHAR(100) UNIQUE NOT NULL,
  mot_de_passe VARCHAR(255) NOT NULL,
  statut ENUM('client', 'admin', 'technicien') NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE vehicles (
  id_vehicle INT AUTO_INCREMENT PRIMARY KEY,
  marque VARCHAR(50) NOT NULL,
  modele VARCHAR(50) NOT NULL,
  annee YEAR NOT NULL,
  etat VARCHAR(50) DEFAULT 'bon',
  client_id INT,
  CONSTRAINT fk_vehicle_client FOREIGN KEY (client_id) REFERENCES users(id_user) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE anomalies (
  id_anomaly INT AUTO_INCREMENT PRIMARY KEY,
  type VARCHAR(50) NOT NULL,
  description TEXT,
  date_detection DATETIME DEFAULT CURRENT_TIMESTAMP,
  statut ENUM('nouvelle', 'en cours', 'resolue') DEFAULT 'nouvelle',
  vehicle_id INT,
  CONSTRAINT fk_anomaly_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles(id_vehicle) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE reparations (
  id_reparation INT AUTO_INCREMENT PRIMARY KEY,
  date_reparation DATETIME DEFAULT CURRENT_TIMESTAMP,
  rapport TEXT,
  statut ENUM('en attente', 'en cours', 'terminee') DEFAULT 'en attente',
  vehicle_id INT,
  technicien_id INT,
  CONSTRAINT fk_rep_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles(id_vehicle) ON DELETE CASCADE,
  CONSTRAINT fk_rep_tech FOREIGN KEY (technicien_id) REFERENCES users(id_user) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE interventions (
  id_intervention INT AUTO_INCREMENT PRIMARY KEY,
  date_intervention DATETIME DEFAULT CURRENT_TIMESTAMP,
  statut ENUM('planifiee', 'effectuee', 'annulee') DEFAULT 'planifiee',
  vehicle_id INT,
  admin_id INT,
  technicien_id INT,
  CONSTRAINT fk_int_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles(id_vehicle) ON DELETE CASCADE,
  CONSTRAINT fk_int_admin FOREIGN KEY (admin_id) REFERENCES users(id_user) ON DELETE SET NULL,
  CONSTRAINT fk_int_tech FOREIGN KEY (technicien_id) REFERENCES users(id_user) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tokens de notification push (FCM/OneSignal)
CREATE TABLE push_tokens (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  token VARCHAR(255) NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_user_token (user_id, token),
  CONSTRAINT fk_token_user FOREIGN KEY (user_id) REFERENCES users(id_user) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Données de test (1 admin, 1 technicien, 1 client, 1 véhicule)
-- Comptes VERROUILLÉS : aucun mot de passe par défaut. Définir un mot de passe fort avec :

INSERT INTO users (nom, email, mot_de_passe, statut) VALUES
('Admin SAT', 'admin@sat.local', '!COMPTE_VERROUILLE', 'admin'),
('Tech SAT', 'tech@sat.local', '!COMPTE_VERROUILLE', 'technicien'),
('Client SAT', 'client@sat.local', '!COMPTE_VERROUILLE', 'client');

-- HCH_DB_NAME=smartautotrack php scripts/set_password.php admin@sat.local

INSERT INTO vehicles (marque, modele, annee, etat, client_id)
VALUES ('Toyota', 'Corolla', 2020, 'bon', (SELECT id_user FROM users WHERE email='client@sat.local'));

-- Un jeu d'anomalies et réparations exemple
INSERT INTO anomalies (type, description, vehicle_id)
VALUES
('batterie', 'Tension batterie faible détectée', (SELECT id_vehicle FROM vehicles LIMIT 1)),
('freins', 'Usure plaquettes avant > 80%', (SELECT id_vehicle FROM vehicles LIMIT 1));

INSERT INTO reparations (rapport, statut, vehicle_id, technicien_id)
VALUES
('Remplacement batterie 60Ah AGM. Test OK.', 'terminee', (SELECT id_vehicle FROM vehicles LIMIT 1), (SELECT id_user FROM users WHERE statut='technicien' LIMIT 1));

INSERT INTO interventions (statut, vehicle_id, admin_id, technicien_id)
VALUES
('planifiee', (SELECT id_vehicle FROM vehicles LIMIT 1), (SELECT id_user FROM users WHERE statut='admin' LIMIT 1), (SELECT id_user FROM users WHERE statut='technicien' LIMIT 1));


