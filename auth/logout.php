<?php
require_once __DIR__ . '/../config/config.php';

destroySession();

redirect('auth/login.php?deconnecte=1');
