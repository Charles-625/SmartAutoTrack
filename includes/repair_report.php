<?php
/**
 * Rapport de fin d'intervention : clôture d'une réparation, commune au
 * technicien (technicien/reparations.php) et au garage (garage/reparations.php).
 *
 * Le formulaire de fin de réparation et son traitement étaient dupliqués dans
 * les deux pages ; ils sont réunis ici :
 *   - repairReportFormFields() : champs du formulaire (aussi utilisés par la
 *                                fenêtre « Marquer terminée » de
 *                                technicien/taches.php et technicien/interventions.php) ;
 *   - repairReportParse()      : lecture et validation des champs POST ;
 *   - repairReportClose()      : enregistrement, dans UNE transaction, de la
 *                                réparation, de la clôture de l'intervention,
 *                                du véhicule (kilométrage, état), des anomalies
 *                                résolues, du journal et de la notification client ;
 *   - repairReportCompose()    : texte lisible du rapport, écrit dans le journal
 *                                d'activité (visible du client, du garage, du
 *                                technicien et de l'admin via activity_log_fetch()).
 *
 * Les colonnes reparation.kilometrage et reparation.etatVehicule sont ajoutées
 * par scripts/migrate_structure.php (section 5). Tant qu'elles manquent,
 * repairReportReady() renvoie false : la réparation est enregistrée sans
 * elles, le véhicule et le journal reçoivent quand même les deux valeurs.
 *
 * Tables : reparation, intervention, vehicule, anomalie, notifications
 *          (écriture), journalactivites (via log_activity()).
 */

require_once __DIR__ . '/activity_log.php';

/** États du véhicule à la sortie (liste blanche) : valeur stockée => libellé affiché. */
const REPAIR_VEHICLE_STATES = [
    'BON' => 'Bon',
    'A_SURVEILLER' => 'À surveiller',
    'HORS_SERVICE' => 'Hors service',
];

/** Kilométrage maximal accepté (reste dans un INT MySQL). */
const REPAIR_MAX_KILOMETRAGE = 9999999;

// Nom de l'activité écrite dans le journal à la clôture : REPAIR_REPORT_ACTIVITY,
// défini dans includes/activity_log.php (reconnu par activity_log_is_report()).

/** Les colonnes kilometrage et etatVehicule de `reparation` existent (scripts/migrate_structure.php appliqué). */
function repairReportReady(PDO $conn): bool {
    static $ready = null;
    if ($ready === null) {
        $stmt = $conn->query("
            SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'reparation'
              AND COLUMN_NAME IN ('kilometrage', 'etatVehicule')
        ");
        $ready = (int)$stmt->fetchColumn() === 2;
    }
    return $ready;
}

/**
 * Lit et valide les champs du formulaire de fin de réparation.
 *
 * Les textes passent par sanitize(), comme avant la factorisation. Le
 * kilométrage n'est comparé ici qu'aux bornes fixes : la comparaison avec le
 * kilométrage actuel du véhicule se fait dans repairReportClose(), sur la
 * ligne verrouillée.
 *
 * @param array $post Données POST du formulaire.
 * @return array{data: array, errors: string[]} data : interventionId, titre,
 *         description, diagnostic, travaux, pieces, duree, cout,
 *         recommandations, kilometrage (int|null), etatVehicule (clé de
 *         REPAIR_VEHICLE_STATES ou '').
 */
function repairReportParse(array $post): array {
    $km = trim((string)($post['kilometrage'] ?? ''));
    $etat = (string)($post['etat_vehicule'] ?? '');
    $data = [
        'interventionId' => filter_var($post['intervention_id'] ?? null, FILTER_VALIDATE_INT),
        'titre' => sanitize($post['titre'] ?? ''),
        'description' => sanitize($post['description'] ?? ''),
        'diagnostic' => sanitize($post['diagnostic'] ?? ''),
        'travaux' => sanitize($post['travaux_effectues'] ?? ''),
        'pieces' => sanitize($post['pieces_utilisees'] ?? ''),
        'duree' => (float)($post['duree_intervention'] ?? 0),
        'cout' => (float)($post['cout'] ?? 0),
        'recommandations' => sanitize($post['recommandations'] ?? ''),
        'kilometrage' => ctype_digit($km) ? (int)$km : null,
        'etatVehicule' => isset(REPAIR_VEHICLE_STATES[$etat]) ? $etat : '',
    ];

    $errors = [];
    if (!$data['interventionId']) $errors[] = 'Intervention requise.';
    if (empty($data['titre'])) $errors[] = 'Titre requis.';
    if (empty($data['description'])) $errors[] = 'Description requise.';
    if (empty($data['diagnostic'])) $errors[] = 'Diagnostic requis.';
    if (empty($data['travaux'])) $errors[] = 'Travaux effectués requis.';
    if ($data['duree'] <= 0) $errors[] = 'Durée d\'intervention requise.';
    if ($data['cout'] < 0) $errors[] = 'Coût invalide.';
    if ($km === '') {
        $errors[] = 'Kilométrage relevé requis.';
    } elseif ($data['kilometrage'] === null || $data['kilometrage'] > REPAIR_MAX_KILOMETRAGE) {
        $errors[] = 'Kilométrage relevé invalide : saisissez un nombre entier de kilomètres.';
    }
    if ($data['etatVehicule'] === '') $errors[] = 'État du véhicule à la sortie requis.';

    return ['data' => $data, 'errors' => $errors];
}

/**
 * Formate un entier avec des espaces entre les milliers (ex. 25 000).
 *
 * @param float|int $value
 * @return string
 */
function repairReportNumber($value): string {
    return number_format((float)$value, 0, ',', ' ');
}

/**
 * Texte du rapport de fin d'intervention, une information par ligne
 * (« Libellé : valeur »). Les lignes sans valeur sont omises. Les valeurs
 * sont celles de repairReportParse() (déjà passées par sanitize()) : à
 * l'affichage, h() n'encode pas une seconde fois.
 *
 * @param array $data Données validées (voir repairReportParse()).
 * @return string Texte multi-lignes (séparateur "\n").
 */
function repairReportCompose(array $data): string {
    $duree = (float)($data['duree'] ?? 0);
    $dureeTxt = $duree > 0 ? rtrim(rtrim(number_format($duree, 2, ',', ' '), '0'), ',') . ' h' : '';
    $km = $data['kilometrage'] ?? null;
    $etat = $data['etatVehicule'] ?? '';

    $lines = [
        'Titre' => $data['titre'] ?? '',
        'Diagnostic' => $data['diagnostic'] ?? '',
        'Travaux effectués' => $data['travaux'] ?? '',
        'Pièces utilisées' => $data['pieces'] ?? '',
        'Durée' => $dureeTxt,
        'Coût' => repairReportNumber($data['cout'] ?? 0) . ' XAF',
        'Kilométrage relevé' => $km !== null ? repairReportNumber($km) . ' km' : '',
        'État du véhicule' => REPAIR_VEHICLE_STATES[$etat] ?? '',
        'Recommandations' => $data['recommandations'] ?? '',
    ];

    $out = [];
    foreach ($lines as $label => $value) {
        $value = trim((string)$value);
        if ($value !== '') $out[] = $label . ' : ' . $value;
    }
    return implode("\n", $out);
}

/**
 * Enregistre la fin d'une intervention, dans une seule transaction :
 *   1. verrouille l'intervention (EN_COURS, dans le périmètre $scope) et son véhicule ;
 *   2. refuse un kilométrage relevé inférieur au kilométrage actuel du véhicule ;
 *   3. crée la réparation TERMINEE (avec kilometrage/etatVehicule si
 *      repairReportReady()) ;
 *   4. clôture l'intervention (TERMINEE) ;
 *   5. met à jour vehicule.kilometrage et vehicule.etat ;
 *   6. passe les anomalies NOUVELLE/EN_COURS de l'intervention à TRAITEE ;
 *   7. écrit le rapport complet dans le journal (REPAIR_REPORT_ACTIVITY) ;
 *   8. notifie le client (avec, si le coût est non nul, l'invitation à
 *      régler par Mobile Money depuis l'onglet Réparations).
 * En cas d'exception, la transaction est annulée et l'exception relancée.
 *
 * @param PDO         $conn
 * @param array       $scope     Périmètre autorisé, une seule clé :
 *                               ['idTechnicien' => id] (technicien : SES interventions)
 *                               ou ['idGarage' => id] (garage : celles de SON garage).
 * @param array       $data      Données validées par repairReportParse().
 * @param int         $actorId   idUtilisateur de la session (auteur de l'entrée du journal).
 * @param string|null $garageNom Nom du garage ajouté au message de notification (page garage).
 * @return array{ok: bool, error: string|null, kmActuel: int|null, idReparation: int|null}
 *         error : 'not_found' (hors périmètre ou plus EN_COURS) ou 'km_too_low'.
 * @throws InvalidArgumentException si $scope n'est pas l'une des deux formes prévues.
 */
function repairReportClose(PDO $conn, array $scope, array $data, int $actorId, ?string $garageNom = null): array {
    // Liste blanche : la colonne de périmètre est insérée dans le SQL.
    $scopeColumns = ['idTechnicien' => 'idTechnicien', 'idGarage' => 'idGarage'];
    $key = count($scope) === 1 ? (string)array_key_first($scope) : '';
    if (!isset($scopeColumns[$key]) || (int)$scope[$key] <= 0) {
        throw new InvalidArgumentException('Périmètre de clôture invalide.');
    }
    $scopeCol = $scopeColumns[$key];
    $scopeId = (int)$scope[$key];
    $interventionId = (int)$data['interventionId'];

    $conn->beginTransaction();
    try {
        $stmt = $conn->prepare("
            SELECT i.idIntervention, i.idClient, i.idTechnicien, i.idVehicule, v.kilometrage
            FROM intervention i
            JOIN vehicule v ON v.idVehicule = i.idVehicule
            WHERE i.idIntervention = ? AND i.$scopeCol = ? AND i.statut = 'EN_COURS'
            FOR UPDATE
        ");
        $stmt->execute([$interventionId, $scopeId]);
        $iv = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$iv) {
            $conn->rollBack();
            return ['ok' => false, 'error' => 'not_found', 'kmActuel' => null, 'idReparation' => null];
        }
        $kmActuel = (int)$iv['kilometrage'];
        if ((int)$data['kilometrage'] < $kmActuel) {
            $conn->rollBack();
            return ['ok' => false, 'error' => 'km_too_low', 'kmActuel' => $kmActuel, 'idReparation' => null];
        }
        $technicienId = $iv['idTechnicien'] !== null ? (int)$iv['idTechnicien'] : null;

        $cols = 'idIntervention, idTechnicien, titre, description, diagnostic, travauxEffectues, piecesUtilisees, dureeIntervention, cout, recommandations';
        $params = [$interventionId, $technicienId, $data['titre'], $data['description'], $data['diagnostic'], $data['travaux'], $data['pieces'], $data['duree'], $data['cout'], $data['recommandations']];
        if (repairReportReady($conn)) {
            $cols .= ', kilometrage, etatVehicule';
            $params[] = (int)$data['kilometrage'];
            $params[] = $data['etatVehicule'];
        }
        $marks = implode(', ', array_fill(0, count($params), '?'));
        $conn->prepare("INSERT INTO reparation ($cols, statut) VALUES ($marks, 'TERMINEE')")->execute($params);
        $reparationId = (int)$conn->lastInsertId();

        $conn->prepare("UPDATE intervention SET statut = 'TERMINEE' WHERE idIntervention = ? AND $scopeCol = ?")->execute([$interventionId, $scopeId]);

        $conn->prepare("UPDATE vehicule SET kilometrage = ?, etat = ? WHERE idVehicule = ?")
            ->execute([(int)$data['kilometrage'], $data['etatVehicule'], (int)$iv['idVehicule']]);

        // La réparation règle les anomalies constatées sur cette intervention
        $conn->prepare("UPDATE anomalie SET statut = 'TRAITEE', dateResolution = NOW() WHERE idIntervention = ? AND statut IN ('NOUVELLE', 'EN_COURS')")->execute([$interventionId]);

        // Une seule entrée, rattachée à l'intervention : activity_log_fetch() la
        // montre au client (i.idClient), au garage (idGarage déduit), au
        // technicien (idTechnicien) et à l'admin.
        log_activity($conn, REPAIR_REPORT_ACTIVITY, [
            'idUtilisateur' => $actorId,
            'idIntervention' => $interventionId,
            'idReparation' => $reparationId,
            'idTechnicien' => $technicienId,
            'categorie' => 'reparation',
            'description' => repairReportCompose($data),
        ]);

        // Réparation facturée : le message rappelle où la régler (texte seul,
        // les notifications n'ont pas de lien).
        $message = 'Le rapport de réparation pour votre véhicule est disponible' . ($garageNom !== null && $garageNom !== '' ? ' (' . $garageNom . ')' : '') . '.'
            . ((float)$data['cout'] > 0 ? " Vous pouvez la régler par Mobile Money depuis l'onglet Réparations." : '');
        $conn->prepare("INSERT INTO notifications (user_id, type, titre, message) VALUES (?, 'rapport', 'Rapport de réparation disponible', ?)")
            ->execute([$iv['idClient'], $message]);

        $conn->commit();
        return ['ok' => true, 'error' => null, 'kmActuel' => $kmActuel, 'idReparation' => $reparationId];
    } catch (Throwable $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        throw $e;
    }
}

/**
 * Message d'erreur affiché quand le kilométrage relevé est inférieur au
 * kilométrage actuel du véhicule.
 *
 * @param int $saisi    Kilométrage saisi.
 * @param int $kmActuel Kilométrage actuel du véhicule.
 * @return string
 */
function repairReportKmError(int $saisi, int $kmActuel): string {
    return 'Le kilométrage relevé (' . repairReportNumber($saisi) . ' km) est inférieur au kilométrage actuel du véhicule ('
        . repairReportNumber($kmActuel) . ' km). Vérifiez le compteur.';
}

/**
 * Affiche les champs du formulaire de fin de réparation (tout sauf
 * l'intervention, le jeton CSRF et les boutons, propres à chaque page).
 *
 * @param string   $p        Préfixe des classes CSS : 'tv2' (technicien) ou 'gv2' (garage).
 * @param array    $old      Valeurs à réafficher après une erreur (clés = noms des champs POST).
 * @param int|null $kmActuel Kilométrage actuel du véhicule, affiché en aide et
 *                           utilisé comme minimum ; null s'il n'est pas encore connu
 *                           (le script de la page le renseigne alors dans #repKmActuel).
 * @return void
 */
function repairReportFormFields(string $p, array $old = [], ?int $kmActuel = null): void {
    $p = $p === 'gv2' ? 'gv2' : 'tv2';
    $v = function (string $name) use ($old): string {
        return (string)($old[$name] ?? '');
    };
    ?>
            <div class="<?php echo h($p); ?>-form-group"><label for="repTitre">Titre</label><input type="text" name="titre" id="repTitre" required value="<?php echo h($v('titre')); ?>" placeholder="Ex. Remplacement des plaquettes de frein"></div>
            <div class="<?php echo h($p); ?>-form-group"><label for="repDescription">Description générale</label><textarea name="description" id="repDescription" required placeholder="Ex. Bruit métallique au freinage à l'avant…"><?php echo h($v('description')); ?></textarea></div>
            <div class="<?php echo h($p); ?>-form-group"><label for="repDiagnostic">Diagnostic</label><textarea name="diagnostic" id="repDiagnostic" required placeholder="Ex. Plaquettes avant usées à 90 %…"><?php echo h($v('diagnostic')); ?></textarea></div>
            <div class="<?php echo h($p); ?>-form-group"><label for="repTravaux">Travaux effectués</label><textarea name="travaux_effectues" id="repTravaux" required placeholder="Ex. Remplacement des plaquettes avant, purge du circuit…"><?php echo h($v('travaux_effectues')); ?></textarea></div>
            <div class="<?php echo h($p); ?>-form-group"><label for="repPieces">Pièces utilisées</label><textarea name="pieces_utilisees" id="repPieces" placeholder="Ex. 2 plaquettes avant Bosch, liquide de frein DOT4"><?php echo h($v('pieces_utilisees')); ?></textarea></div>
            <div class="<?php echo h($p); ?>-form-row">
                <div class="<?php echo h($p); ?>-form-group"><label for="repDuree">Durée (heures)</label><input type="number" name="duree_intervention" id="repDuree" required min="0" step="0.5" value="<?php echo h($v('duree_intervention')); ?>" placeholder="Ex. 1.5"></div>
                <div class="<?php echo h($p); ?>-form-group"><label for="repCout">Coût (XAF)</label><input type="number" name="cout" data-only="digits" inputmode="numeric" id="repCout" required min="0" step="1" value="<?php echo h($v('cout')); ?>" placeholder="Ex. 25000"></div>
            </div>
            <div class="<?php echo h($p); ?>-form-row">
                <div class="<?php echo h($p); ?>-form-group">
                    <label for="repKilometrage">Kilométrage relevé (km)</label>
                    <input type="number" name="kilometrage" data-only="digits" inputmode="numeric" id="repKilometrage" required min="<?php echo (int)($kmActuel ?? 0); ?>" max="<?php echo (int)REPAIR_MAX_KILOMETRAGE; ?>" step="1" value="<?php echo h($v('kilometrage')); ?>" placeholder="Ex. 85000">
                    <div id="repKmActuel" style="font-size:12px; margin-top:4px; opacity:.75;"><?php echo h($kmActuel !== null ? 'Kilométrage actuel : ' . repairReportNumber($kmActuel) . ' km' : ''); ?></div>
                </div>
                <div class="<?php echo h($p); ?>-form-group">
                    <label for="repEtat">État du véhicule à la sortie</label>
                    <select name="etat_vehicule" id="repEtat" required>
                        <option value="">Sélectionner l'état</option>
                        <?php foreach (REPAIR_VEHICLE_STATES as $key => $label): ?>
                            <option value="<?php echo h($key); ?>" <?php echo $v('etat_vehicule') === $key ? 'selected' : ''; ?>><?php echo h($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="<?php echo h($p); ?>-form-group"><label for="repRecommandations">Recommandations</label><textarea name="recommandations" id="repRecommandations" placeholder="Ex. Contrôler les disques dans 5 000 km"><?php echo h($v('recommandations')); ?></textarea></div>
    <?php
}

/**
 * Page de retour autorisée après une clôture lancée depuis la fenêtre
 * « Marquer terminée » (liste blanche : jamais une URL libre).
 *
 * @param mixed $value Valeur reçue (GET ou POST « return »).
 * @return string 'taches', 'interventions' ou '' (rester sur reparations.php).
 */
function repairReportReturnKey($value): string {
    $allowed = ['taches', 'interventions'];
    return in_array($value, $allowed, true) ? $value : '';
}

/**
 * Lien « Marquer terminée » d'une intervention EN_COURS (technicien). Sans
 * JavaScript, il ouvre le formulaire de technicien/reparations.php ; avec,
 * le script de repairReportFinishModal() l'intercepte et ouvre la fenêtre
 * sur la page même, pré-remplie grâce aux attributs data-*.
 *
 * @param array  $iv        Intervention : id, type, marque, modele, immatriculation,
 *                          kilometrage, client_prenom, client_nom.
 * @param string $returnKey Page d'origine ('taches' ou 'interventions').
 * @param string $class     Classes CSS du lien.
 * @return void
 */
function repairReportFinishLink(array $iv, string $returnKey, string $class): void {
    $returnKey = repairReportReturnKey($returnKey);
    $href = 'reparations.php?action=new&intervention_id=' . (int)$iv['id'] . ($returnKey !== '' ? '&return=' . $returnKey : '');
    $label = ($iv['type'] ?: 'Intervention') . ' — client : ' . $iv['client_prenom'] . ' ' . $iv['client_nom'];
    $vehicule = $iv['marque'] . ' ' . $iv['modele'] . ' (' . $iv['immatriculation'] . ')';
    ?><a href="<?php echo h($href); ?>" class="<?php echo h($class); ?> js-finish-repair" style="text-decoration:none;"
       data-id="<?php echo (int)$iv['id']; ?>" data-label="<?php echo h($label); ?>"
       data-vehicule="<?php echo h($vehicule); ?>" data-km="<?php echo (int)$iv['kilometrage']; ?>">Marquer terminée</a><?php
}

/**
 * Fenêtre « Marquer terminée » (technicien/taches.php, technicien/interventions.php) :
 * le formulaire complet de fin de réparation, envoyé en POST (+ CSRF) au
 * traitement de technicien/reparations.php (form=new_reparation), avec
 * return=$returnKey pour revenir ensuite sur la page d'origine. Le contrôle
 * de propriété et d'état reste fait côté serveur par repairReportClose().
 *
 * @param string $returnKey Page d'origine ('taches' ou 'interventions').
 * @return void
 */
function repairReportFinishModal(string $returnKey): void {
    $returnKey = repairReportReturnKey($returnKey);
    ?>
<div class="tv2-modal-overlay" id="finishRepairOverlay">
    <div class="tv2-modal">
        <h3>Marquer l'intervention terminée</h3>
        <p class="tv2-modal-sub">Renseignez le rapport de fin d'intervention : il clôture l'intervention et s'ajoute au journal d'activité du client, du garage et au vôtre.</p>
        <div style="background:#F7F3EC; border-radius:12px; padding:12px 14px; margin-bottom:16px; font-size:13.5px; line-height:1.5;">
            <div><strong>Intervention :</strong> <span id="finishRepairLabel"></span></div>
            <div><strong>Véhicule :</strong> <span id="finishRepairVehicule"></span></div>
            <div><strong>Kilométrage actuel :</strong> <span id="finishRepairKm"></span></div>
        </div>
        <form method="POST" action="reparations.php" id="finishRepairForm">
            <input type="hidden" name="form" value="new_reparation">
            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
            <input type="hidden" name="return" value="<?php echo h($returnKey); ?>">
            <input type="hidden" name="intervention_id" id="finishRepairIntervention" value="">
            <?php repairReportFormFields('tv2'); ?>
            <div class="tv2-modal-actions">
                <button type="button" class="tv2-btn-outline" id="finishRepairCancel">Annuler</button>
                <button type="submit" class="tv2-btn-primary">Enregistrer et clôturer</button>
            </div>
        </form>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var overlay = document.getElementById('finishRepairOverlay');
    var form = document.getElementById('finishRepairForm');
    var km = document.getElementById('repKilometrage');
    function close() { overlay.classList.remove('show'); }
    document.getElementById('finishRepairCancel').addEventListener('click', close);
    overlay.addEventListener('click', function (e) { if (e.target === overlay) close(); });
    document.querySelectorAll('.js-finish-repair').forEach(function (link) {
        link.addEventListener('click', function (e) {
            e.preventDefault();
            var kmActuel = parseInt(link.dataset.km, 10) || 0;
            var kmTxt = kmActuel.toLocaleString('fr-FR') + ' km';
            form.reset();
            document.getElementById('finishRepairIntervention').value = link.dataset.id;
            document.getElementById('finishRepairLabel').textContent = link.dataset.label;
            document.getElementById('finishRepairVehicule').textContent = link.dataset.vehicule;
            document.getElementById('finishRepairKm').textContent = kmTxt;
            document.getElementById('repKmActuel').textContent = 'Kilométrage actuel : ' + kmTxt;
            km.min = kmActuel;
            overlay.classList.add('show');
            document.getElementById('repTitre').focus();
        });
    });
});
</script>
    <?php
}
