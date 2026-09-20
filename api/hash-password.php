<?php
/**
 * One-off CLI helper: generate an access.passwordHash value for
 * dbfolio.json. Not part of the runtime adapter.
 *
 * Usage:
 *   php api/hash-password.php 'my chosen password'
 */

declare(strict_types=1);

if ($argc !== 2 || $argv[1] === '') {
    fwrite(STDERR, "Usage: php hash-password.php '<password>'\n");
    exit(1);
}

$password = $argv[1];
$salt = bin2hex(random_bytes(16));
$hash = hash('sha256', $salt . $password);

echo "sha256\${$salt}\${$hash}\n";
