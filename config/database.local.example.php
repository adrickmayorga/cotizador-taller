<?php
declare(strict_types=1);

// Copia este archivo como database.local.php y completa tus datos si no usas variables de entorno.
$dbHost = getenv('DB_HOST') ?: (getenv('SRNU_DB_HOST') ?: '127.0.0.1');
$dbName = getenv('DB_NAME') ?: (getenv('SRNU_DB_NAME') ?: 'srnu_cotizador');
$dbUser = getenv('DB_USER') ?: (getenv('SRNU_DB_USER') ?: 'root');
$dbPass = getenv('DB_PASS') !== false ? getenv('DB_PASS') : (getenv('SRNU_DB_PASS') !== false ? getenv('SRNU_DB_PASS') : 'srnucustom');

if (!defined('SRNU_DB_HOST')) define('SRNU_DB_HOST', $dbHost);
if (!defined('SRNU_DB_NAME')) define('SRNU_DB_NAME', $dbName);
if (!defined('SRNU_DB_USER')) define('SRNU_DB_USER', $dbUser);
if (!defined('SRNU_DB_PASS')) define('SRNU_DB_PASS', $dbPass);
