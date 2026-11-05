<?php declare(strict_types=1);
namespace Kulturhus\Native\Language;

/** Interface labels only. Original donor details, letters and reasons stay literal. */
final class DonationCoverMessages
{
    public const ENGLISH = [
        'Spendenbegleitschreiben prüfen und erzeugen'=>'Review and create donation cover letters',
        'Dieses Begleitschreiben verwendet die ausgestellte ursprüngliche Bescheinigung und die gespeicherte Briefvorlage. Es ändert oder unterschreibt keine Zuwendungsbescheinigung und versendet keine Nachricht.'=>'This cover letter uses the original issued certificate and saved letter template. It does not change or sign a donation certificate or send a message.',
        'Eigene frühere Bestätigungen bleiben mit Finanzleserecht erreichbar. Neue Begleitschreiben benötigen Finanzschreibrecht und Zugriff auf das ursprüngliche Dokument.'=>'Your own earlier confirmations remain available with finance read access. New cover letters require finance write access and access to the original document.',
        'Geprüfte Bescheinigung'=>'Originally reviewed certificate',
        'Tatsächlich ausgestellte Bescheinigung'=>'Actual issued certificate',
        'Ursprüngliche Bescheinigungsnummer'=>'Original certificate number',
        'Briefvorlage'=>'Letter template',
        'Sammelspendenbegleitschreiben'=>'Collective donation cover letter',
        'Spendenbegleitschreiben'=>'Donation cover letter',
        'Ursprünglicher Zuwender und Anschrift'=>'Original donor and address',
        'Ursprünglicher Zuwendungsbetrag'=>'Original donation amount',
        'Genau das ursprüngliche Bescheinigungs-PDF öffnen'=>'Open the exact original certificate PDF',
        'Begleitschreibenbestätigung'=>'Original personal cover letter confirmation',
        'Eigener Grund'=>'Your original reason',
        'Genau dieses ursprüngliche Begleitschreiben-PDF herunterladen'=>'Download this exact original cover letter PDF',
        'Genau diese persönliche Erstbestätigung herunterladen'=>'Download this exact personal first confirmation',
        'Gespeichertes Begleitschreiben in der Dokumentablage öffnen'=>'Open the saved cover letter in the document library',
        'Ein weiteres Begleitschreiben bewusst neu öffnen'=>'Explicitly open another cover letter',
        'Ursprünglichen Begleitschreibenausgang lesend prüfen'=>'Read the original cover letter outcome',
        'Briefdatum'=>'Letter date',
        'Betreff'=>'Subject',
        'Einleitung'=>'Introduction',
        'Brieftext'=>'Letter text',
        'Schlusstext'=>'Closing text',
        'Eigener Grund für dieses Begleitschreiben'=>'Your own reason for this cover letter',
        'Betreff bis 200 Zeichen, Einleitung, Brieftext und Schlusstext jeweils bis 6.000 Zeichen, eigener Grund bis 2.000 Zeichen. Ganze Angaben bleiben bei Fehlern erhalten.'=>'Subject up to 200 characters; introduction, letter text and closing up to 6,000 characters each; your reason up to 2,000 characters. Complete details are retained if an error occurs.',
        'Ganzes Begleitschreiben unverbindlich prüfen'=>'Review the complete cover letter without saving',
        'Eigenen ungespeicherten Briefentwurf verwerfen'=>'Discard your own unsaved letter draft',
        'Ganze Briefangaben mit aktuellem Stand erneut prüfen'=>'Review the complete letter details against the current state',
        'Geprüftes, noch nicht gespeichertes Begleitschreiben'=>'Reviewed cover letter, not yet saved',
        'Genau diese ursprüngliche Bescheinigung, Anschrift, Betrag und alle ganzen Briefangaben einschließlich der PDF-Vorschau sind geprüft.'=>'I have checked this exact original certificate, address, amount and all complete letter details, including the PDF preview.',
        'Geprüftes Begleitschreiben bewusst erzeugen'=>'Explicitly create the reviewed cover letter',
        'Eigenes Begleitschreiben bewusst öffnen'=>'Explicitly open your own cover letter',
        'Eigene frühere Begleitschreiben'=>'Your own earlier cover letters',
        'Ganze Begleitschreibensuche'=>'Complete cover letter search',
        'Begleitschreibenseite'=>'Cover letter page',
        'Genau diese eigenen Begleitschreiben anzeigen'=>'Show these exact cover letters of your own',
        'Seite {page} von {pages} · {count} eigene Bestätigungen'=>'Page {page} of {pages} · {count} confirmations of your own',
        'Begleitschreiben vom {date}'=>'Cover letter dated {date}',
        'Keine passenden eigenen Begleitschreiben vorhanden.'=>'No matching cover letters of your own exist.',
        'Aktuelle Finanzleseberechtigung erforderlich.'=>'Current finance read access is required.',
        'Aktuelle Finanzschreibberechtigung erforderlich.'=>'Current finance write access is required.',
        'Alle ursprünglichen Brieftextfelder prüfen.'=>'Check all original letter text fields.',
        'Aufgelösten ganzen Briefbetreff einzeilig mit höchstens 300 Zeichen prüfen.'=>'Check the complete resolved subject: one line, no more than 300 characters.',
        'Begleitschreiben am eigenen ursprünglichen Finanzdokument öffnen.'=>'Open the cover letter at your own original financial document.',
        'Begleitschreiben benötigen das aktuelle installierte Paket.'=>'Cover letters require the current installed package.',
        'Begleitschreiben konnte nicht geprüft werden. Ganze Angaben bleiben ungespeichert.'=>'The cover letter could not be reviewed. All complete details remain unsaved.',
        'Begleitschreiben oder Quelle wird gerade bearbeitet. Erneut prüfen.'=>'The cover letter or source is being edited. Review it again.',
        'Begleitschreibenausgang nicht bestätigt. Die ursprüngliche persönliche Entscheidung zuerst lesend prüfen.'=>'The cover letter outcome is unconfirmed. First read the original personal decision.',
        'Begleitschreibenbereich konnte nicht geladen werden.'=>'The cover letter workspace could not be loaded.',
        'Bescheinigung oder Dokumentrechte werden gerade bearbeitet. Erneut prüfen.'=>'The certificate or document permissions are being edited. Review them again.',
        'Bescheinigung, Brieftexte oder Vorlagenquellen inzwischen geändert. Ganzes Begleitschreiben erneut prüfen.'=>'The certificate, letter texts or template sources have changed. Review the complete cover letter again.',
        'Briefvorlage oder Bescheinigung wird gerade bearbeitet. Erneut prüfen.'=>'The letter template or certificate is being edited. Review it again.',
        'Der Begleitschreibenentwurf wurde in einem anderen Tab geändert. Ganze Angaben erneut öffnen.'=>'The cover letter draft was changed in another tab. Reopen the complete details.',
        'Diese Begleitschreibenkennung gehört zu anderen ursprünglichen Angaben.'=>'This cover letter key belongs to different original details.',
        'Diese Begleitschreibenkennung gehört zu einer anderen ursprünglichen Prüfung.'=>'This cover letter key belongs to a different original review.',
        'Eigene ausgestellte Zuwendungsbescheinigung auswählen.'=>'Choose your own issued donation certificate.',
        'Eigene unverbindliche Begleitschreibenvorschau erneut prüfen.'=>'Review your own unsaved cover letter preview again.',
        'Eigene ursprüngliche Begleitschreibenbestätigung nicht verfügbar.'=>'Your own original cover letter confirmation is unavailable.',
        'Eigene ursprüngliche Begleitschreibenkennung und Prüfung auswählen.'=>'Choose your own original cover letter key and review.',
        'Eigenen aktiven Verein auswählen.'=>'Choose your own active association.',
        'Eigenen ganzen Grund mit höchstens 2.000 Zeichen angeben.'=>'Enter your complete reason, no more than 2,000 characters.',
        'Ein Speichervorgang läuft bereits. Bitte erneut versuchen.'=>'A save is already in progress. Please try again.',
        'Formular abgelaufen. Bitte neu laden.'=>'The form has expired. Please reload.',
        'Ganze Begleitschreibensuche ohne Steuerzeichen mit höchstens 150 Zeichen eingeben.'=>'Enter the complete cover letter search without control characters, no more than 150 characters.',
        'Ganze ursprüngliche Begleitschreibenangaben prüfen.'=>'Check the complete original cover letter details.',
        'Ganzen Betreff und Brieftext innerhalb der angezeigten Grenzen prüfen.'=>'Check the complete subject and letter text against the displayed limits.',
        'Ganzes Briefdatum prüfen.'=>'Check the complete letter date.',
        'Genau diese ursprüngliche Bescheinigung und dieses Briefdatum erneut prüfen.'=>'Review this exact original certificate and letter date again.',
        'Genau dieses vollständig geprüfte Begleitschreiben ausdrücklich bestätigen.'=>'Explicitly confirm this exact fully reviewed cover letter.',
        'Genau eine eigene Begleitschreibenaktion auswählen.'=>'Choose exactly one of your own cover letter actions.',
        'Gespeicherte Briefquelle bitte prüfen lassen.'=>'Have the saved letter source checked.',
        'Geänderte ganze Briefangaben zuerst erneut prüfen.'=>'First review the complete changed letter details again.',
        'Tatsächlich ausgestellte Einzel- oder Sammelbescheinigung auswählen.'=>'Choose an actually issued single or collective certificate.',
        'Ursprüngliche Bescheinigung dieses Begleitschreibens nicht verfügbar.'=>'The original certificate for this cover letter is unavailable.',
        'Ursprüngliche Einzelbescheinigung bitte prüfen lassen.'=>'Have the original single certificate checked.',
        'Ursprüngliche Quellen der Sammelbescheinigung fehlen.'=>'The original sources of the collective certificate are missing.',
        'Ursprüngliche Sammelbescheinigung bitte prüfen lassen.'=>'Have the original collective certificate checked.',
        'Ursprüngliche Zuwenderanschrift fehlt.'=>'The original donor address is missing.',
        'Ursprüngliche Zuwendungsbescheinigung zuerst wiederherstellen.'=>'Restore the original donation certificate first.',
        'Ursprüngliche persönliche Begleitschreibenbestätigung bitte prüfen lassen.'=>'Have the original personal cover letter confirmation checked.',
        'Ursprünglichen Begleitschreibenausgang zuerst lesend prüfen.'=>'First read the original cover letter outcome.',
        'Ursprünglichen Zuwendungsbetrag bitte prüfen lassen.'=>'Have the original donation amount checked.',
        'Ursprünglicher Bescheinigungsstand stimmt nicht überein.'=>'The original certificate state does not match.',
        'Ursprüngliches Begleitschreiben-PDF bitte prüfen lassen.'=>'Have the original cover letter PDF checked.',
        'Ursprüngliches ausgestelltes Bescheinigungs-PDF bitte prüfen lassen.'=>'Have the original issued certificate PDF checked.',
        'Vereinszuordnung inzwischen geändert.'=>'The association assignment has changed.',
        'Verfügbare genaue Begleitschreibenseite auswählen.'=>'Choose an available exact cover letter page.',
        'Zu einer stornierten Bescheinigung kein neues Begleitschreiben erstellen.'=>'Do not create a new cover letter for a cancelled certificate.',
        'Für diese ursprüngliche Bescheinigung fehlen nachweisbare Vorlagenwerte: {values}. Ganze Brieftexte gezielt berichtigen.'=>'Verified template values are missing for this original certificate: {values}. Correct the complete letter texts.',
    ];

    public static function key(string $source): string { return 'donation_cover_'.substr(hash('sha256', $source), 0, 24); }
    public static function messages(string $locale): array
    {
        $english = explode('-', $locale, 2)[0] === 'en'; $messages = [];
        foreach (self::ENGLISH as $source=>$translation) $messages[self::key($source)] = $english ? $translation : $source;
        return $messages;
    }
    public static function text(string $source, array $messages): string
    {
        if (preg_match('/\AFür diese ursprüngliche Bescheinigung fehlen nachweisbare Vorlagenwerte: (.+)\. Ganze Brieftexte gezielt berichtigen\.\z/u', $source, $match)) {
            $labels = array_map(static fn(string $label): string => TextSettingsMessages::text($label, $messages), explode(', ', $match[1]));
            $template='Für diese ursprüngliche Bescheinigung fehlen nachweisbare Vorlagenwerte: {values}. Ganze Brieftexte gezielt berichtigen.';
            return str_replace('{values}', implode(', ', $labels), $messages[self::key($template)] ?? $template);
        }
        return $messages[self::key($source)] ?? TextSettingsMessages::text($source, $messages);
    }
}
