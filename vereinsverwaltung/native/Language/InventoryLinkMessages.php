<?php declare(strict_types=1);
namespace Kulturhus\Native\Language;
/** Fixed reverse-link labels; inventory names and identifiers stay literal. */
final class InventoryLinkMessages
{
    public const ENGLISH=[
        'Inventarzuordnung'=>'Inventory links',
        'Verknüpftes Inventar'=>'Linked inventory',
        '{count} verknüpfte Gegenstände'=>'{count} linked items',
        '1 verknüpfter Gegenstand'=>'1 linked item',
        '{count} Gegenstände · Seite {page} von {pages}'=>'{count} items · Page {page} of {pages}',
        '1 Gegenstand · Seite {page} von {pages}'=>'1 item · Page {page} of {pages}',
        'Bisher kein Gegenstand zugeordnet.'=>'No item has been linked yet.',
        'Aktiv'=>'Active',
        'Archiviert'=>'Archived',
        'Archivierte Gegenstände bleiben mit ihrer ursprünglichen Belegzuordnung erhalten.'=>'Archived items retain their original link to this financial record.',
        'Handkasse mit Lagerort anlegen'=>'Create physical cash box with a storage location',
        'Gegenstand aus diesem Beleg anlegen'=>'Create item from this financial record',
        'Die Verknüpfung verändert keinen Geldbestand. Bestehende Gegenstände können über ihre Rechnung oder Sachspende zugeordnet werden.'=>'Linking inventory does not change a money balance. Existing items can be linked through their invoice or donation in kind.',
        'Verknüpfte Inventarseiten'=>'Linked inventory pages',
        'Vorherige'=>'Previous',
        'Nächste'=>'Next',
        'Seite'=>'Page',
        'Seite anzeigen'=>'Show page',
        'Bekannte ursprüngliche Seitenangabe'=>'Known original page selection',
        'Inventarzuordnung neu anzeigen'=>'Reopen inventory links',
        'Zuordnungsseite prüfen. Die bekannten ursprünglichen Seitenangaben bleiben erhalten.'=>'Check the links page. Your known original page selection is retained.',
        'Finanzberechtigung für die Inventarzuordnung erforderlich.'=>'Finance permission is required for inventory links.',
        'Finanzquelle für die Inventarzuordnung prüfen.'=>'Check the financial source for these inventory links.',
        'Finanzzugang geändert. Inventarzuordnung neu öffnen.'=>'Finance access has changed. Reopen the inventory links.',
        'Beleg nicht verfügbar.'=>'Financial record unavailable.',
        'Sachspende nicht verfügbar.'=>'Donation in kind unavailable.',
        'Konto nicht verfügbar.'=>'Account unavailable.',
        'Keine ausreichende Finanzberechtigung.'=>'Insufficient finance permission.',
        'Zugang nicht aktiv.'=>'Access is inactive.',
    ];
    public static function key(string $s):string{return 'inventory_link_'.substr(hash('sha256',$s),0,24);}
    public static function messages(string $locale):array{$en=explode('-',$locale,2)[0]==='en';$out=[];foreach(self::ENGLISH as$s=>$value)$out[self::key($s)]=$en?$value:$s;return$out;}
    public static function text(string $source,array $messages,array $values=[]):string{$text=$messages[self::key($source)]??$source;foreach($values as$key=>$value)$text=str_replace('{'.$key.'}',(string)$value,$text);return$text;}
}
