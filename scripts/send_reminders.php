<?php
/**
 * Envoie les rappels d'échéance (assurance, visite technique, vidange,
 * freins, pneus) aux clients Premium : notification aux paliers J30, J7, J0
 * et RETARD, email Brevo aux paliers J7 et RETARD (voir
 * maintenanceSendReminders() dans includes/maintenance.php). Chaque rappel
 * n'est envoyé qu'une fois (table `rappel_envoye`) : le script peut être
 * relancé sans risque de doublon.
 *
 * Prérequis : scripts/migrate_structure.php appliqué (sections 4 et 8) ;
 * sinon le script s'arrête sans rien envoyer. Écrit en base (notifications,
 * rappel_envoye) : ne pas lancer sur une base de test sans le vouloir.
 *
 *   php scripts/send_reminders.php               tous les clients Premium
 *   php scripts/send_reminders.php --client=12   un seul client (idClient)
 *
 * À lancer une fois par jour, de préférence le matin :
 *
 *   Windows (Planificateur de tâches, XAMPP) :
 *     schtasks /Create /TN "SmartAutoTrack rappels" /SC DAILY /ST 07:00 ^
 *       /TR "C:\xampp\php\php.exe C:\xampp\htdocs\HCH\scripts\send_reminders.php"
 *
 *   Linux (crontab -e) :
 *     0 7 * * * php /var/www/HCH/scripts/send_reminders.php >> /var/log/smartautotrack-rappels.log 2>&1
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Accès interdit');
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/maintenance.php';

$idClient = null;
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--client=(\d+)$/', $arg, $m)) {
        $idClient = (int)$m[1];
    } else {
        fwrite(STDERR, "Option inconnue : $arg\nUsage : php scripts/send_reminders.php [--client=ID]\n");
        exit(2);
    }
}

$conn = (new Database())->getConnection();
if (!$conn) {
    fwrite(STDERR, "Connexion impossible (voir les logs PHP).\n");
    exit(1);
}

// « Aujourd'hui » calculé en PHP : MySQL n'a pas le même fuseau sur ce serveur.
$today = date('Y-m-d');
echo "Rappels d'échéance du " . maintenanceFormatDate($today) . ($idClient !== null ? " (client $idClient)" : '') . "\n";

$counts = maintenanceSendReminders($conn, $today, $idClient);
if (!$counts['ready']) {
    fwrite(STDERR, "Échéances ou abonnements indisponibles : appliquez d'abord php scripts/migrate_structure.php --apply\n");
    exit(1);
}

echo "  clients examinés       : {$counts['clients']}\n";
echo "  clients Premium        : {$counts['premium']}\n";
echo "  notifications envoyées : {$counts['notifications']}\n";
echo "  emails envoyés         : {$counts['emails']}\n";
echo "  déjà envoyés (ignorés) : {$counts['alreadySent']}\n";
echo "  erreurs (voir les logs): {$counts['errors']}\n";
exit($counts['errors'] > 0 ? 1 : 0);
