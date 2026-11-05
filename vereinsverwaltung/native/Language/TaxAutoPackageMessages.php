<?php declare(strict_types=1);
namespace Kulturhus\Native\Language;
final class TaxAutoPackageMessages
{
    public const ENGLISH=[
        'Jahrespaket aus dem Steuerassistenten'=>'Annual package from the tax assistant',
        'Unterlagenpaket prüfen'=>'Review working papers package',
        'Gespeicherten Paketauftrag lesen'=>'Read saved package outcome',
        'Dieses Paket beruht auf dem gespeicherten Zwischenstand des Steuerassistenten. Prüfen Sie die vollständigen Unterlagen und offenen Punkte.'=>'This package is based on the saved draft in the tax assistant. Review the complete working papers and outstanding points.',
        'Jahr {year} · gespeicherte Fassung {version}'=>'Year {year} · saved revision {version}',
        'Das vollständige Jahrespaket und seine offenen Punkte sind geprüft.'=>'I have reviewed the complete annual package and its outstanding points.',
        'Geprüftes Jahrespaket ablegen'=>'Save reviewed annual package',
        'Paket ausdrücklich erneut prüfen'=>'Explicitly review package again',
        'Gespeicherten Paketauftrag in neuem Tab lesen'=>'Read saved package outcome in a new tab',
        'Bekannte ursprüngliche Paketangaben'=>'Known original package details',
        'Für diese Kennung ist noch kein gespeichertes Jahrespaket lesbar. Zuerst denselben Ausgang erneut lesen; keinen zweiten Auftrag beginnen.'=>'No saved annual package is readable for this reference yet. Read the same outcome again first; do not start a second command.',
        'Gespeicherten Paketauftrag erneut lesen'=>'Read saved package outcome again',
        'Steuerassistenten für {year} öffnen'=>'Open tax assistant for {year}',
        'Zum Jahresabschluss zurück'=>'Back to year-end accounts',
        'Originalnachweise vor dem Ablegen prüfen'=>'Review original evidence before saving',
        'Das Paket enthält genau die ausgewählten ursprünglichen Dokumentversionen. Spätere Änderungen überschreiben diesen Ausgang nicht.'=>'The package contains the selected original document versions. Later changes do not overwrite this outcome.',
        'Das Ablegen verändert weder Buchungen noch den Zwischenstand und übermittelt keine Steuererklärung.'=>'Saving the package preserves the transactions and draft. Tax submission is a separate step.',
        'Jahresunterlagen als Paket prüfen'=>'Review annual working papers package',
        'Ursprüngliches Jahr, Fassung und Prüfung vollständig auswählen.'=>'Select the complete original year, revision and review.',
        'Ursprüngliche Jahrespaketbindung vollständig prüfen.'=>'Review the complete original annual package binding.',
        'Diese Kennung gehört zu einem anderen ursprünglichen Jahrespaket.'=>'This reference belongs to a different original annual package.',
        'Den unveränderten ursprünglichen Auftrag des Jahrespakets prüfen lassen.'=>'Have the unchanged original annual package command checked.',
        'Ausgewählte ursprüngliche PDF-Nachweise zuerst prüfen; kein unvollständiges Ersatzpaket speichern.'=>'Review the selected original PDF evidence first. Keep the complete evidence selection for this package.',
        'Daten geändert. Vorschau erneut prüfen.'=>'Data has changed. Review the preview again.',
        'Ein neues Jahrespaket außerhalb eines bereits laufenden Vorgangs erneut prüfen.'=>'Review the new annual package outside the transaction already in progress.',
        'Jahresunterlagen werden gerade gespeichert. Zuerst den Ausgang mit derselben Kennung lesen.'=>'Annual working papers are being saved. Read the outcome with the same reference first.',
        'Jahrespaket im ursprünglichen Steuerassistenten erneut öffnen.'=>'Reopen the annual package in its original tax assistant.',
        'Ursprüngliches Erklärungsjahr vollständig prüfen.'=>'Review the complete original filing year.',
        'Jahrespaketformular ist unvollständig. Bekannte Angaben bleiben erhalten.'=>'The annual package form is incomplete. Known details are retained.',
        'Ursprünglichen Jahrespaketauftrag vollständig prüfen.'=>'Review the complete original annual package command.',
        'Die ursprüngliche Jahrespaketkennung passt nicht zu diesem Auftrag.'=>'The original annual package reference does not match this command.',
        'Das vollständige Jahrespaket ausdrücklich bestätigen.'=>'Explicitly confirm the complete annual package.',
        'Die ursprüngliche Jahrespaketprüfung ist abgelaufen oder passt nicht. Zuerst den gespeicherten Ausgang lesen.'=>'The original annual package review has expired or does not match. Read the saved outcome first.',
        'Ursprüngliche Jahrespaketkennung vollständig prüfen.'=>'Review the complete original annual package reference.',
        'Speicherantwort des Jahrespakets unklar. Zuerst den Ausgang mit derselben Kennung lesen; keinen zweiten Auftrag beginnen.'=>'The annual package save response is unclear. Read the outcome with the same reference first; do not start a second command.',
        'Finanzberechtigung für die Steuer-Vorbereitung erforderlich.'=>'Financial permission for tax preparation required.',
        'Vereinszuordnung inzwischen geändert.'=>'The club assignment has changed.',
    ];
    public static function key(string $source):string{return 'tax_auto_package_'.substr(hash('sha256',$source),0,24);}
    public static function messages(string $locale):array{$english=explode('-',$locale,2)[0]==='en';$out=[];foreach(self::ENGLISH as$source=>$text)$out[self::key($source)]=$english?$text:$source;return$out;}
    public static function text(string $source,array $messages,array $values=[]):string{$text=$messages[self::key($source)]??AnnualTaxMessages::text($source,$messages,$values);foreach($values as$key=>$value)if(is_scalar($value))$text=str_replace('{'.$key.'}',(string)$value,$text);return$text;}
}
