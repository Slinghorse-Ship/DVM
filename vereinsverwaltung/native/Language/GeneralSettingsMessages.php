<?php declare(strict_types=1);
namespace Kulturhus\Native\Language;
/** Only owned labels and validation; club names, custom choices and saved texts remain literal. */
final class GeneralSettingsMessages
{
    public const ENGLISH=[
        'Mitgliederausweise & Wallet'=>'Membership cards & Wallet',
        'Vereinsdaten'=>'Club details','Briefanreden & Titel'=>'Letter salutations & titles','Individuelle Felder'=>'Custom fields',
        'Vereinseinstellungen'=>'Club settings','Einstellungsbereiche'=>'Settings areas','Aktueller gespeicherter Stand'=>'Current saved state',
        'Einstellungsbereiche – Unterseiten'=>'Settings areas – pages','Verein & Zugänge'=>'Club & access','Beiträge & Nummern'=>'Fees & numbering','Vorlagen & Briefpapier'=>'Templates & stationery','Systemstatus'=>'System status','Sprachen'=>'Languages',
        'Steuern & Vorsteuer'=>'Tax & input VAT','Rollen & Rechte'=>'Roles & permissions','Postfächer & Versand'=>'Mailboxes & delivery','Zusatzfelder'=>'Custom fields','Briefanreden'=>'Letter salutations',
        'Ungespeicherter Entwurf'=>'Unsaved draft','Entwurfskennung'=>'Draft key','Vorgang'=>'Original settings key',
        'Vorschau prüfen'=>'Review preview','Aktuellen Stand bewusst prüfen'=>'Deliberately review current state','Entwurf verwerfen'=>'Discard draft',
        'Die vollständigen Einstellungen sind geprüft.'=>'I have reviewed the complete settings.','Geprüfte Einstellungen bestätigen'=>'Confirm reviewed settings',
        'Noch nicht gespeichert. Zeilenaktionen ändern nur diesen Entwurf.'=>'Not saved yet. Row actions change only this draft.',
        'Geprüfter Vorschlag'=>'Reviewed proposal','Damals gelesener Stand'=>'State read for this review','Nach Bestätigung gespeicherte Werte'=>'Values saved after confirmation',
        'Frühere Vorschläge'=>'Earlier proposals','Frühere Eingaben'=>'Earlier input','Frühere Prüfung'=>'Earlier review',
        'Ursprünglichen Ausgang lesend prüfen'=>'Read original outcome','Angenommene Originalbestätigung'=>'Accepted original confirmation',
        'Original-JSON herunterladen'=>'Download original JSON','Eingaben'=>'Original input','Prüfung'=>'Original review',
        'Tatsächliches Ergebnis'=>'Actual result','Gespeicherte Fassung'=>'Saved version','Zeitpunkt'=>'Time','Konto'=>'Account',
        'Weitere Einstellungen bearbeiten'=>'Edit further settings','Entwürfe'=>'Your open drafts','Entwurf öffnen'=>'Open draft',
        'Ausgang noch nicht bestätigt. Zuerst die ursprüngliche Kennung lesend prüfen.'=>'Outcome not confirmed yet. First read the original key.',
        'Diese frühere Prüfung ist nicht mehr aktuell. Die vorgeschlagenen Werte bleiben erhalten.'=>'This earlier review is no longer current. The proposed values are retained.',
        'Private Bestätigungen'=>'Private confirmations','Bestätigungen durchsuchen'=>'Search confirmations','Suche'=>'Search','Suchen'=>'Search',
        'Seite'=>'Page','Seite öffnen'=>'Open page','Zurück'=>'Previous','Weiter'=>'Next','Keine Bestätigungen vorhanden.'=>'No confirmations yet.',
        'Gespeicherte Fassungen'=>'Saved versions','Fassung öffnen'=>'Open version','Frühere Originalfassung'=>'Earlier original version',
        'Frühere Fassungen enthalten die tatsächlich gespeicherten Werte. Eine damalige Vorschau oder ausdrückliche Annahme wird nicht nachträglich behauptet.'=>'Earlier versions contain the values actually saved. A former preview or explicit approval is not invented.',
        'Noch keine gespeicherte Fassung.'=>'No saved version yet.','Keine passende Bestätigung.'=>'No matching confirmation.',
        'Vereinsname'=>'Club name','Eingetragener Verein'=>'Registered association','Abweichende Bezeichnung'=>'Alternative name','Art des Vereins'=>'Type of association','Vereinskategorie'=>'Association category','Vereinsregisternummer'=>'Association registration number',
        'Straße & Hausnummer'=>'Street & house number','Zweite Adresszeile'=>'Second address line','PLZ'=>'Postal code','Ort'=>'City','Telefon'=>'Phone','E-Mail'=>'Email','Website'=>'Website',
        'Telefon im Vereinsprofil anzeigen'=>'Show phone in club profile','E-Mail im Vereinsprofil anzeigen'=>'Show email in club profile',
        'Name & Eintragung'=>'Name & registration','Anschrift & Kontakt'=>'Address & contact','Weitere Vereinsangaben'=>'Further club details',
        'Vereinsdaten verändern keine früheren Briefe, Belege oder veröffentlichten Originale.'=>'Club details do not change earlier letters, documents or published originals.',
        'Standard-Briefanrede'=>'Default letter salutation','Persönlich'=>'Personal','Formell'=>'Formal','Titel (ein Eintrag je Zeile)'=>'Titles (one entry per line)',
        'Briefanrede'=>'Letter salutation','Anrede'=>'Salutation','Bezeichnung'=>'Name','Feldname'=>'Field name','Typ'=>'Type','Auswahlwerte (eine Zeile je Wert)'=>'Choices (one value per line)','Pflichtfeld'=>'Required field',
        'Ja'=>'Yes','Nein'=>'No','Text'=>'Text','Zahl'=>'Number','Datum'=>'Date','Ja/Nein'=>'Yes/No','Auswahl'=>'Choice','Individuelles Feld'=>'Custom field',
        'Nach oben'=>'Move up','Nach unten'=>'Move down','Entfernen'=>'Remove','Anrede hinzufügen'=>'Add salutation','Feld hinzufügen'=>'Add field',
        'Höchstens 30 Anreden. Variablen bleiben unverändert, zum Beispiel {{VORNAME}} und {{NACHNAME}}.'=>'At most 30 salutations. Variables stay unchanged, for example {{VORNAME}} and {{NACHNAME}}.',
        'Höchstens 50 Felder. Umbenennen und Umordnen erhalten die Feldkennung. Frühere Profilwerte werden nicht geändert; entfernte Angaben bleiben dort als frühere Angaben lesbar.'=>'At most 50 fields. Renaming and reordering preserve the field key. Earlier profile values do not change; removed values remain readable there as earlier details.',
        'Zeichenhöchstzahl'=>'Character limit','Feldkennung'=>'Field key','Vollständige Werte'=>'Complete values',
        'Eigenen Einstellungsbereich auswählen.'=>'Select your settings area.','Eigene Vereinseinstellungen erforderlich.'=>'Your current club settings permission is required.',
        'Textfeld enthält ungültige Daten.'=>'A text field contains invalid data.','Pflichtfeld oder zulässige Textlänge prüfen.'=>'Check the required field or permitted text length.',
        'Unerwartetes Einstellungsfeld.'=>'Unexpected settings field.','E-Mail-Adresse prüfen.'=>'Check the email address.','HTTPS-Adresse ohne Zugangsdaten erforderlich.'=>'An HTTPS address without credentials is required.',
        'Unbekannte Variable: '=>'Unknown variable: ','Unvollständige Variable.'=>'Incomplete variable.','Die ausgefüllte Zeile benötigt eine Bezeichnung.'=>'The completed row needs a name.',
        'Bezeichnung doppelt.'=>'Duplicate name.','Feldkennung prüfen.'=>'Check the field key.','Mindestens einen Auswahlwert eintragen.'=>'Enter at least one choice.',
        'Mindestens eine Briefanrede ist erforderlich.'=>'At least one letter salutation is required.','Ungültige Auswahl.'=>'Invalid choice.','Ungültiger Schalter.'=>'Invalid checkbox value.',
        'Zu viele oder ungültige Zeilen.'=>'Too many or invalid rows.','Die höchste Zeilenzahl ist erreicht.'=>'The maximum row count has been reached.',
        'Ursprüngliche Einstellungsfassung prüfen.'=>'Check the original settings version.','Vollständige ursprüngliche Einstellungsfelder prüfen.'=>'Check all original settings fields.',
        'Vereinseinstellungen im vorhandenen Bereich öffnen.'=>'Open club settings in the existing area.','Formular abgelaufen. Bitte neu laden.'=>'The form has expired. Please reload.',
        'Gültige Einstellungsaktion auswählen.'=>'Select a valid settings action.','Gültige ungespeicherte Zeilenaktion auswählen.'=>'Select a valid unsaved row action.',
        'Eigenen ursprünglichen Einstellungsentwurf öffnen.'=>'Open your original settings draft.',
        'Zu viele offene Einstellungsentwürfe. Einen vorhandenen Entwurf öffnen oder verwerfen.'=>'Too many open settings drafts. Open or discard an existing draft.',
        'Dieses frühere Einstellungsformular wurde nicht gespeichert. Die erhaltenen Angaben zuerst prüfen und danach gesondert bestätigen.'=>'This earlier settings form was not saved. First review the retained values, then confirm them separately.',
        'Zuerst den ursprünglichen Einstellungsausgang prüfen.'=>'Check the original settings outcome first.',
        'Der Entwurf wurde in einem anderen Tab geändert. Die ursprünglichen Angaben erneut öffnen.'=>'The draft changed in another tab. Open the original values again.',
        'Die vollständig geprüften Einstellungen ausdrücklich bestätigen.'=>'Explicitly confirm the fully reviewed settings.',
        'Ursprüngliche Einstellungskennung und Prüfung auswählen.'=>'Select the original settings key and review.',
        'Diese Einstellungskennung gehört zu anderen ursprünglichen Angaben.'=>'This settings key belongs to different original values.',
        'Diese Einstellungen wurden inzwischen geändert. Aktuellen Stand bewusst erneut prüfen.'=>'These settings have changed. Deliberately review the current state again.',
        'Diese Einstellungen wurden inzwischen geändert. Bitte neu laden.'=>'These settings have changed. Please reload.',
        'Einstellungen werden gerade bearbeitet. Bitte erneut prüfen.'=>'Settings are being edited. Please review again.',
        'Einstellungsbestätigungen benötigen das aktuelle installierte Paket.'=>'Settings confirmations require the current installed package.',
        'Ursprüngliche Einstellungsbestätigung bitte prüfen lassen.'=>'Have the original settings confirmation checked.',
        'Ursprüngliche Einstellungsbestätigung nicht verfügbar.'=>'Original settings confirmation is unavailable.',
        'Ursprüngliche Einstellungsbestätigung auswählen.'=>'Select an original settings confirmation.',
        'Einstellungssuche mit höchstens 150 Zeichen eingeben.'=>'Enter a settings search of at most 150 characters.',
        'Verfügbare Einstellungsseite auswählen.'=>'Select an available settings page.','Verfügbare Fassungsseite auswählen.'=>'Select an available version page.',
        'Ursprüngliche Einstellungsfassung nicht verfügbar.'=>'Original settings version is unavailable.',
        'Einstellungsausgang nicht bestätigt. Die ursprüngliche Bestätigung über ihre Kennung prüfen.'=>'Settings outcome is unconfirmed. Read the original confirmation by its key.',
        'Einstellungsbereich konnte nicht geladen werden.'=>'The settings area could not be loaded.',
        'Eigenen aktiven Verein auswählen.'=>'Select your active club.','Vereinszuordnung inzwischen geändert.'=>'The club association has changed.',
    ];
    public static function key(string $source):string{return 'general_settings_'.substr(hash('sha256',$source),0,24);}
    public static function messages(string $locale):array{$out=[];$en=explode('-',$locale,2)[0]==='en';foreach(self::ENGLISH as$source=>$translation)$out[self::key($source)]=$en?$translation:$source;return$out;}
    public static function text(string $source,array $messages):string
    {
        if(isset($messages[self::key($source)]))return$messages[self::key($source)];
        if(str_starts_with($source,'Unbekannte Variable: '))return self::text('Unbekannte Variable: ',$messages).substr($source,strlen('Unbekannte Variable: '));
        foreach(\Kulturhus\Verein\SettingsSchema::fields('club')as$field)if(str_starts_with($source,$field[0].': '))return self::text($field[0],$messages).': '.self::text(substr($source,strlen($field[0])+2),$messages);
        return$source;
    }
}
