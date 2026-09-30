<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../config/roles.php';
require_once 'includes/helpers.php';

/**
 * Fiche détaillée d'un garage (espace Administrateur).
 *
 * Accès : rôle admin uniquement.
 * GET `id` : identifiant du garage ; si absent ou inconnu, redirection vers
 * garages.php.
 * Actions POST (jeton CSRF requis) :
 *   - ?action=validate_document : passe un document EN_ATTENTE à VALIDE ;
 *   - ?action=reject_document   : le passe à REJETE.
 * Chaque décision est journalisée et notifiée au compte du garage.
 *
 * Tables : garage, utilisateur, documentgarage (lecture/écriture),
 * technicien, intervention, notifications, journal d'activité.
 * Liens : garage/parametres.php (dépôt des documents), garages.php.
 */
requireRole('admin');

$db = new Database();
$conn = $db->getConnection();

$garageId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
if (!$garageId) { header('Location: garages.php'); exit; }

$stmt = $conn->prepare("
    SELECT g.idGarage, g.nomGarage, g.adresse, g.statutGarage,
           u.idUtilisateur, u.nom, u.prenom, u.email, u.telephone, u.dateCreation AS created_at
    FROM garage g LEFT JOIN utilisateur u ON u.idUtilisateur = g.idUtilisateur
    WHERE g.idGarage = ?
");
$stmt->execute([$garageId]);
$garage = $stmt->fetch();
if (!$garage) { header('Location: garages.php'); exit; }

// Documents administratifs du garage (assurance, Kbis, certification...) —
// symétrique de technician_documents, avec un vrai statut de vérification
// que l'admin traite ici (cf. garage/parametres.php pour le dépôt).
$docTypes = [
    'assurance' => 'Attestation d\'assurance',
    'kbis' => 'Extrait Kbis / immatriculation',
    'certification' => 'Certification professionnelle',
    'autre' => 'Autre document',
];
$errors = [];
$docAction = $_GET['action'] ?? '';
$postOk = $_SERVER['REQUEST_METHOD'] === 'POST' && verifyCSRFToken($_POST['csrf_token'] ?? '');

// Action : valider ou rejeter un document. La requête filtre sur idGarage
// et sur le statut EN_ATTENTE : impossible de traiter le document d'un autre
// garage ou de revenir sur un document déjà vérifié.
if (($docAction === 'validate_document' || $docAction === 'reject_document') && $postOk) {
    $docId = filter_var($_POST['document_id'] ?? null, FILTER_VALIDATE_INT);
    $newStatut = $docAction === 'validate_document' ? 'VALIDE' : 'REJETE';
    if (!$docId) {
        $errors[] = 'Document invalide.';
    } else {
        $stmt = $conn->prepare("SELECT typeDocument FROM documentgarage WHERE idDocument = ? AND idGarage = ? AND statutVerification = 'EN_ATTENTE'");
        $stmt->execute([$docId, $garageId]);
        $doc = $stmt->fetch();
        if ($doc) {
            $conn->prepare("UPDATE documentgarage SET statutVerification = ? WHERE idDocument = ? AND idGarage = ?")
                ->execute([$newStatut, $docId, $garageId]);

            log_activity($conn, $docAction === 'validate_document' ? 'Document garage validé par l\'administrateur' : 'Document garage rejeté par l\'administrateur', [
                'idUtilisateur' => $_SESSION['user_id'] ?? null,
                'idGarage' => $garageId,
                'description' => $docTypes[$doc['typeDocument']] ?? $doc['typeDocument'],
                'categorie' => 'garage',
            ]);
            if ($garage['idUtilisateur']) {
                $notifMsg = $docAction === 'validate_document'
                    ? 'Un document que vous avez déposé (' . ($docTypes[$doc['typeDocument']] ?? $doc['typeDocument']) . ') a été validé par un administrateur.'
                    : 'Un document que vous avez déposé (' . ($docTypes[$doc['typeDocument']] ?? $doc['typeDocument']) . ') a été rejeté par un administrateur. Vous pouvez le déposer à nouveau.';
                $conn->prepare("INSERT INTO notifications (user_id, type, titre, message) VALUES (?, 'validation', ?, ?)")
                    ->execute([$garage['idUtilisateur'], $docAction === 'validate_document' ? 'Document validé' : 'Document rejeté', $notifMsg]);
            }

            header("Location: garage_detail.php?id=$garageId&success=doc_" . strtolower($newStatut));
            exit;
        } else {
            $errors[] = 'Document introuvable, ou déjà vérifié.';
        }
    }
}

$stmt = $conn->prepare("SELECT idDocument, typeDocument, statutVerification FROM documentgarage WHERE idGarage = ? ORDER BY idDocument DESC");
$stmt->execute([$garageId]);
$documents = $stmt->fetchAll();

// Techniciens rattachés au garage, avec leur charge (interventions actives)
// et leur nombre total d'interventions.
$stmt = $conn->prepare("
    SELECT u.idUtilisateur AS id, u.nom, u.prenom, t.specialite, t.statutValidation,
           SUM(CASE WHEN i.statut IN ('PLANIFIEE','EN_COURS') THEN 1 ELSE 0 END) AS actives,
           COUNT(i.idIntervention) AS total_interventions
    FROM technicien t
    JOIN utilisateur u ON u.idUtilisateur = t.idTechnicien
    LEFT JOIN intervention i ON i.idTechnicien = t.idTechnicien
    WHERE t.idGarage = ?
    GROUP BY u.idUtilisateur, u.nom, u.prenom, t.specialite, t.statutValidation
    ORDER BY u.nom
");
$stmt->execute([$garageId]);
$techniciens = $stmt->fetchAll();

$stmt = $conn->prepare("
    SELECT i.idIntervention AS id, i.type, i.dateIntervention, i.statut, v.marque, v.modele, v.immatriculation
    FROM intervention i JOIN vehicule v ON v.idVehicule = i.idVehicule
    WHERE i.idGarage = ? ORDER BY i.dateIntervention DESC LIMIT 10
");
$stmt->execute([$garageId]);
$interventions = $stmt->fetchAll();

$journal = activity_log_fetch($conn, 'admin', (int)$_SESSION['user_id'], [], ['idGarage' => $garageId, 'limit' => 8]);

$pageTitle = $garage['nomGarage'];
$hideNavbar = true;
$bodyClass = 'av2';
$extraStylesheets = ['assets/css/admin_v2.css'];
$extraFonts = ['https://fonts.googleapis.com/css2?family=Sora:wght@600;700;800&family=Manrope:wght@400;500;600;700&display=swap'];
include '../includes/header.php';
?>
<div class="av2-shell">
    <?php $activeNav = 'garages'; include 'includes/sidebar.php'; ?>

    <main class="av2-main">
        <?php if (isset($_GET['success'])): ?>
            <div class="av2-alert success">
                <?php
                $successMsg = ['doc_valide' => 'Document validé.', 'doc_rejete' => 'Document rejeté.'];
                echo h($successMsg[$_GET['success']] ?? 'Action effectuée.');
                ?>
            </div>
        <?php endif; ?>
        <?php foreach ($errors as $err): ?><div class="av2-alert error"><?php echo h($err); ?></div><?php endforeach; ?>

        <div class="av2-page-head">
            <div>
                <div class="av2-kicker">Garage partenaire</div>
                <h1 class="av2-h1"><?php echo h($garage['nomGarage']); ?></h1>
                <p class="av2-sub"><?php echo h($garage['adresse'] ?: 'Adresse non renseignée'); ?></p>
            </div>
            <a href="garages.php" class="av2-btn-outline" style="text-decoration:none;">← Retour aux garages</a>
        </div>

        <div class="av2-stats av2-stats-3">
            <div class="av2-card av2-stat-card">
                <div class="av2-stat-icon" style="background:#EFF0F6;"><svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M14.5 4.5L19.5 9.5L9 20H4V15L14.5 4.5Z" stroke="#6D74A0" stroke-width="1.7" stroke-linejoin="round"/></svg></div>
                <div><div class="av2-stat-value"><?php echo count($techniciens); ?></div><div class="av2-stat-label">Techniciens</div></div>
            </div>
            <div class="av2-card av2-stat-card">
                <div class="av2-stat-icon" style="background:#E7F3FC;"><svg width="18" height="18" viewBox="0 0 24 24" fill="none"><rect x="4" y="3" width="16" height="18" rx="2" stroke="#1E7DBF" stroke-width="1.8"/></svg></div>
                <div><div class="av2-stat-value"><?php echo count($interventions); ?></div><div class="av2-stat-label">Interventions récentes</div></div>
            </div>
            <div class="av2-card av2-stat-card">
                <div class="av2-stat-icon" style="background:<?php echo $garage['statutGarage'] === 'VALIDE' ? '#E9F6EE' : '#FFF4E2'; ?>;"><svg width="18" height="18" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="<?php echo $garage['statutGarage'] === 'VALIDE' ? '#1E8A4C' : '#C8871A'; ?>" stroke-width="1.8"/></svg></div>
                <div><div class="av2-stat-value" style="font-size:17px;"><?php echo h(av2_status_label($garage['statutGarage'])); ?></div><div class="av2-stat-label">Statut</div></div>
            </div>
        </div>

        <div class="av2-body">
            <div class="av2-col">
                <div class="av2-card av2-panel">
                    <div class="av2-panel-head"><h2>Techniciens du garage</h2></div>
                    <?php if (empty($techniciens)): ?>
                        <div class="av2-empty">Aucun technicien rattaché à ce garage.</div>
                    <?php else: ?>
                        <div class="av2-table-wrap">
                            <table class="av2-table">
                                <thead><tr><th>Nom</th><th>Spécialité</th><th>Statut</th><th>Actives</th><th>Total</th></tr></thead>
                                <tbody>
                                    <?php foreach ($techniciens as $t): ?>
                                        <tr>
                                            <td><?php echo h($t['prenom'] . ' ' . $t['nom']); ?></td>
                                            <td><?php echo h($t['specialite'] ?: '—'); ?></td>
                                            <td><span class="av2-badge <?php echo h(av2_status_badge($t['statutValidation'])); ?>"><?php echo h(av2_status_label($t['statutValidation'])); ?></span></td>
                                            <td><?php echo (int)$t['actives']; ?></td>
                                            <td><?php echo (int)$t['total_interventions']; ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="av2-card av2-panel">
                    <div class="av2-panel-head"><h2>Interventions récentes</h2></div>
                    <?php if (empty($interventions)): ?>
                        <div class="av2-empty">Aucune intervention enregistrée.</div>
                    <?php else: ?>
                        <div style="display:flex; flex-direction:column; gap:10px;">
                            <?php foreach ($interventions as $iv): $ivBadge = $iv['statut'] === 'TERMINEE' ? 'ok' : ($iv['statut'] === 'ANNULEE' ? 'bad' : ($iv['statut'] === 'EN_COURS' ? 'warn' : 'info')); ?>
                                <div class="av2-row">
                                    <div style="flex-grow:1; min-width:0;">
                                        <div class="av2-row-title"><?php echo h($iv['type'] ?: 'Intervention'); ?> — <?php echo h($iv['marque'] . ' ' . $iv['modele']); ?></div>
                                        <div class="av2-row-meta"><?php echo h($iv['immatriculation']); ?> · <?php echo h(date('d/m/Y', strtotime($iv['dateIntervention']))); ?></div>
                                    </div>
                                    <span class="av2-badge <?php echo h($ivBadge); ?>"><?php echo h(ucfirst(strtolower($iv['statut']))); ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="av2-col">
                <div class="av2-card av2-panel-sm">
                    <div class="av2-panel-head"><h2>Contact</h2></div>
                    <div style="display:flex; flex-direction:column; gap:10px; font-size:13.5px;">
                        <?php if ($garage['idUtilisateur']): ?>
                            <div><strong>Responsable</strong><br><?php echo h($garage['prenom'] . ' ' . $garage['nom']); ?></div>
                            <div><strong>Email</strong><br><?php echo h($garage['email']); ?></div>
                            <div><strong>Téléphone</strong><br><?php echo h($garage['telephone'] ?: '—'); ?></div>
                            <div><strong>Intégré le</strong><br><?php echo h(date('d/m/Y', strtotime($garage['created_at']))); ?></div>
                        <?php else: ?>
                            <div class="av2-empty">Aucun compte de connexion lié.</div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="av2-card av2-panel-sm">
                    <div class="av2-panel-head"><h2>Documents</h2></div>
                    <?php if (empty($documents)): ?>
                        <div class="av2-empty">Aucun document envoyé par ce garage.</div>
                    <?php else: ?>
                        <div style="display:flex; flex-direction:column; gap:10px;">
                            <?php foreach ($documents as $doc): ?>
                                <div class="av2-row">
                                    <div style="flex-grow:1; min-width:0;">
                                        <div class="av2-row-title" style="font-size:13.5px;"><?php echo h($docTypes[$doc['typeDocument']] ?? $doc['typeDocument']); ?></div>
                                        <a href="<?php echo SITE_URL; ?>ajax/download_garage_document.php?id=<?php echo (int)$doc['idDocument']; ?>" target="_blank" class="av2-table-link">Voir le fichier</a>
                                    </div>
                                    <span class="av2-badge <?php echo h(av2_status_badge($doc['statutVerification'])); ?>"><?php echo h(av2_status_label($doc['statutVerification'])); ?></span>
                                    <?php if ($doc['statutVerification'] === 'EN_ATTENTE'): ?>
                                        <div style="display:flex; gap:8px; width:100%; margin-top:6px;">
                                            <form method="POST" action="garage_detail.php?id=<?php echo (int)$garageId; ?>&action=validate_document" style="display:inline;">
                                                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                                <input type="hidden" name="document_id" value="<?php echo (int)$doc['idDocument']; ?>">
                                                <button type="submit" class="av2-table-link av2-link-btn btn-confirm" data-confirm="Valider ce document ?">Valider</button>
                                            </form>
                                            <form method="POST" action="garage_detail.php?id=<?php echo (int)$garageId; ?>&action=reject_document" style="display:inline;">
                                                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                                <input type="hidden" name="document_id" value="<?php echo (int)$doc['idDocument']; ?>">
                                                <button type="submit" class="av2-table-link av2-link-btn btn-confirm" data-confirm="Rejeter ce document ?" style="color:#E5484D;">Rejeter</button>
                                            </form>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="av2-card av2-panel-sm">
                    <div class="av2-panel-head"><h2>Activité du garage</h2></div>
                    <?php if (empty($journal)): ?>
                        <div class="av2-empty">Aucune activité récente.</div>
                    <?php else: ?>
                        <div class="av2-timeline">
                            <?php foreach ($journal as $j): ?>
                                <div class="av2-timeline-item">
                                    <div class="av2-timeline-dot"><svg width="14" height="14" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="4" fill="#3956E8"/></svg></div>
                                    <div>
                                        <div class="av2-timeline-title"><?php echo h($j['nomActivite']); ?></div>
                                        <div class="av2-timeline-time"><?php echo h(date('d/m/Y H:i', strtotime($j['dateHeure']))); ?></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>
</div>

<?php include '../includes/footer.php'; ?>
