<?php declare(strict_types=1);
namespace Kulturhus\Native\Language;
/** Fixed read-only financial captions; original account codes, names and values stay literal. */
final class InventoryAccountingMessages
{
 public const ENGLISH=[
  'Steuerliche Zuordnung aus Rechnung oder Sachspende'=>'Tax coding from an invoice or donated goods',
  'Die Kontierung wird am Finanzbeleg gepflegt und hier angezeigt. Bestand und Gegenstandswerte erzeugen keine zweite Buchung.'=>'Tax coding is maintained in the financial record and shown here. Stock and item values do not create another accounting entry.',
  'Noch kein Finanzbeleg mit diesem Gegenstand verknüpft. Eine hochgeladene Datei allein ist noch keine gebuchte Rechnung.'=>'No financial record is linked to this item yet. An uploaded file alone is not a recorded invoice.',
  'Die folgenden Rechnungspositionen sind diesem Gegenstand zugeordnet.'=>'The following invoice positions are assigned to this item.',
  'Zuordnung zur gesamten Rechnung oder Sachspende. Ihre Positionen gelten nicht automatisch vollständig für diesen Gegenstand.'=>'Assignment to the whole invoice or donated goods record. Its positions do not automatically apply in full to this item.',
  'Für diese Quelle sind noch keine Kontierungspositionen erfasst. Die Zuordnung bleibt offen.'=>'No tax coding positions have been recorded for this source yet. Its assignment remains open.',
  'Sphäre / Kostenstelle (KOST1)'=>'Activity area / cost centre (KOST1)',
  'Steuerkonto'=>'Tax account',
  'BU-Schlüssel'=>'BU posting key',
  'Noch offen'=>'Still open',
  'Keiner'=>'None',
  'Kontierung im Finanzbeleg ansehen / bearbeiten'=>'View / edit tax coding in the financial record',
  'Kontierung im Finanzbeleg ansehen'=>'View tax coding in the financial record',
  'Anschaffung über Rechnung'=>'Purchase from an invoice',
  '{amount} insgesamt laut Original'=>'{amount} total according to the original',
  'Der Rechnungsbetrag wird in der Vermögensübersicht centgenau auf die vollständig erfassten Teile verteilt. Die Originalrechnung und die gespeicherten Einzelwerte bleiben unverändert.'=>'The asset overview allocates the invoice amount to all recorded units to the exact cent. The original invoice and stored individual values are retained.',
  'Bestand oder Einzelwerte haben sich geändert. Die ursprünglichen Zuordnungen stehen unten; eine aktuelle Verteilung wird hier nicht zugesichert. Bitte die Zuordnung prüfen.'=>'Stock or individual values have changed. The original assignments are shown below; a current allocation is not confirmed here. Please review the assignment.',
  'Entfernter Gegenstand'=>'Removed item',
  'Ursprüngliche Menge: {quantity} {unit}'=>'Original quantity: {quantity} {unit}',
  'Archiviert'=>'Archived',
  'Originalrechnung ansehen'=>'View original invoice',
 ];
 public static function key(string$s):string{return'inventory_accounting_'.substr(hash('sha256',$s),0,24);}
 public static function messages(string$locale):array{$en=explode('-',$locale,2)[0]==='en';$out=[];foreach(self::ENGLISH as$s=>$v)$out[self::key($s)]=$en?$v:$s;return$out;}
 public static function text(string$s,array$messages,array$values=[]):string{$replace=[];foreach($values as$k=>$v)$replace['{'.$k.'}']=(string)$v;return strtr($messages[self::key($s)]??$s,$replace);}
}
