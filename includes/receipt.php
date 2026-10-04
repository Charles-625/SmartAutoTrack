<?php
/**
 * Reçu de paiement d'une réparation, au format PDF (includes/pdf_lite.php).
 *
 * Utilisé par ajax/download_receipt.php, qui charge le paiement (contrôle
 * d'accès compris) puis appelle receiptBuildPdf(). Seul un paiement de
 * RÉPARATION (paiement.idReparation renseigné) au statut PAYE donne un reçu ;
 * les paiements d'abonnement Premium ne sont pas concernés.
 *
 * Fonctions pures (testées par tests/Unit/ReceiptTest.php) :
 *   - receiptNumber()       : n° de reçu affiché, REC-<idPaiement> ;
 *   - receiptMaskPhone()    : numéro Mobile Money masqué, 2 derniers chiffres
 *                             gardés (237651797837 → 6XX XX XX 37) ;
 *   - receiptFormatAmount() : montant « 25 000 XAF » (espaces insécables) ;
 *   - receiptText()         : décodage des entités HTML des saisies.
 * Aucun accès à la base ici.
 */

require_once __DIR__ . '/pdf_lite.php';

/** N° de reçu affiché sur le document : REC-<idPaiement>. */
function receiptNumber(int $paiementId): string {
    return 'REC-' . $paiementId;
}

/**
 * Numéro Mobile Money masqué pour le reçu : seuls le premier chiffre (6,
 * commun à tous les mobiles camerounais) et les 2 derniers restent visibles,
 * groupés comme un numéro national (6XX XX XX 37). L'indicatif 237 éventuel
 * est retiré. Un numéro vide donne « — » ; un numéro trop court pour être
 * masqué utilement (moins de 4 chiffres) est entièrement masqué.
 */
function receiptMaskPhone(?string $phone): string {
    $digits = preg_replace('/\D+/', '', (string)$phone);
    if (strlen($digits) === 12 && strncmp($digits, '237', 3) === 0) {
        $digits = substr($digits, 3);
    }
    $len = strlen($digits);
    if ($len === 0) {
        return '—';
    }
    if ($len < 4) {
        return str_repeat('X', $len);
    }
    $masked = $digits[0] . str_repeat('X', $len - 3) . substr($digits, -2);
    // Groupement 3-2-2-2 pour un numéro national à 9 chiffres, sinon par 3.
    $groups = $len === 9 ? [3, 2, 2, 2] : array_fill(0, (int)ceil($len / 3), 3);
    $parts = [];
    $offset = 0;
    foreach ($groups as $size) {
        if ($offset >= $len) break;
        $parts[] = substr($masked, $offset, $size);
        $offset += $size;
    }
    return implode(' ', $parts);
}

/**
 * Montant en XAF sans décimales, séparateur de milliers et espace avant
 * l'unité insécables (jamais coupé en fin de ligne) : « 25 000 XAF ».
 */
function receiptFormatAmount($amount): string {
    $nbsp = "\u{00A0}";
    return number_format((float)$amount, 0, ',', $nbsp) . $nbsp . 'XAF';
}

/**
 * Texte en clair pour le PDF : les saisies passent par sanitize() avant
 * stockage (entités HTML) ; le PDF n'est pas du HTML, on les décode.
 */
function receiptText($value): string {
    return trim(html_entity_decode((string)($value ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
}

/**
 * Construit le PDF du reçu de paiement.
 *
 * @param array $p Ligne chargée par ajax/download_receipt.php : idPaiement,
 *                 montant, datePaiement, telephone, operateur, referenceCampay,
 *                 referenceExterne, client_nom, client_prenom, raisonSociale,
 *                 idReparation, reparation_titre, idIntervention,
 *                 intervention_type, marque, modele, immatriculation, garage_nom.
 * @param string|null $demoNote Mention du mode démonstration CamPay, ou null.
 * @param bool $compress false pour des flux lisibles (tests).
 * @return string Binaire PDF.
 */
function receiptBuildPdf(array $p, ?string $demoNote = null, bool $compress = true): string {
    $blue = [21, 101, 192];
    $id = (int)$p['idPaiement'];
    $number = receiptNumber($id);

    $pdf = new PdfLite();
    $pdf->setCompression($compress);
    $pdf->setTitle('Reçu de paiement ' . $number);
    $pdf->setFooterText('Document généré le ' . date('d/m/Y à H:i') . ' par SmartAutoTrack');
    $pdf->addPage();
    $left = $pdf->leftX();
    $width = $pdf->contentWidth();
    $right = $left + $width;

    // Bandeau d'en-tête sur toute la largeur de la page.
    $pdf->rect(0, 0, PdfLite::PAGE_WIDTH, 74, $blue);
    $pdf->setTextColor(255, 255, 255);
    $pdf->setFont(true, 17);
    $pdf->text($left, 36, 'SmartAutoTrack — Reçu de paiement');
    $pdf->setFont(false, 10);
    $pdf->text($left, 56, 'Suivi d\'entretien et de réparation automobile');

    // Référence : n° de reçu à gauche, date du paiement à droite.
    $pdf->setTextColor(33, 37, 41);
    $pdf->setFont(true, 11);
    $pdf->text($left, 104, 'Reçu n° ' . $number);
    $pdf->setFont(false, 10);
    $date = !empty($p['datePaiement']) ? date('d/m/Y à H:i', strtotime($p['datePaiement'])) : '—';
    $pdf->textRight($right, 104, 'Date du paiement : ' . $date);
    $pdf->line($left, 114, $right, 114, [210, 220, 235], 0.8);
    $pdf->setY(128);

    // Bloc Client : la raison sociale n'apparaît que pour une entreprise.
    $client = ['Nom' => receiptText($p['client_nom'] ?? ''), 'Prénom' => receiptText($p['client_prenom'] ?? '')];
    $raison = receiptText($p['raisonSociale'] ?? '');
    if ($raison !== '') {
        $client = ['Raison sociale' => $raison] + $client;
    }
    receiptPdfBlock($pdf, 'Client', $client, $blue);

    // Bloc Réparation.
    $vehicule = receiptText(trim(($p['marque'] ?? '') . ' ' . ($p['modele'] ?? '')));
    $immat = receiptText($p['immatriculation'] ?? '');
    $intervention = !empty($p['idIntervention']) ? 'N° ' . (int)$p['idIntervention'] : '';
    $motif = receiptText($p['intervention_type'] ?? '');
    if ($motif !== '') {
        $intervention .= ($intervention !== '' ? ' — ' : '') . $motif;
    }
    receiptPdfBlock($pdf, 'Réparation', [
        'Titre' => receiptText($p['reparation_titre'] ?? '') ?: 'Réparation',
        'N° de réparation' => (string)(int)$p['idReparation'],
        'Intervention' => $intervention,
        'Véhicule' => $vehicule . ($immat !== '' ? ' — ' . $immat : ''),
        'Prise en charge' => receiptText($p['garage_nom'] ?? '') ?: 'SmartAutoTrack',
    ], $blue);

    // Bloc Paiement.
    receiptPdfBlock($pdf, 'Paiement', [
        'Montant' => receiptFormatAmount($p['montant'] ?? 0),
        'Moyen de paiement' => 'Mobile Money',
        'Opérateur' => receiptText($p['operateur'] ?? ''),
        'Numéro' => receiptMaskPhone($p['telephone'] ?? ''),
        'Référence CamPay' => receiptText($p['referenceCampay'] ?? ''),
        'Référence SmartAutoTrack' => receiptText($p['referenceExterne'] ?? ''),
    ], $blue);

    // Mention de confirmation (et du mode démonstration le cas échéant).
    $pdf->ensureSpace(60);
    $y = $pdf->getY();
    $pdf->rect($left, $y, $width, 30, [232, 245, 233], [165, 214, 167]);
    $pdf->setFont(true, 11);
    $pdf->setTextColor(27, 94, 32);
    $pdf->text($left + 12, $y + 19, 'Paiement confirmé par CamPay');
    $pdf->setY($y + 42);
    if ($demoNote !== null && $demoNote !== '') {
        $pdf->setFont(false, 9);
        $pdf->setTextColor(138, 90, 0);
        $pdf->writeText($demoNote, $left, $width, 12);
    }

    return $pdf->output();
}

/**
 * Bloc titré du reçu : titre de section (couleur d'accent, filet) puis cadre
 * de fond clair, une paire libellé / valeur par ligne (valeur vide → « — »).
 */
function receiptPdfBlock(PdfLite $pdf, string $title, array $rows, array $color): void {
    $left = $pdf->leftX();
    $width = $pdf->contentWidth();
    $labelW = 150;
    $pad = 10;
    $lineH = 14;

    $pdf->setFont(false, 10);
    $wrapped = [];
    $boxH = 2 * $pad;
    foreach ($rows as $label => $value) {
        $wrapped[$label] = $pdf->wrapText($value !== '' ? $value : '—', $width - $labelW - 2 * $pad);
        $boxH += count($wrapped[$label]) * $lineH + 4;
    }
    // Le titre et son cadre restent sur la même page.
    $pdf->ensureSpace($boxH + 28);
    $y = $pdf->getY();
    $pdf->setFont(true, 13);
    $pdf->setTextColor($color[0], $color[1], $color[2]);
    $pdf->text($left, $y + 11, $title);
    $pdf->line($left, $y + 18, $left + $width, $y + 18, $color, 1);

    $top = $y + 28;
    $pdf->rect($left, $top, $width, $boxH, [245, 248, 252], [210, 220, 235]);
    $pdf->setY($top + $pad);
    foreach ($wrapped as $label => $lines) {
        $pdf->setFont(true, 10);
        $pdf->setTextColor(90, 98, 110);
        $pdf->text($left + $pad, $pdf->getY() + 8, $label);
        $pdf->setFont(false, 10);
        $pdf->setTextColor(33, 37, 41);
        $pdf->writeText(implode("\n", $lines), $left + $pad + $labelW, $width - $labelW - 2 * $pad, $lineH);
        $pdf->setY($pdf->getY() + 4);
    }
    $pdf->setY($top + $boxH + 22);
}
