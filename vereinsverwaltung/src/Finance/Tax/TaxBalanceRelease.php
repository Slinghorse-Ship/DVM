<?php declare(strict_types=1);
namespace Kulturhus\Verein;

use DomainException;
require_once dirname(__DIR__).'/Balance/BalanceFilingEnvironment.php';

/** Canonical production factory: local preparation is distinct from native SDK and transport evidence. */
final class TaxBalanceRelease implements BalanceFilingRuntime
{
    private const ADAPTERS=['verein-6.9-v1'=>['taxonomy'=>'6.9','date'=>'2025-04-01','years'=>[2025,2026],'version'=>'Bilanz_6_9','stage'=>'catalog_and_local_validation']];
    private static function normal(mixed $value):mixed
    {if(!is_array($value))return $value;if(!array_is_list($value))ksort($value,SORT_STRING);foreach($value as &$child)$child=self::normal($child);unset($child);return $value;}
    public static function digest(array $value):string
    {return hash('sha256',json_encode(self::normal($value),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));}
    private static function package(int $year,string $version):array
    {
        try{
            $root=dirname(__DIR__,3).'/resources/ebilanz';$registry=json_decode((string)file_get_contents($root.'/releases.json'),true,32,JSON_THROW_ON_ERROR);
            $matches=array_values(array_filter($registry['packages']??[],static fn($p)=>is_array($p)&&($p['eric']['data_version']??null)===$version&&in_array($year,$p['years']??[],true)));
            if(($registry['schema']??null)!==1||count($matches)!==1)throw new \UnexpectedValueException();$package=$matches[0];$adapter=self::ADAPTERS[$package['adapter']??'']??null;
            if($adapter===null||($package['stage']??null)!==$adapter['stage']||($package['taxonomy']['version']??null)!==$adapter['taxonomy']||($package['taxonomy']['date']??null)!==$adapter['date']||!in_array($year,$adapter['years'],true)||$version!==$adapter['version']||($package['production_authorized']??null)!==false)throw new \UnexpectedValueException();
            $raw=str_replace("\r\n","\n",(string)file_get_contents($root.'/taxonomy-'.$adapter['taxonomy'].'.json'));$taxonomy=json_decode($raw,true,64,JSON_THROW_ON_ERROR);
            if(!hash_equals((string)$package['taxonomy']['sha256'],hash('sha256',$raw))||($taxonomy['version']??null)!==$adapter['taxonomy']||($taxonomy['date']??null)!==$adapter['date']||($taxonomy['source']??null)!==($package['taxonomy']['source']??null)||empty($taxonomy['source_files_sha256']))throw new \UnexpectedValueException();
            $versions=$package['eric']['reviewed_versions']??null;if(!is_array($versions)||!array_is_list($versions)||!$versions)throw new \UnexpectedValueException();
            foreach($versions as $row)if(!is_array($row)||array_keys($row)!==['ericapi','checkbilanz']||!preg_match('/^[0-9]+(?:\.[0-9]+){3}$/D',(string)$row['ericapi'])||!preg_match('/^[0-9]+(?:\.[0-9]+){3}$/D',(string)$row['checkbilanz']))throw new \UnexpectedValueException();
            return $package;
        }catch(\Throwable){throw new DomainException('Für dieses Jahr fehlt das geprüfte E-Bilanz-Programmpaket.');}
    }
    public static function software(int $year,string $version='Bilanz_6_9'):array
    {
        $package=self::package($year,$version);
        return ['id'=>$package['id'],'sha256'=>self::digest($package),'year'=>$year,'taxonomy'=>$package['taxonomy']['version'],'data_version'=>$version,'adapter'=>$package['adapter'],'stage'=>$package['stage']];
    }
    /** Configuration availability only. Never starts an SDK or records a proof on GET. */
    public static function availability(array $filing):array
    {
        $result=['validation_configured'=>false,'transport_configured'=>false];
        try{
            $environment=BalanceFilingEnvironment::check((string)($filing['environment']??''));$package=self::package((int)$filing['tax_year'],(string)$filing['data_version']);
            $config=TaxEricAdapter::configuration();$profile=$config['profiles'][$filing['data_version']]??[];
            $result['validation_configured']=($profile['family']??null)==='balance'&&($environment==='test'?($config['balance_test_enabled']??false)===true:(($config['test_fixture_only']??false)!==true&&($config['balance_production_enabled']??false)===true&&($profile['test_only']??false)!==true));
            $result['transport_configured']=$result['validation_configured']&&($profile['transport_enabled']??false)===true&&($environment==='test'||(($package['production_authorized']??false)===true&&($config['production_enabled']??false)===true&&in_array($filing['data_version'],$config['production_versions']??[],true)));
        }catch(DomainException){}
        return $result;
    }
    public function proof(array $filing,bool $sending=false):array
    {
        $environment=BalanceFilingEnvironment::check((string)($filing['environment']??''));$package=self::package((int)$filing['tax_year'],(string)$filing['data_version']);$binding=self::software((int)$filing['tax_year'],(string)$filing['data_version']);
        $config=TaxEricAdapter::configuration();$profile=$config['profiles'][$filing['data_version']]??[];
        if(($config['test_fixture_only']??false)===true||($config['balance_production_enabled']??false)!==true||($profile['family']??null)!=='balance'||($profile['test_only']??false)===true)throw new DomainException('Die native E-Bilanz-Prüfung für diese Umgebung ist nicht bestätigt.');
        if($sending&&(($package['production_authorized']??false)!==true||($config['production_enabled']??false)!==true||($profile['transport_enabled']??false)!==true||!in_array($filing['data_version'],$config['production_versions']??[],true)))throw new DomainException('Die produktive E-Bilanz-Übermittlung ist für dieses Jahrespaket noch nicht freigegeben.');
        $result=TaxEricAdapter::invoke(['action'=>'balance-runtime','environment'=>$environment,'data_version'=>$filing['data_version'],'tax_year'=>(int)$filing['tax_year'],'package_sha256'=>$binding['sha256']]);
        $rawVersions=$result['eric_versions']??null;
        // JSON objects and native library enumeration do not promise member order.
        $versions=is_array($rawVersions)&&count($rawVersions)===2&&isset($rawVersions['ericapi'],$rawVersions['checkbilanz'])?['ericapi'=>$rawVersions['ericapi'],'checkbilanz'=>$rawVersions['checkbilanz']]:null;
        if(($result['code']??null)!==0||($result['runtime_verified']??null)!==true||($result['sent']??null)!==false||($result['transport_attempted']??null)!==false||($result['environment']??null)!==$environment||($result['package_sha256']??null)!==$binding['sha256']||!is_array($versions)||!in_array($versions,$package['eric']['reviewed_versions'],true)||!preg_match('/^[a-f0-9]{64}$/D',(string)($result['version_sha256']??'')))throw new DomainException('Der native ERiC-Versionsnachweis passt nicht zum E-Bilanz-Jahrespaket.');
        $hashes=[];foreach(['_path','library','catalog']as$key){$hash=hash_file('sha256',$config[$key]);if(!is_string($hash))throw new DomainException('Laufzeit nicht lesbar.');$hashes[$key]=$hash;}
        return ['kind'=>'original-sdk','environment'=>$environment,'release'=>$binding,'eric_versions'=>$versions,'version_sha256'=>$result['version_sha256'],'runtime_sha256'=>self::digest($hashes)];
    }
    public function validate(array $request):array{return TaxEricAdapter::validate($request);}
    public function transmit(#[\SensitiveParameter] array $request):array{return TaxEricAdapter::transmit($request);}
}
