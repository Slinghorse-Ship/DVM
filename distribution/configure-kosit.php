<?php declare(strict_types=1);
/** Write a new private KoSIT configuration; never executes Java or imports invoices. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
ini_set('zend.exception_ignore_args','1');
require_once __DIR__.'/src/PackageStore.php';
require_once dirname(__DIR__).'/vereinsverwaltung/src/Finance/Invoices/EInvoiceValidator.php';
try{
    if($argc===2&&$argv[1]==='--help'){
        echo "KoSIT privat konfigurieren\nphp distribution/configure-kosit.php --java=/ABS/java --jar=/ABS/validator.jar --repository=/ABS/config --workdir=/PRIVAT/work --output=/PRIVAT/kosit.json\nNeue Konfiguration; bestehende Dateien bleiben erhalten. Kein Java-Aufruf, keine Rechnungsprüfung, keine Aktivierung.\n";exit;
    }
    $args=[];foreach(array_slice($argv,1)as$arg){
        if(!preg_match('/^--(java|jar|repository|workdir|output)=(.+)$/Ds',$arg,$m)||isset($args[$m[1]]))throw new DomainException('Mit --help die Optionen prüfen.');
        $args[$m[1]]=$m[2];
    }
    if(count($args)!==5)throw new DomainException('Alle fünf privaten Pfade angeben.');
    foreach($args as$value){
        if(!str_starts_with($value,'/')||preg_match('/[\x00-\x1f\x7f]/',$value)||preg_match('~(?:^|/)\.\.?(/|$)~',$value))throw new DomainException('Absolute Pfade ohne Verknüpfungen verwenden.');
        for($walk=$value;;$walk=dirname($walk)){
            if(is_link($walk))throw new DomainException('Verknüpfungen im Laufzeitpfad sind nicht erlaubt.');
            if($walk===dirname($walk))break;
        }
    }
    $parent=realpath(dirname($args['output']));
    if(!$parent||file_exists($args['output'])||($parent===dirname(__DIR__)||str_starts_with($parent,dirname(__DIR__).'/'))||(fileperms($parent)&0077)!==0)throw new DomainException('Neue Konfigurationsdatei außerhalb des Checkouts in einem privaten Ordner mit0700 wählen.');
    $c=[];foreach(['java','jar','repository','workdir']as$key){$c[$key]=realpath($args[$key]);if(!$c[$key])throw new DomainException('Laufzeitpfad fehlt.');}
    if(!is_file($c['java'])||!is_executable($c['java'])||!is_file($c['jar'])||!is_dir($c['repository'])||!is_dir($c['workdir'])||!is_writable($c['workdir'])||(fileperms($c['workdir'])&0077)!==0)throw new DomainException('Java, KoSIT, Regeln und privates Arbeitsverzeichnis prüfen.');
    $expected=null;foreach(PackageStore::json(__DIR__.'/packages/linux-runtime/manifest.json')['components']as$entry)if($entry['id']==='kosit')$expected=$entry['sha256'];
    $c['validator_sha256']=hash_file('sha256',$c['jar']);
    if(!is_string($expected)||!hash_equals($expected,$c['validator_sha256']))throw new DomainException('Nur die zum Paket gehörende geprüfte KoSIT-Version verwenden.');
    $c['scenarios']=$c['repository'].'/scenarios.xml';
    if(!is_file($c['scenarios'])||is_link($c['scenarios']))throw new DomainException('scenarios.xml fehlt.');
    $c['configuration_sha256']=\Kulturhus\Verein\EInvoiceValidator::configurationHash($c['repository']);
    $output=$parent.'/'.basename($args['output']);
    PackageStore::writeNew($output,json_encode($c,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
    echo json_encode(['configuration_written'=>true,'output'=>$output,'runtime_checked'=>false,'activated'=>false],JSON_THROW_ON_ERROR)."\n";
}catch(Throwable $error){fwrite(STDERR,($error instanceof DomainException?$error->getMessage():'KoSIT-Konfiguration nicht geschrieben; vorhandene Dateien bleiben erhalten.')."\n");exit(1);}
