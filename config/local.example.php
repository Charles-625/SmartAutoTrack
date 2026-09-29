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

    // Assistant IA (Hugging Face Inference Providers)
    'HF_TOKEN' => '',                   // jeton avec la permission « Make calls to Inference Providers »
    'HF_MODEL' => 'Qwen/Qwen2.5-7B-Instruct:fastest',
];
