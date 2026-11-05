<?php declare(strict_types=1);
namespace Kulturhus\Native\Language;
/** Explicit interface text; original plugin identity and configuration remain literal. */
final class ExtensionMessages
{
 public const ENGLISH=[
  'Erweiterungen'=>'Extensions','Zusätzliche Erweiterungen konnten nicht geladen werden. Lokales Paket prüfen.'=>'Additional extensions could not be loaded. Review the local package.',
  'Paket fehlt'=>'Package missing','Aktualisierung erforderlich'=>'Update required','Start fehlgeschlagen'=>'Start failed',
  'Freigabe prüfen'=>'Review authorisation','Manuell geladen'=>'Loaded manually','Aktiv'=>'Active','Inaktiv'=>'Inactive','Nicht installiert'=>'Not installed',
  'Version'=>'Version','Lokales Paket wieder ergänzen. Die gespeicherten Einstellungen bleiben erhalten.'=>'Restore the local package. Its saved settings are retained.',
  'Installieren'=>'Install','Deaktivieren'=>'Disable','Aktualisieren'=>'Update','Aktivieren'=>'Enable','Erneut starten'=>'Restart',
  'Benötigt:'=>'Requires:','ab'=>'from','Einstellungen'=>'Settings','Speichern'=>'Save','Entfernen'=>'Remove',
  'Einstellungen dieser Erweiterung löschen'=>'Delete this extension’s settings','Deinstallieren'=>'Uninstall',
  'Erweiterungen blättern'=>'Browse extensions','Zurück'=>'Back','Weitere'=>'Next','Letzte Änderungen'=>'Recent changes',
  'installiert'=>'installed','aktiviert'=>'enabled','deaktiviert'=>'disabled','konfiguriert'=>'configured','aktualisiert'=>'updated','deinstalliert'=>'uninstalled',
  'Änderungsverlauf blättern'=>'Browse change history','Ungültige Erweiterungsseite.'=>'Invalid extension page.','Ungültige Verlaufsseite.'=>'Invalid history page.',
  'Erweiterungsanfrage prüfen.'=>'Review the extension request.','Unbekanntes Konfigurationsfeld.'=>'Unknown configuration field.',
  'Konfiguration prüfen.'=>'Review the configuration.','Schalter prüfen.'=>'Review the switch.','Zahl prüfen.'=>'Review the number.',
  'Erweiterungsverwaltung nicht freigegeben.'=>'Extension administration is not authorised.','Anfrage nicht erlaubt.'=>'This request is not allowed.',
  'Formular abgelaufen. Bitte neu laden.'=>'This form has expired. Please reload.','Erweiterungsaktion prüfen.'=>'Review the extension action.',
  'Entfernen ausdrücklich bestätigen.'=>'Explicitly confirm removal.','Erweiterung gespeichert.'=>'Extension saved.',
  'Erweiterung konnte nicht gespeichert werden. Der bisherige Stand bleibt erhalten.'=>'The extension could not be saved. The previous state is retained.',
  'Erweiterung derzeit nicht sicher prüfbar. Öffnen Sie den gespeicherten Stand erneut.'=>'The extension cannot currently be verified. Reopen the saved state.',
  'Erweiterungsverwaltung nicht verfügbar.'=>'Extension administration is unavailable.','Neu laden'=>'Reload',
  'Erweiterungen können nur von der Vereinsadministration verwaltet werden.'=>'Only the club administration can manage extensions.',
  'Native Vereinsanmeldung nicht verfügbar.'=>'Native club sign-in is unavailable.','Vereinszugang nicht aktiv.'=>'Club access is not active.',
  'Native Anmeldung erforderlich.'=>'Native sign-in is required.','Aktuelle Anmeldung geändert.'=>'The current sign-in has changed.',
  'Erweiterungsverwaltung außerhalb einer Geschäftsbuchung starten.'=>'Start extension administration outside a business transaction.',
  'Erweiterungsaktion ungültig.'=>'Invalid extension action.','Vereinszuordnung geändert.'=>'Club assignment has changed.',
  'Erweiterungsquelle geändert. Bitte neu öffnen.'=>'The extension source has changed. Please reopen it.',
  'Erweiterung wurde geändert. Bitte neu laden.'=>'The extension has changed. Please reload.',
  'Erweiterung bereits installiert.'=>'The extension is already installed.','Erweiterung zuerst installieren.'=>'Install the extension first.',
  'Geänderte Erweiterung zuerst aktualisieren.'=>'Update the changed extension first.',
  'Installation fehlgeschlagen. Der bisherige Stand bleibt erhalten.'=>'Installation failed. The previous state is retained.',
  'Ein Downgrade benötigt ein geprüftes Gesamtpaket.'=>'A downgrade requires a verified complete package.',
  'Aktualisierung fehlgeschlagen. Der bisherige Stand bleibt erhalten.'=>'The update failed. The previous state is retained.',
  'Erweiterung zuerst deaktivieren.'=>'Disable the extension first.',
  'Deinstallation fehlgeschlagen. Der bisherige Stand bleibt erhalten.'=>'Uninstall failed. The previous state is retained.',
  'Erweiterungszugriff entzogen.'=>'Extension access has been revoked.',
  'Erweiterung konnte nicht initialisiert werden. Der bisherige Stand bleibt erhalten.'=>'The extension could not be initialised. The previous state is retained.',
  'Lokale Zieladresse prüfen.'=>'Review the local destination address.','Konfiguration zu groß.'=>'The configuration is too large.',
  'Erweiterungsbeschreibung ungültig.'=>'Invalid extension definition.','Erweiterungsabhängigkeit ungültig.'=>'Invalid extension dependency.',
  'Konfigurationsfeld ungültig.'=>'Invalid configuration field.','Textbegrenzung fehlt.'=>'Text limit missing.','Zahlbegrenzung fehlt.'=>'Number limits missing.',
  'Auswahl fehlt.'=>'Selection missing.','Auswahlwert ungültig.'=>'Invalid selection value.',
  'Website-Hinweis'=>'Website notice','Website-Darstellungen'=>'Website layouts','Hinweis'=>'Notice',
  'Lokale Zieladresse (optional)'=>'Local destination address (optional)','Ergänzung im Seitentitel (optional)'=>'Title suffix (optional)',
  'Erweiterung bereits registriert.'=>'The extension is already registered.','Erweiterung nicht lokal registriert.'=>'The extension is not registered locally.',
  'Erweiterungsquelle ungültig.'=>'Invalid extension source.','Erweiterungsquelle fehlt.'=>'Extension source missing.',
  'Erweiterungsquellen begrenzen.'=>'Limit the extension sources.','Lokale Erweiterungsquelle erforderlich.'=>'A local extension source is required.',
  'Erweiterungsquelle prüfen.'=>'Review the extension source.','Symbolische Erweiterungsquellen sind nicht erlaubt.'=>'Symbolic extension sources are not allowed.',
  'Erweiterungsquelle außerhalb des Moduls oder zu groß.'=>'The extension source is outside its module or too large.',
  'Zyklische Erweiterungsabhängigkeit.'=>'Cyclic extension dependency.','Lokale Abhängigkeitsversion zu alt.'=>'The local dependency version is too old.',
  'Nur einfache Erweiterungswerte sind erlaubt.'=>'Only plain extension values are allowed.',
  'Ungültiger Erweiterungsschlüssel.'=>'Invalid extension key.','Erweiterungswerte nur im Lebenszyklus ändern.'=>'Change extension values only during their lifecycle.',
  'Erweiterungswert zu groß.'=>'The extension value is too large.',
  'Ursprüngliche Angaben'=>'Original input','Erweiterung'=>'Extension','Konfiguration'=>'Configuration',
  'Aktuelle Erweiterungen öffnen'=>'Open current extensions',
  'Die ursprünglichen Angaben bleiben erhalten. Öffnen Sie den aktuellen Stand bewusst, bevor Sie eine neue Aktion bestätigen.'=>'Your original input is retained. Deliberately reopen the current state before confirming a new action.',
  'Ursprüngliche Erweiterung und ihren Stand prüfen.'=>'Review the original extension and its version.',
 ];
 public const PREFIXES=[
  'Konfiguration prüfen: '=>'Review configuration: ',
  'Abhängigkeit zuerst installieren, aktualisieren und aktivieren: '=>'Install, update and enable the dependency first: ',
  'Abhängige Erweiterung zuerst deaktivieren oder entfernen: '=>'Disable or remove the dependent extension first: ',
 ];
 public static function key(string $source):string{return 'extension_'.substr(hash('sha256',$source),0,24);}
 public static function messages(string $locale):array{$en=explode('-',$locale,2)[0]==='en';$out=[];foreach(self::ENGLISH+self::PREFIXES as$source=>$translated)$out[self::key($source)]=$en?$translated:$source;return$out;}
 public static function text(string $source,array $messages):string
 {
  if(isset(self::ENGLISH[$source]))return$messages[self::key($source)]??$source;
  foreach(self::PREFIXES as$prefix=>$unused)if(str_starts_with($source,$prefix))return($messages[self::key($prefix)]??$prefix).substr($source,strlen($prefix));
  return ManagerMessages::text($source,$messages);
 }
}
