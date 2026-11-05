<?php declare(strict_types=1);
namespace Kulturhus\Native\Language;
/** Fixed attachment words; file names and original business values remain literal. */
final class InventoryFileMessages
{
 public const ENGLISH=[
  'Die ursprüngliche Dateizuordnung wurde inzwischen geändert. Aktuellen Dokumentenstand prüfen.'=>'The original file assignment has changed. Check the current document state.',
  'Ursprüngliche Dateivorgangskennung vollständig prüfen.'=>'Check the complete original file request identifier.',
  'Ursprüngliche Dateiquelle vollständig prüfen.'=>'Check the complete original file source.',
  'Datei am ursprünglich geöffneten Gegenstand erneut prüfen.'=>'Recheck the file at the originally opened item.',
  'Aktuelle Inventarbearbeitung für diesen Dateianhang erforderlich.'=>'Current inventory write permission is required for this attachment.',
  'Dateiformular unvollständig angekommen. Ursprüngliche Angaben neu prüfen; es wurde nichts zugeordnet.'=>'The file form arrived incomplete. Recheck the original details; nothing was attached.',
  'Ursprünglichen Dateiauftrag vollständig prüfen.'=>'Check the complete original file request.',
  'Datei unter ihrer ursprünglichen Vorgangsadresse erneut prüfen.'=>'Recheck the file at its original request address.',
  'Diese Dateikennung gehört zu einem anderen ursprünglichen Auftrag.'=>'This file identifier belongs to a different original request.',
  'Dateizuordnung konnte nicht bestätigt werden. Aktuellen Speicherstand neu prüfen.'=>'The attachment could not be confirmed. Recheck the current saved state.',
  'Fotos & weitere Dokumente'=>'Photos & other documents',
  '{count} Dateien beim Gegenstand · Fotos, Anleitungen und Nachweise'=>'{count} files for this item · photos, instructions and evidence',
  'Dateiauftrag'=>'Original file request',
  'Die vollständigen bekannten Angaben bleiben zur Prüfung sichtbar. Es wird keine andere Datei oder Gegenstandszuordnung eingesetzt.'=>'All complete known details remain available for review. No different file or item assignment is substituted.',
  'Gegenstand und Dateizuordnung neu prüfen'=>'Recheck item and attachment',
  'Dieser Dateianhang wurde bereits gespeichert.'=>'This attachment has already been saved.',
  'Die vorhandene Datei bleibt zugeordnet. Eine Wiederholung legt keinen zweiten Anhang an.'=>'The existing file remains attached. Repeating the request does not create a second attachment.',
  'Gespeicherte Datei öffnen: {name}'=>'Open saved file: {name}',
  'Weiteren Dateianhang vorbereiten'=>'Prepare another attachment',
  'Datei'=>'File',
  '{size} KB · Herunterladen'=>'{size} KB · Download',
  'Noch keine Fotos oder Nachweise hinterlegt.'=>'No photos or evidence have been attached yet.',
  'Datei diesem Gegenstand zuordnen'=>'Attach file to this item',
  'Datei auswählen und anschließend bewusst hinzufügen. Die Auswahl allein speichert und überträgt nichts.'=>'Choose a file, then explicitly add it. Choosing a file alone does not save or transfer it.',
  'Foto oder Nachweis (PNG, JPEG, PDF · max. 2 MB; HEIC/RAW bis 256 MiB werden als JPEG gespeichert)'=>'Photo or evidence (PNG, JPEG, PDF · max. 2 MB; HEIC/RAW up to 256 MiB is saved as JPEG)',
  'Die Datei bleibt im Browser aus Datenschutzgründen nicht vorausgewählt. Zuerst den gespeicherten Stand prüfen; falls noch nicht gespeichert, dieselbe ursprüngliche Datei erneut auswählen.'=>'For privacy, the browser does not preselect the file again. Check the saved state first; if it has not been saved, select the same original file again.',
  'Datei hinzufügen'=>'Add file',
  'Gespeicherten Stand dieses Dateiauftrags prüfen'=>'Check saved state of this file request',
  'Datei konnte nicht hochgeladen werden (maximal 2 MB).'=>'The file could not be uploaded (maximum 2 MB).',
  'Bitte PNG, JPEG oder PDF auswählen.'=>'Choose PNG, JPEG or PDF.',
  'Datei überschreitet die erlaubte Größe oder ist leer.'=>'The file exceeds the permitted size or is empty.',
  'Datei überschreitet die erlaubte Größe.'=>'The file exceeds the permitted size.',
  'Vorgangskennung mit anderen Eingaben bereits verwendet.'=>'This request identifier has already been used with different details.',
  'Inventarvorgang gespeichert.'=>'Inventory operation saved.',
];
 public static function key(string $s):string{return 'inventory_file_'.substr(hash('sha256',$s),0,24);}
 public static function messages(string $locale):array{$en=explode('-',$locale,2)[0]==='en';$out=[];foreach(self::ENGLISH as$s=>$v)$out[self::key($s)]=$en?$v:$s;return$out;}
 public static function text(string $s,array $messages,array $values=[]):string{$t=$messages[self::key($s)]??$s;$replace=[];foreach($values as$k=>$v)$replace['{'.$k.'}']=(string)$v;return strtr($t,$replace);}
}
