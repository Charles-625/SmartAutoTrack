<?php
/**
 * Modèle de configuration locale.
 * Copiez ce fichier en config/local.php et renseignez vos identifiants.
 * Une variable d'environnement du même nom (si définie) a priorité.
 */
return [
    'db_host' => 'localhost',
    'db_name' => 'Charles',
    'db_user' => 'root',
    'db_pass' => '',

    // Paiement Mobile Money CamPay (https://www.campay.net)
    'CAMPAY_USE_DEMO' => 'true',        // true : https://demo.campay.net, false : https://www.campay.net
    'CAMPAY_BASE_URL' => '',            // facultatif, remplace l'URL déduite de CAMPAY_USE_DEMO
    'CAMPAY_APP_ID' => '',
    'CAMPAY_USERNAME' => '',
    'CAMPAY_PASSWORD' => '',
    'CAMPAY_TOKEN' => '',               // jeton d'accès permanent (prioritaire sur USERNAME/PASSWORD)
    'CAMPAY_WEBHOOK_KEY' => '',         // clé de signature des notifications (webhook)
    'CAMPAY_SIMULATION' => 'false',     // true : aucun appel à CamPay, paiements validés localement (développement)
    'CAMPAY_DEMO_MAX_AMOUNT' => '25',   // démo uniquement : montant débité plafonné (la démo refuse plus de 25 XAF), 0 = pas de plafond

    // Assistant IA (OpenRouter, https://openrouter.ai)
    'OPENROUTER_API_KEY' => '',         // clé sk-or-v1-... créée sur https://openrouter.ai/keys
    'OPENROUTER_MODEL' => 'nvidia/nemotron-3-super-120b-a12b:free', // tout identifiant de https://openrouter.ai/models (gratuit : suffixe « :free »)
    // Modèles de secours, séparés par des virgules, essayés dans l'ordre si le principal est saturé ou retiré.
    'OPENROUTER_FALLBACK_MODELS' => 'nvidia/nemotron-3-ultra-550b-a55b:free,google/gemma-4-31b-it:free',

    // Envoi d'emails transactionnels (Brevo / Sendinblue)
    'BREVO_API_KEY' => '',              // Clé API v3 (xkeysib-...)
    'BREVO_SENDER_EMAIL' => 'travel@aclconnext.com', // Adresse expéditeur validée sur Brevo
    'BREVO_SENDER_NAME' => 'SmartAutoTrack',

    // Authentification Google OAuth 2.0
    'GOOGLE_CLIENT_ID' => '',           // Obtenu sur Google Cloud Console
    'GOOGLE_CLIENT_SECRET' => '',       // Obtenu sur Google Cloud Console
    'GOOGLE_REDIRECT_URI' => '',        // Facultatif (déduit par défaut : SITE_URL/auth/google_callback.php, ex. http://localhost/HCH/auth/google_callback.php)
                                        // Doit figurer À L'IDENTIQUE dans « URI de redirection autorisés » sur Google Cloud Console
];
