<?php declare(strict_types=1);
require __DIR__.'/../src/Shared/FormValidation.php';use Kulturhus\Verein\FormValidation;$n=0;
foreach(['Bitte alle Pflichtfelder ausfüllen.','Bitte description ausfüllen und die Feldlänge beachten.','Bitte Titel, Kategorie und Termindaten prüfen.','Bitte Art, Name, E-Mail und Anschrift prüfen.'] as $message){if(!FormValidation::fieldError($message))throw new RuntimeException('Required field classification failed');++$n;}
foreach(['Der Eintrag wurde inzwischen geändert. Bitte neu laden und erneut prüfen.','Keine Berechtigung.','Formular neu öffnen.','SMTP-Verbindung fehlgeschlagen.','Datei konnte nicht gespeichert werden.','Die Freigabe fehlt.',''] as $message){if(FormValidation::fieldError($message))throw new RuntimeException('Unrelated server error must not mark guessed fields');++$n;}
echo "$n exact server field-classification checks passed\n";
