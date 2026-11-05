<?php declare(strict_types=1);
namespace Kulturhus\Native\Language;
/** Fixed invoice decision controls. Original people, reasons and evidence stay literal. */
final class InvoiceReviewMessages
{
    public const ENGLISH=[
        'Finanzberechtigung erforderlich.'=>'Finance permission is required.','Keine ausreichende Finanzberechtigung.'=>'Insufficient finance permission.','Beleg nicht verfügbar.'=>'Receipt unavailable.','Rechnungsfreigabe oder Original wird gerade bearbeitet. Erneut prüfen.'=>'The invoice approval or original is being edited. Review it again.',
        'Prüfen'=>'Review','Noch nicht freigegeben'=>'Not yet approved','Freigegeben'=>'Approved','Erneut prüfen'=>'Review again','Zur Klärung zurückgestellt'=>'Deferred for clarification','Freigabe zurückgenommen'=>'Approval withdrawn','Bereits bezahlt'=>'Already paid','Abgeschlossen – keine Zahlungsfreigabe'=>'Closed – no payment approval',
        'Prüfschritt fortsetzen'=>'Resume the original review step',
        'Die Angaben dieses Formulars bleiben erhalten. Den gespeicherten früheren Ausgang und den aktuellen Rechnungsstand getrennt prüfen. Lesen und Neuprüfen speichern keine Entscheidung und lösen keine Zahlung aus.'=>'The details in this form are retained. Review the earlier saved outcome and the current invoice state separately. Reading and reviewing again save no decision and start no payment.',
        'Früher gespeicherter Ausgang'=>'Earlier saved outcome',
        'Diese frühere Entscheidung bestätigt nicht automatisch neue oder geänderte Angaben dieses Formulars.'=>'This earlier decision does not automatically confirm new or changed details in this form.',
        'Ursprünglicher Prüfschritt:'=>'Original review step:','Rückfrage zurückstellen'=>'Defer with a question','Angaben aus diesem Formular'=>'Details from this form',
        'Gespeicherten Prüfschritt lesen'=>'Read saved review outcome','Neuen Prüfschritt mit aktuellen Originalen beginnen'=>'Begin a new review step with current originals','Aktuelle Originale neu prüfen'=>'Review current originals again',
        'Empfänger'=>'Recipient','Nicht hinterlegt – am Original prüfen'=>'Not recorded – check against the original','Gesamtbetrag'=>'Total amount','Teilzahlung: Rest prüfen.'=>'Partial payment: review the remainder.',
        'Du kannst den Originalbeleg und diese Angaben hier prüfen. Freigeben darf der Kassenwart oder eine dafür berechtigte Person.'=>'You can review the original receipt and these details here. The treasurer or another authorised person may approve them.',
        'Prüfung bestätigen'=>'Confirm review','Originalrechnung geprüft'=>'Original invoice reviewed','Empfänger, IBAN und Betrag stimmen'=>'Recipient, IBAN and amount are correct','private Erstattung eingeschlossen'=>'including private reimbursement','Rechnung freigeben'=>'Approve invoice','Angabe stimmt nicht'=>'A detail is incorrect','Was muss geklärt werden?'=>'What needs clarification?','Betrag, Empfänger oder IBAN'=>'Amount, recipient or IBAN','Zurückstellen'=>'Defer','Zahlung unten.'=>'Payment options below.','Andere Zahlungsart'=>'Another payment method','Umsatz zuordnen'=>'Allocate a movement','Zahlung manuell erfassen'=>'Record an actual payment manually','Freigabe zurücknehmen'=>'Withdraw approval','Grund'=>'Reason','Freigabe mit Grund zurücknehmen'=>'Withdraw approval with a reason',
        'Freigabeverlauf · {count} Entscheidungen'=>'Approval history · {count} decisions',
        'Vorherige Entscheidungen bleiben erhalten. Der Export „Belegpaket“ enthält die Prüfstände und zugehörigen verfügbaren Dateifassungen.'=>'Earlier decisions are retained. The receipt package export includes the reviewed snapshots and their available file revisions.',
        'Zurückgenommen'=>'Withdrawn','Uhr'=>'Berlin time','Geprüften Stand anzeigen'=>'Show reviewed snapshot','Entscheidung {number}'=>'Decision {number}','Prüfsumme'=>'Checksum','{count} Dateifassungen.'=>'{count} file revisions.','Bereits bezahlt.'=>'Already paid.',
        'Einen angebotenen Prüfschritt ausdrücklich wählen.'=>'Explicitly choose an offered review step.',
        'Das ursprüngliche Rechnungsformular vollständig öffnen.'=>'Open the complete original invoice form.',
        'Die ursprünglichen Prüfungsangaben vollständig beibehalten.'=>'Retain the complete original review details.',
        'Früher gespeicherten Prüfschritt gelesen. Die Angaben dieses Formulars wurden nicht erneut gespeichert.'=>'Earlier saved review outcome read. The details in this form were not saved again.',
        'Für dieses ursprüngliche Formular wurde noch kein gespeicherter Ausgang gelesen. Die Angaben bleiben erhalten.'=>'No saved outcome has yet been read for this original form. Its details are retained.',
        'Für einen neuen Prüfschritt ist eine aktuelle bestätigte Zahlungsberechtigung erforderlich.'=>'A new review step requires current confirmed payment authority.',
        'Dieser Prüfschritt ist beim aktuellen Rechnungsstand nicht verfügbar.'=>'This review step is unavailable for the current invoice state.',
        'Zuerst den früher gespeicherten Ausgang dieses Formulars lesen.'=>'First read the earlier saved outcome of this form.',
        'Für diese ursprüngliche Kennung ist bereits eine frühere Entscheidung gespeichert. Den früheren Ausgang vergleichen und einen neuen Prüfschritt gesondert beginnen.'=>'An earlier decision is already saved for this original key. Compare that outcome and separately begin a new review step.',
        'Aktuellen Rechnungsstand gelesen. Originale und Angaben vergleichen und den Prüfschritt anschließend gesondert bestätigen.'=>'Current invoice state read. Compare the originals and details, then confirm the review step separately.',
        'Vereinszugang geändert. Das ursprüngliche Formular erneut prüfen.'=>'Club access changed. Review the original form again.',
        'Rechnung oder Prüfentscheidung wird gerade bearbeitet. Erneut lesen.'=>'The invoice or review decision is being edited. Read it again.',
        'Rechnung, Dateien oder Freigabestand wurden geändert. Neu öffnen und erneut prüfen.'=>'The invoice, files or approval state changed. Open and review them again.',
        'Diese Vorgangskennung wurde für andere Angaben verwendet.'=>'This operation key was used for different details.',
        'Bitte kurz beschreiben, was noch geklärt werden muss (maximal 1000 Zeichen).'=>'Briefly describe what still needs clarification (at most 1000 characters).',
        'Grund für die Rücknahme angeben (maximal 1000 Zeichen).'=>'Enter the reason for withdrawal (at most 1000 characters).',
        'Rechnung, Betrag, Empfänger und zugeordnete Dateien ausdrücklich prüfen.'=>'Explicitly review the invoice, amount, recipient and linked files.',
        'Nur ein bestätigtes Vorstandsamt oder der Superuser mit Finanzbearbeitungsrecht darf Rechnungen freigeben oder die Freigabe zurücknehmen.'=>'Only a confirmed board office holder or the superuser with finance write permission may approve invoices or withdraw approval.',
        'Bereits bezahlte oder abgeschlossene Ausgaben werden hier nicht zur Zahlung freigegeben.'=>'Expenses already paid or closed cannot be approved for payment here.',
        'Alle zugeordneten Rechnungsunterlagen müssen für die Prüfung lesbar sein.'=>'All linked invoice documents must be readable for review.',
        'Zuerst einen aktiven Originalbeleg als PDF, XML oder Bild zuordnen. Papierbelege bitte einscannen. Interne Notizen und erzeugte Vorschauen reichen nicht.'=>'First link an active original receipt as a PDF, XML or image. Scan paper receipts. Internal notes and generated previews are insufficient.',
        'Keine vorhandene Freigabe zum Zurücknehmen.'=>'There is no existing approval to withdraw.',
        'Formular neu öffnen.'=>'Open the form again.',
        'Rechnung geprüft und zur Zahlung freigegeben. Jetzt den Zahlungsweg auswählen; es wurde noch kein Geld überwiesen.'=>'Invoice reviewed and approved for payment. Choose the payment method now; no money has been transferred.',
        'Die Ausgabe ist bereits bezahlt. Es wurde keine neue Zahlung ausgelöst.'=>'The expense is already paid. No new payment was started.',
        'Freigabe zurückgenommen. Die bisherigen Entscheidungen bleiben dokumentiert.'=>'Approval withdrawn. Earlier decisions remain documented.',
        'Rechnung zur Klärung zurückgestellt. Sie bleibt offen, bis die Angaben geprüft und erneut freigegeben wurden.'=>'Invoice deferred for clarification. It remains open until its details are reviewed and approved again.',
        'Die frühere Prüfentscheidung bleibt dokumentiert. Der aktuelle Stand ist nicht freigegeben; bitte erneut prüfen.'=>'The earlier review decision remains documented. The current state is not approved; review it again.',
    ];
    public static function key(string$source):string{return 'invoice_review_'.substr(hash('sha256',$source),0,24);}
    public static function messages(string$locale):array{$english=explode('-',$locale,2)[0]==='en';$out=[];foreach(self::ENGLISH as$source=>$translation)$out[self::key($source)]=$english?$translation:$source;return$out;}
    public static function text(string$source,array$messages,array$values=[]):string{$text=$messages[self::key($source)]??$source;foreach($values as$key=>$value)$text=str_replace('{'.$key.'}',(string)$value,$text);return$text;}
}
