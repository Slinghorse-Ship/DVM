<?php declare(strict_types=1);
namespace Kulturhus\Native\Language;

/** Fixed recovery copy; original names, identifiers and year strings remain literal. */
final class AssetBookSourceMessages
{
    public const ENGLISH=[
        'Anlagenauswahl prüfen'=>'Review the original asset selection',
        'Die vollständige Gegenstandskennung prüfen.'=>'Check the complete item identifier.',
        'Das ursprünglich angeforderte Jahr vollständig prüfen. Es wird kein anderes Jahr berechnet.'=>'Check the whole originally requested year. No other year is calculated.',
        'Der ursprünglich angeforderte Gegenstand ist derzeit nicht verfügbar.'=>'The originally requested item is currently unavailable.',
        'Der ursprüngliche Anlagenstand konnte gerade nicht geladen werden. Bitte erneut prüfen.'=>'The original asset record could not be loaded at this time. Please check again.',
        'Für diese Ansicht ist aktueller Finanzzugriff erforderlich.'=>'Current finance access is required for this view.',
        'Ursprünglicher Gegenstand'=>'Original item',
        'Ursprünglich angefordertes Jahr'=>'Originally requested year',
        'Vollständige Gegenstandskennung'=>'Complete item identifier',
        'Die ganze Kennung aus dem ursprünglichen Link verwenden.'=>'Use the whole identifier from the original link.',
        'Jahr'=>'Year',
        'Ein vollständiges vierstelliges Jahr eingeben. Die Bewertungsmethode kann den zulässigen Zeitraum weiter einschränken.'=>'Enter a whole four-digit year. The valuation method may further restrict the permitted period.',
        'Ursprüngliche Auswahl erneut prüfen'=>'Check the original selection again',
        'Jahr korrigieren und ursprünglichen Gegenstand öffnen'=>'Correct the year and open the original item',
        'Gegenstand und Jahr prüfen'=>'Check the item and year',
        'Gegenstandsauswahl ausdrücklich öffnen'=>'Explicitly open the item selection',
        'Gegenstandsauswahl ohne bisherige Angaben öffnen'=>'Open the item selection without the previous details',
        'Angaben lesen'=>'Read the original submitted details',
        'Mehrteilige oder nicht als Text übermittelte Angaben werden nicht auf einzelne Werte reduziert.'=>'Multi-part details or values not submitted as text are not reduced to individual values.',
        'Die ursprünglichen Anlagenpläne, Jahresbestätigungen und Nachweise bleiben erhalten.'=>'The original asset plans, annual confirmations and supporting records remain preserved.',
        'Anschaffungen & Abschreibungen'=>'Acquisitions & depreciation',
        'Abschreibungen sind für diesen Verein in den Steuereinstellungen nicht aktiviert.'=>'Depreciation is not enabled in this association tax settings.',
        'Die vollständige ursprüngliche Prüfkennung prüfen.'=>'Check the complete original review identifier.',
        'Die Angaben im Link und im fehlgeschlagenen Vorgang gehören nicht zu derselben ursprünglichen Auswahl.'=>'The link and the failed operation do not refer to the same original selection.',
        'Die übermittelte Anfrage gehört nicht zu dieser Anlagenansicht.'=>'The submitted request does not belong to this asset view.',
        'Diese Angaben gehören nicht zum ursprünglichen gespeicherten Prüfstand. Öffnen Sie ihn ausdrücklich erneut.'=>'These details do not match the original saved review. Explicitly open that review again.',
        'Die Bewertungsmethode erlaubt diesen Jahresstand nicht. Prüfen Sie das ganze ursprüngliche Jahr.'=>'The valuation method does not permit this annual period. Check the whole originally requested year.',
        'Der ursprünglich angeforderte Prüfstand ist derzeit nicht verfügbar.'=>'The originally requested review is currently unavailable.',
        'Die eingetragenen Angaben werden hier nur gelesen. Eine Jahresbestätigung wird nicht ausgeführt.'=>'These details are only used to open a view. No annual confirmation is performed.',
        'Ursprünglichen Prüfstand ausdrücklich öffnen'=>'Explicitly open the original review',
        'Anlagenplan ohne bisherigen Prüfstand prüfen'=>'Check the asset plan without the previous review',
        'Vorheriger Vorgang'=>'Previous operation',
        'Ungespeicherte Angaben des fehlgeschlagenen Vorgangs werden nicht übernommen. Lesen Sie sie bei Bedarf unter den ursprünglichen Angaben.'=>'Unsaved details from the failed operation are not applied. You can read them under the original submitted details.',
        'Jahr prüfen.'=>'Check the year.',
        'Gegenstand nicht verfügbar.'=>'Item unavailable.',
        'Prüfstand auswählen.'=>'Select a review.',
        'Prüfstand nicht verfügbar.'=>'Review unavailable.',
    ];
    public static function key(string $source):string{return 'asset_book_source_'.substr(hash('sha256',$source),0,24);}
    public static function messages(string $locale):array{$english=explode('-',$locale,2)[0]==='en';$out=[];foreach(self::ENGLISH as$source=>$translation)$out[self::key($source)]=$english?$translation:$source;return $out;}
    public static function text(string $source,array $messages):string{return $messages[self::key($source)]??FinanceTaxMessages::text($source,$messages);}
}
