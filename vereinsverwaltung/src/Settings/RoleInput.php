<?php declare(strict_types=1);
namespace Kulturhus\Verein\Settings;

use DomainException;
use Kulturhus\Verein\{AdmissionVotes,EditorialPolicy};

/** Closed native role operations retain full form values before canonical normalization. */
final class RoleInput
{
    public const OPERATIONS=['role-save','role-assign','board-grant','identity-assign','identity-superuser'];
    public const RIGHTS=['members_access','finance_access','inventory_access','loans_access','documents_access','press_access','minutes_access'];

    public static function capture(string $operation,array $input):array
    {
        if(!in_array($operation,self::OPERATIONS,true))throw new DomainException('Gültigen Rollen- oder Kontenvorgang auswählen.',422);
        $fields=match($operation){
            'role-save'=>['id','version','data'],
            'role-assign'=>['user','version','role'],
            'board-grant'=>['user','version','office','enabled'],
            'identity-assign'=>['account','version','role_version','expected_contact','role','contact','enabled'],
            'identity-superuser'=>['account','version','enabled'],
        };
        if(array_diff(array_keys($input),$fields))throw new DomainException('Ursprüngliche Rollenfelder prüfen.',422);
        $out=[];
        foreach($fields as$field){
            $value=$input[$field]??($field==='data'?[]:'');
            $out[$field]=match($field){
                'data'=>self::data($value),
                'version','role_version'=>self::versionText($value),
                'enabled'=>self::flagText($value),
                'office'=>self::office($value),
                default=>self::identifier($value,in_array($field,['id','expected_contact','contact'],true)),
            };
        }
        return$out;
    }

    public static function version(mixed $value):int{return(int)self::versionText($value);}
    public static function flag(mixed $value):bool{return self::flagText($value)==='1';}

    private static function versionText(mixed $value):string
    {
        if((!is_string($value)&&!is_int($value))||!preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D',(string)$value)||(int)$value>2147483647)throw new DomainException('Ursprüngliche ganzzahlige Fassung auswählen.',422);
        return(string)$value;
    }

    private static function flagText(mixed $value):string
    {
        if(!in_array($value,['0','1',0,1,false,true],true))throw new DomainException('Ausdrückliche Auswahl prüfen.',422);
        return in_array($value,['1',1,true],true)?'1':'0';
    }

    private static function identifier(mixed $value,bool $optional=false):string
    {
        if(!is_string($value)||preg_match('//u',$value)!==1||mb_strlen($value)>128||preg_match('/[\x00-\x1f\x7f]/',$value)||(!$optional&&$value===''))throw new DomainException('Ursprüngliches eigenes Konto, Profil oder Rolle auswählen.',422);
        return$value;
    }

    private static function office(mixed $value):string
    {
        if(!is_string($value)||!in_array(AdmissionVotes::officeName($value),['',...AdmissionVotes::OFFICES],true))throw new DomainException('Verfügbares Vorstandsamt auswählen.',422);
        return$value;
    }

    private static function right(mixed $value):string
    {
        if(!is_string($value)||!in_array($value,['none','read','write'],true))throw new DomainException('Ausdrückliche Bereichsfreigabe auswählen.',422);
        return$value;
    }

    private static function data(mixed $value):array
    {
        $fields=['name','description',...self::RIGHTS,'settings_access','website_custom','website_levels'];
        if(!is_array($value)||array_diff(array_keys($value),$fields))throw new DomainException('Vollständige ursprüngliche Rollenfelder prüfen.',422);
        $name=$value['name']??'';$description=$value['description']??'';
        RoleText::name($name);RoleText::description($description);
        $out=['name'=>(string)$name,'description'=>(string)$description];
        foreach(self::RIGHTS as$field)$out[$field]=self::right($value[$field]??'');
        $out['settings_access']=self::flagText($value['settings_access']??'0');
        $out['website_custom']=self::flagText($value['website_custom']??'0');
        $levels=$value['website_levels']??[];
        if(!is_array($levels)||array_diff(array_keys($levels),array_keys(EditorialPolicy::AREAS)))throw new DomainException('Ursprüngliche Website-Bereichsfreigaben prüfen.',422);
        $out['website_levels']=[];
        foreach(EditorialPolicy::AREAS as$area=>$label)$out['website_levels'][$area]=self::right($levels[$area]??'');
        return$out;
    }
}
