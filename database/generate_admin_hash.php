<?php
/**
 * Run: php database/generate_admin_hash.php "yourPassword"
 * Paste the output into seed.sql if you want a different admin password.
 */
if ($argc < 2) {
    fwrite(STDERR, "Usage: php generate_admin_hash.php <password>\n");
    exit(1);
}
echo password_hash($argv[1], PASSWORD_BCRYPT, ['cost' => 10]) . PHP_EOL;
