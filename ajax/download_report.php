<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/repair_report.php';

/**
 * Téléchargement du rapport d'une réparation, sous forme de fichier HTML
 * autonome (imprimable). Appelé depuis client/dashboard.php et
 * client/reparations.php.
 *
 * Accès : tout utilisateur connecté, mais cloisonné par rôle : le client
 * pour ses véhicules, le technicien pour ses réparations, le garage pour
 * ses interventions, l'admin pour tout.
 * GET : id (identifiant de la réparation).
 * Le kilométrage relevé et l'état du véhicule à la sortie (rapport de fin
 * d'intervention, includes/repair_report.php) sont ajoutés quand les
 * colonnes reparation.kilometrage / etatVehicule existent
 * (repairReportReady()) et sont renseignées.
 * Tables lues : reparation, intervention, vehicule, utilisateur, garage.
 */
requireAuth();

$reparation_id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

if (!$reparation_id) {
    http_response_code(400);
    exit('ID de réparation invalide');
}

try {
    $db = new Database();
    $conn = $db->getConnection();

    // Le statut est reconverti vers le vocabulaire historique (planifiee/en_cours/
    // validee) pour que le contenu du rapport (ucfirst($reparation['statut']))
    // reste cohérent avec ce qu'affichaient les autres pages.
    $cols = "r.idReparation AS id, r.titre, r.description, r.diagnostic,
             r.travauxEffectues AS travaux_effectues, r.piecesUtilisees AS pieces_utilisees, r.recommandations,
             r.cout, r.dureeIntervention AS duree_intervention, r.dateReparation AS created_at,
             NULL AS date_debut, NULL AS date_fin,
             CASE r.statut WHEN 'EN_ATTENTE' THEN 'planifiee' WHEN 'EN_COURS' THEN 'en_cours' ELSE 'validee' END AS statut,
             v.marque, v.modele, v.immatriculation,
             ut.prenom as technicien_prenom, ut.nom as technicien_nom,
             c.nom as client_nom, c.prenom as client_prenom";
    // Colonnes du rapport de fin d'intervention, NULL tant que la migration n'est pas appliquée.
    $cols .= repairReportReady($conn)
        ? ", r.kilometrage AS kilometrage_releve, r.etatVehicule AS etat_vehicule"
        : ", NULL AS kilometrage_releve, NULL AS etat_vehicule";
    // Construire la requête selon le rôle. Chaque rôle a sa propre
    // restriction explicite — l'ancien "else" ("pour les admins") était en
    // réalité exécuté pour tout rôle qui n'est ni client ni technicien, donc
    // aussi par le garage (ajouté depuis), avec un accès total non cloisonné.
    if ($_SESSION['role'] === 'client') {
        // Pour les clients, vérifier qu'ils possèdent le véhicule
        $stmt = $conn->prepare("
            SELECT $cols
            FROM reparation r
            JOIN intervention i ON r.idIntervention = i.idIntervention
            JOIN vehicule v ON i.idVehicule = v.idVehicule
            JOIN utilisateur c ON i.idClient = c.idUtilisateur
            LEFT JOIN utilisateur ut ON r.idTechnicien = ut.idUtilisateur
            WHERE r.idReparation = ? AND i.idClient = ?
        ");
        $stmt->execute([$reparation_id, $_SESSION['user_id']]);
    } elseif ($_SESSION['role'] === 'technicien') {
        // Pour les techniciens, seulement leurs réparations
        $stmt = $conn->prepare("
            SELECT $cols
            FROM reparation r
            JOIN intervention i ON r.idIntervention = i.idIntervention
            JOIN vehicule v ON i.idVehicule = v.idVehicule
            JOIN utilisateur c ON i.idClient = c.idUtilisateur
            LEFT JOIN utilisateur ut ON r.idTechnicien = ut.idUtilisateur
            WHERE r.idReparation = ? AND r.idTechnicien = ?
        ");
        $stmt->execute([$reparation_id, $_SESSION['user_id']]);
    } elseif ($_SESSION['role'] === 'garage') {
        // Pour un garage, seulement les réparations issues de ses propres interventions
        $stmt = $conn->prepare("
            SELECT $cols
            FROM reparation r
            JOIN intervention i ON r.idIntervention = i.idIntervention
            JOIN vehicule v ON i.idVehicule = v.idVehicule
            JOIN utilisateur c ON i.idClient = c.idUtilisateur
            JOIN garage g ON g.idGarage = i.idGarage
            LEFT JOIN utilisateur ut ON r.idTechnicien = ut.idUtilisateur
            WHERE r.idReparation = ? AND g.idUtilisateur = ?
        ");
        $stmt->execute([$reparation_id, $_SESSION['user_id']]);
    } elseif ($_SESSION['role'] === 'admin') {
        // Vision globale de l'administrateur
        $stmt = $conn->prepare("
            SELECT $cols
            FROM reparation r
            JOIN intervention i ON r.idIntervention = i.idIntervention
            JOIN vehicule v ON i.idVehicule = v.idVehicule
            JOIN utilisateur c ON i.idClient = c.idUtilisateur
            LEFT JOIN utilisateur ut ON r.idTechnicien = ut.idUtilisateur
            WHERE r.idReparation = ?
        ");
        $stmt->execute([$reparation_id]);
    } else {
        http_response_code(403);
        exit('Accès refusé');
    }

    $reparation = $stmt->fetch();

    if (!$reparation) {
        http_response_code(404);
        exit('Réparation introuvable');
    }

    // Générer le rapport
    $html = generateReportHTML($reparation);

    // Le rapport est téléchargé en HTML ; on interdit toute exécution de script à l'ouverture.
    header('Content-Type: text/html; charset=utf-8');
    header('Content-Disposition: attachment; filename="rapport_reparation_' . $reparation_id . '.html"');
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'");

    echo $html;

} catch (Exception $e) {
    error_log('[SmartAutoTrack] download_report : ' . $e->getMessage());
    http_response_code(500);
    exit('Erreur lors de la génération du rapport');
}

/**
 * Échappe une valeur pour l'insérer dans du HTML.
 * double_encode = false : les données saisies via sanitize() sont déjà encodées
 * en base, on évite ainsi d'afficher "&amp;#039;" tout en neutralisant toute
 * balise brute (<script>, etc.).
 */
function e($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false);
}

/** Échappe puis convertit les retours à la ligne. */
function eMultiline($value) {
    return nl2br(e($value));
}

/**
 * Construit le document HTML complet du rapport de réparation.
 * Toutes les valeurs issues de la base passent par e() / eMultiline().
 *
 * @param array $reparation Ligne issue de la requête ci-dessus (colonnes aliasées).
 * @return string Page HTML autonome (styles inline, aucun script).
 */
function generateReportHTML($reparation) {
    $html = '<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rapport de Réparation - ' . e($reparation['immatriculation']) . '</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 40px; line-height: 1.6; }
        .header { text-align: center; border-bottom: 2px solid #1E90FF; padding-bottom: 20px; margin-bottom: 30px; }
        .header h1 { color: #1E90FF; margin: 0; }
        .header p { margin: 5px 0; color: #666; }
        .section { margin-bottom: 25px; }
        .section h2 { color: #1E90FF; border-bottom: 1px solid #ddd; padding-bottom: 5px; }
        .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        .info-item { margin-bottom: 10px; }
        .info-item strong { display: inline-block; width: 120px; color: #333; }
        .report-content { background: #f9f9f9; padding: 20px; border-radius: 5px; margin-top: 10px; }
        .footer { text-align: center; margin-top: 40px; padding-top: 20px; border-top: 1px solid #ddd; color: #666; }
        @media print { body { margin: 20px; } }
    </style>
</head>
<body>
    <div class="header">
        <h1>SmartAutoTrack</h1>
        <p>Rapport de Réparation</p>
        <p>Généré le ' . date('d/m/Y H:i') . '</p>
    </div>

    <div class="section">
        <h2>Informations de la Réparation</h2>
        <div class="info-grid">
            <div class="info-item">
                <strong>ID Réparation :</strong> ' . (int)$reparation['id'] . '
            </div>
            <div class="info-item">
                <strong>Statut :</strong> ' . e(ucfirst((string)$reparation['statut'])) . '
            </div>
            <div class="info-item">
                <strong>Coût :</strong> ' . number_format((float)$reparation['cout'], 0, ',', ' ') . ' XAF
            </div>
            <div class="info-item">
                <strong>Technicien :</strong> ' . e($reparation['technicien_prenom'] ?? '') . ' ' . e($reparation['technicien_nom'] ?? '') . '
            </div>
        </div>
    </div>

    <div class="section">
        <h2>Véhicule</h2>
        <div class="info-grid">
            <div class="info-item">
                <strong>Marque :</strong> ' . e($reparation['marque']) . '
            </div>
            <div class="info-item">
                <strong>Modèle :</strong> ' . e($reparation['modele']) . '
            </div>
            <div class="info-item">
                <strong>Immatriculation :</strong> ' . e($reparation['immatriculation']) . '
            </div>
            <div class="info-item">
                <strong>Propriétaire :</strong> ' . e($reparation['client_prenom']) . ' ' . e($reparation['client_nom']) . '
            </div>';

    // Relevés de fin d'intervention, seulement s'ils ont été saisis.
    if ($reparation['kilometrage_releve'] !== null) {
        $html .= '<div class="info-item">
                <strong>Kilométrage relevé :</strong> ' . number_format((float)$reparation['kilometrage_releve'], 0, ',', ' ') . ' km
            </div>';
    }
    if (!empty($reparation['etat_vehicule'])) {
        $etat = REPAIR_VEHICLE_STATES[$reparation['etat_vehicule']] ?? $reparation['etat_vehicule'];
        $html .= '<div class="info-item">
                <strong>État à la sortie :</strong> ' . e($etat) . '
            </div>';
    }

    $html .= '</div>
    </div>

    <div class="section">
        <h2>Description de la Réparation</h2>
        <p>' . eMultiline($reparation['description']) . '</p>
    </div>

    <div class="section">
        <h2>Dates</h2>
        <div class="info-grid">
            <div class="info-item">
                <strong>Créée le :</strong> ' . date('d/m/Y H:i', strtotime($reparation['created_at'])) . '
            </div>';

    if (!empty($reparation['date_debut'])) {
        $html .= '<div class="info-item">
                <strong>Début :</strong> ' . date('d/m/Y H:i', strtotime($reparation['date_debut'])) . '
            </div>';
    }

    if (!empty($reparation['date_fin'])) {
        $html .= '<div class="info-item">
                <strong>Fin :</strong> ' . date('d/m/Y H:i', strtotime($reparation['date_fin'])) . '
            </div>';
    }

    $html .= '</div>
    </div>';

    // Sections facultatives : affichées seulement si le champ est renseigné.
    $blocks = [
        'diagnostic'        => 'Diagnostic',
        'travaux_effectues' => 'Travaux Effectués',
        'pieces_utilisees'  => 'Pièces Utilisées',
        'recommandations'   => 'Recommandations',
    ];
    foreach ($blocks as $column => $title) {
        if (!empty($reparation[$column])) {
            $html .= '<div class="section">
            <h2>' . $title . '</h2>
            <div class="report-content">
                ' . eMultiline($reparation[$column]) . '
            </div>
        </div>';
        }
    }

    $html .= '<div class="section">
        <h2>Détails de l\'Intervention</h2>
        <div class="info-grid">
            <div class="info-item">
                <strong>Durée :</strong> ' . e($reparation['duree_intervention'] ?? '') . ' heures
            </div>
            <div class="info-item">
                <strong>Coût total :</strong> ' . number_format((float)$reparation['cout'], 0, ',', ' ') . ' XAF
            </div>
        </div>
    </div>';

    $html .= '<div class="footer">
        <p>Ce rapport a été généré automatiquement par SmartAutoTrack</p>
        <p>Pour toute question, contactez notre service client</p>
    </div>
</body>
</html>';

    return $html;
}
?>
