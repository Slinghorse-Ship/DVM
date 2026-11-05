<?php declare(strict_types=1);
/** Build an upload package, or operate on an existing installation; secrets only on stdin. */
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
ini_set('zend.exception_ignore_args','1');
require dirname(__DIR__).'/vereinsverwaltung/native/bootstrap.php';
require __DIR__.'/src/PackageStore.php';
try {
    if (in_array('--help',$argv,true)) { echo "Upgradepaket: php distribution/upgrade-native.php --build=/absolutes/neues/paket [--with-ocr]\nBestehende Installation: php distribution/upgrade-native.php --config-stdin [--resume|--rollback|--status]\nJSON auf stdin: target, token; ältere CLI-Installation zusätzlich previous_source.\nprivate/upgrade-package und web/upgrade getrennt hochladen. Keine alten privaten Dateien ersetzen.\n"; exit; }
    $build=null; foreach (array_slice($argv,1) as $argument) if (str_starts_with($argument,'--build=')) $build=substr($argument,8);
    if ($build!==null) {
        if (array_diff(array_slice($argv,1),['--build='.$build,'--with-ocr'])) throw new DomainException('Upgradepaket-Aufruf mit --help prüfen.');
        $result=Kulturhus\Native\Installer\UpgradeBundle::build(dirname(__DIR__),$build,in_array('--with-ocr',$argv,true));
    } else {
        if (!in_array('--config-stdin',$argv,true) || array_diff(array_slice($argv,1),['--config-stdin','--resume','--rollback','--status']) || count(array_intersect($argv,['--resume','--rollback','--status']))>1) throw new DomainException('Privaten Upgrade-Aufruf mit --help prüfen.');
        $raw=stream_get_contents(STDIN,65537); if (strlen($raw)>65536) throw new DomainException('Upgradeangaben zu groß.');
        $config=json_decode($raw,true,16,JSON_THROW_ON_ERROR); unset($raw);
        if (!is_string($config['target']??null) || !is_string($config['token']??null)) throw new DomainException('Privates Ziel und Upgradeschlüssel erforderlich.');
        $root=realpath($config['target'])?:throw new DomainException('Installiertes Ziel fehlt.'); $package=$root.'/private/upgrade-package';
        $permit=Kulturhus\Native\Installer\UpgradePermit::authenticate($root,$package,$config['token']);
        $upgrade=new Kulturhus\Native\Installer\Upgrade($root,$package);
        if (in_array('--status',$argv,true)) $result=$upgrade->status($permit);
        else {
            Kulturhus\Native\Installer\UpgradeFiles::privateFile($root.'/private/environment.json');
            $environment=PackageStore::json($root.'/private/environment.json');
            $db=new PDO($environment['VEREIN_DATABASE'],$environment['VEREIN_DATABASE_USER'],$environment['VEREIN_DATABASE_PASSWORD'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
            $result=in_array('--rollback',$argv,true)?$upgrade->rollback($db,$permit):(in_array('--resume',$argv,true)?$upgrade->status($permit):$upgrade->begin($db,$permit,$config['previous_source']??null));
            while (!$result['complete']) $result=$upgrade->step($db,$permit);
        }
        unset($config,$environment);
    }
    echo json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)."\n";
} catch (Throwable $e) {
    fwrite(STDERR,$e instanceof DomainException?$e->getMessage()."\n":"Upgrade unterbrochen. Privates Paket und installierte Laufzeit prüfen; dann --resume oder --rollback verwenden.\n"); exit(1);
}
