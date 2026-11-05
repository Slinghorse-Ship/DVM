<?php declare(strict_types=1);
/** Retired historical identity migration; never load a website or database here. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
fwrite(STDERR, "Stillgelegt: initialize-production.php war eine einmalige SQLite-Identitätsübernahme mit festen Benutzer-IDs.\n"
    . "Keine Verbindung hergestellt und keine Daten geändert. Für einen neuen Verein den ausdrücklich konfigurierten MySQL-Installer bin/install-production.php mit eigener leerer Datenbank verwenden.\n"
    . "Bestehende Installationen benötigen eine separat geprüfte Übernahme; vorhandene Recovery-Dateien unverändert aufbewahren.\n");
exit(2);
