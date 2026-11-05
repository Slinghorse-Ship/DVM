<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use PDO;
use DomainException;

/** Non-executable merge fields and exact, permission-checked attachment revisions. */
final class MailingContent
{
    public const BUILT_INS=['NAME','ANREDE','EMAIL','ADRESSE','MITGLIEDSNUMMER'];
    public static function fields(string $text):array
    {
        if(strlen($text)>15000)throw new DomainException('Serienfelder auf 15.000 Zeichen begrenzen.');
        $fields=[];
        foreach(preg_split('/\r?\n/',$text) as $line){
            if(trim($line)==='')continue;
            [$key,$value]=array_pad(explode('=',$line,2),2,null);$key=trim($key);
            if(!preg_match('/^[A-Z][A-Z0-9_]{0,39}$/D',$key)||$value===null||isset($fields[$key])||in_array($key,self::BUILT_INS,true)||strlen($value)>1000)throw new DomainException('Serienfelder als NAME=Wert eingeben; eindeutige Großbuchstaben, Zahlen und Unterstriche verwenden. Standardfelder nicht überschreiben.');
            $fields[$key]=trim($value);
        }
        if(count($fields)>30)throw new DomainException('Höchstens 30 freie Serienfelder.');ksort($fields);return $fields;
    }
    public static function render(string $template,array $values):string
    {
        $result=preg_replace_callback('/\{\{\s*([A-Z][A-Z0-9_]*)\s*\}\}/',static function($m)use($values){if(!array_key_exists($m[1],$values)||$values[$m[1]]==='')throw new DomainException('Serienfeld fehlt oder ist leer: '.$m[1]);return (string)$values[$m[1]];},$template);
        if(str_contains($result,'{{')||str_contains($result,'}}'))throw new DomainException('Unbekanntes oder verschachteltes Serienfeld.');return $result;
    }
    public static function values(array $people,array $fields):array
    {
        $join=static fn($key)=>implode(', ',array_values(array_unique(array_filter(array_column($people,$key),fn($v)=>$v!==''))));
        return $fields+['NAME'=>$join('name'),'ANREDE'=>'Guten Tag '.$join('name').',','EMAIL'=>$join('email'),'ADRESSE'=>$join('address'),'MITGLIEDSNUMMER'=>$join('member_number')];
    }
    public static function attachments(PDO $db,string $actor,array $selected,bool $saved=false):array
    {
        if(count($selected)>10)throw new DomainException('Höchstens zehn Mailanhänge auswählen.');
        $docs=new Documents($db,$actor);$out=[];$size=0;
        foreach($selected as $selection){
            $id=$saved?($selection['id']??''):$selection;if(!is_string($id))throw new DomainException('Mailanhang prüfen.');
            $d=$docs->document($id);
            if($d['state']!=='active'||$d['admission_only']||$d['scopes']!==['orga'])throw new DomainException('Gemeinsame Mailanhänge müssen aktive Organisationsdokumente sein. Persönliche Mitglieder- und Finanzbelege über ihre eigenen Abläufe versenden.');
            $f=$docs->file($id,$saved?(int)($selection['revision']??0):(int)$d['revision']);
            if($saved&&(!isset($selection['sha256'])||!hash_equals($selection['sha256'],$f['sha256'])))throw new DomainException('Anhang geändert; Entwurf erneut prüfen.');
            $size+=strlen($f['bytes']);if($size>5000000)throw new DomainException('Mailanhänge zusammen auf 5 MB begrenzen.');
            $out[]=['id'=>$id,'revision'=>(int)$f['revision'],'sha256'=>$f['sha256'],'title'=>$d['title'],'name'=>$f['name'],'mime'=>$f['mime'],'size'=>strlen($f['bytes']),'bytes'=>$f['bytes']];
        }
        if(count(array_unique(array_column($out,'id')))!==count($out))throw new DomainException('Datei nur einmal anhängen.');return $out;
    }
    public static function snapshots(array $files):array{return array_map(static function($f){unset($f['bytes']);return $f;},$files);}
}
