<?php

// Simulate password verification
$hash = '$2y$13$0T9.kEqjyQpzU01f2oreYeEZbvU6wHKfKgwWK7.Ez9gdQg8ROjmpO';
$password = 'Admin@2026!';

echo "Hash from database: " . $hash . PHP_EOL;
echo "Testing password: " . $password . PHP_EOL;
echo "bcrypt_verify result: " . (password_verify($password, $hash) ? 'TRUE' : 'FALSE') . PHP_EOL;

var_dump(password_get_info($hash));
