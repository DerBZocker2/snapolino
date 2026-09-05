<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';

logout_admin();
header('Location: login.php');
