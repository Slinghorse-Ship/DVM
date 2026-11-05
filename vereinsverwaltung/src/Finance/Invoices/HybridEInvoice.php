<?php declare(strict_types=1);
namespace Kulturhus\Verein;

use DomainException;

/** Private local converter + mandatory full XML and PDF/A validation. No remote service. */
final class HybridEInvoice
{
    public static function configured():bool{return (string)getenv('VEREIN_HYBRID_RUNTIME')!=='';}

    public static function packageHash(string $directory):string
    {
        $files=[];$iterator=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory,\FilesystemIterator::SKIP_DOTS));
        foreach($iterator as $file){if($file->isLink())throw new DomainException('Hybrid-Laufzeit darf keine symbolischen Links enthalten.');$name=str_replace('\\','/',substr($file->getPathname(),strlen($directory)+1));if($file->isFile()&&!str_contains($name,'__pycache__/'))$files[$name]=hash_file('sha256',$file->getPathname());}
        ksort($files,SORT_STRING);return hash('sha256',json_encode($files,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
    }

    public static function runtime():array
    {
        $path=(string)getenv('VEREIN_HYBRID_RUNTIME');$real=$path?realpath($path):false;$config=$real&&is_file($real)?json_decode((string)file_get_contents($real),true):null;
        if(!is_array($config))throw new DomainException('Die geprüfte ZUGFeRD-Laufzeit ist noch nicht eingerichtet. Das vorhandene Rechnungs-PDF bleibt verfügbar.');
        foreach(['java','jar','python','packages','workdir','jar_sha256','packages_sha256','java_sha256','python_sha256'] as $key)if(!is_string($config[$key]??null)||$config[$key]==='')throw new DomainException('Die ZUGFeRD-Laufzeit ist unvollständig eingerichtet.');
        foreach(['java','jar','python','packages','workdir'] as $key){$v=$config[$key];if($v[0]!=='/'&&$v[0]!=='\\'&&!preg_match('~^[A-Za-z]:[/\\\\]~D',$v))$v=dirname($real).DIRECTORY_SEPARATOR.$v;$resolved=realpath($v);if(!$resolved)throw new DomainException('Eine benötigte ZUGFeRD-Laufzeitdatei fehlt.');$config[$key]=$resolved;}
        foreach(['java','jar','python'] as $key)if(!is_file($config[$key])||!hash_equals($config[$key.'_sha256'],hash_file('sha256',$config[$key])))throw new DomainException('ZUGFeRD-Laufzeitdatei wurde verändert oder fehlt.');
        if(!is_dir($config['workdir'])||!is_writable($config['workdir'])||!is_dir($config['packages'])||!hash_equals($config['packages_sha256'],self::packageHash($config['packages'])))throw new DomainException('ZUGFeRD-Pakete oder privates Arbeitsverzeichnis prüfen.');
        return $config;
    }

    private static function process(array $args,string $directory,string $log,int $seconds,?array $environment=null):int
    {
        $process=proc_open($args,[0=>['pipe','r'],1=>['file',$directory.'/'.$log.'.xml','w'],2=>['file',$directory.'/'.$log.'.err','w']],$pipes,$directory,$environment,['bypass_shell'=>true]);
        if(!is_resource($process))throw new DomainException('ZUGFeRD-Prüfung konnte nicht gestartet werden.');fclose($pipes[0]);
        try{return EInvoiceValidator::monitorProcess($process,$directory,$seconds,64000000);}finally{if(proc_get_status($process)['running'])proc_terminate($process,9);proc_close($process);}
    }

    public static function parseReport(string $report,string $expectedProfile='urn:cen.eu:en16931:2017'):array
    {
        if(strlen($report)>8000000||str_contains($report,'<!DOCTYPE')||str_contains($report,'<!ENTITY'))throw new DomainException('Unsicherer ZUGFeRD-Prüfbericht.');
        $previous=libxml_use_internal_errors(true);
        try{
            $dom=new \DOMDocument();if(!$dom->loadXML($report,LIBXML_NONET))throw new DomainException('ZUGFeRD-Prüfbericht ist nicht lesbar.');$xp=new \DOMXPath($dom);
            // Mustang also emits a real veraPDF validation result. Overall "valid"
            // alone is deliberately insufficient: it could hide PDF/A warnings.
            $pdfText=trim((string)$xp->evaluate('string(/validation/pdf/text()[1])'));
            $valid=$xp->evaluate('string(/validation/@filename)')==='invoice.pdf'
                &&$xp->query('/validation/pdf/summary[@status="valid"]')->length===1
                &&$xp->query('/validation/xml/summary[@status="valid"]')->length===1
                &&$xp->query('/validation/summary[@status="valid"]')->length===1
                &&$xp->query('//error|//fatal|//exception')->length===0
                &&preg_match('/^ValidationResult \[flavour=3b, totalAssertions=([1-9][0-9]*), assertions=\[\], isCompliant=true\]$/D',$pdfText,$matches)
                &&in_array($expectedProfile,['urn:cen.eu:en16931:2017','urn:cen.eu:en16931:2017#compliant#urn:xeinkauf.de:kosit:xrechnung_3.0'],true)
                &&$xp->evaluate('string(/validation/xml/info/profile)')===$expectedProfile;
            if(!$valid)throw new DomainException('Die ZUGFeRD- oder PDF/A-Prüfung wurde nicht bestanden. Es wird kein hybrides Rechnungsdokument gespeichert.');
            return ['state'=>'valid','pdfa'=>'3b','profile'=>$expectedProfile==='urn:cen.eu:en16931:2017'?'EN16931':'XRechnung 3.0','pdf_assertions'=>(int)$matches[1],'validator_version'=>$xp->evaluate('string(/validation/xml/info/validator/@version)')];
        }finally{libxml_clear_errors();libxml_use_internal_errors($previous);}
    }

    public static function build(string $original,string $xml,string $expectedProfile='urn:cen.eu:en16931:2017'):array
    {
        if(!str_starts_with($original,'%PDF-')||strlen($original)>15000000||strlen($xml)>1000000)throw new DomainException('Originalrechnung ist für die ZUGFeRD-Ausgabe nicht geeignet.');
        $config=self::runtime();$lock=fopen($config['workdir'].'/hybrid.lock','c');if(!$lock)throw new DomainException('ZUGFeRD-Prüfsperre fehlt.');if(!flock($lock,LOCK_EX|LOCK_NB)){fclose($lock);throw new DomainException('Eine ZUGFeRD-Erstellung läuft bereits. Bitte anschließend erneut versuchen.');}
        $dir=$config['workdir'].'/hybrid-'.bin2hex(random_bytes(12));$previousTimeLimit=(int)ini_get('max_execution_time');
        try{
            if(!mkdir($dir,0700))throw new DomainException('Privates ZUGFeRD-Arbeitsverzeichnis fehlt.');
            file_put_contents($dir.'/original.pdf',$original);file_put_contents($dir.'/invoice.xml',$xml);chmod($dir.'/original.pdf',0600);chmod($dir.'/invoice.xml',0600);
            set_time_limit(150);$environment=getenv();$environment['PYTHONPATH']=$config['packages'];$environment['PYTHONDONTWRITEBYTECODE']='1';$environment['PYTHONNOUSERSITE']='1';
            // Redirect cache lookup into this fresh private directory, so excluded
            // package __pycache__ files cannot replace the verified source files.
            $exit=self::process([$config['python'],'-B','-X','utf8','-X','pycache_prefix='.$dir.'/bytecode',dirname(__DIR__, 2).'/../tools/build-hybrid-invoice.py',$dir.'/original.pdf',$dir.'/invoice.xml',$dir.'/invoice.pdf'],$dir,'conversion',30,$environment);
            if($exit!==0||!is_file($dir.'/invoice.pdf'))throw new DomainException('Das Original-PDF konnte nicht unverändert mit den Rechnungsdaten verbunden werden. Es wurde nichts gespeichert.');
            $sha=hash_file('sha256',$dir.'/invoice.pdf');
            $exit=self::process([$config['java'],'-Xmx256m','-Dfile.encoding=UTF-8','-Dsun.stdout.encoding=UTF-8','-Dsun.stderr.encoding=UTF-8','-Djavax.xml.accessExternalDTD=','-Djavax.xml.accessExternalSchema=file,jar:file','-Djavax.xml.accessExternalStylesheet=file,jar:file','-jar',$config['jar'],'--disable-file-logging','--action','validate','--source','invoice.pdf'],$dir,'validation',90);
            if($exit!==0||!is_file($dir.'/validation.xml'))throw new DomainException('Die vollständige ZUGFeRD-Prüfung konnte nicht abgeschlossen werden.');
            $report=(string)file_get_contents($dir.'/validation.xml');$validated=self::parseReport($report,$expectedProfile);$bytes=(string)file_get_contents($dir.'/invoice.pdf');
            if(!hash_equals($sha,hash('sha256',$bytes)))throw new DomainException('Das geprüfte ZUGFeRD-Dokument wurde verändert.');
            return ['bytes'=>$bytes,'validation'=>$validated+['sha256'=>$sha,'source_sha256'=>hash('sha256',$original),'xml_sha256'=>hash('sha256',$xml),'jar_sha256'=>$config['jar_sha256'],'packages_sha256'=>$config['packages_sha256'],'report'=>$report,'checked_at'=>gmdate('c')]];
        }finally{
            set_time_limit($previousTimeLimit);
            if(is_dir($dir)){foreach(scandir($dir) as $name){$file=$dir.'/'.$name;if($name!=='.'&&$name!=='..'&&is_file($file))unlink($file);}rmdir($dir);}flock($lock,LOCK_UN);fclose($lock);
        }
    }
}
