<?php declare(strict_types=1);
namespace Kulturhus\Native\Language;
/** Owned numbering labels; prefixes, suffixes and original number strings remain literal. */
final class NumberingMessages
{
    public const ENGLISH=[
        'Nummernkreise'=>'Numbering','Nummernkreis'=>'Numbering','Beiträge & Nummern'=>'Fees & numbering','Beitragssätze'=>'Fee rates','Leistungen'=>'Services','Bankverbindung'=>'Bank details','SEPA für Mitglieder'=>'Member SEPA mandates',
        'Rechnungen & Einnahmen'=>'Invoices & income','Ausgaben'=>'Expenses','Mitgliedsnummern'=>'Membership numbers',
        'Präfix'=>'Prefix','Suffix'=>'Suffix','Datum'=>'Date','Kein Datum'=>'No date','Jahr'=>'Year','Jahr und Monat'=>'Year and month',
        'Stellen der laufenden Nummer'=>'Running number digits','Nächste laufende Nummer'=>'Next running number','Aktuell nächster Wert'=>'Current next value',
        'Beispieldatum'=>'Example date','Beispielnummer'=>'Example number','Bereits vergeben'=>'Already assigned','Noch nicht vergeben'=>'Not assigned yet','Nummernkreis ausgeschöpft.'=>'Number range exhausted.',
        'Beispiele für dieses Datum; es wird keine Nummer reserviert oder vergeben.'=>'Examples for this date; no number is reserved or assigned.',
        'Bestehende Nummern und Dokumente bleiben unverändert. Nur künftige tatsächliche Fachvorgänge vergeben eine Nummer.'=>'Existing numbers and documents remain unchanged. Only future actual business operations assign a number.',
        'Die Regeln wurden noch nicht ausdrücklich gespeichert. Bisherige Finanzbelege können ihre bestehende KH-Nummerierung verwenden.'=>'These rules have not yet been explicitly saved. Existing finance operations may use their established KH numbering.',
        'Lesender Finanzzugriff. Nummernregeln können hier nicht geändert werden.'=>'Read-only finance access. Numbering rules cannot be changed here.',
        'Nummernregeln prüfen'=>'Review numbering rules','Aktuelle Zähler bewusst prüfen'=>'Deliberately review current counters',
        'Die vollständigen Nummernregeln und das Beispieldatum sind geprüft.'=>'I have reviewed the complete numbering rules and example date.',
        'Geprüfte Nummernregeln bestätigen'=>'Confirm reviewed numbering rules','Ursprünglichen Nummernausgang lesend prüfen'=>'Read original numbering outcome',
        'Nummernbestätigung'=>'Original numbering confirmation','Weitere Nummernregeln bearbeiten'=>'Edit further numbering rules',
        'Eine neue Prüfung übernimmt nur die aktuelle Fassung. Gewünschte Regeln, nächste Werte und Beispieldatum bleiben erhalten.'=>'A new review adopts only the current version. Desired rules, next values and example date are retained.',
        'Frühere Nummernvorschläge'=>'Earlier numbering proposals','Entwürfe'=>'Your open numbering drafts',
        'Prüfung noch nicht bestätigt. Zuerst den ursprünglichen Ausgang lesen.'=>'Outcome is unconfirmed. Read the original outcome first.',
        'Aktuelle Finanzberechtigung für Nummernkreise erforderlich.'=>'Current finance permission for numbering is required.',
        'Nummernkreise im vorhandenen Einstellungsbereich öffnen.'=>'Open numbering in the existing settings area.',
        'Dieses frühere Nummernformular wurde nicht gespeichert. Die erhaltenen Angaben zuerst prüfen und danach gesondert bestätigen.'=>'This earlier numbering form was not saved. Review the retained values first, then confirm them separately.',
        'Ursprüngliche Nummernbestätigung auswählen.'=>'Select an original numbering confirmation.',
        'Gültige Nummernaktion auswählen.'=>'Select a valid numbering action.','Zuerst den ursprünglichen Nummernausgang prüfen.'=>'Check the original numbering outcome first.',
        'Die vollständig geprüften Nummernregeln ausdrücklich bestätigen.'=>'Explicitly confirm the fully reviewed numbering rules.',
        'Vollständige ursprüngliche Nummernfelder und Beispieldatum prüfen.'=>'Check all original numbering fields and the example date.',
        'Nummernfeld enthält ungültige Daten.'=>'A numbering field contains invalid data.','Gültiges Beispieldatum auswählen.'=>'Choose a valid example date.',
        'Nummernkreis unvollständig.'=>'Incomplete numbering rule.','Präfix/Suffix enthält unzulässige Zeichen.'=>'Prefix or suffix contains invalid characters.',
        'Zahl zwischen {min} und {max} erforderlich.'=>'Enter a number between {min} and {max}.',
        'Die ursprüngliche Nummernfassung ist nicht mehr aktuell.'=>'The original numbering version is no longer current.',
        'Der gewünschte nächste Wert liegt unter dem aktuellen Zähler.'=>'The desired next value is below the current counter.',
        'Der nächste Nummernwert darf nicht zurückgesetzt werden.'=>'The next running number cannot be reduced.',
        'Eine angezeigte Beispielnummer ist bereits vergeben. Präfix, Suffix oder nächsten Wert prüfen.'=>'A displayed example number is already assigned. Check the prefix, suffix or next value.',
        'Rechnungs- und Ausgabenbeispiel sind gleich. Unterschiedliche Nummernregeln wählen.'=>'Invoice and expense examples are identical. Choose different numbering rules.',
        'Nummernkreis wird gerade bearbeitet. Bitte erneut prüfen.'=>'Numbering is being edited. Please review again.',
        'Nummernvergabe benötigt einen eigenen laufenden Fachvorgang.'=>'Number assignment requires its own active business operation.',
        'Nummernprüfung benötigt einen aktuellen Leseschritt.'=>'Numbering review requires a current read.',
        'Nummernkreise konnten nicht geladen werden.'=>'Numbering could not be loaded.',
        'Nummernausgang nicht bestätigt. Die ursprüngliche Bestätigung über ihre Kennung prüfen.'=>'Numbering outcome is unconfirmed. Read the original confirmation by its key.',
        'Beispiele nach Korrektur des Datums'=>'Examples after correcting the date',
    ];
    public static function key(string $source):string{return 'numbering_'.substr(hash('sha256',$source),0,24);}
    public static function messages(string $locale):array{$out=[];$en=explode('-',$locale,2)[0]==='en';foreach(self::ENGLISH as$source=>$translation)$out[self::key($source)]=$en?$translation:$source;return$out;}
    public static function text(string $source,array $messages):string
    {
        if(isset($messages[self::key($source)]))return$messages[self::key($source)];
        if(preg_match('/\AZahl zwischen ([0-9]+) und ([0-9]+) erforderlich\.\z/D',$source,$parts))return strtr(self::text('Zahl zwischen {min} und {max} erforderlich.',$messages),['{min}'=>$parts[1],'{max}'=>$parts[2]]);
        return GeneralSettingsMessages::text($source,$messages);
    }
}
