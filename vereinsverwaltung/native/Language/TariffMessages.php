<?php declare(strict_types=1);
namespace Kulturhus\Native\Language;
/** Owned labels only; tariff titles, original text and placeholder codes remain literal. */
final class TariffMessages
{
    public const ENGLISH=[
        'Gespeichert.'=>'Saved.',
        'Beiträge im Mitgliedsantrag'=>'Fees in the membership application','Diese Jahresbeiträge gelten für neue Mitgliedsanträge und Forderungen. Bereits gespeicherte Beträge bleiben erhalten.'=>'These yearly fees apply to new membership applications and charges. Existing saved amounts are retained.','Einzelmitgliedschaft'=>'Individual membership','Familienmitgliedschaft'=>'Family membership','Noch nicht eingerichtet.'=>'Not configured yet.','Als Belegvorlage verwenden'=>'Use as receipt template',
        'Bestätigungen suchen'=>'Search confirmations','Verlauf'=>'Private confirmations','Frühere Fassungen sind tatsächliche gespeicherte Werte. Eine damalige Vorschau oder Annahme wird daraus nicht erfunden.'=>'Earlier versions contain actual saved values. No earlier preview or explicit approval is invented.','Ursprüngliche Fassung herunterladen'=>'Download original version','Gespeicherte Fassung'=>'Original saved version','Zeichengrenze'=>'Character limit',
        'Tarife'=>'Tariffs','Beitrags- und Spendensätze'=>'Fee and donation rates','Leistungskatalog'=>'Service catalogue',
        'Beitrag'=>'Membership fee','Spende'=>'Donation','Leistung'=>'Service','Neuer Beitrag'=>'New membership fee','Neue Spende'=>'New donation','Neue Leistung'=>'New service',
        'Zuweisungen und Preise'=>'Assignments and prices','Zur Tarifübersicht'=>'Back to tariff catalogue','Weitere Tarifangaben bearbeiten'=>'Edit further tariff details',
        'Bezeichnung'=>'Name','Beschreibung'=>'Description','Betrag / Einzelpreis brutto (EUR)'=>'Amount / gross unit price (EUR)','Zeitraum'=>'Interval','Abrechnungszeitraum'=>'Billing period',
        'MwSt.'=>'VAT','Sphäre'=>'Tax sphere','Steuerkonto'=>'Tax account','Tags (durch Komma getrennt)'=>'Tags (comma separated)','Verwendungszweck / Rechnungsposition'=>'Payment reference / invoice item',
        'Betragsart'=>'Price type','Aktiv'=>'Active','Inaktiv'=>'Inactive','Fester Betrag'=>'Fixed price','Altersstaffel'=>'Age-based tiers','Staffel nach Mitgliedsjahren'=>'Membership-duration tiers','Nach Staffel'=>'Tiered price',
        'Einmalig'=>'One-off','Monatlich'=>'Monthly','Vierteljährlich'=>'Quarterly','Halbjährlich'=>'Half-yearly','Jährlich'=>'Yearly','Vorheriger'=>'Previous','Aktuell'=>'Current','Nächster'=>'Next',
        'Alters- / Dauerstaffel'=>'Age / membership-duration tiers','Ab Jahren'=>'From years','Betrag brutto (EUR)'=>'Gross amount (EUR)','Stufe hinzufügen'=>'Add tier','Stufe entfernen'=>'Remove tier','Nach oben'=>'Move up','Nach unten'=>'Move down',
        'Bei festem Betrag werden die Staffelzeilen nicht verwendet. Eine Staffel beginnt bei 0; jede Jahresgrenze darf nur einmal vorkommen.'=>'Fixed prices do not use tier rows. Tiers start at 0; each year boundary may appear only once.',
        'Tarifangaben prüfen'=>'Review tariff details','Aktuellen Tarif bewusst prüfen'=>'Deliberately review current tariff','Geprüfte Tarifangaben bestätigen'=>'Confirm reviewed tariff details',
        'Die vollständigen Tarifangaben und ihre Verwendung sind geprüft.'=>'I have reviewed the complete tariff details and their use.',
        'Eine neue Prüfung übernimmt nur die aktuelle Fassung. Deine gewünschten Tarifangaben bleiben erhalten.'=>'A new review adopts only the current version. Your desired tariff details are retained.',
        'Gespeicherter Tarif'=>'Original tariff confirmation','Ursprünglichen Tarifausgang lesend prüfen'=>'Read original tariff outcome',
        'Frühere Tarifvorschläge'=>'Earlier tariff proposals','Entwürfe'=>'Your open tariff drafts',
        'Lesender Finanzzugriff. Tarife können hier nicht geändert werden.'=>'Read-only finance access. Tariffs cannot be changed here.',
        'Speichern startet keine Abrechnung und ändert keine vorhandenen Belege. Änderungen wirken auf spätere Preisprüfungen.'=>'Saving starts no billing and changes no existing receipts. Changes affect later price reviews.',
        'Dieser Tarif ist mit dem öffentlichen Mitgliedsantrag verbunden. Er muss aktiv, ein positiver fester Jahresbetrag für das aktuelle Jahr und ohne Umsatzsteuer bleiben.'=>'This tariff is linked to the public membership application. It must remain active, with a positive fixed price for the current year and no VAT.',
        'Vollständige Staffelwerte'=>'Complete tier values','Keine Staffelzeilen.'=>'No tier rows.','Keine passenden Tarife.'=>'No matching tariffs.',
        'Noch offen'=>'Not set','Originale Preis- und Belegwerte bleiben erhalten.'=>'Original prices and receipt values are retained.',
        'Fett'=>'Bold','Kursiv'=>'Italic','Unterstrichen'=>'Underline','Platzhalter'=>'Placeholder','Einfügen'=>'Insert',
        'Ohne JavaScript kannst du **fett**, *kursiv*, __unterstrichen__ und bekannte Platzhalter wie {{BEZEICHNUNG}} direkt eingeben.'=>'Without JavaScript you can enter **bold**, *italic*, __underlined__ and known placeholders such as {{BEZEICHNUNG}} directly.',
        'Formatierte Vorschau'=>'Formatted preview','Tarifart'=>'Tariff kind','Verknüpfte Mitgliedsbeiträge'=>'Linked membership fees',
        'Sammelposten'=>'Collective item','{sphere} · Unterteilung {code}'=>'{sphere} · Subdivision {code}',
        'Ideeller Bereich'=>'Nonprofit sphere','Vermögensverwaltung'=>'Asset management','Zweckbetrieb'=>'Tax-privileged activity','Wirtschaftlicher Geschäftsbetrieb'=>'Commercial activity',
        'Aktuelle Finanzberechtigung für Tarife erforderlich.'=>'Current finance permission for tariffs is required.',
        'Tarife im vorhandenen Einstellungsbereich öffnen.'=>'Open tariffs in the existing settings area.',
        'Dieses frühere Tarifformular wurde nicht gespeichert. Die erhaltenen Angaben zuerst prüfen und danach gesondert bestätigen.'=>'This earlier tariff form was not saved. Review the retained values first, then confirm them separately.',
        'Vollständige ursprüngliche Tarifangaben prüfen.'=>'Check the complete original tariff details.','Vollständige ursprüngliche Tariffelder prüfen.'=>'Check all original tariff fields.',
        'Eigenen ursprünglichen Tarif auswählen.'=>'Select your original tariff.','Eigene Tarifart auswählen.'=>'Select a valid tariff kind.','Ursprüngliche Tarifkennung und Fassung gemeinsam prüfen.'=>'Check the original tariff identity and version together.',
        'Tariffeld enthält ungültige Daten.'=>'A tariff field contains invalid data.','Vollständige Staffelzeilen prüfen.'=>'Check the complete tier rows.',
        'Ungültiges Tariffeld.'=>'Invalid tariff field.','Ungültiges Staffelfeld.'=>'Invalid tier field.','Sphäre prüfen.'=>'Check the tax sphere.','Sphäre und Steuerkonto gemeinsam auswählen.'=>'Select the tax sphere and account together.',
        'Staffel muss bei 0 beginnen; jede Alters-/Jahresgrenze nur einmal verwenden.'=>'Tiers must start at 0; use each age or year boundary only once.',
        'Aktives Steuerkonto wählen.'=>'Choose an active tax account.','Bezeichnung bereits vorhanden.'=>'This name is already in use.',
        'Tarife werden gerade bearbeitet. Bitte erneut prüfen.'=>'Tariffs are being edited. Please review again.',
        'Tarif inzwischen geändert oder nicht verfügbar.'=>'The tariff has changed or is unavailable.','Tarifversion prüfen.'=>'Check the tariff version.',
        'Tarif nicht verfügbar.'=>'Tariff unavailable.','Ursprünglichen Tarifstand bitte prüfen lassen.'=>'Have the original tariff state checked.',
        'Eigenen Tarifbereich auswählen.'=>'Select a valid tariff area.','Verfügbare Tarifseite auswählen.'=>'Select an available tariff page.',
        'Tarifsuche mit höchstens 150 Zeichen eingeben.'=>'Enter a tariff search with at most 150 characters.','Verfügbare Bestätigungsseite auswählen.'=>'Select an available confirmation page.',
        'Ursprüngliche Tariffassung nicht verfügbar.'=>'Original tariff version unavailable.','Tarifprüfung benötigt einen aktuellen Leseschritt.'=>'Tariff review requires a current read.',
        'Tarifbestätigungen benötigen das aktuelle installierte Paket.'=>'Tariff confirmations require the current installed package.',
        'Die vollständig geprüften Tarifangaben ausdrücklich bestätigen.'=>'Explicitly confirm the fully reviewed tariff details.',
        'Ursprüngliche Tarifkennung und Prüfung auswählen.'=>'Select the original tariff key and review.','Diese Tarifkennung gehört zu anderen ursprünglichen Angaben.'=>'This tariff key belongs to different original details.',
        'Dieser Tarif wurde inzwischen geändert. Aktuellen Stand bewusst erneut prüfen.'=>'This tariff has changed. Deliberately review the current state again.',
        'Die ursprüngliche Tariffassung ist nicht mehr aktuell.'=>'The original tariff version is no longer current.',
        'Ursprüngliche Tarifbestätigung bitte prüfen lassen.'=>'Have the original tariff confirmation checked.','Ursprüngliche Tarifbestätigung nicht verfügbar.'=>'Original tariff confirmation unavailable.',
        'Eigenen ursprünglichen Tarifentwurf öffnen.'=>'Open your original tariff draft.','Höchstens 20 Staffelzeilen verwenden.'=>'Use at most 20 tier rows.',
        'Gültige ungespeicherte Staffelaktion auswählen.'=>'Select a valid unsaved tier action.','Gültige Tarifaktion auswählen.'=>'Select a valid tariff action.',
        'Ursprüngliche Tarifbestätigung auswählen.'=>'Select an original tariff confirmation.','Zuerst den ursprünglichen Tarifausgang prüfen.'=>'Check the original tariff outcome first.',
        'Tarifausgang nicht bestätigt. Die ursprüngliche Bestätigung über ihre Kennung prüfen.'=>'Tariff outcome is unconfirmed. Read the original confirmation by its key.',
        'Tarife konnten nicht geladen werden.'=>'Tariffs could not be loaded.',
        'Unbekannter Platzhalter: {name}'=>'Unknown placeholder: {name}',
        'Betrag ohne Tausendertrennzeichen mit höchstens zwei Nachkommastellen eingeben.'=>'Enter an amount without thousands separators and with at most two decimal places.','Betrag ist zu groß.'=>'The amount is too large.',
        'Der verknüpfte Mitgliedsbeitrag muss aktiv sein: positiver Festbetrag pro aktuellem Jahr ohne Umsatzsteuer.'=>'The linked membership fee must be active: a positive fixed price for the current year without VAT.',
    ];
    public static function key(string $source):string{return 'tariff_'.substr(hash('sha256',$source),0,24);}
    public static function messages(string $locale):array{$out=[];$en=explode('-',$locale,2)[0]==='en';foreach(self::ENGLISH as$source=>$translation)$out[self::key($source)]=$en?$translation:$source;return$out;}
    public static function text(string $source,array $messages):string
    {
        if(isset($messages[self::key($source)]))return$messages[self::key($source)];
        if(preg_match('/\A(.+) · Unterteilung ([1-4][1-9])\z/D',$source,$parts))return strtr(self::text('{sphere} · Unterteilung {code}',$messages),['{sphere}'=>self::text($parts[1],$messages),'{code}'=>$parts[2]]);
        if(str_starts_with($source,'Unbekannte Variable: '))return strtr(self::text('Unbekannter Platzhalter: {name}',$messages),['{name}'=>substr($source,strlen('Unbekannte Variable: '))]);
        return NumberingMessages::text($source,$messages);
    }
}
