<?php declare(strict_types=1);
namespace Kulturhus\Native\Language;
/** Fixed cache administration text; cache names, keys, tags and values are originals. */
final class CacheMessages
{
 public const ENGLISH=[
  'Cache'=>'Cache','Aktualisieren'=>'Refresh','Einträge'=>'Entries','abgelaufen'=>'expired',
  'Bereich'=>'Namespace','Alle Bereiche'=>'All namespaces','Anzeigen'=>'Show',
  'Abgelaufene entfernen'=>'Remove expired entries','Cache leeren'=>'Clear cache','Bereich leeren'=>'Clear namespace',
  'Keine Cacheeinträge.'=>'No cache entries.','Abgelaufen'=>'Expired','Bis zur Invalidierung'=>'Until invalidated',
  'Gültig bis'=>'Valid until','Entfernen'=>'Remove','Cacheeinträge blättern'=>'Browse cache entries',
  'Seite {page} von {pages}'=>'Page {page} of {pages}','Vorherige'=>'Previous','Weitere'=>'Next',
  'Markierte Einträge entfernen'=>'Remove tagged entries','Markierung'=>'Tag','Cache aktualisiert.'=>'Cache updated.',
  'Keine Berechtigung für die Cacheverwaltung.'=>'You do not have permission to manage the cache.',
  'Diese Anfrage ist nicht erlaubt.'=>'This request is not allowed.','Formular abgelaufen. Bitte neu laden.'=>'This form has expired. Please reload.',
  'Cacheseite prüfen.'=>'Review the cache page.','Cache nicht verfügbar. Bitte erneut versuchen.'=>'Cache is unavailable. Please try again.',
  'Neu laden'=>'Reload','Cacheverwaltung nicht verfügbar. Bitte neu laden.'=>'Cache administration is unavailable. Please reload.',
  'Privater Websitecache nicht eingerichtet.'=>'The private website cache is not configured.',
  'Vereinszuordnung geändert. Bitte neu laden.'=>'The club assignment has changed. Please reload.',
  'Unbekannte Cacheaktion.'=>'Unknown cache action.','Cacheanfrage prüfen.'=>'Review the cache request.',
  'Cachegrenzen prüfen.'=>'Review the cache limits.','Cachebezeichnung prüfen.'=>'Review the cache name.',
  'Cachebereich prüfen.'=>'Review the cache namespace.','Zu viele Cacheabhängigkeiten.'=>'Too many cache dependencies.',
  'Cacheabhängigkeit prüfen.'=>'Review the cache dependency.','Zu viele Cachemarkierungen.'=>'Too many cache tags.',
  'Cachemarkierung prüfen.'=>'Review the cache tag.','Cachedauer muss zwischen 0 und 30 Tagen liegen.'=>'Cache lifetime must be between 0 and 30 days.',
  'Cachewert zu tief verschachtelt.'=>'The cache value is nested too deeply.','Cachewerte müssen JSON-Daten sein.'=>'Cache values must be JSON data.',
  'Cacheeintrag prüfen.'=>'Review the cache entry.','Ursprüngliche Angaben'=>'Original input',
  'Aktion'=>'Action','Eintrag'=>'Entry','Cache erneut öffnen'=>'Reopen cache administration',
  'Ihre ursprüngliche Anfrage bleibt erhalten. Öffnen Sie den aktuellen Cache bewusst, bevor Sie eine neue Aktion bestätigen.'=>'Your original request is retained. Deliberately reopen the current cache before confirming a new action.',
 ];
 public static function key(string $source):string{return 'cache_'.substr(hash('sha256',$source),0,24);}
 public static function messages(string $locale):array{$en=explode('-',$locale,2)[0]==='en';$out=[];foreach(self::ENGLISH as$source=>$translated)$out[self::key($source)]=$en?$translated:$source;return$out;}
 public static function text(string $source,array $messages,array $values=[]):string{$text=$messages[self::key($source)]??ManagerMessages::text($source,$messages);foreach($values as$key=>$value)$text=str_replace('{'.$key.'}',(string)$value,$text);return$text;}
}
