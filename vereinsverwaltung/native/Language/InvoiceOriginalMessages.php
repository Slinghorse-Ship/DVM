<?php declare(strict_types=1);
namespace Kulturhus\Native\Language;
final class InvoiceOriginalMessages
{
 public const ENGLISH=[
  'Firmenkontakt:'=>'Company contact:','Bankangaben des Firmenkontakts:'=>'Company contact bank details:','Vor einer Zahlung am Original prüfen.'=>'Compare with the original before making a payment.','Bankdaten beim Firmenkontakt ansehen'=>'View bank details at the company contact',
  'Rechnungsartikel und zugeordnete Gegenstände'=>'Original invoice items and assigned inventory',
  'Hier stehen die ursprünglich geprüften Artikelangaben. Die aktuelle Kontierung steht gesondert unter den zusätzlichen Buchhaltungsangaben.'=>'These are the originally reviewed item details. Current account coding is shown separately under additional accounting details.',
  'Artikel {ordinal}'=>'Item {ordinal}','Ursprünglicher Artikeltext nicht gesondert gespeichert. Mit dem Original vergleichen.'=>'Original item description was not retained separately. Compare with the original.',
  'Menge:'=>'Quantity:','Einzelpreis:'=>'Unit price:','nicht gesondert erfasst'=>'not recorded separately','netto'=>'net','brutto'=>'gross','auf Cent gerundet'=>'rounded to cents','Positionsbetrag netto'=>'Position amount net','Steuer'=>'Tax','Brutto'=>'Gross',
  'Bestätigte Steuer-Rundung: ursprünglich {calculated}, zugeordnet {allocated}; Gesamtsteuer laut Original {total}.'=>'Confirmed tax rounding: originally {calculated}, allocated {allocated}; total tax on the original {total}.',
  'Im Inventar:'=>'In inventory:','Gegenstand aus dieser Position anlegen'=>'Create inventory item from this position','Gegenstand – kein aktueller Zugriff'=>'Inventory item – no current access','Hinweis aus Erfassungsentwurf'=>'Original capture hint','Zugeordnete Gegenstände'=>'Assigned inventory items','Position {ordinal}'=>'Position {ordinal}',
  'Veranstaltungsausgabe'=>'Event expense','Künstler / Band'=>'Artist / band','GEMA / Musikrechte'=>'GEMA / music rights','Original im Terminordner'=>'Original in the event folder',
 ];
 public static function key(string$s):string{return'invoice_original_'.substr(hash('sha256',$s),0,24);}
 public static function messages(string$locale):array{$en=explode('-',$locale,2)[0]==='en';$out=[];foreach(self::ENGLISH as$s=>$v)$out[self::key($s)]=$en?$v:$s;return$out;}
 public static function text(string$s,array$messages,array$values=[]):string{return strtr($messages[self::key($s)]??$s,array_combine(array_map(static fn($k)=>'{'.$k.'}',array_keys($values)),array_values($values)));}
}
