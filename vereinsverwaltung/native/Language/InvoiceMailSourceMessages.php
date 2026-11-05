<?php declare(strict_types=1);
namespace Kulturhus\Native\Language;
/** Fixed correspondence controls only. Senders, subjects, body and original requests stay literal. */
final class InvoiceMailSourceMessages
{
    public const ENGLISH=[
        'E-Mails ohne Beleg'=>'Emails without a receipt','noch zu lesen'=>'to read','abgelegt'=>'filed','Auswahl prüfen'=>'Review selection',
        'Hier stehen Nachrichten ohne übernommene Rechnungsdatei. Der Text kann wichtige Hinweise enthalten. Die E-Mail allein wird nicht automatisch als Rechnung, Ausgabe oder Zahlung behandelt.'=>'These messages have no imported invoice file. Their text may contain useful information. An email alone is not automatically treated as an invoice, expense or payment.',
        'Noch zu lesen'=>'To read','Abgelegt'=>'Filed','Ohne Betreff'=>'No subject','Von'=>'From','An'=>'To','Datum laut E-Mail'=>'Date stated in the email',
        'Nachrichteninhalt des Absenders, nicht automatisch geprüft.'=>'Message content supplied by the sender; not automatically verified.',
        'HTML wurde in einfachen Text umgewandelt.'=>'HTML has been converted to plain text.',
        'Begrenzte Vorschau; vollständiger Inhalt in der Originaldatei.'=>'Limited preview; the complete content is in the original file.',
        'Original-E-Mail herunterladen (.eml)'=>'Download original email (.eml)','Grund'=>'Reason','Bis zu 1000 Zeichen.'=>'Up to 1,000 characters.',
        'E-Mail ablegen'=>'File email','E-Mail wiederherstellen'=>'Restore email',
        'Ablegen entfernt nur diese Nachricht aus „Noch zu lesen“. Das Original bleibt erhalten und kann wiederhergestellt werden. Im Mailpostfach wird nichts gelöscht.'=>'Filing removes only this message from “To read”. The original is retained and can be restored. Nothing is deleted from the mailbox.',
        'E-Mails ohne Beleg suchen'=>'Search emails without a receipt','E-Mail suchen'=>'Search emails','Betreff oder Absender'=>'Subject or sender','Suchen'=>'Search',
        'Keine passenden E-Mails ohne Beleg in dieser Ansicht.'=>'No matching emails without a receipt in this view.',
        'E-Mail lesen'=>'Read email','E-Mail-Seiten'=>'Email pages','Zurück'=>'Previous','Weiter'=>'Next','Seite {page} von {pages}'=>'Page {page} of {pages}',
        'Die ursprüngliche Nachrichtenauswahl ist nicht verfügbar'=>'The original message selection is unavailable',
        'Es wurde keine andere Nachricht oder Nachrichtenseite geöffnet.'=>'No other message or message page has been opened.',
        'Übermittelte Angaben'=>'Submitted details','Angabe im Link'=>'Detail in the link','Angabe im Formular'=>'Detail in the form','Mehrteilige Angabe'=>'Multipart detail',
        'Ursprüngliche Nachricht'=>'Original message','Nachrichtenstand'=>'Message status','Nachrichtensuche'=>'Message search','Nachrichtenseite'=>'Message page','Ursprüngliche Version'=>'Original version','Ablegen / Wiederherstellen'=>'File / restore',
        'Ursprüngliche Nachrichtenauswahl erneut prüfen'=>'Review the original message selection again',
        'Übermittelte Nachricht gesondert prüfen'=>'Review the submitted message separately',
        'Nachrichtenliste mit dieser Suche öffnen'=>'Open the message list with this search',
        'Noch zu lesen ohne diese Filter öffnen'=>'Open To read without these filters',
        'Die Nachricht im ursprünglichen Bereich E-Mails ohne Beleg öffnen.'=>'Open the message in its original Emails without a receipt area.',
        'Die vollständige ursprüngliche Nachrichtenkennung prüfen.'=>'Check the complete original message identifier.',
        'Link und Formular gehören nicht zu derselben ursprünglichen Nachricht.'=>'The link and form do not belong to the same original message.',
        'Die vollständige ursprüngliche Nachrichtenversion prüfen.'=>'Check the complete original message version.',
        'Ablegen oder Wiederherstellen ausdrücklich auswählen.'=>'Explicitly choose filing or restoring.',
        'Nachrichtensuche, Stand und Seite vollständig prüfen.'=>'Check the complete message search, status and page.',
        'Die ursprünglich angeforderte Nachrichtenseite ist nicht mehr verfügbar. Die Liste ausdrücklich neu öffnen.'=>'The originally requested message page is no longer available. Explicitly open the list again.',
        'Für diese Nachrichten ist aktueller Finanzzugriff erforderlich.'=>'Current finance access is required for these messages.',
        'Zum Ablegen oder Wiederherstellen ist aktuelles Finanz-Bearbeitungsrecht erforderlich.'=>'Current finance editing permission is required to file or restore messages.',
        'Die ursprüngliche Nachricht ist derzeit nicht verfügbar oder kann nicht vollständig geprüft werden.'=>'The original message is currently unavailable or cannot be fully checked.',
        'Die ursprünglichen Nachrichten konnten gerade nicht geladen werden. Bitte erneut prüfen.'=>'The original messages could not be loaded. Please review them again.',
        'Bitte einen kurzen Grund angeben.'=>'Enter a brief reason of at most 1,000 characters.',
        'Die E-Mail wurde inzwischen geändert. Bitte neu öffnen.'=>'The email has changed. Open it again.',
        'E-Mail abgelegt. Das Original bleibt erhalten.'=>'Email filed. The original is retained.',
        'E-Mail wiederhergestellt.'=>'Email restored.',
    ];
    public static function key(string $source):string{return 'invoice_mail_source_'.substr(hash('sha256',$source),0,24);}
    public static function messages(string $locale):array{$english=explode('-',$locale,2)[0]==='en';$out=[];foreach(self::ENGLISH as$source=>$translation)$out[self::key($source)]=$english?$translation:$source;return$out;}
    public static function text(string $source,array $messages,array $values=[]):string{$text=$messages[self::key($source)]??$source;foreach($values as$key=>$value)$text=str_replace('{'.$key.'}',(string)$value,$text);return$text;}
}
