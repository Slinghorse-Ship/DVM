<?php declare(strict_types=1);
namespace Kulturhus\Verein {
    // Inject one source hash/readability change without modifying application files.
    function hash_file(string $algorithm,string $filename,bool $binary=false):string|false {
        $hash=\hash_file($algorithm,$filename,$binary);
        return ($GLOBALS['runtime_changed_file']??null)===$filename?\hash($algorithm,'synthetic changed source',$binary):$hash;
    }
    function is_file(string $filename):bool {
        return ($GLOBALS['runtime_missing_file']??null)===$filename?false:\is_file($filename);
    }
}
namespace {
    require __DIR__.'/../src/Finance/Tax/TaxFilings.php';
    use Kulturhus\Verein\TaxFilings;
    $dir=sys_get_temp_dir().'/kh-fingerprint-'.bin2hex(random_bytes(8));mkdir($dir,0700);
    $old=getenv('VEREIN_ERIC_RUNTIME');$n=0;
    function fingerprintCheck(bool $ok):void{if(!$ok)throw new RuntimeException('Runtime fingerprint regression');++$GLOBALS['n'];}
    try{
        $config=['version'=>1,'manufacturer_id'=>'12345','library'=>__FILE__,'scratch'=>$dir,'catalog'=>$dir.'/catalog.json','command'=>[PHP_BINARY],'profiles'=>[],'production_enabled'=>false];
        file_put_contents($dir.'/catalog.json','{}');file_put_contents($dir.'/runtime.json',json_encode($config,JSON_THROW_ON_ERROR));chmod($dir.'/runtime.json',0600);
        putenv('VEREIN_ERIC_RUNTIME='.$dir.'/runtime.json');
        $baseline=TaxFilings::runtimeHash();fingerprintCheck(strlen($baseline)===64&&$baseline===TaxFilings::runtimeHash());
        $method=explode('public static function contentHash(',explode('public static function runtimeHash():string',file_get_contents(__DIR__.'/../src/Finance/Tax/TaxFilings.php'),2)[1],2)[0];
        preg_match_all("/'([^']+\\.(?:php|py|json))'/",$method,$files);
        foreach(['Finance/Tax/DatevStatementCatalog.php','../resources/accounting/statement-groups.json','../resources/accounting/statements/2025.json','../resources/accounting/statements/2026.json','../tools/ebilanz_release.py','../resources/ebilanz/releases.json','../tools/taxonomy_purposes.py','../resources/ebilanz/taxonomy-6.10.json','../resources/accounting/purposes.json','../resources/accounting/reviews/taxonomy-6.10.json'] as $file)
            fingerprintCheck(in_array($file,$files[1],true));
        foreach(['../tools/taxonomy_sources.py','../resources/ebilanz/taxonomies.json','../resources/ebilanz/taxonomy-6.7.json','../resources/ebilanz/taxonomy-6.8.json'] as $file)
            fingerprintCheck(in_array($file,$files[1],true));
        foreach($files[1] as $file){
            $path=__DIR__.'/../src/'.$file;
            // Production builds the path from src/__DIR__, without tests/../.
            $path=realpath(__DIR__.'/../src').'/'.$file;
            $GLOBALS['runtime_changed_file']=$path;fingerprintCheck(TaxFilings::runtimeHash()!==$baseline);unset($GLOBALS['runtime_changed_file']);
            $GLOBALS['runtime_missing_file']=$path;$rejected=false;
            try{TaxFilings::runtimeHash();}catch(DomainException){$rejected=true;}
            fingerprintCheck($rejected);unset($GLOBALS['runtime_missing_file']);
        }
        fingerprintCheck(TaxFilings::runtimeHash()===$baseline);
        echo "$n runtime fingerprint checks passed; no database, transport or source-file edits\n";
    }finally{
        unset($GLOBALS['runtime_changed_file'],$GLOBALS['runtime_missing_file']);
        putenv($old===false?'VEREIN_ERIC_RUNTIME':'VEREIN_ERIC_RUNTIME='.$old);
        foreach(['runtime.json','catalog.json'] as $name)if(is_file($dir.'/'.$name))unlink($dir.'/'.$name);
        rmdir($dir);
    }
}
