<?php declare(strict_types=1);
namespace Kulturhus\Native\Language;

/** Fixed interface terms only; requested and issued human text remains literal. */
final class TextSettingsMessages
{
    public const ENGLISH=[
        'Textbausteine'=>'Text templates',
        'Beiträge'=>'Membership dues',
        'Rechnungen'=>'Invoices',
        'Spenden'=>'Donations',
        'Beitragsrechnung'=>'Membership invoice',
        'Beitragserinnerung'=>'Membership reminder',
        'Beitrags-Lastschrift'=>'Membership direct debit',
        'Rechnung'=>'Invoice',
        'Rechnungserinnerung'=>'Invoice reminder',
        'Lastschriftvorankündigung'=>'Direct debit advance notice',
        'Spendenbegleitschreiben'=>'Donation cover letter',
        'Sammelspendenbegleitschreiben'=>'Collective donation cover letter',
        'Betreff'=>'Subject',
        'Benachrichtigungstext'=>'Notification text',
        'Einleitung'=>'Introduction',
        'Schlusstext'=>'Closing text',
        'Dokument-PDF als Anhang vormerken'=>'Include the document PDF as an attachment',
        'Bearbeite Betreff, Benachrichtigung und PDF-Texte für Beiträge, Rechnungen und Spenden. Die Sprache der Oberfläche verändert eure eigenen Texte nicht.'=>'Edit subjects, notifications and PDF text for membership dues, invoices and donations. The interface language does not change your own text.',
        'Vorschau und PDF speichern keinen Beleg und versenden keine Nachricht. Erst die getrennte Bestätigung übernimmt die vollständig geprüfte Gruppe.'=>'Preview and PDF do not save a document or send a message. Only the separate confirmation applies the completely reviewed group.',
        'Textbausteine sind für diesen Zugriff nicht verfügbar.'=>'Text templates are unavailable for this access.',
        'Textbausteinbestätigung'=>'Original text template confirmation',
        'Diese persönliche Bestätigung erhält damalige ganze Angaben, Normierung und tatsächliche Vorschauquellen. Spätere Änderungen ersetzen sie nicht.'=>'This private confirmation retains the complete original details, normalization and actual preview sources. Later changes do not replace it.',
        'Aktuell gespeicherte Textbausteine'=>'Currently saved text templates',
        'Textbausteingruppen'=>'Text template groups',
        'Neuen eigenen Textbausteinentwurf öffnen'=>'Open a new private text template draft',
        'Eigener ungespeicherter Textbausteinentwurf'=>'Your private unsaved text template draft',
        'Textbausteinausgang nicht bestätigt. Die ursprüngliche Bestätigung über ihre Kennung lesen.'=>'The text template outcome is unconfirmed. Read the original confirmation by its key.',
        'Ursprünglichen Textbausteinausgang lesend prüfen'=>'Read the original text template outcome',
        'Fehlt die ursprüngliche Bestätigung weiterhin, dieselben ganzen Angaben bewusst erneut prüfen. Dabei wird die Gruppe noch nicht gespeichert.'=>'If the original confirmation is still absent, deliberately review the same complete details again. This does not save the group yet.',
        'Entwurf erhalten und Gruppe wechseln'=>'Keep the draft and change group',
        'Vorlage für die Vorschau'=>'Template to preview',
        'Betreff bis 200 Zeichen in einer Zeile, andere Texte bis 6.000 Zeichen. Ganze falsche Angaben bleiben korrigierbar.'=>'Use up to 200 characters on one line for the subject and 6,000 characters for other text. Complete invalid values remain available for correction.',
        'Betreff und Benachrichtigung gehören zur E-Mail-Vorschau. Einleitung und Schlusstext gehören zum Rechnungs-PDF.'=>'The subject and notification appear in the email preview. The introduction and closing appear in the invoice PDF.',
        'Diese Briefvorlage verwendet Betreff und Benachrichtigungstext.'=>'This letter template uses the subject and notification text.',
        'Weitere erhaltene Textangaben'=>'Additional retained text',
        'Einleitung und Schlusstext bleiben hier erhalten. Für diese Briefvorlage wird der Benachrichtigungstext verwendet.'=>'The introduction and closing are retained here. This letter template uses the notification text.',
        'Text formatieren'=>'Format text',
        'Variable auswählen'=>'Select a variable',
        'Variable wählen'=>'Choose a variable',
        'Einfügen'=>'Insert',
        'Formatierte Eingabe ansehen'=>'View formatted input',
        'Nur diese Vorlage auf Standard zurücksetzen'=>'Reset only this template to its default',
        'Vollständige Textbausteingruppe prüfen'=>'Review the complete text template group',
        'Vollständige Textbausteinprüfung'=>'Complete text template review',
        'Alle Vorlagen dieser Gruppe wurden als Nachricht und PDF geprüft. Die ausgewählte Vorlage zeigt unten ihre tatsächlichen Musterwerte.'=>'Every template in this group has been checked as a message and PDF. The selected template shows its actual sample values below.',
        'Vollständige gewünschte und normierte Angaben'=>'Complete requested and normalized details',
        'Benachrichtigungsvorschau'=>'Notification preview',
        'Ohne Finanzlesen bleiben Bankvariablen in der Musteransicht leer. Eine eigene Absenderwahl gilt der Gestaltungsvorschau; Rechnungen behalten den Verein als rechtlichen Aussteller.'=>'Without finance read permission, bank variables remain blank in the sample. A separate sender address applies to the layout preview; invoices retain the association as their legal issuer.',
        'Die vollständige Textbausteingruppe und alle ihre Vorschauen sind geprüft.'=>'The complete text template group and all its previews have been reviewed.',
        'Geprüfte Textbausteingruppe bestätigen'=>'Confirm the reviewed text template group',
        'Entwürfe'=>'Your open text template drafts',
        'Textbausteinbestätigungen'=>'Your original text template confirmations',
        'Tatsächliche frühere Textbausteinfassungen'=>'Actual earlier text template versions',
        'Rohe Textbausteinfassung'=>'Original raw text template version',
        'Aktuelle Vereinseinstellungen für Textbausteine erforderlich.'=>'Current association settings permission is required for text templates.',
        'Eigene Textbausteingruppe auswählen.'=>'Choose your own text template group.',
        'Verfügbare Textvorlage dieser Gruppe auswählen.'=>'Select an available template in this group.',
        'Vollständige ursprüngliche Textbausteine prüfen.'=>'Check the complete original text templates.',
        'Vollständige ursprüngliche Textvorlagenfelder prüfen.'=>'Check the complete original template fields.',
        'Ganzes ursprüngliches Textvorlagenfeld prüfen.'=>'Check the complete original template field.',
        'Betreff ohne Zeilenumbruch eingeben.'=>'Enter the subject without line breaks.',
        'Textbaustein unvollständig.'=>'The text template is incomplete.',
        'Textbausteine oder ihre Quellen werden gerade bearbeitet. Bitte erneut prüfen.'=>'Text templates or their sources are being edited. Please review again.',
        'Die Textbausteine oder ihre Quellen wurden inzwischen geändert. Aktuellen Stand bewusst erneut prüfen.'=>'The text templates or their sources have changed. Deliberately review the current version again.',
        'Die vollständig geprüfte Textbausteingruppe ausdrücklich bestätigen.'=>'Explicitly confirm the completely reviewed text template group.',
        'Ursprüngliche Textbausteinbestätigung bitte prüfen lassen.'=>'Have the original text template confirmation checked.',
        'Den eigenen vollständig geprüften Textbausteinentwurf öffnen.'=>'Open your own completely reviewed text template draft.',
        'Ursprüngliche Textbausteinbestätigung auswählen.'=>'Select the original text template confirmation.',
        'Gültige Textbausteinaktion auswählen.'=>'Select a valid text template action.',
        'Zuerst den ursprünglichen Textbausteinausgang lesen.'=>'Read the original text template outcome first.',
        'Dieses frühere Textbausteinformular wurde nicht gespeichert. Ganze Angaben zuerst prüfen und danach gesondert bestätigen.'=>'This earlier text template form was not saved. Review the complete details first, then confirm separately.',
        'Textbausteine konnten nicht geladen werden.'=>'Text templates could not be loaded.',
        'Nachrichtenvorschau benötigt das vollständige installierte Mailpaket. Bitte erneut prüfen.'=>'The message preview requires the complete installed mail package. Please review again.',
        'Betreff und Nachricht nach Variablenersetzung prüfen; der Betreff muss einzeilig, nicht leer und höchstens 320 Zeichen lang sein.'=>'Check the subject and message after variable substitution; the subject must be nonempty, on one line and no longer than 320 characters.',
    ];
    public static function key(string $source):string{return 'text_settings_'.substr(hash('sha256',$source),0,24);}
    public static function messages(string $locale):array
    {$en=explode('-',$locale,2)[0]==='en';$out=[];foreach(self::ENGLISH as$source=>$translation)$out[self::key($source)]=$en?$translation:$source;return$out;}
    public static function text(string $source,array $messages):string
    {
        if(isset($messages[self::key($source)]))return$messages[self::key($source)];
        foreach(array_keys(\Kulturhus\Verein\Settings\TextInput::GROUPS)as$section)foreach(\Kulturhus\Verein\SettingsSchema::templateLabels($section)as$label)foreach([' · ',': ']as$separator)if(str_starts_with($source,$label.$separator))return self::text($label,$messages).$separator.self::text(substr($source,strlen($label.$separator)),$messages);
        foreach(\Kulturhus\Verein\Settings\TextInput::FIELDS as$label)if(str_starts_with($source,$label.': '))return self::text($label,$messages).': '.self::text(substr($source,strlen($label)+2),$messages);
        return PaperSettingsMessages::text($source,$messages);
    }
}
