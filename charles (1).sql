-- phpMyAdmin SQL Dump
-- version 5.2.0
-- https://www.phpmyadmin.net/
--
-- Hôte : localhost
-- Généré le : lun. 21 sep. 2026 à 17:06
-- Version du serveur : 8.0.41
-- Version de PHP : 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Base de données : `charles`
--

-- --------------------------------------------------------

--
-- Structure de la table `anomalies`
--

CREATE TABLE `anomalies` (
  `id` int NOT NULL,
  `vehicle_id` int NOT NULL,
  `type` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `niveau` enum('faible','moyen','critique') COLLATE utf8mb4_unicode_ci NOT NULL,
  `statut` enum('detectee','en_cours','resolue') COLLATE utf8mb4_unicode_ci DEFAULT 'detectee',
  `date_detection` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `date_resolution` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `interventions`
--

CREATE TABLE `interventions` (
  `id` int NOT NULL,
  `vehicle_id` int NOT NULL,
  `technicien_id` int NOT NULL,
  `admin_id` int NOT NULL,
  `type_intervention` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `priorite` enum('basse','moyenne','haute') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'moyenne',
  `date_planifiee` datetime NOT NULL,
  `statut` enum('planifiee','en_cours','terminee','annulee') COLLATE utf8mb4_unicode_ci DEFAULT 'planifiee',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Déchargement des données de la table `interventions`
--

INSERT INTO `interventions` (`id`, `vehicle_id`, `technicien_id`, `admin_id`, `type_intervention`, `description`, `priorite`, `date_planifiee`, `statut`, `created_at`) VALUES
(4, 3, 8, 1, 'dhggh', 'vjhvjh', 'moyenne', '2025-09-25 20:13:00', 'terminee', '2025-09-25 19:13:42'),
(5, 3, 8, 1, 'agshvfasdas', 'nklgnhlkg', 'moyenne', '2025-09-25 20:37:00', 'terminee', '2025-09-25 19:38:10');

-- --------------------------------------------------------

--
-- Structure de la table `messages`
--

CREATE TABLE `messages` (
  `id` int NOT NULL,
  `expediteur_id` int NOT NULL,
  `destinataire_id` int NOT NULL,
  `sujet` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `contenu` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `lu` enum('oui','non') COLLATE utf8mb4_unicode_ci DEFAULT 'non',
  `date_envoi` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Déchargement des données de la table `messages`
--

INSERT INTO `messages` (`id`, `expediteur_id`, `destinataire_id`, `sujet`, `contenu`, `lu`, `date_envoi`) VALUES
(5, 1, 9, 'uryufuy', 'jiuihuh', 'oui', '2025-09-24 22:37:49'),
(6, 6, 9, 'bjr', 'mon moteur', 'oui', '2025-09-24 22:41:53'),
(7, 1, 6, 'Visite technique', 'Salut M.DJOKO vous êtes priez de passer dans nos locaux au plus tard cette fin de semaine pour le Diagnostic de votre véhicule pour le compte de ce dernier trimestre\r\n\r\nCordialement SmartAutoTrack', 'non', '2026-06-26 12:47:38'),
(8, 11, 1, 'Demande de Visite Automobile', 'jkgjkfguijbdvbruiuu8hiuhdjkhuifhjkkjnbcjhifws cm, xm,ndkjnfjkwhjkfiufhwshhiudfhwiuefiu', 'oui', '2026-06-26 13:18:24'),
(9, 11, 1, 'Message', 'vjhkj', 'non', '2026-07-08 14:36:23');

-- --------------------------------------------------------

--
-- Structure de la table `notifications`
--

CREATE TABLE `notifications` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `type` enum('anomalie','intervention','message','rapport','validation') COLLATE utf8mb4_unicode_ci NOT NULL,
  `titre` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `message` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `lu` enum('oui','non') COLLATE utf8mb4_unicode_ci DEFAULT 'non',
  `date_creation` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Déchargement des données de la table `notifications`
--

INSERT INTO `notifications` (`id`, `user_id`, `type`, `titre`, `message`, `lu`, `date_creation`) VALUES
(6, 1, 'message', 'Nouveau message', 'Vous avez reçu un nouveau message de Jean Durand', 'oui', '2025-09-24 15:29:39'),
(9, 1, 'validation', 'Nouvelle demande de technicien', 'Nouvelle demande d\'inscription de technicien: henga charles', 'oui', '2025-09-24 16:11:09'),
(10, 1, 'validation', 'Nouvelle demande de technicien', 'Nouvelle demande d\'inscription de technicien: charles henga', 'oui', '2025-09-24 16:15:12'),
(11, 8, 'validation', 'Compte validé', 'Votre compte technicien a été validé par un administrateur.', 'oui', '2025-09-24 16:18:01'),
(12, 1, 'validation', 'Nouvelle demande de technicien', 'Nouvelle demande d\'inscription de technicien: Emile HAMABOU', 'oui', '2025-09-24 16:34:06'),
(13, 9, 'validation', 'Compte validé', 'Votre compte technicien a été validé par un administrateur.', 'oui', '2025-09-24 16:35:45'),
(14, 9, 'message', 'Nouveau message', 'Vous avez reçu un nouveau message de SmartAutoTrack Admin', 'oui', '2025-09-24 22:37:49'),
(15, 9, 'message', 'Nouveau message', 'Vous avez reçu un nouveau message de live djoko', 'oui', '2025-09-24 22:41:53'),
(16, 9, 'intervention', 'Nouvelle intervention assignée', 'Vous avez été assigné à une nouvelle intervention: cghcgh', 'oui', '2025-09-25 18:54:29'),
(18, 8, 'intervention', 'Nouvelle intervention assignée', 'Vous avez été assigné à une nouvelle intervention: dhggh', 'oui', '2025-09-25 19:13:42'),
(19, 6, 'intervention', 'Intervention planifiée', 'Une intervention a été planifiée pour votre véhicule: dhggh', 'oui', '2025-09-25 19:13:42'),
(20, 8, 'intervention', 'Nouvelle intervention assignée', 'Vous avez été assigné à une nouvelle intervention: agshvfasdas', 'oui', '2025-09-25 19:38:10'),
(21, 6, 'intervention', 'Intervention planifiée', 'Une intervention a été planifiée pour votre véhicule: agshvfasdas', 'oui', '2025-09-25 19:38:10'),
(22, 1, 'validation', 'Nouvelle demande de technicien', 'Nouvelle demande d\'inscription de technicien: Chris EKWALLA', 'oui', '2026-06-24 17:22:24'),
(23, 6, 'message', 'Nouveau message', 'Vous avez reçu un nouveau message de Charles henga', 'non', '2026-06-26 12:47:38'),
(24, 1, 'message', 'Nouveau message', 'Vous avez reçu un nouveau message de Bernadette NGANGOUP', 'oui', '2026-06-26 13:18:24'),
(25, 1, 'message', 'Nouveau message', 'Vous avez reçu un nouveau message de Bernadette NGANGOUP', 'non', '2026-07-08 14:36:23'),
(26, 1, 'validation', 'Nouvelle demande de technicien', 'Nouvelle demande d\'inscription de technicien: land landry', 'non', '2026-08-24 10:46:26'),
(27, 1, 'validation', 'Nouvelle demande de technicien', 'Nouvelle demande d\'inscription de technicien: charlie charli', 'non', '2026-08-24 10:49:11'),
(28, 13, 'validation', 'Compte validé', 'Votre compte technicien a été validé par un administrateur.', 'non', '2026-08-24 10:52:20'),
(29, 1, 'validation', 'Nouvelle demande de technicien', 'Nouvelle demande d\'inscription de technicien: hch charles', 'oui', '2026-08-31 11:26:11'),
(30, 15, 'validation', 'Compte validé', 'Votre compte technicien a été validé par un administrateur.', 'non', '2026-08-31 11:27:20');

-- --------------------------------------------------------

--
-- Structure de la table `reparations`
--

CREATE TABLE `reparations` (
  `id` int NOT NULL,
  `vehicle_id` int NOT NULL,
  `technicien_id` int NOT NULL,
  `intervention_id` int DEFAULT NULL,
  `anomalie_id` int DEFAULT NULL,
  `description` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `titre` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `diagnostic` text COLLATE utf8mb4_unicode_ci,
  `travaux_effectues` text COLLATE utf8mb4_unicode_ci,
  `pieces_utilisees` text COLLATE utf8mb4_unicode_ci,
  `recommandations` text COLLATE utf8mb4_unicode_ci,
  `cout` decimal(10,2) DEFAULT '0.00',
  `duree_intervention` decimal(5,2) DEFAULT '0.00',
  `statut` enum('planifiee','en_cours','terminee','validee','valide','en_attente') COLLATE utf8mb4_unicode_ci DEFAULT 'planifiee',
  `date_debut` timestamp NULL DEFAULT NULL,
  `date_fin` timestamp NULL DEFAULT NULL,
  `rapport` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `technician_documents`
--

CREATE TABLE `technician_documents` (
  `id` int NOT NULL,
  `technicien_id` int NOT NULL,
  `type_document` enum('diplome','certificat','photo','autre') COLLATE utf8mb4_unicode_ci NOT NULL,
  `nom_fichier` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `chemin_fichier` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `taille_fichier` int DEFAULT NULL,
  `uploaded_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Déchargement des données de la table `technician_documents`
--

INSERT INTO `technician_documents` (`id`, `technicien_id`, `type_document`, `nom_fichier`, `chemin_fichier`, `taille_fichier`, `uploaded_at`) VALUES
(1, 9, 'autre', 'reponses_soutenance.pdf', 'uploads/techniciens/9_1758731646_0.pdf', 7025, '2025-09-24 16:34:06'),
(2, 10, 'autre', 'INTRO CHAP 1 ET 2.pdf', 'uploads/techniciens/10_1782321744_0.pdf', 208874, '2026-06-24 17:22:24'),
(3, 12, 'autre', '_PDG.pdf', 'uploads/techniciens/12_1787568386_0.pdf', 23653, '2026-08-24 10:46:26'),
(4, 13, 'autre', 'Bonne lettre.pdf', 'uploads/techniciens/13_1787568551_0.pdf', 706201, '2026-08-24 10:49:11'),
(5, 15, 'autre', 'Bonne lettre.pdf', 'uploads/techniciens/15_1788175571_0.pdf', 706201, '2026-08-31 11:26:11');

-- --------------------------------------------------------

--
-- Structure de la table `users`
--

CREATE TABLE `users` (
  `id` int NOT NULL,
  `nom` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `prenom` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `telephone` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `role` enum('client','technicien','admin') COLLATE utf8mb4_unicode_ci NOT NULL,
  `mot_de_passe` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `competences` text COLLATE utf8mb4_unicode_ci,
  `experience` text COLLATE utf8mb4_unicode_ci,
  `statut` enum('actif','pending','rejete') COLLATE utf8mb4_unicode_ci DEFAULT 'pending',
  `statut_validation` enum('valide','en_attente','rejete') COLLATE utf8mb4_unicode_ci DEFAULT 'en_attente',
  `theme_preference` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT 'light',
  `photo_profil` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Déchargement des données de la table `users`
--

INSERT INTO `users` (`id`, `nom`, `prenom`, `email`, `telephone`, `role`, `mot_de_passe`, `competences`, `experience`, `statut`, `statut_validation`, `theme_preference`, `photo_profil`, `created_at`, `updated_at`) VALUES
(1, 'henga', 'Charles', 'admin@smartautotrack.com', '651797837', 'admin', '$2y$10$FZQqfFz4uPul3t1NiNLpp.Nlm/.VRGe2hG9owZ4UlliifrE5cU/JO', NULL, NULL, 'actif', 'valide', 'light', NULL, '2025-09-24 15:12:40', '2026-09-21 14:38:54'),
(6, 'djoko', 'live', 'djoko@gmail.com', '671524727', 'client', '$2y$10$3Ci.9g09UYap9T1bAIQRCeV5YhFJbiICY3yiYdj84bQUG5uw4j3lu', NULL, NULL, 'actif', 'valide', 'light', NULL, '2025-09-24 16:06:00', '2025-09-24 16:06:00'),
(8, 'henga', 'charles', 'hengacharles@gmail.com', '650251246', 'technicien', '$2y$10$eKeS07pnWlI7N78Eel179uzWjC9djS9y7iqGy3QaDD090j5fGO9/a', 'fhffhj', '3', 'actif', 'valide', 'light', NULL, '2025-09-24 16:15:12', '2025-09-24 16:18:01'),
(9, 'HAMABOU', 'Emile', 'emilehamabou@gmail.com', '676470880', 'technicien', '$2y$10$HemDGu8Bj98maIVCkRGr2.S4scZHmI3NXzOWxBgoldVp70eScA3FW', 'tststyufhjjhjxf', '12', 'actif', 'valide', 'light', NULL, '2025-09-24 16:34:06', '2025-09-24 16:35:45'),
(10, 'EKWALLA', 'Chris', 'ekwallachris@gmail.com', '657464231', 'technicien', '$2y$10$Z4Bl1hco/QzmYd6wvO8.8OJqS9b1hNkGnFed.b2oDog1/uQ4Kx3.a', 'Electricien Automobile', '3 ans d&#039;experiences', 'pending', 'en_attente', 'light', NULL, '2026-06-24 17:22:24', '2026-06-24 17:22:24'),
(11, 'NGANGOUP', 'Bernadette', 'ngangoupbernadette@gmail.com', '658039513', 'client', '$2y$10$MSGu5MoClhovq6yzyxk20uhIGUToo3MnbrzjYGQZXYrm3SfoJ6mbO', NULL, NULL, 'actif', 'valide', 'light', NULL, '2026-06-26 13:14:07', '2026-06-26 13:14:07'),
(12, 'landry', 'land', 'landry@gmail.com', '658039513', 'technicien', '$2y$10$n1a2Z4w2hZ8AXcRJLiMQ0u7/.Yvjg0C.c5nBgKsUB8TdzlH.hJ./i', 'hjdvshvhjsdvj', 'djkvsdvdvbbn', 'pending', 'en_attente', 'light', NULL, '2026-08-24 10:46:26', '2026-08-24 10:46:26'),
(13, 'charli', 'charlie', 'Charli@gmail.com', '651797837', 'technicien', '$2y$10$P86F/B3K6wK4Rw1vqh6cCuDmeUr7JtsFHjFi.DhBrRSfG9jGC4/Gi', 'jjdvbsnkjjkwlk,l', '.,l.,.[l', 'actif', 'valide', 'light', NULL, '2026-08-24 10:49:11', '2026-08-24 10:52:20'),
(14, 'hwtettyetyeq', '157773', '11998@gmail.com', '658039513', 'client', '$2y$10$mISrVZvn3zvbnHkxx87/TOkUlIqJIRHGL289b19Q5NJw.wX8GfnsK', NULL, NULL, 'actif', 'valide', 'light', NULL, '2026-08-31 11:22:27', '2026-08-31 11:22:27'),
(15, 'charles', 'hch', 'hch@gmail.com', '658039513', 'technicien', '$2y$10$/koB9YIWL8krruBWrUPiw.0I.ySNrHT5tfhmjoSzVPF2RFHCIXrn.', ',mvwjopwi0fpefpewqihciqoeh', '2p9ur93ur23', 'actif', 'valide', 'light', NULL, '2026-08-31 11:26:11', '2026-08-31 11:27:20');

-- --------------------------------------------------------

--
-- Structure de la table `vehicles`
--

CREATE TABLE `vehicles` (
  `id` int NOT NULL,
  `client_id` int NOT NULL,
  `marque` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `modele` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `immatriculation` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `annee` int DEFAULT NULL,
  `couleur` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `kilometrage` int DEFAULT '0',
  `statut` enum('actif','en_panne','en_entretien','hors_service') COLLATE utf8mb4_unicode_ci DEFAULT 'actif',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Déchargement des données de la table `vehicles`
--

INSERT INTO `vehicles` (`id`, `client_id`, `marque`, `modele`, `immatriculation`, `annee`, `couleur`, `kilometrage`, `statut`, `created_at`) VALUES
(3, 6, 'Toyota Corolla', 'berline', '1234def', NULL, NULL, 0, 'actif', '2025-09-24 16:06:00'),
(4, 11, 'Cadillac', 'escalade', 'CE-458-KL', NULL, NULL, 0, 'actif', '2026-06-26 13:14:07'),
(5, 14, 'Cadillac e', 'escalade', '1155344343avvg', NULL, NULL, 0, 'actif', '2026-08-31 11:22:27');

--
-- Index pour les tables déchargées
--

--
-- Index pour la table `anomalies`
--
ALTER TABLE `anomalies`
  ADD PRIMARY KEY (`id`),
  ADD KEY `vehicle_id` (`vehicle_id`);

--
-- Index pour la table `interventions`
--
ALTER TABLE `interventions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `vehicle_id` (`vehicle_id`),
  ADD KEY `technicien_id` (`technicien_id`),
  ADD KEY `admin_id` (`admin_id`);

--
-- Index pour la table `messages`
--
ALTER TABLE `messages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `expediteur_id` (`expediteur_id`),
  ADD KEY `destinataire_id` (`destinataire_id`);

--
-- Index pour la table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Index pour la table `reparations`
--
ALTER TABLE `reparations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `vehicle_id` (`vehicle_id`),
  ADD KEY `technicien_id` (`technicien_id`),
  ADD KEY `anomalie_id` (`anomalie_id`),
  ADD KEY `fk_reparations_intervention` (`intervention_id`);

--
-- Index pour la table `technician_documents`
--
ALTER TABLE `technician_documents`
  ADD PRIMARY KEY (`id`),
  ADD KEY `technicien_id` (`technicien_id`);

--
-- Index pour la table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `idx_users_theme` (`theme_preference`);

--
-- Index pour la table `vehicles`
--
ALTER TABLE `vehicles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `immatriculation` (`immatriculation`),
  ADD KEY `client_id` (`client_id`);

--
-- AUTO_INCREMENT pour les tables déchargées
--

--
-- AUTO_INCREMENT pour la table `anomalies`
--
ALTER TABLE `anomalies`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT pour la table `interventions`
--
ALTER TABLE `interventions`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT pour la table `messages`
--
ALTER TABLE `messages`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT pour la table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=31;

--
-- AUTO_INCREMENT pour la table `reparations`
--
ALTER TABLE `reparations`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT pour la table `technician_documents`
--
ALTER TABLE `technician_documents`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT pour la table `users`
--
ALTER TABLE `users`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT pour la table `vehicles`
--
ALTER TABLE `vehicles`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- Contraintes pour les tables déchargées
--

--
-- Contraintes pour la table `anomalies`
--
ALTER TABLE `anomalies`
  ADD CONSTRAINT `anomalies_ibfk_1` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`) ON DELETE CASCADE;

--
-- Contraintes pour la table `interventions`
--
ALTER TABLE `interventions`
  ADD CONSTRAINT `interventions_ibfk_1` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `interventions_ibfk_2` FOREIGN KEY (`technicien_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `interventions_ibfk_3` FOREIGN KEY (`admin_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Contraintes pour la table `messages`
--
ALTER TABLE `messages`
  ADD CONSTRAINT `messages_ibfk_1` FOREIGN KEY (`expediteur_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `messages_ibfk_2` FOREIGN KEY (`destinataire_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Contraintes pour la table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Contraintes pour la table `reparations`
--
ALTER TABLE `reparations`
  ADD CONSTRAINT `fk_reparations_intervention` FOREIGN KEY (`intervention_id`) REFERENCES `interventions` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `reparations_ibfk_1` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `reparations_ibfk_2` FOREIGN KEY (`technicien_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `reparations_ibfk_3` FOREIGN KEY (`anomalie_id`) REFERENCES `anomalies` (`id`) ON DELETE SET NULL;

--
-- Contraintes pour la table `technician_documents`
--
ALTER TABLE `technician_documents`
  ADD CONSTRAINT `technician_documents_ibfk_1` FOREIGN KEY (`technicien_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Contraintes pour la table `vehicles`
--
ALTER TABLE `vehicles`
  ADD CONSTRAINT `vehicles_ibfk_1` FOREIGN KEY (`client_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
