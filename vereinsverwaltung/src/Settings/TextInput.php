<?php declare(strict_types=1);
namespace Kulturhus\Verein\Settings;
use DomainException;
use Kulturhus\Verein\SettingsSchema;

/** Complete human requests stay separate from canonical template values. */
final class TextInput
{
    public const GROUPS=['texts_contributions'=>'Beiträge','texts_invoices'=>'Rechnungen','texts_donations'=>'Spenden'];
    public const FIELDS=['subject'=>'Betreff','message'=>'Benachrichtigungstext','intro'=>'Einleitung','closing'=>'Schlusstext','attach_pdf'=>'Dokument-PDF als Anhang vormerken'];
    public static function supports(string $section):bool{return isset(self::GROUPS[$section]);}
    public static function initial(string $section,array $state,?string $template=null):array
    {
        self::section($section);return self::capture($section,['version'=>(string)$state['version'],'template'=>$template??(string)array_key_first(SettingsSchema::templateLabels($section)),'data'=>$state['data']]);
    }
    public static function section(string $section):void
    {if(!self::supports($section))throw new DomainException('Eigene Textbausteingruppe auswählen.',422);}
    public static function template(string $section,string $template):void
    {self::section($section);if(!array_key_exists($template,SettingsSchema::templateLabels($section)))throw new DomainException('Verfügbare Textvorlage dieser Gruppe auswählen.',422);}
    public static function capture(string $section,array $input):array
    {
        self::section($section);$keys=array_keys($input);sort($keys);
        if($keys!==['data','template','version']||!is_array($input['data'])||!is_string($input['template'])||strlen($input['template'])>40000||preg_match('//u',$input['template'])!==1)throw new DomainException('Vollständige ursprüngliche Textbausteine prüfen.',422);
        $keys=array_keys($input['data']);sort($keys);$expected=array_keys(SettingsSchema::templateLabels($section));sort($expected);
        if($keys!==$expected)throw new DomainException('Vollständige ursprüngliche Textbausteine prüfen.',422);
        $expected=array_keys(self::FIELDS);sort($expected);
        foreach($input['data']as$row){
            if(!is_array($row))throw new DomainException('Vollständige ursprüngliche Textvorlagenfelder prüfen.',422);$keys=array_keys($row);sort($keys);
            if($keys!==$expected)throw new DomainException('Vollständige ursprüngliche Textvorlagenfelder prüfen.',422);
            foreach($row as$field=>$value){
                if($field==='attach_pdf'&&(is_int($value)||is_bool($value)))continue;
                if(!is_string($value)||strlen($value)>40000||preg_match('//u',$value)!==1||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$value))throw new DomainException('Ganzes ursprüngliches Textvorlagenfeld prüfen.',422);
            }
        }
        return ['version'=>(string)GeneralInput::version($input['version']),'template'=>$input['template'],'data'=>$input['data']];
    }
    public static function normalize(string $section,array $data):array
    {
        $data=self::capture($section,['version'=>'0','template'=>(string)array_key_first(SettingsSchema::templateLabels($section)),'data'=>$data])['data'];$out=[];
        foreach(SettingsSchema::templateLabels($section)as$template=>$label)foreach(self::FIELDS as$field=>$fieldLabel){
            try{
                if($field==='attach_pdf'){$out[$template][$field]=SettingsSchema::bool($data[$template][$field]);continue;}
                if($field==='subject'&&preg_match('/[\r\n]/',$data[$template][$field]))throw new DomainException('Betreff ohne Zeilenumbruch eingeben.');
                $text=GeneralValues::text($data[$template][$field],$field==='subject'?200:6000);
                $out[$template][$field]=SettingsSchema::formatted($text,40000);
            }catch(DomainException$failure){throw new DomainException($label.' · '.$fieldLabel.': '.$failure->getMessage(),422,$failure);}
        }
        return$out;
    }
    /** Preserve the old unchecked flag/omitted string-field contract only for canonical callers. */
    public static function canonical(string $section,array $data):array
    {
        self::section($section);$defaults=SettingsSchema::templates($section);
        if(array_diff(array_keys($data),array_keys($defaults)))throw new DomainException('Unerwartetes Einstellungsfeld.',422);
        $complete=[];
        foreach($defaults as$key=>$row){
            if(!is_array($data[$key]??null)||array_diff(array_keys($data[$key]),array_keys(self::FIELDS)))throw new DomainException('Textbaustein unvollständig.',422);
            foreach(self::FIELDS as$field=>$label){$value=$data[$key][$field]??($field==='attach_pdf'?false:'');if($field!=='attach_pdf'&&is_int($value))$value=(string)$value;$complete[$key][$field]=$value;}
        }
        return self::normalize($section,$complete);
    }
    public static function reset(string $section,array $input,string $template):array
    {
        $input=self::capture($section,$input);self::template($section,$template);
        $input['data'][$template]=SettingsSchema::templates($section)[$template];$input['template']=$template;return$input;
    }
}
