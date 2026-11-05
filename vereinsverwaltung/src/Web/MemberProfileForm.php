<?php declare(strict_types=1);
namespace Kulturhus\Verein\Web;

/** Preserve declared profile inputs and validate their shape before any model coercion. */
final class MemberProfileForm
{
    public const FIELDS=['id','version','kind','name','email','address','phone','birthday','notes','legacy_member_number','honorary','custom_fields','custom_fields_version','create_as','number','status','family','family_name','expected_family','start_precision','start_year','start','access_mail','create_request'];
    private const REQUIRED=['id','version','kind','name','email','address','phone','birthday','notes','legacy_member_number','custom_fields_version'];
    public static function original(array $body):array{return array_intersect_key($body,array_flip(self::FIELDS));}
    public static function target(array $body,array $query):string
    {
        foreach(self::FIELDS as $field){
            if($field==='custom_fields')continue;
            if(array_key_exists($field,$body)&&!is_string($body[$field]))throw new \DomainException('Ursprüngliche Profilangaben vollständig prüfen. Diese Eingabeform wird nicht übernommen.',422);
        }
        if(array_key_exists('custom_fields',$body)){
            if(!is_array($body['custom_fields']))throw new \DomainException('Ursprüngliche Profilangaben vollständig prüfen. Diese Eingabeform wird nicht übernommen.',422);
            foreach($body['custom_fields'] as $value)if(!is_string($value))throw new \DomainException('Ursprüngliche Profilangaben vollständig prüfen. Diese Eingabeform wird nicht übernommen.',422);
        }
        foreach(['honorary','access_mail'] as $flag)if(isset($body[$flag])&&!in_array($body[$flag],['0','1'],true))throw new \DomainException('Ursprüngliche Profilangaben vollständig prüfen. Diese Eingabeform wird nicht übernommen.',422);
        if(isset($body['custom_fields_version'])&&(!preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D',$body['custom_fields_version'])||(int)$body['custom_fields_version']>2147483647))throw new \DomainException('Ursprüngliche Profilkennung und Fassung vollständig prüfen.',422);
        foreach(self::REQUIRED as $field)if(!array_key_exists($field,$body))throw new \DomainException('Ursprüngliche Profilangaben vollständig prüfen. Diese Eingabeform wird nicht übernommen.',422);
        $id=$body['id']??null;$version=$body['version']??null;
        if(!is_string($id)||strlen($id)>150||preg_match('//u',$id)!==1||preg_match('/[\x00-\x1f\x7f]/',$id)||!is_string($version)||!preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D',$version)||(int)$version>2147483647)throw new \DomainException('Ursprüngliche Profilkennung und Fassung vollständig prüfen.',422);
        if(($query['view']??null)!=='members')throw new \DomainException('Profilangaben am ursprünglich geöffneten Profil erneut prüfen.',409);
        if($id===''){
            if(($query['new']??null)!=='1'||isset($query['id']))throw new \DomainException('Profilangaben am ursprünglich geöffneten Profil erneut prüfen.',409);
            $createKey=MemberProfileCreation::key($body['create_request']??null);
            if(array_key_exists('create_request',$query)&&($query['create_request']!==$createKey))throw new \DomainException('Profilangaben am ursprünglich geöffneten Profil erneut prüfen.',409);
            if($version!=='0'||!in_array($body['create_as']??null,['contact','member'],true))throw new \DomainException('Anlageart und ursprüngliche neue Profilfassung prüfen.',422);
        }elseif(!is_string($query['id']??null)||$query['id']!==$id||isset($query['new']))throw new \DomainException('Profilangaben am ursprünglich geöffneten Profil erneut prüfen.',409);
        return $id;
    }
    public static function completeCustom(array $body,array $definition):void
    {
        foreach($definition['rows'] as $row)if(!is_array($body['custom_fields']??null)||!array_key_exists($row['id'],$body['custom_fields']))throw new \DomainException('Ursprüngliche Profilangaben vollständig prüfen. Diese Eingabeform wird nicht übernommen.',422);
    }
    public static function blocked(array $body,array $query,?string $opened=null,?array $definition=null):bool
    {
        if(($body['action']??null)!=='save')return false;
        try{$target=self::target($body,$query);if($definition!==null)self::completeCustom($body,$definition);return $opened!==null&&$target!==$opened;}catch(\DomainException){return true;}
    }
}
