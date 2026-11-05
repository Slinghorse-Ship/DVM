<?php declare(strict_types=1);
namespace Kulturhus\Native\Language;
/** Fixed setup labels only; original creditor names and addresses stay literal. */
final class MemberBankSettingsMessages
{
 public const ENGLISH=[
 'SEPA-Mandate einrichten'=>'Set up SEPA mandates',
 'Die Freischaltung erlaubt Mitgliedern, ein persönliches SEPA-Mandat abzugeben. Hier wird kein Einzug erstellt oder an eine Bank übermittelt.'=>'Enabling this allows members to provide a personal SEPA mandate. No collection is created or submitted to a bank here.',
 'Deutsche Gläubiger-ID'=>'German creditor identifier',
 '18 Zeichen; Leerzeichen werden beim Speichern entfernt.'=>'18 characters; spaces are removed when saving.',
 'Vollständiger Name des Zahlungsempfängers'=>'Full name of the creditor',
 'Anschrift des Zahlungsempfängers'=>'Creditor address',
 'Persönliche SEPA-Mandatserteilung für Mitglieder freischalten'=>'Enable personal SEPA mandate authorisation for members',
 'Einstellungen speichern'=>'Save settings',
 'Geprüfter Einrichtungsstand {version}'=>'Reviewed setup version {version}',
 'Der ursprüngliche Einrichtungsstand hat sich geändert. Deine Eingaben bleiben erhalten. Öffne und prüfe den aktuellen Stand vor dem Speichern.'=>'The original setup version has changed. Your entries are retained. Open and review the current version before saving.',
 'Aktuelle SEPA-Einrichtung neu öffnen und prüfen'=>'Reopen and review the current SEPA setup',
 'Eingaben ansehen'=>'View original entries',
 'SEPA-Einstellungen gespeichert. Es wurde kein Einzug ausgelöst.'=>'SEPA settings saved. No collection was initiated.',
 'SEPA-Einrichtung ist nur für Superuser freigegeben.'=>'SEPA setup is available only to superusers.',
 'Formular abgelaufen. Bitte neu öffnen.'=>'The form has expired. Reopen it.',
 'Formular prüfen.'=>'Check the form.',
 'Gläubigerangaben prüfen.'=>'Check the creditor details.',
 'Deutsche Gläubiger-ID mit 18 Zeichen eingeben.'=>'Enter a German creditor identifier with 18 characters.',
 'Prüfziffer der Gläubiger-ID stimmt nicht.'=>'The creditor identifier check digits do not match.',
 'Vor Freischaltung alle Gläubigerangaben vervollständigen.'=>'Complete all creditor details before enabling mandates.',
 'SEPA-Einstellungen inzwischen geändert. Bitte neu öffnen.'=>'The SEPA settings have changed. Reopen them.',
 'SEPA-Einstellungen derzeit nicht verfügbar.'=>'SEPA settings are currently unavailable.',
 'Freischaltung des SEPA-Mandats prüfen.'=>'Check the SEPA mandate enablement.',
 'Eigenen aktiven Verein auswählen.'=>'Select your own active club.',
 'Geprüfte SEPA-Einstellungen passen nicht zum aktuellen Verein und Originalstand.'=>'The reviewed SEPA settings do not match the current club and original version.',
 'SEPA-Einstellungen werden gerade bearbeitet. Aktuellen Stand erneut prüfen.'=>'The SEPA settings are being edited. Review the current version again.',
 'SEPA-Einrichtung im ursprünglich geöffneten Bereich prüfen.'=>'Review SEPA setup in the originally opened section.',
 'Ursprünglichen SEPA-Einrichtungsstand prüfen.'=>'Check the original SEPA setup version.',
 ];
 public static function key(string $source):string{return'member_bank_settings_'.substr(hash('sha256',$source),0,24);}
 public static function messages(string $locale):array{$english=explode('-',$locale,2)[0]==='en';$out=[];foreach(self::ENGLISH as$source=>$translation)$out[self::key($source)]=$english?$translation:$source;return$out;}
 public static function text(string $source,array $messages,array $values=[]):string{$text=$messages[self::key($source)]??$source;foreach($values as$key=>$value)$text=str_replace('{'.$key.'}',(string)$value,$text);return$text;}
}
