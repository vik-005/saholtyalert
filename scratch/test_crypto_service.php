<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/Service/CryptoService.php';

use App\Service\CryptoService;

$crypto = new CryptoService('0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef');

$token = $crypto->encryptId(489, 'alert');
$id = $crypto->decryptId($token, 'alert');

echo "Token for 489: $token (len: " . strlen($token) . ")\n";
echo "Decrypted: $id\n";

$wrongContext = $crypto->decryptId($token, 'notification');
echo "Wrong context decrypted: " . var_export($wrongContext, true) . "\n";
