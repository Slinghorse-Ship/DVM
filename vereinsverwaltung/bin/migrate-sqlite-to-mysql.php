<?php declare(strict_types=1);
/** Retired schema-81 importer; recovery sources must never be opened or modified here. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
fwrite(STDERR, "Stillgelegt: Der einmalige SQLite-Import für MySQL-Schema 81 ist mit dem aktuellen MySQL-Schema nicht kompatibel.\n"
    . "Auch --dry-run führt keinen Import aus. Keine Verbindung hergestellt und keine Quelldateien oder Zieldaten geändert.\n"
    . "SQLite-Dateien und Recovery-Sicherungen unverändert aufbewahren. Eine Altübernahme benötigt ein separat geprüftes Verfahren; für neue Vereine distribution/install-native.php --config-stdin mit eigener leerer MySQL-Datenbank verwenden.\n");
exit(2);
