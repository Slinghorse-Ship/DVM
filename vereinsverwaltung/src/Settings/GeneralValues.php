<?php declare(strict_types=1);
namespace Kulturhus\Verein\Settings;

use DomainException;
use Kulturhus\Verein\{MemberCustomFields,SettingsSchema};

/** The three public club forms count Unicode characters; other settings retain their contracts. */
final class GeneralValues
{
    public const SECTIONS=['club','salutations','fields'];
    public static function supports(string $section):bool{return in_array($section,self::SECTIONS,true);}
    public static function text(mixed $value,int $max,bool $required=false):string
    {
        if(!is_string($value)&&!is_int($value))throw new DomainException('Textfeld enthält ungültige Daten.',422);
        $text=trim(str_replace("\r\n","\n",(string)$value));
        if(preg_match('//u',$text)!==1||mb_strlen($text,'UTF-8')>$max||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/',$text)||($required&&$text===''))throw new DomainException('Pflichtfeld oder zulässige Textlänge prüfen.',422);
        return$text;
    }
    private static function closed(array $data,array $keys):void
    {
        if(array_diff(array_keys($data),$keys))throw new DomainException('Unerwartetes Einstellungsfeld.',422);
    }
    public static function formatted(mixed $value,int $max):string
    {
        $text=self::text($value,$max);preg_match_all('/\{\{([^{}]+)\}\}/u',$text,$matches);
        foreach($matches[1]as$key)if(!isset(SettingsSchema::VARIABLES[$key]))throw new DomainException('Unbekannte Variable: '.$key,422);
        $plain=preg_replace('/\{\{[^{}]+\}\}/u','',$text);
        if(str_contains($plain,'{{')||str_contains($plain,'}}'))throw new DomainException('Unvollständige Variable.',422);
        return$text;
    }
    public static function normalize(string $section,array $data):array
    {
        if(!self::supports($section))throw new DomainException('Eigenen Einstellungsbereich auswählen.',422);
        if($section==='club'){
            unset($data['cash_enabled']);$fields=SettingsSchema::fields('club');self::closed($data,array_keys($fields));$out=[];
            foreach($fields as$key=>$field){[$label,$type,$limit,$default]=$field;$value=$data[$key]??($type==='checkbox'?false:$default);
                try{
                    $value=$type==='checkbox'?SettingsSchema::bool($value):self::text($value,$limit,$field[4]??false);
                    if($value!==''&&$type==='email'&&!filter_var($value,FILTER_VALIDATE_EMAIL))throw new DomainException('E-Mail-Adresse prüfen.',422);
                    if($value!==''&&$type==='url'&&(!filter_var($value,FILTER_VALIDATE_URL)||strtolower((string)parse_url($value,PHP_URL_SCHEME))!=='https'||parse_url($value,PHP_URL_USER)||parse_url($value,PHP_URL_PASS)))throw new DomainException('HTTPS-Adresse ohne Zugangsdaten erforderlich.',422);
                    $out[$key]=$value;
                }catch(DomainException $failure){throw new DomainException($label.': '.$failure->getMessage(),422,$failure);}
            }
            return$out;
        }
        self::closed($data,$section==='salutations'?['mode','titles','rows']:['rows']);
        $out=$section==='salutations'?['mode'=>SettingsSchema::choice($data['mode']??'',['personal','formal']),'titles'=>self::text($data['titles']??'',2000),'rows'=>[]]:['rows'=>[]];$names=[];$ids=[];
        foreach(SettingsSchema::rows($data['rows']??[],$section==='salutations'?30:50)as$row){
            self::closed($row,$section==='salutations'?['name','personal','formal']:['id','name','type','options','required']);
            $name=self::text($row['name']??'',$section==='salutations'?60:100);
            if($name===''){
                foreach($section==='salutations'?['personal','formal']:['options']as$key)if(self::text($row[$key]??'',2000)!=='')throw new DomainException('Die ausgefüllte Zeile benötigt eine Bezeichnung.',422);
                if($section==='fields'&&SettingsSchema::bool($row['required']??false))throw new DomainException('Die ausgefüllte Zeile benötigt eine Bezeichnung.',422);
                continue;
            }
            if($section==='salutations'){$out['rows'][]=['name'=>$name,'personal'=>self::formatted($row['personal']??'',300),'formal'=>self::formatted($row['formal']??'',300)];continue;}
            if(isset($names[$name]))throw new DomainException('Bezeichnung doppelt.',422);$names[$name]=true;
            $field=['name'=>$name,'type'=>SettingsSchema::choice($row['type']??'text',['text','number','date','checkbox','select']),'options'=>self::text($row['options']??'',1000),'required'=>SettingsSchema::bool($row['required']??false)];
            $id=self::text($row['id']??'',32);if($id==='')$id=array_key_exists('id',$row)?bin2hex(random_bytes(16)):MemberCustomFields::id($field);
            if(!preg_match('/\A[a-f0-9]{32}\z/D',$id)||isset($ids[$id]))throw new DomainException('Feldkennung prüfen.',422);$ids[$id]=true;$field['id']=$id;
            if($field['type']==='select'&&!MemberCustomFields::options($field))throw new DomainException('Mindestens einen Auswahlwert eintragen.',422);
            $out['rows'][]=$field;
        }
        if($section==='salutations'&&!$out['rows'])throw new DomainException('Mindestens eine Briefanrede ist erforderlich.',422);
        return$out;
    }
}
