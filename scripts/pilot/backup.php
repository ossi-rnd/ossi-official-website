<?php

// A consistent copy of a SQLite database while it's in use: php backup.php <from> <to>.
// Used by backup-pilot.ps1.

[$script, $from, $to] = $argv + [null, null, null];

if ($from === null || $to === null || ! is_file($from)) {
    fwrite(STDERR, "Usage: php backup.php <database> <copy>\n");
    exit(1);
}

$db = new PDO('sqlite:'.$from, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec('VACUUM INTO '.$db->quote($to));
echo "ok\n";
