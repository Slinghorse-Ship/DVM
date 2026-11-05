<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;
use PDO;

/** Values are part of the contact transaction and its optimistic version. */
final class MemberCustomFields
{
    public static function id(array $row): string
    {
        return !empty($row['id'])?(string)$row['id']:substr(hash('sha256','legacy-field:'.($row['name']??'')),0,32);
    }
    public static function definition(PDO $db,string $club):array
    {
        $q=$db->prepare("SELECT payload,version FROM club_settings WHERE club_id=? AND section='fields'");$q->execute([$club]);$r=$q->fetch();
        $data=json_decode($r['payload']??'{}',true)?:[];$rows=[];
        foreach($data['rows']??[] as $row){$row['id']=self::id($row);$rows[]=$row;}
        return ['rows'=>$rows,'version'=>(int)($r['version']??0)];
    }
    public static function options(array $row):array
    {
        return array_values(array_unique(array_filter(array_map('trim',preg_split('/\R/u',(string)($row['options']??''))?:[]),static fn($v)=>$v!=='')));
    }
    public static function values(?string $json):array {return json_decode($json??'{}',true)?:[];}
    public static function save(PDO $db,string $club,string $contact,mixed $input,int $version):void
    {
        $definition=self::definition($db,$club);
        if($version!==$definition['version'])throw new DomainException('Die individuellen Felder wurden inzwischen geändert. Bitte die Seite neu laden und die Eingaben vergleichen.');
        if(!is_array($input))throw new DomainException('Individuelle Angaben prüfen.');
        $q=$db->prepare('SELECT custom_fields FROM contacts WHERE club_id=? AND id=?');$q->execute([$club,$contact]);$values=self::values($q->fetchColumn()?:'{}');$previousValues=$values;
        $allowed=array_column($definition['rows'],'id');if(array_diff(array_keys($input),$allowed))throw new DomainException('Ein individuelles Feld ist nicht mehr verfügbar. Bitte neu laden.');
        foreach($definition['rows'] as $row){
            $id=$row['id'];$raw=$input[$id]??'';
            if(!is_string($raw)||preg_match('//u',$raw)!==1||mb_strlen($raw,'UTF-8')>2000||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/',$raw))throw new DomainException($row['name'].': Bitte höchstens 2000 Zeichen eingeben.');
            $value=trim($raw);$valid=true;
            if($value===''){if($row['required']??false)throw new DomainException($row['name'].': Bitte ausfüllen.');}
            else $valid=match($row['type']){
                'number'=>preg_match('/^-?[0-9]{1,15}(?:[.,][0-9]{1,8})?$/D',$value)===1,
                'date'=>preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D',$value,$m)===1&&checkdate((int)$m[2],(int)$m[3],(int)$m[1]),
                'checkbox'=>in_array($value,['0','1'],true),
                'select'=>in_array($value,self::options($row),true),
                default=>true,
            };
            if(!$valid)throw new DomainException($row['name'].': Der Wert passt nicht zur aktuellen Felddefinition. Bitte prüfen und neu auswählen beziehungsweise eingeben.');
            $values[$id]=['name'=>$row['name'],'type'=>$row['type'],'value'=>$value];
        }
        if($values===$previousValues)return;
        $db->prepare('UPDATE contacts SET custom_fields=? WHERE club_id=? AND id=?')->execute([json_encode($values,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),$club,$contact]);
    }
}
