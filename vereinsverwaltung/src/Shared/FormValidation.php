<?php declare(strict_types=1);
namespace Kulturhus\Verein;
final class FormValidation
{
 /** Only explicit field-validation messages; permissions, conflicts and operational failures never imply missing inputs. */
 public static function fieldError(string $error):bool
 {
  return in_array($error,['Bitte alle Pflichtfelder ausfüllen.','Pflichtfeld oder zulässige Textlänge prüfen.','Bitte Art, Name, E-Mail und Anschrift prüfen.','Bitte Titel, Kategorie und Termindaten prüfen.'],true)
   ||preg_match('/^Bitte (description|counterparty|amount|document_date|due_date|name) ausfüllen und die Feldlänge beachten\.$/D',$error)===1;
 }
}
