<?php

function feistelRound(int $val, int $round, string $context, string $key): int {
    $h = hash_hmac('sha256', pack('n', $val) . chr($round) . $context, $key, true);
    $num = unpack('n', substr($h, 0, 2))[1];
    return $num & 0xFFFF;
}

function encodeId(int $id, string $context, string $key): string {
    $L = ($id >> 16) & 0xFFFF;
    $R = $id & 0xFFFF;
    for ($i = 1; $i <= 4; $i++) {
        $nextL = $R;
        $nextR = $L ^ feistelRound($R, $i, $context, $key);
        $L = $nextL;
        $R = $nextR;
    }
    $scrambled = pack('nn', $L, $R);
    $mac = substr(hash_hmac('sha256', $context . ':' . $scrambled, $key, true), 0, 4);
    return rtrim(strtr(base64_encode($scrambled . $mac), '+/', '-_'), '=');
}

function decodeId(string $token, string $context, string $key): ?int {
    $pad = strlen($token) % 4;
    if ($pad > 0) $token .= str_repeat('=', 4 - $pad);
    $raw = base64_decode(strtr($token, '-_', '+/'), true);
    if ($raw === false || strlen($raw) !== 8) return null;
    $scrambled = substr($raw, 0, 4);
    $mac = substr($raw, 4, 4);
    $expectedMac = substr(hash_hmac('sha256', $context . ':' . $scrambled, $key, true), 0, 4);
    if (!hash_equals($expectedMac, $mac)) return null;
    $parts = unpack('nL/nR', $scrambled);
    $L = $parts['L'];
    $R = $parts['R'];
    for ($i = 4; $i >= 1; $i--) {
        $prevR = $L;
        $prevL = $R ^ feistelRound($L, $i, $context, $key);
        $L = $prevL;
        $R = $prevR;
    }
    return ($L << 16) | $R;
}

$k = 'mysecretkey123';
foreach ([1, 2, 488, 489, 490, 100000] as $id) {
    $enc = encodeId($id, 'alert', $k);
    $dec = decodeId($enc, 'alert', $k);
    echo "ID $id -> $enc -> $dec (" . ($id === $dec ? 'OK' : 'FAIL') . ")\n";
}

// Test invalid token
$tampered = 'invalidToken!';
$decTampered = decodeId($tampered, 'alert', $k);
echo "Tampered -> " . ($decTampered === null ? 'REJECTED (OK)' : 'FAIL') . "\n";
