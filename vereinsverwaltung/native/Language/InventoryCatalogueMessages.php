<?php declare(strict_types=1);
namespace Kulturhus\Native\Language;
/** Fixed catalogue words only; stored names, units, numbers and values remain literal. */
final class InventoryCatalogueMessages
{
    public const BROWSER=['Bitte ungespeicherte Änderungen zuerst speichern.','Bestände werden gefiltert …','Die Auswahl konnte nicht aktualisiert werden. Bitte erneut filtern.'];
    public const ENGLISH=[
        'Material'=>'Materials','Vereinsmaterial und verfügbare Mengen'=>'Club materials and available quantities','Material suchen'=>'Search materials','Bezeichnung oder Kategorie'=>'Name or category','Suchen'=>'Search','Kein Material für diese Suche vorhanden.'=>'No materials match this search.',
        'Gegenstand suchen'=>'Search items','Kategorie'=>'Category','Bestand'=>'Stock','Unter Mindestbestand'=>'Below minimum stock','Gesperrte Bestände'=>'Blocked stock','Alle'=>'All','Anzeigen'=>'Show',
        'Gemeinsam angelegte Einzelstücke'=>'Items created together','Alle Gegenstände anzeigen'=>'Show all items',
        '{count} Gegenstände · Seite {page} von {pages}'=>'{count} items · Page {page} of {pages}',
        'Einzelstück · Seriennummer: {number}'=>'Individual item · Serial number: {number}','noch nicht bekannt'=>'not known yet',
        'Ausleihbar'=>'Available for loans','Verbrauchsmaterial'=>'Consumable','Vereinseigentum'=>'Club property','Fremde Leihgabe'=>'Third-party loan',
        'Gesamt'=>'Total','Gesperrt'=>'Blocked','Mindestbestand'=>'Minimum stock','Keine Gegenstände gefunden.'=>'No items found.',
        'Gegenstandsseiten'=>'Item pages','Vorherige'=>'Previous','Nächste'=>'Next','Seite'=>'Page','Seite anzeigen'=>'Show page',
        'Offene Ausleihen'=>'Outstanding loans','Überfällig'=>'Overdue','+ Ausleihe'=>'+ Loan',
        'Gegenstand suchen (Name, Nummer, Seriennummer)'=>'Search items (name, number, serial number)','Mitglied / Verantwortung'=>'Member / responsibility','Alle Personen'=>'All people','Lagerort'=>'Storage location','Alle Orte'=>'All locations','Filtern'=>'Filter',
        '{count} Teilbestände · Seite {page} von {pages}'=>'{count} stock portions · Page {page} of {pages}',
        'Aktueller Ort'=>'Current location','Aufbewahrung'=>'Held at',' · Zuständig: {name}'=>' · Responsible: {name}','Bestandsseiten'=>'Stock pages','Keine Bestände gefunden.'=>'No stock found.',
        '{count} Ausleihen · Seite {page} von {pages}'=>'{count} loans · Page {page} of {pages}','+ Zeitfenster planen'=>'+ Plan time window','Entleiher, Nummer oder Einsatz'=>'Borrower, number or purpose','Ausleihstatus'=>'Loan status','Offene'=>'Outstanding','Geplante Zeitfenster'=>'Planned time windows','Mitglied / Kontakt'=>'Member / contact','Entleiher'=>'Borrower','Geplante Ausgabe'=>'Planned handover','Rückgabe bis'=>'Return by','Noch offen'=>'Outstanding','Keine Ausleihen gefunden.'=>'No loans found.','Ausleihseiten'=>'Loan pages','Geplant'=>'Planned','Ausgegeben'=>'Issued','Abgeschlossen'=>'Completed','Storniert'=>'Cancelled',
        'Dokumentierte Gegenstandswerte'=>'Documented item values',
        'Nur aktive Gegenstände: Wert je Einheit × Bestand oder der auf die erfassten Teile verteilte Rechnungsbetrag. Archivierte Gegenstände bleiben mit ihren Belegen im Archiv erhalten. Fremde Leihgaben und verknüpfte Handkassen sind ausgenommen.'=>'Active items only: value per unit × stock, or the invoice amount allocated to the recorded units. Archived items remain in the archive with their documents. Third-party loans and linked cash boxes are excluded.',
        'Diese Inventarwertsumme ist kein Bank- oder Kassenbestand und kein steuerlicher Buchwert. Abschreibungen werden nicht berechnet. {count} eigene Gegenstände haben noch keinen dokumentierten Wert.'=>'This inventory value is not a bank or cash balance or a tax book value. Depreciation is not calculated. {count} club items do not yet have a documented value.',
        'Zuständiges Mitglied'=>'Responsible member','Aktueller Lagerort'=>'Current storage location','Alle Lagerorte'=>'All storage locations',
        'Die Ortsauswahl zeigt Gegenstände mit Bestand am gewählten Ort; die Wertspalte bezieht sich auf den gesamten Gegenstand über alle Orte.'=>'The location filter shows items with stock at the selected location; the value column covers the entire item across all locations.',
        'Gesamtmenge'=>'Total quantity','Dokumentierter Stückwert'=>'Documented unit value','Je Stück erfasst'=>'Recorded per unit','Nicht bewertet'=>'Not valued','Wert im Vereinsvermögen'=>'Value in club assets','Nicht enthalten'=>'Not included','Vermögensseiten'=>'Asset pages',
        'Ausgewählte Person nicht verfügbar – Filter prüfen'=>'Selected person unavailable – review filter','Ausgewählter Lagerort nicht verfügbar – Filter prüfen'=>'Selected storage location unavailable – review filter',
        'Inventarauswahl vollständig prüfen. Die ursprünglichen Filter- und Seitenangaben bleiben erhalten.'=>'Check the complete inventory selection. The original filter and page input remains available.',
        'Inventarauswahl'=>'Original inventory selection','Die vollständigen Filter- und Seitenangaben bleiben zur Prüfung erhalten. Öffne die Auswahl neu, bevor du erneut filterst.'=>'The complete filter and page input remains available for review. Reopen the selection before filtering again.','Inventarauswahl neu öffnen'=>'Reopen inventory selection',
        'Bitte ungespeicherte Änderungen zuerst speichern.'=>'Save unsaved changes first.','Bestände werden gefiltert …'=>'Filtering stock …','Die Auswahl konnte nicht aktualisiert werden. Bitte erneut filtern.'=>'The selection could not be updated. Filter again.',
    ];
    public static function key(string $source):string{return 'inventory_catalogue_'.substr(hash('sha256',$source),0,24);}
    public static function messages(string $locale):array{$en=explode('-',$locale,2)[0]==='en';$out=[];foreach(self::ENGLISH as $source=>$translation)$out[self::key($source)]=$en?$translation:$source;return $out;}
    public static function text(string $source,array $messages,array $values=[]):string{if(!isset($messages[self::key($source)]))return InventoryLocationMessages::text($source,$messages,$values);$replace=[];foreach($values as $name=>$value)$replace['{'.$name.'}']=(string)$value;return strtr($messages[self::key($source)],$replace);}
}
