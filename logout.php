<?php
declare(strict_types=1);
require_once __DIR__.'/config/database.php';
srnu_auth_logout();
header('Location: login.php');
exit;
