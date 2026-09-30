<?php
require_once __DIR__ . '/../config/config.php';

/**
 * Déconnexion (tout utilisateur) : détruit la session puis renvoie vers la
 * page de connexion avec ?deconnecte=1 pour afficher la confirmation.
 */
destroySession();

redirect('auth/login.php?deconnecte=1');
