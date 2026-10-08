<?php
/**
 * Assistant IA (OpenRouter, API compatible OpenAI).
 *
 * Configuration : OPENROUTER_API_KEY (clé créée sur https://openrouter.ai/keys),
 * OPENROUTER_MODEL (modèle principal), OPENROUTER_FALLBACK_MODELS (modèles
 * de secours, séparés par des virgules, essayés dans l'ordre si le principal
 * est saturé, retiré ou ne répond pas) et facultativement OPENROUTER_API_URL.
 *
 * Le modèle ne reçoit que les données du périmètre de l'utilisateur connecté
 * (ses véhicules pour un client, des statistiques globales pour un admin).
 *
 * Échéances (assurance, visite technique, vidange, freins, pneus) : toujours
 * calculées par l'application (includes/maintenance.php), jamais par le
 * modèle. Le contexte client en reçoit la liste déjà calculée
 * (aiScheduleLines()) et les règles en vigueur (aiRuleLine(), pour qu'il
 * ne cite pas d'intervalles génériques), et les consignes lui demandent de
 * la rappeler sans la recalculer ; le contexte admin n'en reçoit que les totaux
 * (aiFleetScheduleLines()). Tant que la migration n'est pas appliquée
 * (maintenanceReady() faux), contexte et consignes sont inchangés.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/http_client.php';
require_once __DIR__ . '/maintenance.php';

/**
 * Erreur de l'assistant : getMessage() est affichable à tout utilisateur,
 * $detail (cause technique) est réservé à l'administrateur.
 */
class AiException extends RuntimeException {
    /** Détail technique, affiché seulement à un administrateur. */
    public string $detail;

    /**
     * Vrai si un autre modèle a des chances de réussir (modèle saturé,
     * retiré, réservé, en panne ou réponse vide) ; faux si l'échec touche
     * tous les modèles (clé refusée, assistant non configuré).
     */
    public bool $retryable;

    public function __construct(string $message, string $detail = '', bool $retryable = false) {
        parent::__construct($message);
        $this->detail = $detail;
        $this->retryable = $retryable;
    }
}

/** Modèle principal par défaut, si OPENROUTER_MODEL n'est pas configuré (gratuit, testé le 07/10/2026). */
const AI_DEFAULT_MODEL = 'nvidia/nemotron-3-super-120b-a12b:free';

/** Nombre maximal de modèles essayés pour une même question (principal compris). */
const AI_MAX_MODELS = 4;

/** Délai maximal d'attente d'un modèle, en secondes. */
const AI_MODEL_TIMEOUT = 40;

// Historique conservé en session (messages user + assistant), longueur max
// d'une question, et limite de débit par utilisateur.
const AI_HISTORY_LENGTH = 10;
const AI_MAX_MESSAGE_LENGTH = 1000;
const AI_RATE_LIMIT = 20;            // messages par fenêtre
const AI_RATE_WINDOW = 600;          // secondes

/** Titre de la section des échéances du contexte client (repère aussi les consignes associées dans aiSystemPrompt()). */
const AI_SCHEDULE_HEADING = 'Échéances calculées par SmartAutoTrack';

/** Nombre maximal de lignes d'échéance envoyées au modèle (grandes flottes). */
const AI_SCHEDULE_MAX_LINES = 60;

/** L'assistant est utilisable seulement si une clé OpenRouter est configurée. */
function aiIsConfigured(): bool {
    return trim((string)appConfig('OPENROUTER_API_KEY', '')) !== '';
}

/**
 * Liste ordonnée des modèles à essayer : le modèle principal puis les
 * modèles de secours, sans doublon ni entrée vide, limitée à AI_MAX_MODELS.
 *
 * @param string|null $primary   Modèle principal (défaut : OPENROUTER_MODEL ou AI_DEFAULT_MODEL).
 * @param string|null $fallbacks Modèles de secours séparés par des virgules
 *                               (défaut : OPENROUTER_FALLBACK_MODELS).
 * @return string[] Au moins un modèle.
 */
function aiModelList(?string $primary = null, ?string $fallbacks = null): array {
    $primary = trim($primary ?? (string)appConfig('OPENROUTER_MODEL', AI_DEFAULT_MODEL));
    $fallbacks = $fallbacks ?? (string)appConfig('OPENROUTER_FALLBACK_MODELS', '');
    $models = [];
    foreach (array_merge([$primary], explode(',', $fallbacks)) as $model) {
        $model = trim($model);
        if ($model !== '' && !in_array($model, $models, true)) {
            $models[] = $model;
        }
    }
    return array_slice($models ?: [AI_DEFAULT_MODEL], 0, AI_MAX_MODELS);
}

/**
 * Envoie une requête chat/completions à OpenRouter pour un modèle donné
 * (transport par défaut de aiChat(), remplaçable dans les tests).
 *
 * @return array{status:int, data:mixed, raw:string} Réponse HTTP décodée.
 * @throws RuntimeException service injoignable (réseau, délai dépassé).
 */
function aiOpenRouterRequest(string $model, array $messages, int $maxTokens): array {
    return httpJsonRequest(
        'POST',
        (string)appConfig('OPENROUTER_API_URL', 'https://openrouter.ai/api/v1/chat/completions'),
        [
            'Authorization' => 'Bearer ' . trim((string)appConfig('OPENROUTER_API_KEY')),
            // En-têtes facultatifs d'identification de l'application chez OpenRouter.
            'HTTP-Referer' => SITE_URL,
            'X-Title' => SITE_NAME,
        ],
        [
            'model' => $model,
            'messages' => $messages,
            'max_tokens' => $maxTokens,
            'temperature' => 0.4,
        ],
        AI_MODEL_TIMEOUT
    );
}

/**
 * Interprète la réponse d'OpenRouter pour un modèle : renvoie le texte, ou
 * lève une AiException qui indique si un autre modèle mérite d'être essayé.
 *
 * Seule une clé refusée (401) arrête tout : elle vaut pour tous les modèles.
 * Un 403 peut venir du modèle lui-même (réservé à certains usages), un 402
 * d'un modèle payant sans crédits, un 404 d'un modèle retiré, un 429 d'un
 * modèle saturé, un 5xx d'un fournisseur en panne : on passe au suivant.
 *
 * @param array{status:int, data:mixed, raw:string} $response
 * @throws AiException
 */
function aiParseResponse(string $model, array $response): string {
    $status = (int)$response['status'];
    if ($status !== 200) {
        $apiError = is_array($response['data']) ? ($response['data']['error']['message'] ?? $response['data']['error'] ?? '') : '';
        $apiError = is_string($apiError) ? $apiError : json_encode($apiError);
        error_log('[SmartAutoTrack] IA (' . $model . ') : HTTP ' . $status . ' ' . substr((string)$response['raw'], 0, 300));
        if ($status === 401) {
            throw new AiException('L\'assistant IA est momentanément indisponible.', 'OpenRouter refuse la clé (HTTP 401) : ' . $apiError . ' — vérifiez OPENROUTER_API_KEY (https://openrouter.ai/keys).');
        }
        if ($status === 402) {
            throw new AiException('L\'assistant IA est momentanément indisponible.', $model . ' : crédits OpenRouter insuffisants (HTTP 402) : ' . $apiError . ' — rechargez le compte ou choisissez un modèle gratuit (suffixe « :free »).', true);
        }
        if ($status === 429) {
            throw new AiException('L\'assistant IA reçoit trop de demandes. Réessayez dans un instant.', $model . ' : quota atteint (HTTP 429) : ' . $apiError, true);
        }
        throw new AiException('L\'assistant IA est momentanément indisponible.', $model . ' : HTTP ' . $status . ' ' . $apiError, true);
    }

    $content = trim((string)($response['data']['choices'][0]['message']['content'] ?? ''));
    if ($content === '') {
        throw new AiException('L\'assistant IA n\'a pas pu répondre. Reformulez votre question.', $model . ' : réponse vide du modèle.', true);
    }
    return $content;
}

/**
 * Envoie une conversation au modèle (endpoint chat/completions compatible
 * OpenAI) et renvoie le texte de la réponse. Les modèles de aiModelList()
 * sont essayés dans l'ordre : le premier qui répond l'emporte, ce qui
 * protège l'assistant des modèles gratuits saturés ou retirés.
 *
 * @param array<int, array{role:string, content:string}> $messages
 *        Message système + historique + question, dans l'ordre.
 * @param int $maxTokens Longueur maximale de la réponse.
 * @param callable|null $transport fn(string $model, array $messages, int $maxTokens): array
 *        (défaut aiOpenRouterRequest ; remplacé dans les tests unitaires).
 * @param string[]|null $models Modèles à essayer (défaut aiModelList()).
 * @return string Réponse du modèle, jamais vide.
 * @throws AiException non configuré, clé refusée, ou aucun modèle n'a répondu
 *         (l'erreur du dernier modèle essayé, avec le détail de chaque essai).
 */
function aiChat(array $messages, int $maxTokens = 700, ?callable $transport = null, ?array $models = null): string {
    if (!aiIsConfigured()) {
        throw new AiException('L\'assistant IA n\'est pas configuré.', 'OPENROUTER_API_KEY absent de la configuration.');
    }
    $transport = $transport ?? 'aiOpenRouterRequest';
    $models = $models ?? aiModelList();
    $failures = [];
    $last = null;

    foreach ($models as $model) {
        try {
            try {
                $response = $transport($model, $messages, $maxTokens);
            } catch (AiException $e) {
                throw $e;
            } catch (RuntimeException $e) {
                error_log('[SmartAutoTrack] IA (' . $model . ') : ' . $e->getMessage());
                throw new AiException('L\'assistant IA est momentanément injoignable. Réessayez dans un instant.', $model . ' : ' . $e->getMessage(), true);
            }
            return aiParseResponse($model, $response);
        } catch (AiException $e) {
            if (!$e->retryable) {
                throw $e;
            }
            $failures[] = $e->detail;
            $last = $e;
        }
    }
    // Tous les modèles ont échoué : message du dernier, détail de chaque essai pour l'admin.
    throw new AiException($last ? $last->getMessage() : 'L\'assistant IA est momentanément indisponible.', implode(' | ', $failures));
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

/**
 * Lignes d'échéance envoyées au modèle, une par échéance (« - véhicule —
 * phrase de maintenanceDescribe() » : échéance, date ou km restant, statut,
 * dernier entretien). FONCTION PURE.
 *
 * Les échéances en retard ou proches passent en premier ; les autres (à
 * jour, inconnues) complètent jusqu'à AI_SCHEDULE_MAX_LINES, puis une ligne
 * indique combien ont été omises.
 *
 * @param array $schedule Résultat de maintenanceClientSchedule().
 * @return string[] Texte brut (valeurs saisies décodées par maintenanceVehicleName()).
 */
function aiScheduleLines(array $schedule): array {
    $urgent = [];
    $other = [];
    foreach ($schedule as $vehicle) {
        $name = maintenanceVehicleName($vehicle);
        foreach ($vehicle['echeances'] as $item) {
            $line = '- ' . $name . ' — ' . maintenanceDescribe($item);
            if (in_array($item['status'], ['overdue', 'soon'], true)) {
                $urgent[] = $line;
            } else {
                $other[] = $line;
            }
        }
    }
    $lines = array_merge($urgent, $other);
    if (count($lines) > AI_SCHEDULE_MAX_LINES) {
        $omitted = count($lines) - AI_SCHEDULE_MAX_LINES;
        $lines = array_slice($lines, 0, AI_SCHEDULE_MAX_LINES);
        $lines[] = '- … et ' . $omitted . ' autre(s) échéance(s), visibles sur la fiche de chaque véhicule.';
    }
    return $lines;
}

/**
 * Ligne de contexte des règles d'entretien en vigueur (« Règles appliquées
 * par SmartAutoTrack (premier seuil atteint) : Vidange tous les 5 000 km ou
 * 6 mois ; … »), pour que le modèle cite ces intervalles et non des valeurs
 * génériques. Les règles inactives sont omises. FONCTION PURE.
 *
 * @param array $rules Règles (maintenanceRules()).
 * @return string Texte brut ; vide si aucune règle active.
 */
function aiRuleLine(array $rules): string {
    $texts = [];
    foreach ($rules as $type => $rule) {
        if (!empty($rule['actif']) && isset(MAINTENANCE_SERVICE_TYPES[$type])) {
            $texts[] = MAINTENANCE_SERVICE_TYPES[$type] . ' tous les '
                . ($rule['intervalleKm'] !== null ? maintenanceFormatKm((int)$rule['intervalleKm']) . ' ou ' : '')
                . (int)$rule['intervalleMois'] . ' mois';
        }
    }
    return $texts ? 'Règles appliquées par SmartAutoTrack (premier seuil atteint) : ' . implode(' ; ', $texts) . '.' : '';
}

/**
 * Contexte envoyé au modèle pour un client : uniquement SES véhicules,
 * leurs échéances (déjà calculées par maintenanceClientSchedule(), une fois
 * la migration appliquée), anomalies non résolues, interventions et
 * réparations récentes (filtrés par idClient), jamais les données d'un
 * autre client.
 *
 * @return string Texte brut, une ligne par élément.
 */
function aiClientContext(PDO $conn, int $clientId): string {
    $lines = ['Date du jour : ' . date('d/m/Y')];

    $vehicles = aiRows($conn, "SELECT marque, modele, immatriculation, annee, kilometrage, etat FROM vehicule WHERE idClient = ? ORDER BY idVehicule", [$clientId]);
    $lines[] = "\nVéhicules (" . count($vehicles) . ") :";
    foreach ($vehicles as $v) {
        $lines[] = '- ' . aiText($v['marque'] . ' ' . $v['modele']) . ' (' . aiText($v['immatriculation']) . ')'
            . ($v['annee'] ? ', année ' . (int)$v['annee'] : '')
            . ', ' . (int)$v['kilometrage'] . ' km, état : ' . aiText($v['etat']);
    }

    // Échéances : « aujourd'hui » calculé en PHP (fuseau différent de MySQL).
    // Une erreur n'empêche pas l'assistant de répondre (comme aiRows()).
    try {
        $schedule = maintenanceClientSchedule($conn, $clientId, date('Y-m-d'));
        $ruleLine = $schedule ? aiRuleLine(maintenanceRules($conn)) : '';
    } catch (Throwable $e) {
        error_log('[SmartAutoTrack] IA contexte échéances : ' . $e->getMessage());
        $schedule = [];
        $ruleLine = '';
    }
    if ($schedule) {
        $lines[] = "\n" . AI_SCHEDULE_HEADING . " (les plus urgentes d'abord) :";
        array_push($lines, ...aiScheduleLines($schedule));
        if ($ruleLine !== '') {
            $lines[] = $ruleLine;
        }
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

/**
 * Totaux des échéances de tous les véhicules (en retard, bientôt), en tout
 * et par type, avec les mêmes règles que les écrans client et les rappels
 * (maintenanceBuildSchedule()). Trois requêtes au total.
 *
 * @param PDO    $conn  Connexion à la base.
 * @param string $today Date du jour AAAA-MM-JJ (calculée en PHP).
 * @return string[] Lignes de contexte ; vide si la migration n'est pas appliquée.
 */
function aiFleetScheduleLines(PDO $conn, string $today): array {
    if (!maintenanceReady($conn)) {
        return [];
    }
    $vehicles = $conn->query('SELECT idVehicule, kilometrage, dateExpirationAssurance, dateProchaineVisiteTechnique FROM vehicule')->fetchAll(PDO::FETCH_ASSOC);
    $last = maintenanceLastServices($conn, array_column($vehicles, 'idVehicule'));
    $rules = maintenanceRules($conn);
    $byType = array_fill_keys(array_keys(MAINTENANCE_TYPES), ['overdue' => 0, 'soon' => 0]);
    foreach ($vehicles as $v) {
        foreach (maintenanceBuildSchedule($v, $last[(int)$v['idVehicule']] ?? [], $rules, $today) as $item) {
            if (isset($byType[$item['type']][$item['status']])) {
                $byType[$item['type']][$item['status']]++;
            }
        }
    }
    $lines = ["\nÉchéances des véhicules (calculées par SmartAutoTrack) : "
        . array_sum(array_column($byType, 'overdue')) . ' en retard, '
        . array_sum(array_column($byType, 'soon')) . ' bientôt (sous ' . MAINTENANCE_SOON_DAYS . ' jours ou ' . maintenanceFormatKm(MAINTENANCE_SOON_KM) . ') :'];
    foreach ($byType as $type => $c) {
        $lines[] = '- ' . MAINTENANCE_TYPES[$type] . ' : ' . $c['overdue'] . ' en retard, ' . $c['soon'] . ' bientôt';
    }
    return $lines;
}

/**
 * Contexte envoyé au modèle pour un administrateur : uniquement des totaux
 * et des agrégats (par statut, par garage, par technicien, échéances en
 * retard ou proches par type), aucune donnée personnelle détaillée. Chaque
 * requête est indépendante : si l'une échoue, sa section affiche « aucune
 * donnée » (voir aiRows()) ou est omise (échéances).
 */
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

    // « Aujourd'hui » calculé en PHP (fuseau différent de MySQL).
    try {
        array_push($lines, ...aiFleetScheduleLines($conn, date('Y-m-d')));
    } catch (Throwable $e) {
        error_log('[SmartAutoTrack] IA contexte échéances : ' . $e->getMessage());
    }

    return implode("\n", $lines);
}

/**
 * Consignes système du modèle selon le rôle (supervision pour l'admin,
 * aide à l'entretien pour le client), suivies des données de contexte.
 * Règle commune : ne jamais inventer de données ni poser de diagnostic
 * mécanique définitif.
 *
 * Client : si le contexte contient la section AI_SCHEDULE_HEADING
 * (échéances, migration appliquée), quatre consignes s'ajoutent : ne pas
 * recalculer ni inventer de date, rappeler poliment une échéance en retard
 * ou proche, inviter à renseigner une date inconnue, ne citer que les
 * intervalles de aiRuleLine(). Sans cette section,
 * les consignes sont inchangées.
 */
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
        . "- Les montants sont en francs CFA (XAF).\n"
        . (str_contains($context, AI_SCHEDULE_HEADING)
            ? "- Les échéances ci-dessous sont calculées par SmartAutoTrack : ne les recalcule pas et n'invente aucune date. "
                . "Si une échéance est en retard ou proche, rappelle-la poliment au début de ta réponse quand c'est pertinent. "
                . "Si une date est inconnue, invite le client à la renseigner dans la fiche du véhicule. "
                . "Pour la fréquence d'un entretien, cite uniquement les règles appliquées par SmartAutoTrack indiquées ci-dessous, jamais des intervalles généraux.\n"
            : '')
        . "\nDonnées du client (à jour) :\n" . $context;
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

/**
 * Historique de conversation en session, séparé par rôle.
 *
 * @return array<int, array{role:string, content:string}>
 */
function aiHistory(string $role): array {
    return $_SESSION['ai_history'][$role] ?? [];
}

/** Ajoute un échange question/réponse à l'historique, tronqué à AI_HISTORY_LENGTH messages. */
function aiRemember(string $role, string $question, string $answer): void {
    $history = aiHistory($role);
    $history[] = ['role' => 'user', 'content' => $question];
    $history[] = ['role' => 'assistant', 'content' => $answer];
    $_SESSION['ai_history'][$role] = array_slice($history, -AI_HISTORY_LENGTH);
}

/** Efface l'historique de conversation (bouton « Nouvelle conversation »). */
function aiResetHistory(string $role): void {
    unset($_SESSION['ai_history'][$role]);
}
