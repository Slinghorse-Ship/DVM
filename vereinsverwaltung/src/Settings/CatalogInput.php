<?php declare(strict_types=1);
namespace Kulturhus\Verein\Settings;
use DomainException;
use Kulturhus\Verein\Settings;
final class CatalogInput
{
    public static function id(mixed $id,bool $new=false):string
    {
        if(!is_string($id)||($id!==''&&!preg_match('/\A[a-f0-9]{32}\z/D',$id))||(!$new&&$id===''))throw new DomainException('Eigenen ursprünglichen Tarif auswählen.',422);
        return$id;
    }
    public static function capture(array $input):array
    {
        $keys=['id','kind','version','data'];
        if(array_diff(array_keys($input),$keys)||array_diff($keys,array_keys($input))||!is_string($input['kind'])||!in_array($input['kind'],CatalogValues::KINDS,true)||!is_array($input['data']))throw new DomainException('Vollständige ursprüngliche Tarifangaben prüfen.',422);
        $id=self::id($input['id'],true);$version=GeneralInput::version($input['version']);
        if(($id==='')!==($version===0))throw new DomainException('Ursprüngliche Tarifkennung und Fassung gemeinsam prüfen.',422);
        $fields=array_keys(Settings::catalogDefaults($input['kind']));$data=$input['data'];
        if(array_diff(array_keys($data),$fields)||array_diff($fields,array_keys($data)))throw new DomainException('Vollständige ursprüngliche Tariffelder prüfen.',422);
        $scalar=static function(mixed $value):void{if((!is_string($value)&&!is_int($value))||(is_string($value)&&(preg_match('//u',$value)!==1||strlen($value)>10000)))throw new DomainException('Tariffeld enthält ungültige Daten.',422);};
        foreach($data as$key=>$value){if($key==='tiers'){if(!is_array($value)||count($value)>100)throw new DomainException('Vollständige Staffelzeilen prüfen.',422);foreach($value as$row){if(!is_array($row)||array_diff(array_keys($row),['from','amount'])||array_diff(['from','amount'],array_keys($row)))throw new DomainException('Vollständige Staffelzeilen prüfen.',422);foreach($row as$cell)$scalar($cell);}}elseif($key==='active'){if(!in_array($value,[true,false,1,0,'1','0'],true))throw new DomainException('Ungültiger Schalter.',422);}else$scalar($value);}
        return['id'=>$id,'kind'=>$input['kind'],'version'=>(string)$version,'data'=>$data];
    }
}
