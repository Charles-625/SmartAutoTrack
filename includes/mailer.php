<?php
/**
 * Service d'envoi d'emails transactionnels via l'API HTTP de Brevo (Sendinblue).
 *
 * Utilise curl pour appeler l'endpoint officiel v3 :
 * POST https://api.brevo.com/v3/smtp/email
 *
 * Les identifiants sont lus via appConfig() (config/local.php ou variables d'environnement) :
 * - BREVO_API_KEY : clé API v3 (ex. xkeysib-...)
 * - BREVO_SENDER_EMAIL : adresse validée sur Brevo
 * - BREVO_SENDER_NAME : nom affiché pour l'expéditeur
 */

require_once __DIR__ . '/../config/config.php';

/**
 * Envoie un email transactionnel via l'API Brevo.
 *
 * @param string $toEmail     Adresse email du destinataire.
 * @param string $toName      Nom ou prénom du destinataire.
 * @param string $subject     Sujet de l'email.
 * @param string $htmlContent Contenu HTML de l'email.
 * @param string $textContent Contenu texte brut (facultatif).
 * @return array{success: bool, messageId: ?string, error: ?string}
 */
function sendBrevoEmail(
    string $toEmail,
    string $toName,
    string $subject,
    string $htmlContent,
    string $textContent = ''
): array {
    $apiKey = appConfig('BREVO_API_KEY', '');
    if (empty($apiKey)) {
        error_log('[Brevo] Clé API manquante (BREVO_API_KEY non configurée)');
        return [
            'success' => false,
            'messageId' => null,
            'error' => 'Le service d\'envoi d\'emails n\'est pas configuré.',
        ];
    }

    $senderEmail = appConfig('BREVO_SENDER_EMAIL', 'travel@aclconnext.com');
    $senderName = appConfig('BREVO_SENDER_NAME', SITE_NAME);

    $payload = [
        'sender' => [
            'name' => $senderName,
            'email' => $senderEmail,
        ],
        'to' => [
            [
                'email' => $toEmail,
                'name' => !empty(trim($toName)) ? trim($toName) : $toEmail,
            ]
        ],
        'subject' => $subject,
        'htmlContent' => $htmlContent,
    ];

    if (!empty($textContent)) {
        $payload['textContent'] = $textContent;
    }

    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'accept: application/json',
            'api-key: ' . $apiKey,
            'content-type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => false, // Compatibilité environnements locaux
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        error_log('[Brevo] Erreur cURL : ' . $curlError);
        return [
            'success' => false,
            'messageId' => null,
            'error' => 'Impossible de contacter le serveur d\'emails.',
        ];
    }

    $data = json_decode((string)$response, true);

    if ($httpCode >= 200 && $httpCode < 300) {
        return [
            'success' => true,
            'messageId' => $data['messageId'] ?? null,
            'error' => null,
        ];
    }

    $errorMsg = $data['message'] ?? 'Erreur inconnue lors de l\'envoi (HTTP ' . $httpCode . ')';
    error_log('[Brevo] Erreur API (' . $httpCode . ') : ' . $errorMsg);

    return [
        'success' => false,
        'messageId' => null,
        'error' => $errorMsg,
    ];
}

/**
 * Génère le gabarit HTML pour l'email de réinitialisation de mot de passe.
 *
 * @param string $name     Nom de l'utilisateur.
 * @param string $resetUrl URL complète de réinitialisation avec le token.
 * @return string Code HTML prêt à l'envoi.
 */
function buildPasswordResetEmailHtml(string $name, string $resetUrl): string {
    $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    $safeUrl = htmlspecialchars($resetUrl, ENT_QUOTES, 'UTF-8');
    $siteName = htmlspecialchars(SITE_NAME, ENT_QUOTES, 'UTF-8');

    return <<<HTML
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Réinitialisation de votre mot de passe</title>
  <style>
    body { margin: 0; padding: 0; background-color: #f4f6f9; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; color: #333333; }
    .wrapper { width: 100%; table-layout: fixed; background-color: #f4f6f9; padding: 30px 0; }
    .card { max-width: 560px; margin: 0 auto; background-color: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.06); border: 1px solid #e5e7eb; }
    .header { background: linear-gradient(135deg, #0d47a1 0%, #1565c0 100%); padding: 32px 30px; text-align: center; }
    .header h1 { color: #ffffff; margin: 0; font-size: 24px; font-weight: 700; letter-spacing: -0.5px; }
    .content { padding: 35px 30px; line-height: 1.6; }
    .btn-wrap { text-align: center; margin: 30px 0; }
    .btn { display: inline-block; background-color: #0d47a1; color: #ffffff !important; padding: 14px 32px; font-size: 15px; font-weight: 600; text-decoration: none; border-radius: 8px; box-shadow: 0 4px 12px rgba(13, 71, 161, 0.25); }
    .btn:hover { background-color: #0b3c8a; }
    .note { font-size: 13px; color: #6b7280; border-top: 1px solid #f3f4f6; padding-top: 20px; margin-top: 30px; }
    .footer { text-align: center; font-size: 12px; color: #9ca3af; padding: 20px 30px 30px; }
    .url-fallback { word-break: break-all; color: #0d47a1; font-size: 12px; }
  </style>
</head>
<body>
  <div class="wrapper">
    <div class="card">
      <div class="header">
        <h1>{$siteName}</h1>
      </div>
      <div class="content">
        <p>Bonjour <strong>{$safeName}</strong>,</p>
        <p>Nous avons reçu une demande de réinitialisation du mot de passe pour votre compte sur <strong>{$siteName}</strong>.</p>
        <p>Pour définir un nouveau mot de passe, cliquez sur le bouton ci-dessous :</p>
        <div class="btn-wrap">
          <a href="{$safeUrl}" class="btn" target="_blank" rel="noopener">Réinitialiser mon mot de passe</a>
        </div>
        <p class="note">
          <strong>Ce lien est valable pendant 1 heure.</strong><br>
          Si vous n'êtes pas à l'origine de cette demande, vous pouvez ignorer cet email en toute sécurité : votre mot de passe actuel reste inchangé.
        </p>
        <p class="note">
          Si le bouton ne fonctionne pas, copiez-collez l'adresse suivante dans votre navigateur :<br>
          <span class="url-fallback">{$safeUrl}</span>
        </p>
      </div>
      <div class="footer">
        &copy; {$siteName} - Tous droits réservés.
      </div>
    </div>
  </div>
</body>
</html>
HTML;
}
