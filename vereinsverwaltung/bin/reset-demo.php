<?php declare(strict_types=1);
/** No automatic reset: a DSN does not prove ownership of a disposable database. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
fwrite(STDERR, "Demo-Reset verweigert, auch mit --demo: MySQL-Datenbanken werden nicht automatisch geleert oder gelöscht.\n"
    . "Für eine neue Demo bewusst eine separate leere lokale MySQL-Datenbank bereitstellen, VEREIN_MODE=synthetic und VEREIN_DATABASE_DSN sowie private VEREIN_DATABASE_USER/PASSWORD setzen und bin/init-demo.php --demo ausführen.\n"
    . "Die lokale Laufzeit anschließend mit KULTURHUS_LOCAL_DEMO=1 und KULTURHUS_DEMO_DATABASE auf diese Demo konfigurieren; Mail gesperrt lassen (KULTURHUS_DISABLE_MAIL=1).\n"
    . "Keine Verbindung hergestellt. Vorhandene Datenbanken, SQLite-/Recovery-Dateien und Demo-Bankdateien bleiben erhalten.\n");
exit(2);
