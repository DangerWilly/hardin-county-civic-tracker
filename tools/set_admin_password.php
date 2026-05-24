<?php
declare(strict_types=1);

/**
 * tools/set_admin_password.php
 *
 * Run this once to generate the bcrypt hash for the admin password, then
 * paste the output into your .env file as ADMIN_PASSWORD_HASH=...
 *
 * Usage:
 *     php tools/set_admin_password.php
 *
 * Why not write to .env directly? Editing user config files programmatically
 * is risky — your .env might have other secrets that a bad parse could
 * scramble. Printing the hash and letting you paste it is safer.
 *
 * Why bcrypt? PHP's password_hash() uses bcrypt by default. Slow on purpose,
 * which is the whole point — a leaked hash takes years to brute-force.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

echo "Set or rotate the admin password for The Hardin Civic.\n";
echo "----------------------------------------------------\n\n";

// Disable terminal echo while typing the password. Works on macOS/Linux; on
// Windows, stty is unavailable and the password will be visible. We warn
// the user; this is acceptable for a personal tool.
$is_windows = strncasecmp(PHP_OS, 'WIN', 3) === 0;

function prompt_password(string $label, bool $is_windows): string
{
    echo $label;
    if ($is_windows) {
        echo "\n(Note: on Windows the password will be visible as you type.)\n> ";
        $line = fgets(STDIN);
    } else {
        system('stty -echo');
        $line = fgets(STDIN);
        system('stty echo');
        echo "\n";
    }
    return rtrim((string) $line, "\r\n");
}

$pw1 = prompt_password("Enter new admin password: ", $is_windows);
$pw2 = prompt_password("Confirm: ", $is_windows);

if ($pw1 !== $pw2) {
    fwrite(STDERR, "\nPasswords don't match. No changes.\n");
    exit(1);
}
if (strlen($pw1) < 12) {
    fwrite(STDERR, "\nPassword must be at least 12 characters. No changes.\n");
    exit(1);
}

$hash = password_hash($pw1, PASSWORD_DEFAULT);

echo "\nAdd these two lines to your .env file:\n";
echo "--------------------------------------\n";
echo "ADMIN_PASSWORD_HASH=" . $hash . "\n";

// Also emit a fresh cookie secret if .env doesn't have one yet
echo "ADMIN_COOKIE_SECRET=" . bin2hex(random_bytes(32)) . "\n";
echo "--------------------------------------\n";
echo "\n(If ADMIN_COOKIE_SECRET is already set in your .env, keep the old one and\n";
echo " only add ADMIN_PASSWORD_HASH. Rotating the secret invalidates all sessions.)\n";
