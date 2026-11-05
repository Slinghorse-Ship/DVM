<?php declare(strict_types=1);
namespace Kulturhus\Native\Routing;
use InvalidArgumentException;
use Kulturhus\Native\Web\Html;
/** Compact ordinary controls; all options compile to the same finite server contract. */
final class View
{
    public static function render(array $template, ?callable $interfaceText=null):string
    {
        $t=$interfaceText??static fn(string $source):string=>$source;
        $p=Policy::normalize($template['url_policy']??[]);$enabled=array_key_exists('url_policy',$template)&&$p!==Policy::DEFAULTS;
        $h=('<details class="template-url-settings"><summary>' . Html::e($t('Adressen und Seitenwechsel')) . '</summary>').Html::hidden('url_policy_present','1').self::check('url_policy_enabled',$t('Eigene Adressregeln verwenden'),$enabled,interfaceText:$t);
        $h.=Html::select('url_slash',$t('Abschließender Schrägstrich'),['inherit'=>$t('Website-Vorgabe übernehmen'),'always'=>$t('Mit Schrägstrich'),'never'=>$t('Ohne Schrägstrich')],$p['slash']).Html::select('url_https',$t('Sichere Verbindung'),['inherit'=>$t('Website-Vorgabe übernehmen'),'https'=>$t('HTTPS verlangen')],$p['https']).Html::select('url_pagination',$t('Seitenwechsel'),['query'=>$t('Über Abfrageparameter'),'path'=>$t('Als Pfad'),'off'=>$t('Keine weiteren Seiten')],$p['pagination']['mode']).Html::input('url_page_marker',$t('Pfadwort für den Seitenwechsel'),$p['pagination']['marker'],'text','maxlength="80"').Html::input('url_page_maximum',$t('Höchste Seitennummer'),$p['pagination']['maximum'],'number','min="1" max="83334"');
        for($i=0;$i<Policy::MAX_SEGMENTS;++$i){$r=$p['segments'][$i]??[];$n='url_segments['.$i.']';$h.='<details><summary>Segment '.($i+1).(isset($r['name'])?' · '.Html::e($r['name']):' ' . Html::e($t('hinzufügen'))).'</summary>'.Html::input($n.'[name]','Name',$r['name']??'','text','maxlength="32"').Html::select($n.'[type]',$t('Zulässiger Inhalt'),['slug'=>$t('Kurzes Pfadwort'),'integer'=>$t('Ganze Zahl'),'date'=>$t('Datum'),'choice'=>$t('Feste Auswahl')],$r['type']??'slug').self::check($n.'[required]',$t('Bei ergänzten Pfaden erforderlich'),$r['required']??false,interfaceText:$t).Html::select($n.'[use]',$t('Verwendung'),['context'=>$t('Als URL-Kontext bereitstellen'),'event-year'=>$t('Programm nach Jahr filtern'),'event-query'=>$t('Programm durchsuchen'),'event-archive'=>$t('Programm oder Archiv auswählen'),'event-sort'=>$t('Programm sortieren')],$r['use']??'context').Html::input($n.'[minimum]',$t('Kleinste Zahl'),$r['minimum']??'','number','min="0" max="2147483647"').Html::input($n.'[maximum]',$t('Größte Zahl'),$r['maximum']??'','number','min="0" max="2147483647"').('<label>' . Html::e($t('Auswahlwerte, je Zeile ein Pfadwort')) . '<textarea class="form-control" name="').Html::e($n.'[values]').'" rows="3" maxlength="3240">'.Html::e(implode("\n",$r['values']??[])).'</textarea></label></details>';}
        return$h.'</details>';
    }
    public static function submitted(array $post):array
    {
        if(!array_key_exists('url_policy_present',$post))return[];
        if($post['url_policy_present']!=='1')throw new InvalidArgumentException('Adressregeln prüfen.');
        if(!self::checked($post,'url_policy_enabled'))return['url_policy'=>Policy::DEFAULTS];
        $rows=$post['url_segments']??[];if(!is_array($rows)||count($rows)>Policy::MAX_SEGMENTS)throw new InvalidArgumentException('Bis zu acht geordnete Adresssegmente festlegen.');$segments=[];$empty=false;
        foreach($rows as$row){if(!is_array($row)||array_diff(array_keys($row),['name','type','required','minimum','maximum','values','use']))throw new InvalidArgumentException('Adresssegment prüfen.');$name=self::text($row['name']??'');if($name===''){$empty=true;continue;}if($empty)throw new InvalidArgumentException('Adresssegmente ohne leere Zwischenzeile festlegen.');$r=['name'=>$name,'type'=>self::text($row['type']??'slug'),'required'=>self::checked($row,'required'),'use'=>self::text($row['use']??'context')];
            if($r['type']==='integer')foreach(['minimum','maximum']as$bound){$value=self::text($row[$bound]??'');if($value!=='')$r[$bound]=self::number($value,2147483647);}
            if($r['type']==='choice'){$values=trim(self::text($row['values']??''));$r['values']=$values===''?[]:preg_split('/\r\n|\r|\n/',$values);}
            $segments[]=$r;
        }
        return['url_policy'=>Policy::normalize(['segments'=>$segments,'pagination'=>['mode'=>self::text($post['url_pagination']??'query'),'marker'=>self::text($post['url_page_marker']??'page'),'maximum'=>self::number(self::text($post['url_page_maximum']??'83334'),Policy::MAX_PAGE)],'slash'=>self::text($post['url_slash']??'inherit'),'https'=>self::text($post['url_https']??'inherit')])];
    }
    private static function text(mixed $v):string{if(!is_string($v)||strlen($v)>3240||!preg_match('//u',$v))throw new InvalidArgumentException('Begrenzten Adresswert angeben.');return$v;}
    private static function number(string $v,int $max):int{if(!preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D',$v)||(int)$v>$max)throw new InvalidArgumentException('Gültige Zahl für die Adressregel angeben.');return(int)$v;}
    private static function checked(array $row,string $key):bool{if(!array_key_exists($key,$row))return false;if($row[$key]!=='1')throw new InvalidArgumentException('Adressauswahl prüfen.');return true;}
    private static function check(string $name,string $label,bool $checked, ?callable $interfaceText=null):string{
        $t=$interfaceText??static fn(string $source):string=>$source;return'<label class="check"><input class="check" type="checkbox" name="'.Html::e($name).'" value="1"'.($checked?' checked':'').'>'.Html::e($t($label)).'</label>';}
}
