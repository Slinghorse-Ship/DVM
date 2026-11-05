<?php declare(strict_types=1);
namespace Kulturhus\Native\Language;
/** Fixed payment-report controls; all human original notes and names remain literal. */
final class InvoicePaymentMessages
{
    public const FIELDS=['doc'=>'Originalrechnung','entry_id'=>'Ursprünglicher Beleg','entry_version'=>'Ursprüngliche Belegversion','report_version'=>'Ursprünglicher Meldungsstand','request_key'=>'Ursprüngliche Vorgangskennung','paid_on'=>'Gemeldetes Zahlungsdatum','note'=>'Ursprüngliche Zahlungsnotiz','reason'=>'Ursprünglicher Rücknahmegrund','transaction_id'=>'Ursprünglicher Kontoumsatz','transaction_version'=>'Ursprüngliche Umsatzversion','amount'=>'Ursprünglicher Zuordnungsbetrag'];
    public const ENGLISH=[
        'Zahlungsmeldung zurückgenommen'=>'Payment report withdrawn',
        'Die frühere Zahlungsmeldung gehört zu einem geschlossenen Beleg. Sie bestätigt keine aktuelle Zahlung.'=>'The earlier payment report belongs to a closed receipt. It does not confirm a current payment.',
        'Keine vorläufige Zahlungsmeldung gespeichert.'=>'No provisional payment report saved.',
        'Früher gespeicherten Meldungsausgang gelesen. Maßgeblich ist der aktuelle Zahlungsstand bei der Rechnung.'=>'Earlier saved report outcome read. The current payment status on the invoice is authoritative.',
        'Zahlung und Kontozuordnung'=>'Payment and transaction assignment',
        'Rechnungsbetrag'=>'Invoice amount',
        'Kontoumsätzen zugeordnet'=>'Assigned to transactions',
        'Noch zuzuordnen'=>'Still to assign',
        'Als bezahlt gemeldet – Kontozuordnung offen'=>'Reported as paid – transaction assignment pending',
        'Zahlungsdatum'=>'Payment date',
        'Die gemeldete Zahlung ist vollständig Kontoumsätzen zugeordnet.'=>'The reported payment is fully assigned to transactions.',
        'Zahlungsangaben prüfen'=>'Review original payment details',
        'Die ursprüngliche Auswahl bleibt erhalten. Es wurde kein aktuellerer Stand und kein anderer Vorgang zur Bestätigung eingesetzt.'=>'The original selection is retained. No newer version or different operation has been substituted for confirmation.',
        'Früher gespeicherte Meldung dieses Vorgangs'=>'Earlier saved report for this operation',
        'Als bezahlt gemeldet'=>'Reported as paid',
        'Meldung zurückgenommen'=>'Report withdrawn',
        'Dieser frühere Ausgang bestätigt keine neuen oder geänderten Angaben.'=>'This earlier outcome does not confirm new or changed details.',
        'Rechnung, Meldungsstand oder Bearbeitungsrecht passen nicht mehr zu dieser ursprünglichen Aktion. Den aktuellen Stand derselben Rechnung gesondert lesen.'=>'The invoice, report version or editing permission no longer matches this original action. Read the current state of the same invoice separately.',
        'Dieselbe Rechnung und Zahlungsmeldung im aktuellen Stand lesen'=>'Read the current state of the same invoice and payment report',
        'Ursprünglichen Kontoumsatz am Beleg lesen'=>'Read the original transaction on the receipt',
        'Originalprüfung und Kontozuordnung am Beleg öffnen'=>'Open original review and transaction assignment on the receipt',
        'Zahlungsstand am Beleg ansehen'=>'View payment status on the receipt',
        'Die vollständige Umsatzsuche und Seitenwahl stehen am zugeordneten Beleg. Die aktuellen Prüfungen und Zahlungsrechte gelten dort erneut. Zuordnen erzeugt keine neue Zahlung und überweist kein Geld.'=>'The assigned receipt provides full transaction search and pagination. Current review and payment permissions are checked again there. Assigning creates no new payment and transfers no money.',
        'Tatsächliche Zahlung manuell erfassen'=>'Record an actual payment manually',
        'Für bereits erfolgte Zahlungen mit Nachweis, die noch nicht in der Kontoliste stehen. Betrag, Konto und tatsächliches Zahlungsdatum werden direkt zur Rechnung gespeichert.'=>'For payments already made with evidence that are missing from the transaction list. Amount, account and actual payment date are saved directly against the invoice.',
        'Zahlung nur vorläufig melden'=>'Report a payment provisionally',
        'Diese Meldung bucht keinen Kontoumsatz und bestätigt keinen Zahlungsnachweis. Sie bleibt offen, bis eine tatsächliche Zahlung zugeordnet ist.'=>'This report records no transaction and confirms no payment evidence. It remains pending until an actual payment is assigned.',
        'Notiz zur Zahlung'=>'Payment note',
        'Bis zu 1000 Zeichen. Den tatsächlich gemeldeten Vorgang beschreiben; die Meldung ist kein Zahlungsnachweis.'=>'Up to 1,000 characters. Describe the actual reported operation; the report is not payment evidence.',
        'Zahlung nur melden – ohne Kontobuchung'=>'Report payment only – without a transaction',
        'Verlauf der Zahlungsmeldungen'=>'Payment report history',
        'Grund für die Rücknahme'=>'Reason for withdrawal',
        'Bis zu 1000 Zeichen. Nur die Meldung wird zurückgenommen; Kontoumsätze und Zahlungen bleiben unverändert.'=>'Up to 1,000 characters. Only the report is withdrawn; transactions and payments remain unchanged.',
        'Nur die Zahlungsmeldung zurücknehmen'=>'Withdraw only the payment report',
        'Originalrechnung'=>'Original invoice',
        'Ursprünglicher Beleg'=>'Original receipt',
        'Ursprüngliche Belegversion'=>'Original receipt version',
        'Ursprünglicher Meldungsstand'=>'Original report version',
        'Ursprüngliche Vorgangskennung'=>'Original operation key',
        'Gemeldetes Zahlungsdatum'=>'Reported payment date',
        'Ursprüngliche Zahlungsnotiz'=>'Original payment note',
        'Ursprünglicher Rücknahmegrund'=>'Original withdrawal reason',
        'Ursprünglicher Kontoumsatz'=>'Original transaction',
        'Ursprüngliche Umsatzversion'=>'Original transaction version',
        'Ursprünglicher Zuordnungsbetrag'=>'Original assignment amount',
        'Als bezahlt gemeldet. Die Zuordnung zur tatsächlichen Kontobuchung ist noch offen.'=>'Reported as paid. Assignment to an actual transaction is still pending.',
        'Zahlungsmeldung zurückgenommen. Der Verlauf bleibt erhalten.'=>'Payment report withdrawn. The history is retained.',
        'Kontobuchung zugeordnet. Es wurde keine neue Zahlung angelegt.'=>'Transaction assigned. No new payment has been created.',
    ];
    public static function key(string $source):string{return 'invoice_payment_'.substr(hash('sha256',$source),0,24);}
    public static function messages(string $locale):array{$english=explode('-',$locale,2)[0]==='en';$out=[];foreach(self::ENGLISH as$source=>$translation)$out[self::key($source)]=$english?$translation:$source;return$out;}
    public static function text(string $source,array $messages,array $values=[]):string{$text=$messages[self::key($source)]??$source;foreach($values as$key=>$value)$text=str_replace('{'.$key.'}',(string)$value,$text);return$text;}
}
