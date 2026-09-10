<?php
declare(strict_types=1);

// PHPMailer manuell eingebunden (kein Composer im Projekt) - offizieller,
// von PHPMailer selbst dokumentierter Weg ohne Autoloader:
// https://github.com/PHPMailer/PHPMailer#manual-installation
require_once __DIR__ . '/Exception.php';
require_once __DIR__ . '/PHPMailer.php';
require_once __DIR__ . '/SMTP.php';
