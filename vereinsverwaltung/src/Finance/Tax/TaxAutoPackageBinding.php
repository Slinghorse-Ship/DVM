<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;
/** The original ZIP itself carries the exact actor, case and reviewed source binding. */
final class TaxAutoPackageBinding
{
    public const FORMAT='dvm-tax-auto-package/1';
    public static function key(string $club,string $actor,int $year,int $version,string $sourceHash):string
    {
        if($club===''||$actor===''||$year<2000||$year>9999||$version<1||$version>2147483647||!preg_match('/\A[a-f0-9]{64}\z/D',$sourceHash))throw new DomainException('Ursprüngliches Jahr, Fassung und Prüfung vollständig auswählen.',422);
        return substr(hash('sha256',json_encode([self::FORMAT,$club,$actor,$year,$version,$sourceHash],JSON_THROW_ON_ERROR)),0,32);
    }
    public static function create(array $actor,int $year,int $version,string $sourceHash,string $bundleHash):array
    {
        return self::validate(['format'=>self::FORMAT,'club_id'=>$actor['club_id'],'actor_id'=>$actor['id'],'year'=>$year,'case_version'=>$version,'source_hash'=>$sourceHash,'reviewed_bundle_hash'=>$bundleHash]);
    }
    public static function validate(array $binding):array
    {
        if(array_keys($binding)!==['format','club_id','actor_id','year','case_version','source_hash','reviewed_bundle_hash']||$binding['format']!==self::FORMAT||!is_string($binding['club_id'])||!is_string($binding['actor_id'])||!is_int($binding['year'])||!is_int($binding['case_version'])||!is_string($binding['source_hash'])||!is_string($binding['reviewed_bundle_hash'])||!preg_match('/\A[a-f0-9]{64}\z/D',$binding['reviewed_bundle_hash']))throw new DomainException('Ursprüngliche Jahrespaketbindung vollständig prüfen.',422);
        self::key($binding['club_id'],$binding['actor_id'],$binding['year'],$binding['case_version'],$binding['source_hash']);return$binding;
    }
    public static function original(array $first,string $key):array
    {
        $binding=$first['snapshot']['tax_auto_package']??null;
        try{if(!is_array($binding))throw new DomainException('Ursprüngliche Jahrespaketbindung fehlt.');$binding=self::validate($binding);}catch(DomainException$failure){throw new DomainException('Den unveränderten ursprünglichen Auftrag des Jahrespakets prüfen lassen.',503,$failure);}
        $copy=$first['snapshot'];unset($copy['preview_hash'],$copy['tax_auto_package']);$hash=hash('sha256',json_encode($copy,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
        if(!hash_equals(self::key($binding['club_id'],$binding['actor_id'],$binding['year'],$binding['case_version'],$binding['source_hash']),$key)||$binding['club_id']!==$first['snapshot']['club']||$binding['year']!==$first['snapshot']['to']||$binding['actor_id']!==$first['document']['created_by']||!hash_equals($binding['reviewed_bundle_hash'],$hash))throw new DomainException('Den unveränderten ursprünglichen Auftrag des Jahrespakets prüfen lassen.',503);
        return$binding;
    }
}
