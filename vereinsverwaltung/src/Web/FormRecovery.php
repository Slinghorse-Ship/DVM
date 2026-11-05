<?php declare(strict_types=1);
namespace Kulturhus\Verein\Web;

/** Readable recovery of submitted values; never selects a target or changes a version. */
final class FormRecovery
{
    private const ENGLISH=[
        'Deine Eingaben'=>'Your entries','Aktuell gespeichert'=>'Currently saved','Angabe'=>'Field',
        'Bitte erneut eingeben.'=>'Please enter this again.','Technische Details'=>'Technical details',
        'Gespeicherte Angaben vergleichen'=>'Compare saved details',
        'Bitte die markierten Angaben prüfen und erneut speichern.'=>'Check the highlighted entries and save again.',
        'Diese Eingabe konnte nicht übernommen werden. Öffne den Vorgang erneut und vergleiche die Angaben.'=>'These entries could not be accepted. Reopen the item and compare the details.',
        'Mit meinen Angaben weiterarbeiten'=>'Continue with my entries',
        'Aktuellen Stand öffnen'=>'Open current saved details',
        'Prüfe die Unterschiede. Erst beim Speichern werden deine Angaben übernommen.'=>'Check the differences. Your entries are applied only when you save.',
        'Aktueller Stand zum Weiterarbeiten übernommen. Bitte Angaben prüfen und speichern.'=>'Current version selected for editing. Check your entries and save.',
    ];
    public static function text(string $source,string $locale='de'):string{return $locale==='en'?(self::ENGLISH[$source]??$source):$source;}
    private static function e(string $value):string{return htmlspecialchars($value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
    private static function value(mixed $value,string $locale):string
    {
        if(!is_scalar($value)&&$value!==null)return self::e(self::text('Bitte erneut eingeben.',$locale));
        $value=(string)$value;return $value===''?'—':nl2br(self::e($value));
    }
    public static function fields(array $own,array $labels,?array $stored=null,string $locale='de'):string
    {
        $rows='';foreach($labels as $key=>$label){if(!array_key_exists($key,$own))continue;$rows.='<tr><th scope="row">'.self::e($label).'</th><td>'.self::value($own[$key],$locale).'</td>';if($stored!==null)$rows.='<td>'.self::value($stored[$key]??null,$locale).'</td>';$rows.='</tr>';}
        if($rows==='')return '';
        return '<div class="form-recovery-scroll" tabindex="0"><table class="table form-recovery-fields"><thead><tr><th>'.self::e(self::text('Angabe',$locale)).'</th><th>'.self::e(self::text('Deine Eingaben',$locale)).'</th>'.($stored!==null?'<th>'.self::e(self::text('Aktuell gespeichert',$locale)).'</th>':'').'</tr></thead><tbody>'.$rows.'</tbody></table></div>';
    }
    public static function technical(array $original,string $locale='de'):string
    {
        return '<details class="form-recovery-technical"><summary>'.self::e(self::text('Technische Details',$locale)).'</summary><pre>'.self::e(json_encode($original,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR)).'</pre></details>';
    }
}
