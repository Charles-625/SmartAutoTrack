<?php
/**
 * Assistant IA (Hugging Face Inference Providers, API compatible OpenAI).
 *
 * Configuration : HF_TOKEN (jeton avec la permission « Make calls to
 * Inference Providers »), HF_MODEL, et facultativement HF_API_URL.
 *
 * Le modèle ne reçoit que les données du périmètre de l'utilisateur connecté
 * (ses véhicules pour un client, des statistiques globales pour un admin).
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/http_client.php';

class AiException extends RuntimeException {
    /** Détail technique, affiché seulement à un administrateur. */
    public string $detail;

    public function __construct(string $message, string $detail = '') {
        parent::__construct($message);
        $this->detail = $detail;
    }
}

const AI_HISTORY_LENGTH = 10;
const AI_MAX_MESSAGE_LENGTH = 1000;
const AI_RATE_LIMIT = 20;            // messages par fenêtre
const AI_RATE_WINDOW = 600;          // secondes

function aiIsConfigured(): bool {
    return (string)appConfig('HF_TOKEN', '') !== '';
}

/** @param array<int, array{role:string, content:string}> $messages */
function aiChat(array $messages, int $maxTokens = 700): string {
    if (!aiIsConfigured()) {
        throw new AiException('L\'assistant IA n\'est pas configuré.', 'HF_TOKEN absent de la configuration.');
    }
    try {
        $response = httpJsonRequest(
            'POST',
            (string)appConfig('HF_API_URL', 'https://router.huggingface.co/v1/chat/completions'),
            ['Authorization' => 'Bearer ' . appConfig('HF_TOKEN')],
            [
                'model' => (string)appConfig('HF_MODEL', 'Qwen/Qwen2.5-7B-Instruct:fastest'),
                'messages' => $messages,
                'max_tokens' => $maxTokens,
                'temperature' => 0.4,
            ],
            60
        );
    } catch (RuntimeException $e) {
        error_log('[SmartAutoTrack] IA : ' . $e->getMessage());
        throw new AiException('L\'assistant IA est momentanément injoignable. Réessayez dans un instant.', $e->getMessage());
    }

    $status = $response['status'];
    if ($status !== 200) {
        $apiError = is_array($response['data']) ? ($response['data']['error']['message'] ?? $response['data']['error'] ?? '') : '';
        $apiError = is_string($apiError) ? $apiError : json_encode($apiError);
        error_log('[SmartAutoTrack] IA : HTTP ' . $status . ' ' . substr($response['raw'], 0, 300));
        if ($status === 401 || $status === 403) {
            throw new AiException('L\'assistant IA est momentanément indisponible.', 'Hugging Face refuse le jeton (HTTP ' . $status . ') : ' . $apiError . ' — créez un jeton avec la permission « Make calls to Inference Providers ».');
        }
        if ($status === 429) {
            throw new AiException('L\'assistant IA reçoit trop de demandes. Réessayez dans un instant.', 'Quota Hugging Face atteint (HTTP 429).');
        }
        throw new AiException('L\'assistant IA est momentanément indisponible.', 'Hugging Face : HTTP ' . $status . ' ' . $apiError);
    }

    $content = trim((string)($response['data']['choices'][0]['message']['content'] ?? ''));
    if ($content === '') {
        throw new AiException('L\'assistant IA n\'a pas pu répondre. Reformulez votre question.', 'Réponse vide du modèle.');
    }
    return $content;
}

/** Les champs saisis sont stockés encodés en HTML (sanitize()) : on les décode pour le modèle. */
function aiText($value, int $max = 200): string {
    $text = trim(html_entity_decode((string)($value ?? ''), ENT_QUOTES, 'UTF-8'));
    return mb_strlen($text) > $max ? mb_substr($text, 0, $max) . '…' : $text;
}

/** Exécute une requête de contexte ; une erreur (schéma différent...) n'empêche pas de répondre. */
function aiRows(PDO $conn, string $sql, array $params = []): array {
    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('[SmartAutoTrack] IA contexte : ' . $e->getMessage());
        return [];
    }
}

function aiClientContext(PDO $conn, int $clientId): string {
    $lines = ['Date du jour : ' . date('d/m/Y')];

    $vehicles = aiRows($conn, "SELECT marque, modele, immatriculation, annee, kilometrage, etat FROM vehicule WHERE idClient = ? ORDER BY idVehicule", [$clientId]);
    $lines[] = "\nVéhicules (" . count($vehicles) . ") :";
    foreach ($vehicles as $v) {
        $lines[] = '- ' . aiText($v['marque'] . ' ' . $v['modele']) . ' (' . aiText($v['immatriculation']) . ')'
            . ($v['annee'] ? ', année ' . (int)$v['annee'] : '')
            . ', ' . (int)$v['kilometrage'] . ' km, état : ' . aiText($v['etat']);
    }

    $anomalies = aiRows($conn, "
        SELECT a.type, a.niveau, a.statut, a.description, a.dateDetection, v.marque, v.modele
        FROM anomalie a JOIN vehicule v ON v.idVehicule = a.idVehicule
        WHERE v.idClient = ? AND a.statut IN ('NOUVELLE', 'EN_COURS')
        ORDER BY a.dateDetection DESC LIMIT 10
    ", [$clientId]);
    $lines[] = "\nAnomalies non résolues (" . count($anomalies) . ") :";
    foreach ($anomalies as $a) {
        $lines[] = '- ' . aiText($a['marque'] . ' ' . $a['modele']) . ' : ' . aiText($a['type'] ?: 'anomalie')
            . ', niveau ' . aiText($a['niveau']) . ', statut ' . aiText($a['statut'])
            . ', détectée le ' . date('d/m/Y', strtotime($a['dateDetection'])) . ' — ' . aiText($a['description'], 160);
    }

    $interventions = aiRows($conn, "
        SELECT i.type, i.statut, i.dateIntervention, v.marque, v.modele
        FROM intervention i JOIN vehicule v ON v.idVehicule = i.idVehicule
        WHERE i.idClient = ? ORDER BY i.dateIntervention DESC LIMIT 5
    ", [$clientId]);
    $lines[] = "\nInterventions récentes :";
    foreach ($interventions as $i) {
        $lines[] = '- ' . aiText($i['type']) . ' sur ' . aiText($i['marque'] . ' ' . $i['modele'])
            . ', statut ' . aiText($i['statut']) . ', le ' . date('d/m/Y', strtotime($i['dateIntervention']));
    }

    $repairs = aiRows($conn, "
        SELECT r.titre, r.statut, r.cout, r.dateReparation, r.recommandations
        FROM reparation r JOIN intervention i ON i.idIntervention = r.idIntervention
        WHERE i.idClient = ? ORDER BY r.dateReparation DESC LIMIT 5
    ", [$clientId]);
    $lines[] = "\nRéparations récentes :";
    foreach ($repairs as $r) {
        $lines[] = '- ' . aiText($r['titre'] ?: 'Réparation') . ', statut ' . aiText($r['statut'])
            . ', ' . number_format((float)$r['cout'], 0, ',', ' ') . ' XAF, le ' . date('d/m/Y', strtotime($r['dateReparation']))
            . ($r['recommandations'] ? ' — recommandations du technicien : ' . aiText($r['recommandations'], 160) : '');
    }

    return implode("\n", $lines);
}

function aiAdminContext(PDO $conn): string {
    $lines = ['Date du jour : ' . date('d/m/Y')];

    $counts = aiRows($conn, "
        SELECT (SELECT COUNT(*) FROM client) AS clients,
               (SELECT COUNT(*) FROM vehicule) AS vehicules,
               (SELECT COUNT(*) FROM garage) AS garages,
               (SELECT COUNT(*) FROM technicien) AS techniciens
    ");
    if ($counts) {
        $c = $counts[0];
        $lines[] = "Totaux : {$c['clients']} clients, {$c['vehicules']} véhicules, {$c['garages']} garages, {$c['techniciens']} techniciens.";
    }

    $groups = [
        'Techniciens par statut de validation' => "SELECT statutValidation AS k, COUNT(*) AS n FROM technicien GROUP BY statutValidation",
        'Garages par statut' => "SELECT statutGarage AS k, COUNT(*) AS n FROM garage GROUP BY statutGarage",
        'Interventions par statut' => "SELECT statut AS k, COUNT(*) AS n FROM intervention GROUP BY statut",
        'Anomalies non résolues par niveau' => "SELECT niveau AS k, COUNT(*) AS n FROM anomalie WHERE statut IN ('NOUVELLE', 'EN_COURS') GROUP BY niveau",
        'Types d\'anomalies les plus fréquents (30 derniers jours)' => "SELECT COALESCE(type, 'non précisé') AS k, COUNT(*) AS n FROM anomalie WHERE dateDetection >= DATE_SUB(NOW(), INTERVAL 30 DAY) GROUP BY type ORDER BY n DESC LIMIT 8",
        'Anomalies critiques ce mois-ci, par garage de rattachement du véhicule' => "SELECT g.nomGarage AS k, COUNT(*) AS n FROM anomalie a JOIN vehicule v ON v.idVehicule = a.idVehicule JOIN garage g ON g.idGarage = v.idGarage WHERE a.niveau = 'CRITIQUE' AND a.dateDetection >= DATE_FORMAT(NOW(), '%Y-%m-01') GROUP BY g.idGarage ORDER BY n DESC LIMIT 8",
        'Interventions actives (planifiées ou en cours) par garage' => "SELECT g.nomGarage AS k, COUNT(*) AS n FROM intervention i JOIN garage g ON g.idGarage = i.idGarage WHERE i.statut IN ('PLANIFIEE', 'EN_COURS') GROUP BY g.idGarage ORDER BY n DESC LIMIT 8",
        'Charge des techniciens (interventions actives)' => "SELECT CONCAT(u.prenom, ' ', u.nom) AS k, COUNT(*) AS n FROM intervention i JOIN utilisateur u ON u.idUtilisateur = i.idTechnicien WHERE i.statut IN ('PLANIFIEE', 'EN_COURS') GROUP BY i.idTechnicien ORDER BY n DESC LIMIT 8",
        'Paiements ce mois-ci par statut (nombre)' => "SELECT statut AS k, COUNT(*) AS n FROM paiement WHERE datePaiement >= DATE_FORMAT(NOW(), '%Y-%m-01') GROUP BY statut",
        'Montant encaissé ce mois-ci (XAF)' => "SELECT 'total' AS k, COALESCE(SUM(montant), 0) AS n FROM paiement WHERE statut = 'PAYE' AND datePaiement >= DATE_FORMAT(NOW(), '%Y-%m-01')",
    ];
    foreach ($groups as $label => $sql) {
        $rows = aiRows($conn, $sql);
        $lines[] = "\n$label :";
        if (!$rows) {
            $lines[] = '- aucune donnée';
        }
        foreach ($rows as $row) {
            $lines[] = '- ' . aiText($row['k'] ?? '—') . ' : ' . number_format((float)$row['n'], 0, ',', ' ');
        }
    }

    return implode("\n", $lines);
}

function aiSystemPrompt(string $role, string $context): string {
    if ($role === ROLE_ADMIN) {
        return "Tu es l'assistant de supervision de SmartAutoTrack, une plateforme camerounaise de suivi automobile "
            . "(clients, garages, techniciens, interventions, anomalies, réparations, paiements Mobile Money).\n"
            . "Règles :\n"
            . "- Réponds en français, de façon concise et structurée.\n"
            . "- Utilise uniquement les chiffres des données ci-dessous ; si la question porte sur une donnée absente, dis précisément ce qui manque au lieu d'inventer.\n"
            . "- Propose des actions de supervision concrètes (relancer un garage, valider des techniciens en attente, répartir la charge...).\n"
            . "- Tu ne poses jamais de diagnostic mécanique automatique.\n"
            . "- Les montants sont en francs CFA (XAF).\n\n"
            . "Données de la plateforme (à jour) :\n" . $context;
    }
    return "Tu es l'assistant de SmartAutoTrack, une plateforme camerounaise de suivi automobile. "
        . "Tu aides un client à comprendre l'entretien de ses véhicules, ses anomalies, interventions et réparations, et à utiliser la plateforme.\n"
        . "Règles :\n"
        . "- Réponds en français, clairement et brièvement (quelques phrases ou une courte liste).\n"
        . "- Tu informes et orientes : tu ne poses jamais de diagnostic mécanique définitif et tu recommandes de faire vérifier par un technicien.\n"
        . "- Si un problème peut compromettre la sécurité (freins, direction, surchauffe moteur, fumée, odeur ou fuite de carburant), conseille de ne pas rouler et de demander une intervention.\n"
        . "- Pour parler du compte du client, appuie-toi uniquement sur les données ci-dessous ; si une information manque, dis-le au lieu de l'inventer.\n"
        . "- Pour agir, indique la page à utiliser : « Interventions » (demander une intervention), « Réparations » (rapports et paiement Mobile Money), « SAV » ou « Messages » (contacter l'équipe).\n"
        . "- Les montants sont en francs CFA (XAF).\n\n"
        . "Données du client (à jour) :\n" . $context;
}

/**
 * Limite de débit par utilisateur, en session (l'utilisateur est authentifié).
 * @return bool true si le message est autorisé (et comptabilisé)
 */
function aiAllowMessage(?int $now = null): bool {
    $now = $now ?? time();
    $calls = array_values(array_filter($_SESSION['ai_calls'] ?? [], fn($t) => $t > $now - AI_RATE_WINDOW));
    if (count($calls) >= AI_RATE_LIMIT) {
        $_SESSION['ai_calls'] = $calls;
        return false;
    }
    $calls[] = $now;
    $_SESSION['ai_calls'] = $calls;
    return true;
}

/** @return array<int, array{role:string, content:string}> */
function aiHistory(string $role): array {
    return $_SESSION['ai_history'][$role] ?? [];
}

function aiRemember(string $role, string $question, string $answer): void {
    $history = aiHistory($role);
    $history[] = ['role' => 'user', 'content' => $question];
    $history[] = ['role' => 'assistant', 'content' => $answer];
    $_SESSION['ai_history'][$role] = array_slice($history, -AI_HISTORY_LENGTH);
}

function aiResetHistory(string $role): void {
    unset($_SESSION['ai_history'][$role]);
}
