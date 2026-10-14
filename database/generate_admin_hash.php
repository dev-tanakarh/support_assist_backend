<?php
/**
 * Run: php database/generate_admin_hash.php "yourPassword"
 * Use the output as password_hash when creating the first admin; see DEPLOY_RENDER_SUPABASE.md.
 */
if ($argc < 2) {
    fwrite(STDERR, "Usage: php generate_admin_hash.php <password>\n");
    exit(1);
}
echo password_hash($argv[1], PASSWORD_BCRYPT, ['cost' => 10]) . PHP_EOL;
