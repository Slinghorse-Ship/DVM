<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;

/** Explicit selection from the BMF AV table; never guess an asset type from its name. */
final class AssetBookSuggestions
{
    public const SOURCE='https://www.bundesfinanzministerium.de/Content/DE/Standardartikel/Themen/Steuern/Weitere_Steuerthemen/Betriebspruefung/AfA-Tabellen/Ergaenzende-AfA-Tabellen/AfA-Tabelle_AV.html';
    public const CHECKED='2026-09-15';
    public static function rules():array
    {
        return [
            'office_furniture'=>['label'=>'Büromöbel','months'=>156,'reference'=>'6.15'],
            'audio_system'=>['label'=>'Beschallungsanlage','months'=>108,'reference'=>'6.14.5'],
            'audio_video'=>['label'=>'Foto-, Film-, Video- oder Audiogerät','months'=>84,'reference'=>'6.14.4'],
        ];
    }
    public static function confirm(array $input):array
    {
        if(($input['guided_asset']??'')!=='1')return $input;
        if(($input['asset_reviewed']??'')!=='1')throw new DomainException('Anschaffungskosten und passende Abschreibungsart zuerst prüfen und bestätigen.');
        $key=$input['life_rule']??'';
        if(!is_string($key))throw new DomainException('Nutzungsdauer prüfen.');
        if($key==='')return $input;
        $rule=self::rules()[$key]??null;
        if(!$rule)throw new DomainException('Unbekannte Abschreibungsregel.');
        if(($input['acquired_on']??'')<'2001-01-01')throw new DomainException('Für Anschaffungen vor 2001 die Nutzungsdauer gesondert prüfen.');
        if(($input['months']??'')!==''&&($input['months']??null)!==(string)$rule['months'])throw new DomainException('Nutzungsdauer weicht vom gewählten Vorschlag ab. Für eine eigene Dauer „Andere / gesondert geprüft“ wählen.');
        $input['months']=(string)$rule['months'];
        $note=$input['reason']??'';if(!is_string($note))throw new DomainException('Nachweis der Nutzungsdauer prüfen.');
        $input['reason']='BMF AfA-Tabelle AV, Nr. '.$rule['reference'].' · '.$rule['label'].' · '.intdiv($rule['months'],12).' Jahre. Nutzungsdauer und gewählte Abschreibung ausdrücklich geprüft. '.trim($note);
        return $input;
    }
}
