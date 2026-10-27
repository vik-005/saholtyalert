<?php

class Base62 {
    private const ALPHABET = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';

    public static function encode(string $binary): string {
        // Convert binary string to GMP or BCMath or pure integer array
        $digits = [0];
        $len = strlen($binary);
        for ($i = 0; $i < $len; $i++) {
            $carry = ord($binary[$i]);
            for ($j = 0; $j < count($digits); $j++) {
                $carry += $digits[$j] << 8;
                $digits[$j] = $carry % 62;
                $carry = intdiv($carry, 62);
            }
            while ($carry > 0) {
                $digits[] = $carry % 62;
                $carry = intdiv($carry, 62);
            }
        }
        $result = '';
        for ($i = 0; $i < $len && ord($binary[$i]) === 0; $i++) {
            $result .= self::ALPHABET[0];
        }
        for ($i = count($digits) - 1; $i >= 0; $i--) {
            $result .= self::ALPHABET[$digits[$i]];
        }
        return $result;
    }

    public static function decode(string $str): ?string {
        if ($str === '') return null;
        $bytes = [0];
        $len = strlen($str);
        for ($i = 0; $i < $len; $i++) {
            $pos = strpos(self::ALPHABET, $str[$i]);
            if ($pos === false) return null;
            $carry = $pos;
            for ($j = 0; $j < count($bytes); $j++) {
                $carry += $bytes[$j] * 62;
                $bytes[$j] = $carry & 0xFF;
                $carry >>= 8;
            }
            while ($carry > 0) {
                $bytes[] = $carry & 0xFF;
                $carry >>= 8;
            }
        }
        $result = '';
        for ($i = 0; $i < $len && $str[$i] === self::ALPHABET[0]; $i++) {
            $result .= "\0";
        }
        for ($i = count($bytes) - 1; $i >= 0; $i--) {
            $result .= chr($bytes[$i]);
        }
        return $result;
    }
}

$raw = pack('J', 123456789012345);
$b62 = Base62::encode($raw);
$dec = Base62::decode($b62);
echo "Raw: " . bin2hex($raw) . "\n";
echo "B62: " . $b62 . "\n";
echo "Dec: " . bin2hex($dec) . " (" . ($raw === $dec ? 'OK' : 'FAIL') . ")\n";
