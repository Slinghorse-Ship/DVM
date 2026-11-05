<?php declare(strict_types=1);
namespace Kulturhus\Native\Language;
/** Explicit location interface labels; saved places, people and notes remain literal. */
final class InventoryLocationMessages
{
    public const ENGLISH=[
        'Inventar & Ausleihe'=>'Inventory & loans',
        'Gegenstände, Aufbewahrung und Ausleihen für unseren Verein'=>'Items, storage and loans for our club',
        'Vereinsvermögen'=>'Club assets',
        'Finanzen · Dokumentierte Gegenstandswerte und Anschaffungsnachweise'=>'Finance · Documented item values and acquisition records',
        '+ Lagerort'=>'+ Storage location','+ Gegenstand'=>'+ Item',
        'Wo ist was?'=>'Where is everything?','Gegenstände'=>'Items','Lagerorte'=>'Storage locations','Ausleihen'=>'Loans','Materialübersicht'=>'Materials overview','Inventarbereiche'=>'Inventory areas',
        'Ausgewählte Person nicht mehr verfügbar – bitte neu auswählen'=>'Selected person is no longer available – please select again',
        'Keine Zuordnung'=>'No assignment','Kontakt'=>'Contact','Bitte auswählen'=>'Please select',
        'Lagerort anlegen'=>'Create storage location','Lagerort bearbeiten'=>'Edit storage location','Lagerort speichern'=>'Save storage location',
        'Name des Lagerorts'=>'Storage location name','Aufbewahrendes Mitglied / Kontakt'=>'Member / contact holding the items','Gebäude / Veranstaltungsort'=>'Building / venue','Raum'=>'Room','Regal / Fach'=>'Shelf / compartment','Status'=>'Status','Aktiv'=>'Active','Archiviert'=>'Archived','Hinweise'=>'Notes',
        'Auch „bei …“ ist ein Lagerort. Eine Privatadresse ist keine Pflicht. Dauerhafte Aufbewahrung erzeugt keine Ausleihe.'=>'“Held by …” can also be a storage location. A private address is not required. Permanent storage does not create a loan.',
        'Abbrechen / zu den Lagerorten'=>'Cancel / back to storage locations','Lagerorte & Aufbewahrung'=>'Storage locations & holders','Lagerorte suchen (Name, Person, Status)'=>'Search storage locations (name, person, status)','Vereinslager · ohne Personenzuordnung'=>'Club storage · no person assigned',
        'Lege zuerst euren Vereinsraum oder einen privaten Aufbewahrungsort an.'=>'First add your club room or a private storage location.',
        'Aktuell in {name}'=>'Currently at {name}','Gespeicherter Lagerort'=>'Saved storage location','Du hast hier Leserechte. Änderungen übernimmt die Inventarverwaltung.'=>'You have read access here. Inventory administrators make changes.',
        'Lagerortangaben'=>'Original storage location details',
        'Die vollständigen ursprünglichen Angaben bleiben zur Prüfung sichtbar. Den tatsächlich geöffneten Lagerort und seinen aktuellen Speicherstand neu prüfen, bevor du erneut speicherst.'=>'The complete original input remains visible for review. Recheck the location you opened and its current saved state before saving again.',
        'Gespeicherten Lagerort neu prüfen'=>'Recheck saved storage location','Neue Lagerortanlage neu prüfen'=>'Recheck new storage location','Lagerortliste öffnen'=>'Open storage location list',
        'Dieser Lagerort wurde bereits angelegt.'=>'This storage location has already been created.',
        'Die ursprüngliche Anlage wird nicht nochmals ausgeführt. Den vorhandenen Lagerort öffnen oder bewusst einen neuen Anlagevorgang beginnen.'=>'The original creation is not performed again. Open the existing location or deliberately start a new creation request.',
        'Vorhandenen Lagerort öffnen'=>'Open existing storage location','Neuen Lagerortvorgang beginnen'=>'Start new storage location request',
        'Vorhandener Lagerort dieses Vorgangs geöffnet. Es wurde kein weiterer Lagerort angelegt.'=>'The existing location for this request has been opened. No additional location was created.',
        'Ursprüngliche Lagerort-Vorgangskennung vollständig prüfen.'=>'Check the complete original storage location request identifier.',
        'Ursprüngliche Lagerortangaben vollständig prüfen. Diese Eingabeform wird nicht übernommen.'=>'Check the complete original storage location details. This input format is not accepted.',
        'Ursprüngliche Lagerortkennung, Fassung und Status vollständig prüfen.'=>'Check the complete original storage location identifier, version and status.',
        'Lagerortangaben am ursprünglich geöffneten Lagerort erneut prüfen.'=>'Recheck the location details at the originally opened storage location.',
        'Lagerortkennung vollständig prüfen.'=>'Check the complete storage location identifier.',
        'Lagerort nicht verfügbar.'=>'Storage location unavailable.',
        'Aktuelle Inventarbearbeitung für diesen Lagerortvorgang erforderlich.'=>'Current inventory write permission is required for this storage location request.',
        'Inventar-Leserecht für Lagerorte erforderlich.'=>'Inventory read permission is required for storage locations.',
        'Diese Lagerortkennung gehört zu einem anderen Vorgang.'=>'This location identifier belongs to a different request.',
        'Lagerort konnte nicht bestätigt werden. Aktuellen Speicherstand neu prüfen.'=>'The storage location could not be confirmed. Recheck its current saved state.',
        'Inventarvorgang gespeichert.'=>'Inventory request saved.',
        'Keine Berechtigung für Material, Inventar und Ausleihen.'=>'You do not have permission for materials, inventory and loans.',
        'Zugang nicht aktiv.'=>'Access inactive.','Eintrag nicht verfügbar.'=>'Entry unavailable.',
        'Der Eintrag wurde inzwischen geändert. Bitte neu laden und erneut prüfen.'=>'The record has changed. Reload and check again.',
        'Diese Vorgangskennung wurde bereits mit anderen Eingaben verwendet.'=>'This request identifier has already been used with different input.',
        'Lagerort ist noch belegt. Bestände und offene Ausleihen zuerst auflösen.'=>'This storage location is still in use. Resolve its stock and outstanding loans first.',
        'Bei belegten Lagerorten den Aufbewahrer über einen neuen Lagerort und eine bestätigte Umlagerung wechseln.'=>'To change the holder of a location in use, create another location and confirm a stock transfer.',
        'Der Lagerort existiert bereits.'=>'This storage location already exists.',
        'Text ist ungültig oder zu lang.'=>'The text is invalid or too long.',
        'Bitte alle Pflichtfelder ausfüllen.'=>'Complete all required fields.',
        'Ungültiger Status.'=>'Invalid status.','Ungültige Version.'=>'Invalid version.',
        'Ungültige Vorgangskennung. Bitte Formular neu öffnen.'=>'Invalid request identifier. Reopen the form.',
        'Gegenstand'=>'Item','Menge'=>'Quantity','Aktueller Ort / Aufbewahrung'=>'Current location / holder','Zuletzt bestätigt'=>'Last confirmed',' · Zuständig: {name}'=>' · Responsible: {name}',
        'Standort nicht bestätigt'=>'Location not confirmed','Verfügbar'=>'Available','Reserviert'=>'Reserved','Gesperrt / Wartung'=>'Blocked / maintenance','Ausgeliehen'=>'On loan','Rückgabe: {date} →'=>'Return: {date} →','Noch nicht bestätigt'=>'Not confirmed yet','Keine Bestände für diese Auswahl.'=>'No stock matches this selection.',
        'Name nicht verfügbar'=>'Name unavailable','Name nicht hinterlegt'=>'Name not provided','CSV exportieren'=>'Export CSV',
    ];
    public static function key(string $source):string{return 'inventory_location_'.substr(hash('sha256',$source),0,24);}
    public static function messages(string $locale):array{$en=explode('-',$locale,2)[0]==='en';$out=[];foreach(self::ENGLISH as $source=>$translation)$out[self::key($source)]=$en?$translation:$source;return $out;}
    public static function text(string $source,array $messages,array $values=[]):string{if(!isset($messages[self::key($source)]))return MemberProfileMessages::text($source,$messages,$values);$text=$messages[self::key($source)];$replace=[];foreach($values as $name=>$value)$replace['{'.$name.'}']=(string)$value;return strtr($text,$replace);}
}
