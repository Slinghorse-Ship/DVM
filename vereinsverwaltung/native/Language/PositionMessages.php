<?php declare(strict_types=1);
namespace Kulturhus\Native\Language;
final class PositionMessages
{
    public const ENGLISH=[
        'Zusätzliche Buchhaltungsangaben'=>'Additional accounting details','Nur bei Bedarf'=>'Only if needed','Positionen & Kontierung'=>'Positions & account coding','Sphäre'=>'Sphere','Brutto'=>'Gross','Noch offen'=>'Still open','Kontenplanbasis SKR42 · {year} · {group}'=>'SKR42 chart basis · {year} · {group}','Änderungsverlauf ({count})'=>'Revision history ({count})','Gespeichert'=>'Saved','Vorher'=>'Before','Nachher'=>'After','Sphäre offen'=>'Sphere open','Steuerkonto offen'=>'Account coding open','Vorsteuer:'=>'Input VAT:','Ungültig'=>'Invalid','Amtliche Spendenformulare & Stand der Bescheinigungsausgabe →'=>'Official donation forms & receipt issue status →',
        'Positionen bearbeiten'=>'Edit positions','Zurück zum Beleg'=>'Back to receipt','Änderung speichern'=>'Save change','Belegbetrag:'=>'Receipt amount:',
        'Die Summe der Positionen muss diesem Betrag entsprechen.'=>'The positions must add up to this amount.','Änderungsgrund'=>'Reason for change','Die vorherige Kontierung bleibt im Änderungsverlauf erhalten.'=>'The previous account coding remains in the revision history.',
        'Positionsvorschau berechnen'=>'Calculate position preview','Vorschau: Der Belegbetrag bleibt unverändert. Erst bewusstes Speichern ändert die Positionen.'=>'Preview: the receipt amount stays unchanged. Only saving deliberately changes the positions.',
        'Aktuellen gespeicherten Stand vergleichen'=>'Compare current saved state','Aktuell gespeicherter Stand'=>'Current saved state','Gespeicherte Version'=>'Saved version','Letzter gespeicherter Änderungsgrund'=>'Latest saved reason for change','Mit diesem Stand weiterbearbeiten'=>'Continue editing from this state',
        'Vergleiche die gespeicherten Positionen mit deinen Eingaben. Lesen speichert keine Änderung. Weiterbearbeiten übernimmt nur den verglichenen Versionsstand; deine Eingaben bleiben erhalten.'=>'Compare the saved positions with your entries. Reading saves no change. Continuing adopts only the compared version; your entries stay intact.',
        'Beleg inzwischen geändert. Bitte neu laden.'=>'The receipt has changed. Compare the current saved state before continuing.',
        'Bitte einen Änderungsgrund angeben (höchstens 500 Zeichen).'=>'Enter a reason for change using at most 500 characters.',
        'Beleg wird gerade bearbeitet. Bitte erneut prüfen.'=>'The receipt is being edited. Check again.',
        'Ursprünglichen Beleg und Versionsstand vollständig prüfen.'=>'Check the complete original receipt and version.',
        'Positionsformular gehört zu einem anderen Beleg.'=>'This position form belongs to another receipt.',
        'Genau eine angebotene Positionsansicht wählen.'=>'Choose exactly one offered position view.',
        'Der verglichene Belegstand wurde inzwischen geändert. Zuerst erneut vergleichen.'=>'The compared receipt state has changed. Compare it again first.',
        'Aktuellen gespeicherten Stand gelesen. Deine Eingaben und ihr Versionsstand bleiben erhalten.'=>'Current saved state read. Your entries and their version stay intact.',
        'Weiterbearbeitung mit dem verglichenen Stand vorbereitet. Änderungen erst gesondert speichern.'=>'Editing from the compared state is prepared. Save changes separately.',
        'Positionen angezeigt. Noch keine Änderung gespeichert und keine Zahlung erfasst.'=>'Positions displayed. No change has been saved and no payment recorded.',
        'Positionen und Kontierung gespeichert. Belegbetrag und Zahlungen bleiben unverändert.'=>'Positions and account coding saved. Receipt amount and payments stay unchanged.',
        'Die Positionssumme muss dem gespeicherten Belegbetrag entsprechen.'=>'The positions must add up to the saved receipt amount.',
        'Verworfene oder gegengebuchte Belege sind unveränderlich.'=>'Discarded or reversed receipts cannot be changed.',
        'Eine ausgegebene Rechnung bleibt unveränderlich. Nur die Kontierung darf angepasst werden; für Preise, Steuern oder Artikel eine Rechnungskorrektur erstellen.'=>'An issued invoice stays unchanged. Only account coding may be adjusted; create an invoice correction for prices, taxes or items.',
        'Dieser Kassenzeitraum ist abgeschlossen. Nur spätere Buchungen sind möglich.'=>'This cash period is closed. Only later postings are possible.',
    ];
    public static function key(string$s):string{return'position_edit_'.substr(hash('sha256',$s),0,24);}
    public static function messages(string$locale):array{$en=explode('-',$locale,2)[0]==='en';$out=[];foreach(self::ENGLISH as$s=>$v)$out[self::key($s)]=$en?$v:$s;return$out;}
    public static function text(string$s,array$messages,array$values=[]):string{$text=$messages[self::key($s)]??ManualFinanceMessages::text($s,$messages);return strtr($text,array_combine(array_map(static fn($k)=>'{'.$k.'}',array_keys($values)),array_values($values)));}
}
