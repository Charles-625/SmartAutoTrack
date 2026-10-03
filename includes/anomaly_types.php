<?php
/**
 * Référentiel des anomalies : types proposés et niveaux de gravité.
 *
 * Défini une seule fois ici, utilisé par :
 *   - client/interventions.php : motif « Anomalie constatée » de la demande
 *     d'intervention (type et gravité choisis dans des listes fermées,
 *     revalidés côté serveur par anomaly_resolve_type() / anomaly_is_valid_niveau() ;
 *     pour « Autre », le client précise lui-même ce qu'il constate) ;
 *   - garage/anomalies.php et technicien/anomalies.php : suggestions
 *     (<datalist>) du champ type, qui reste libre pour un professionnel ;
 *   - garage/demandes.php et admin/anomalies.php : libellés et repérage des
 *     anomalies déclarées par le client.
 * Aucune table propre : anomalie.type est un VARCHAR(100) libre, anomalie.niveau
 * l'ENUM FAIBLE / MOYEN / CRITIQUE.
 */

/** Motif de demande d'intervention qui déclare une anomalie (intervention.type). */
const ANOMALY_REQUEST_MOTIF = 'Anomalie constatée';

/**
 * Intitulé de l'entrée du journal d'activité écrite quand le client déclare
 * une anomalie à sa demande d'intervention. Sert aussi à reconnaître ces
 * anomalies (« Constatée par : Client ») sans colonne supplémentaire.
 */
const ANOMALY_CLIENT_LOG_NAME = 'Anomalie déclarée par le client';

/** Types d'anomalie proposés, dans l'ordre d'affichage (valeur stockée = libellé). */
const ANOMALY_TYPES = [
    'Freinage',
    'Moteur',
    'Transmission / boîte de vitesses',
    'Électricité / batterie',
    'Pneumatiques',
    'Suspension / direction',
    'Climatisation',
    'Éclairage',
    'Carrosserie',
    'Autre',
];

/**
 * Type « Autre » : le client décrit alors lui-même ce qu'il constate (champ
 * anomalie_autre), et c'est ce texte qui est enregistré dans anomalie.type.
 */
const ANOMALY_TYPE_OTHER = 'Autre';

/** Longueur du type saisi librement (bornée par anomalie.type, VARCHAR(100)). */
const ANOMALY_CUSTOM_TYPE_MIN = 3;
const ANOMALY_CUSTOM_TYPE_MAX = 100;

/**
 * Gravité choisie par le client, en mots simples, associée au niveau
 * enregistré (anomalie.niveau). L'ordre est celui de la liste déroulante.
 */
const ANOMALY_CLIENT_SEVERITIES = [
    'FAIBLE'   => 'Je peux rouler normalement',
    'MOYEN'    => 'Je dois faire attention',
    'CRITIQUE' => 'Véhicule immobilisé / dangereux',
];

/** Vrai si $type fait partie de la liste fermée ANOMALY_TYPES (comparaison stricte). */
function anomaly_is_valid_type(string $type): bool {
    return in_array($type, ANOMALY_TYPES, true);
}

/**
 * Type d'anomalie à enregistrer pour une déclaration du client.
 *
 * Un type de la liste est repris tel quel ; pour « Autre », c'est la saisie
 * libre $custom qui est retenue, espaces superflus retirés. Elle doit faire
 * de ANOMALY_CUSTOM_TYPE_MIN à ANOMALY_CUSTOM_TYPE_MAX caractères et ne
 * contenir que des lettres (accents compris), chiffres, espaces et la
 * ponctuation courante . , ' - / ( ) — jamais de balise ni de symbole.
 *
 * @param string $type   Valeur choisie dans la liste ANOMALY_TYPES.
 * @param string $custom Précision saisie par le client (utilisée pour « Autre »).
 * @return string|null Type à stocker dans anomalie.type, ou null si invalide.
 */
function anomaly_resolve_type(string $type, string $custom = ''): ?string {
    if (!anomaly_is_valid_type($type)) {
        return null;
    }
    if ($type !== ANOMALY_TYPE_OTHER) {
        return $type;
    }
    $custom = trim(preg_replace('/\s+/u', ' ', $custom));
    $length = mb_strlen($custom, 'UTF-8');
    if ($length < ANOMALY_CUSTOM_TYPE_MIN || $length > ANOMALY_CUSTOM_TYPE_MAX) {
        return null;
    }
    return preg_match("/^[A-Za-zÀ-ÖØ-öø-ÿ0-9][A-Za-zÀ-ÖØ-öø-ÿ0-9 .,'\\/()-]*$/u", $custom) === 1 ? $custom : null;
}

/** Vrai si $niveau est un niveau d'anomalie connu (FAIBLE, MOYEN, CRITIQUE). */
function anomaly_is_valid_niveau(string $niveau): bool {
    return array_key_exists($niveau, ANOMALY_CLIENT_SEVERITIES);
}

/**
 * Libellé simple d'un niveau, tel que le client l'a choisi.
 *
 * @param string|null $niveau FAIBLE, MOYEN ou CRITIQUE.
 * @return string Libellé client, ou le niveau lui-même s'il est inconnu (texte brut, à échapper).
 */
function anomaly_severity_label(?string $niveau): string {
    return ANOMALY_CLIENT_SEVERITIES[$niveau ?? ''] ?? (string)$niveau;
}
