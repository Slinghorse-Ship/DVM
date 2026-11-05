<?php declare(strict_types=1);
namespace Kulturhus\Native\Language;
/** Only fixed selection/recovery controls; invoice titles and original values stay literal. */
final class InvoiceInboxSourceMessages
{
    public const ENGLISH=[
        'Die vollständige ursprüngliche Rechnung und Belegversion prüfen.'=>'Review the complete original receipt identity and version.',
        'Die ursprüngliche Vorgangskennung der Zahlungsaktion prüfen.'=>'Review the original payment operation key.',
        'Den ursprünglichen Kontoumsatz mit Version und Betrag vollständig prüfen.'=>'Review the complete original transaction, version and amount.',
        'Den ursprünglichen Meldungsstand und vollständigen Zahlungstext prüfen.'=>'Review the original report version and complete payment text.',
        'Der ursprüngliche Beleg passt nicht zu dieser Originalrechnung.'=>'The original receipt does not belong to this original invoice.',
        'Datum und kurze Notiz zur Zahlungsmeldung prüfen.'=>'Check the date and short payment report note.',
        'Tatsächliches Zahlungsdatum bis heute eingeben.'=>'Enter the actual payment date, up to today.',
        'Dieses Formular wurde bereits mit anderen Angaben gespeichert.'=>'This form was already saved with different details.',
        'Rechnung oder Zahlungsmeldung wurde geändert. Bitte neu laden.'=>'The invoice or payment report has changed. Read the current original separately.',
        'Nur offene, noch nicht vollständig zugeordnete Rechnungen als bezahlt melden.'=>'Only report open invoices with an amount still to assign as paid.',
        'Es gibt keine aktive Zahlungsmeldung.'=>'There is no active payment report.',
        'Ursprünglicher Beleg'=>'Original receipt',
        'Ursprüngliche Belegversion'=>'Original receipt version',
        'Ursprünglicher Meldungsstand'=>'Original report version',
        'Ursprüngliche Vorgangskennung'=>'Original operation key',
        'Gemeldetes Zahlungsdatum'=>'Reported payment date',
        'Ursprüngliche Zahlungsnotiz'=>'Original payment note',
        'Ursprünglicher Kontoumsatz'=>'Original transaction',
        'Ursprüngliche Umsatzversion'=>'Original transaction version',
        'Ursprünglicher Zuordnungsbetrag'=>'Original assignment amount',
        'Ausgabenansicht'=>'Expense mode','Ausgabensuche'=>'Expense search','Ausgabenseite'=>'Expense page','Ursprüngliche Ausgabe und Version'=>'Original expense and version',
        'Archivieren / Wiederherstellen'=>'Archive / restore','Grund'=>'Reason',
        'Die ursprünglich angeforderte Rechnungsseite ist nicht mehr verfügbar. Die Liste ausdrücklich neu öffnen.'=>'The originally requested invoice page is no longer available. Explicitly open the list again.',
        'Rechnungseingang prüfen'=>'Review incoming invoice selection',
        'Die ursprüngliche Auswahl ist nicht verfügbar'=>'The original selection is unavailable',
        'Es wurde keine andere Rechnung und keine andere Auswahl geöffnet.'=>'No other invoice or selection has been opened.',
        'Übermittelte Angaben'=>'Submitted details',
        'Angabe im Link'=>'Detail in the link','Angabe im Formular'=>'Detail in the form',
        'Ursprüngliche Rechnung'=>'Original invoice','Ursprüngliche Dateiversion'=>'Original file version',
        'Ausgabe und Version'=>'Expense and version','Rechnungserfassung'=>'Invoice entry',
        'Stand'=>'Status','Rechnungssuche'=>'Invoice search','Belegjahr'=>'Invoice year','Rechnungsseite'=>'Invoice page',
        'Mehrteilige Angabe'=>'Multipart detail',
        'Ursprünglichen Rechnungslink erneut öffnen'=>'Open the original invoice link again',
        'Übermittelte Rechnung gesondert prüfen'=>'Review the submitted invoice separately',
        'Rechnungsliste mit dieser Auswahl öffnen'=>'Open the invoice list with this selection',
        'Rechnungsliste ohne Filter ausdrücklich öffnen'=>'Explicitly open the invoice list without filters',
        'Keine Rechnungen in diesem Jahr'=>'No invoices in this year',
        'Diesen Vorgang im ursprünglichen Rechnungseingang öffnen.'=>'Open this operation in its original incoming invoice area.',
        'Die vollständige ursprüngliche Rechnungserfassung prüfen.'=>'Check the complete original invoice entry.',
        'Die vollständige ursprüngliche Rechnungskennung prüfen.'=>'Check the complete original invoice identifier.',
        'Link und Formular gehören nicht zu derselben ursprünglichen Rechnung.'=>'The link and form do not belong to the same original invoice.',
        'Die vollständige ursprüngliche Dateiversion prüfen.'=>'Check the complete original file version.',
        'Die vollständige ursprüngliche Ausgabe mit ihrer Version auswählen.'=>'Select the complete original expense and its version.',
        'Für den Rechnungseingang ist aktueller Finanzzugriff erforderlich.'=>'Current finance access is required for incoming invoices.',
        'Rechnungssuche, Jahr, Stand und Seite vollständig prüfen.'=>'Check the complete invoice search, year, status and page.',
        'Die ursprünglich angeforderte Rechnung ist derzeit nicht verfügbar.'=>'The originally requested invoice is currently unavailable.',
        'Der ursprüngliche Rechnungseingang konnte gerade nicht geladen werden. Bitte erneut prüfen.'=>'The original incoming invoice area could not be loaded. Please review it again.',
    ];
    public static function key(string $text):string{return 'invoice_inbox_source_'.substr(hash('sha256',$text),0,24);}
    public static function messages(string $locale):array{$english=explode('-',$locale,2)[0]==='en';$out=[];foreach(self::ENGLISH as$source=>$translation)$out[self::key($source)]=$english?$translation:$source;return$out;}
    public static function text(string $source,array $messages):string{return $messages[self::key($source)]??$source;}
}
