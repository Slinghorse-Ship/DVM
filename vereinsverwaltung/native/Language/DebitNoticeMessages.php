<?php declare(strict_types=1);
namespace Kulturhus\Native\Language;

/** Interface labels only; original invoices, letters and reasons stay literal. */
final class DebitNoticeMessages
{
    public const ENGLISH = [
        'Geprüfte Rechnung und Mandat'=>'Originally reviewed invoice and mandate',
        'Aktuelle Forderung stimmt nicht mit der ausgegebenen Originalrechnung überein. Original zuerst klären.'=>'The current claim does not match the issued original invoice. Resolve the original first.',
        'Lastschriftvorankündigung prüfen und erzeugen'=>'Review and create a direct debit notice',
        'Eigene frühere Bestätigungen bleiben mit Finanzleserecht erreichbar. Neue Lastschriftvorankündigungen benötigen Finanzschreibrecht und Zugriff auf das ursprüngliche Dokument.'=>'Your own earlier confirmations remain available with finance read access. New direct debit notices require finance write access and access to the original document.',
        'Briefvorlage'=>'Letter template',
        'Lastschriftvorankündigung'=>'Donation direct debit notice',
        'Vorankündigungsbestätigung'=>'Original personal direct debit notice confirmation',
        'Eigener Grund'=>'Your original reason',
        'Genau dieses ursprüngliche Lastschriftvorankündigung-PDF herunterladen'=>'Download this exact original direct debit notice PDF',
        'Genau diese persönliche Erstbestätigung herunterladen'=>'Download this exact personal first confirmation',
        'Gespeicherte Lastschriftvorankündigung in der Dokumentablage öffnen'=>'Open the saved direct debit notice in the document library',
        'Eine weitere Lastschriftvorankündigung bewusst neu öffnen'=>'Explicitly open another direct debit notice',
        'Ursprünglichen Vorankündigungsausgang lesend prüfen'=>'Read the original direct debit notice outcome',
        'Briefdatum'=>'Letter date',
        'Betreff'=>'Subject',
        'Einleitung'=>'Introduction',
        'Brieftext'=>'Letter text',
        'Schlusstext'=>'Closing text',
        'Eigener Grund für diese Lastschriftvorankündigung'=>'Your own reason for this direct debit notice',
        'Betreff bis 200 Zeichen, Einleitung, Brieftext und Schlusstext jeweils bis 6.000 Zeichen, eigener Grund bis 2.000 Zeichen. Ganze Angaben bleiben bei Fehlern erhalten.'=>'Subject up to 200 characters; introduction, letter text and closing up to 6,000 characters each; your reason up to 2,000 characters. Complete details are retained if an error occurs.',
        'Ganze Lastschriftvorankündigung unverbindlich prüfen'=>'Review the complete direct debit notice without saving',
        'Eigenen ungespeicherten Briefentwurf verwerfen'=>'Discard your own unsaved letter draft',
        'Ganze Briefangaben mit aktuellem Stand erneut prüfen'=>'Review the complete letter details against the current state',
        'Geprüfte, noch nicht gespeicherte Lastschriftvorankündigung'=>'Reviewed direct debit notice, not yet saved',
        'Genau diese Originalrechnung, Anschrift, Mandatsreferenz, Gläubiger-ID, Betrag, Einzugsdatum und alle ganzen Briefangaben einschließlich der PDF-Vorschau sind geprüft.'=>'I have checked this exact original invoice, address, mandate reference, creditor ID, amount, collection date and all complete letter details, including the PDF preview.',
        'Geprüfte Lastschriftvorankündigung bewusst erzeugen'=>'Explicitly create the reviewed direct debit notice',
        'Eigene Lastschriftvorankündigung bewusst öffnen'=>'Explicitly open your own direct debit notice',
        'Eigene frühere Lastschriftvorankündigungen'=>'Your own earlier direct debit notices',
        'Ganze Vorankündigungssuche'=>'Complete direct debit notice search',
        'Vorankündigungsseite'=>'Direct debit notice page',
        'Genau diese eigenen Lastschriftvorankündigungen anzeigen'=>'Show these exact direct debit notices of your own',
        'Seite {page} von {pages} · {count} eigene Bestätigungen'=>'Page {page} of {pages} · {count} confirmations of your own',
        'Lastschriftvorankündigung vom {date}'=>'Direct debit notice dated {date}',
        'Keine passenden eigenen Lastschriftvorankündigungen vorhanden.'=>'No matching direct debit notices of your own exist.',
        'Aktuelle Finanzleseberechtigung erforderlich.'=>'Current finance read access is required.',
        'Aktuelle Finanzschreibberechtigung erforderlich.'=>'Current finance write access is required.',
        'Alle ursprünglichen Brieftextfelder prüfen.'=>'Check all original letter text fields.',
        'Aufgelösten ganzen Briefbetreff einzeilig mit höchstens 300 Zeichen prüfen.'=>'Check the complete resolved subject: one line, no more than 300 characters.',
        'Lastschriftvorankündigung am eigenen ursprünglichen Finanzdokument öffnen.'=>'Open the direct debit notice at your own original financial document.',
        'Lastschriftvorankündigungen benötigen das aktuelle installierte Paket.'=>'Direct debit notices require the current installed package.',
        'Lastschriftvorankündigung konnte nicht geprüft werden. Ganze Angaben bleiben ungespeichert.'=>'The direct debit notice could not be reviewed. All complete details remain unsaved.',
        'Lastschriftvorankündigung oder Quelle wird gerade bearbeitet. Erneut prüfen.'=>'The direct debit notice or source is being edited. Review it again.',
        'Vorankündigungsausgang nicht bestätigt. Die ursprüngliche persönliche Entscheidung zuerst lesend prüfen.'=>'The direct debit notice outcome is unconfirmed. First read the original personal decision.',
        'Vorankündigungsbereich konnte nicht geladen werden.'=>'The direct debit notice workspace could not be loaded.',
        'Originalrechnung, Brieftexte oder Vorlagenquellen inzwischen geändert. Ganze Lastschriftvorankündigung erneut prüfen.'=>'The invoice, letter texts or template sources have changed. Review the complete direct debit notice again.',
        'Der Vorankündigungsentwurf wurde in einem anderen Tab geändert. Ganze Angaben erneut öffnen.'=>'The direct debit notice draft was changed in another tab. Reopen the complete details.',
        'Diese Vorankündigungskennung gehört zu anderen ursprünglichen Angaben.'=>'This direct debit notice key belongs to different original details.',
        'Diese Vorankündigungskennung gehört zu einer anderen ursprünglichen Prüfung.'=>'This direct debit notice key belongs to a different original review.',
        'Eigene ausgegebene Originalrechnung auswählen.'=>'Choose your own issued original invoice.',
        'Eigene unverbindliche Vorankündigungsvorschau erneut prüfen.'=>'Review your own unsaved direct debit notice preview again.',
        'Eigene ursprüngliche Vorankündigungsbestätigung nicht verfügbar.'=>'Your own original direct debit notice confirmation is unavailable.',
        'Eigene ursprüngliche Vorankündigungskennung und Prüfung auswählen.'=>'Choose your own original direct debit notice key and review.',
        'Eigenen aktiven Verein auswählen.'=>'Choose your own active association.',
        'Eigenen ganzen Grund mit höchstens 2.000 Zeichen angeben.'=>'Enter your complete reason, no more than 2,000 characters.',
        'Ein Speichervorgang läuft bereits. Bitte erneut versuchen.'=>'A save is already in progress. Please try again.',
        'Formular abgelaufen. Bitte neu laden.'=>'The form has expired. Please reload.',
        'Ganze Vorankündigungssuche ohne Steuerzeichen mit höchstens 150 Zeichen eingeben.'=>'Enter the complete direct debit notice search without control characters, no more than 150 characters.',
        'Ganze ursprüngliche Angaben zur Vorankündigung prüfen.'=>'Check the complete original direct debit notice details.',
        'Ganzen Betreff und Brieftext innerhalb der angezeigten Grenzen prüfen.'=>'Check the complete subject and letter text against the displayed limits.',
        'Genau diese ursprüngliche Originalrechnung und dieses Briefdatum erneut prüfen.'=>'Review this exact original invoice and letter date again.',
        'Genau diese vollständig geprüfte Lastschriftvorankündigung ausdrücklich bestätigen.'=>'Explicitly confirm this exact fully reviewed direct debit notice.',
        'Genau eine eigene Vorankündigungsaktion auswählen.'=>'Choose exactly one of your own direct debit notice actions.',
        'Geänderte ganze Briefangaben zuerst erneut prüfen.'=>'First review the complete changed letter details again.',
        'Ursprüngliche Rechnung dieser Vorankündigung nicht verfügbar.'=>'The original invoice for this direct debit notice is unavailable.',
        'Ursprüngliche persönliche Vorankündigungsbestätigung bitte prüfen lassen.'=>'Have the original personal direct debit notice confirmation checked.',
        'Ursprünglichen Vorankündigungsausgang zuerst lesend prüfen.'=>'First read the original direct debit notice outcome.',
        'Ursprüngliches Lastschriftvorankündigung-PDF bitte prüfen lassen.'=>'Have the original direct debit notice PDF checked.',
        'Vereinszuordnung inzwischen geändert.'=>'The association assignment has changed.',
        'Verfügbare genaue Vorankündigungsseite auswählen.'=>'Choose an available exact direct debit notice page.',
        'Für diese Originalrechnung fehlen nachweisbare Vorlagenwerte: {values}. Ganze Brieftexte gezielt berichtigen.'=>'Verified template values are missing for this original invoice: {values}. Correct the complete letter texts.',
        'Aktuelle Bankberechtigung für das gebundene Mandat erforderlich.'=>'Current bank authority is required for the bound mandate.',
        'Aktuelle Gläubigerangaben, Mandat und ursprüngliches Rechnungskonto stimmen nicht überein. Keine Ersatzbank verwenden.'=>'Current creditor details, mandate and original invoice account do not match. Do not substitute another bank account.',
        'Beitragsrechnung zuerst endgültig ausgeben.'=>'Issue the final membership fee invoice first.',
        'Briefdatum höchstens heute und Einzugsdatum mindestens 14 Tage nach heute wählen. Tatsächliche rechtzeitige Vorabinformation bleibt erforderlich.'=>'Choose a letter date no later than today and a collection date at least 14 days after today. Actual timely advance notice is still required.',
        'Diese Vorankündigung verwendet die ausgegebene Originalrechnung, das aktuelle Mandat und die freigegebene Briefvorlage. Die Erzeugung bestätigt keinen Versand und keinen Bankauftrag. Rechtzeitige tatsächliche Vorabinformation vor dem Einzug gesondert sicherstellen.'=>'This notice uses the issued original invoice, current mandate and approved letter template. Creating it confirms neither sending nor a bank order. Separately ensure actual timely notice before collection.',
        'Einzug nicht vor der ursprünglichen Rechnungsfälligkeit ankündigen.'=>'Do not announce collection before the original invoice due date.',
        'Einzugsbetrag'=>'Collection amount',
        'Einzugsdatum'=>'Collection date',
        'Ganzes Briefdatum und Einzugsdatum prüfen.'=>'Check the complete letter date and collection date.',
        'Genau das ursprüngliche Rechnungs-PDF öffnen'=>'Open the exact original invoice PDF',
        'Gläubiger-ID'=>'Creditor ID',
        'Mandatsreferenz'=>'Mandate reference',
        'Mitgliedskonto'=>'Member account',
        'Nur eine offene, noch nicht teilweise bezahlte Originalrechnung vorankündigen.'=>'Only announce collection of an open original invoice with no partial payment.',
        'Rechnungsnummer'=>'Invoice number',
        'Rechnung und aktuelles Mandat'=>'Original invoice and current mandate',
        'Ursprüngliche Rechnungsanschrift fehlt.'=>'The original invoice address is missing.',
        'Ursprünglicher Empfänger und Anschrift'=>'Original recipient and address',
        'Ursprünglicher Rechnungsempfänger hat keine eindeutige Mandatszuordnung.'=>'The original invoice recipient has no unambiguous mandate association.',
        'Ursprüngliches Rechnungsdokument zuerst wiederherstellen.'=>'Restore the original invoice document first.',
        'Ursprüngliches Rechnungskonto bitte prüfen lassen.'=>'Have the original invoice account checked.',
        'Ursprüngliches Rechnungskonto ist kein aktives Bankkonto.'=>'The original invoice account is not an active bank account.',
        'Zugehörige Gutschrift zuerst klären. Keine geänderte Rechnungsforderung vorankündigen.'=>'Resolve the associated credit note first. Do not announce collection of a changed invoice claim.',
        'Beitrags-Lastschrift'=>'Membership fee direct debit',
        'Rechnungs-Lastschrift'=>'Invoice direct debit',
            'Ursprünglicher Rechnungsbetrag'=>'Original invoice amount',
        'Bereits bezahlt'=>'Already paid',
        'Noch einzuziehen'=>'Still to collect',
        'Offene Beitragsforderungen mit gültigem persönlichem Mandat können über ihren tatsächlichen Restbetrag eingezogen werden. Bereits bezahlte Anteile bleiben erhalten. Die vollständige Prüfung erfolgt in der Vorschau.'=>'Outstanding contribution demands with a valid personal mandate can be collected for their actual remaining amount. Already paid portions are retained. The full review takes place in the preview.',
        'Restbetrag und ursprüngliche Zahlungen gemeinsam aktuell prüfen.'=>'Review the current remaining amount and original payments together.',
        'Restbetragseinzüge benötigen die vollständig abgeschlossene Paketwartung.'=>'Remaining amount collections require fully completed package maintenance.',
        'Nur eine offene ursprüngliche Restforderung zum Einzug auswählen.'=>'Select an outstanding original remaining demand for collection.',
        'Restbetrag und tatsächliche ursprüngliche Zahlungen stimmen nicht überein.'=>'The remaining amount and actual original payments do not match.',
        'Tatsächliche ursprüngliche Zahlung vor dem Resteinzug klären.'=>'Clarify the actual original payment before collecting the remainder.',
        'Nur offene Beitragsforderungen mit einem tatsächlichen Restbetrag auswählen.'=>'Select outstanding contribution demands with an actual remaining amount.',
        'Nur eine offene ursprüngliche Rechnungsforderung mit tatsächlichem Restbetrag vorankündigen.'=>'Announce an outstanding original invoice demand with an actual remaining amount.',
        'Restforderung nur mit dem unveränderten ursprünglichen Rechnungskonto einziehen.'=>'Collect the remaining demand only using the unchanged original invoice account.',
    ];

    public static function key(string $source): string { return 'debit_notice_'.substr(hash('sha256', $source), 0, 24); }
    public static function messages(string $locale): array
    {
        $english = explode('-', $locale, 2)[0] === 'en'; $messages=[];
        foreach (self::ENGLISH as $source=>$translation) $messages[self::key($source)] = $english ? $translation : $source;
        return $messages;
    }
    public static function text(string $source, array $messages): string
    {
        if (preg_match('/\AFür diese Originalrechnung fehlen nachweisbare Vorlagenwerte: (.+)\. Ganze Brieftexte gezielt berichtigen\.\z/u', $source, $match)) {
            $labels = array_map(static fn(string $label): string => TextSettingsMessages::text($label, $messages), explode(', ', $match[1]));
            $template='Für diese Originalrechnung fehlen nachweisbare Vorlagenwerte: {values}. Ganze Brieftexte gezielt berichtigen.';
            return str_replace('{values}', implode(', ', $labels), $messages[self::key($template)] ?? $template);
        }
        return $messages[self::key($source)] ?? SepaMessages::text($source, $messages);
    }
}
