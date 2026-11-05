<?php declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
ini_set('zend.exception_ignore_args','1');
require dirname(__DIR__).'/vereinsverwaltung/native/bootstrap.php';
try {
    if (in_array('--help',$argv,true)) {
        echo "Native Sicherung und Wiederherstellung\n\nphp distribution/recover-native.php --config-stdin --backup|--verify|--restore [--resume]|--release|--inspect-restore-account|--restore-account-codes\n\nPrivates JSON über STDIN: archive, key_file; backup zusätzlich root, dsn, username, password; restore zusätzlich target, dsn, username, password, optional hosts; release: root, dsn, username, password, reviewed=true.\n\nKontocodes nach abgeschlossener Wiederherstellung: root, dsn, username, password, key_file, account_id; --inspect-restore-account liefert nur den aktuellen Kontostand. --restore-account-codes benötigt zusätzlich original_alias, account_version als ganze Zahl, identity_reviewed=true und request_key (32 Hexzeichen). Zehn neue Codes stehen ausschließlich in einer privaten 0600-Datei; die JSON-Antwort enthält nur deren Pfad und die Prüfmetadaten. Passwort und TOTP-Schlüssel bleiben erhalten.\n\nSicherungen sind AES-256-verschlüsselt. Schlüsseldatei: 64 Hexzeichen, Dateirechte 0600, separat vom Archiv sichern. Restore benötigt ein neues Ziel und eine leere eigene MySQL-Datenbank sowie dieselbe verifizierte Paketfassung. Laufende native Webzugriffe/Worker blockieren Sicherungen. Nach Restore bleiben Hintergrundjobs bis zur expliziten Betriebsprüfung gesperrt. Externe Schlüssel-/Laufzeitpfade und parallel direkt schreibende Altjobs benötigen eine gesonderte Sicherung beziehungsweise Stilllegung.\n";
        exit;
    }
    $operations=array_values(array_intersect(array_slice($argv,1),['--backup','--verify','--restore','--release','--inspect-restore-account','--restore-account-codes']));
    if (count($operations)!==1 || !in_array('--config-stdin',$argv,true) || array_diff(array_slice($argv,1),['--config-stdin','--resume',...$operations]) || (in_array('--resume',$argv,true) && $operations[0]!=='--restore')) throw new DomainException('Mit --help die privaten Einrichtungsangaben prüfen.');
    $raw=stream_get_contents(STDIN,65537);
    if (strlen($raw)>65536) throw new DomainException('Konfiguration zu groß.');
    $config=json_decode($raw,true,16,JSON_THROW_ON_ERROR); unset($raw);
    if (!is_array($config)) throw new DomainException('Private JSON-Konfiguration erforderlich.');
    $accountOperation=in_array($operations[0],['--inspect-restore-account','--restore-account-codes'],true);
    if ($accountOperation) {
        $allowed=['root','dsn','username','password','key_file','account_id'];
        if ($operations[0]==='--restore-account-codes') array_push($allowed,'original_alias','account_version','identity_reviewed','request_key');
        if (array_diff(array_keys($config),$allowed) || array_diff($allowed,array_keys($config))) throw new DomainException('Nur die festgelegten Angaben für das eigene Wiederherstellungskonto angeben.');
        foreach (['root','dsn','username','password','key_file','account_id'] as $name) if (!is_string($config[$name]) || $config[$name]==='' || str_contains($config[$name],"\0")) throw new DomainException('Private Konto-Wiederherstellungsangaben prüfen.');
    }
    $service=new Kulturhus\Native\Installer\Recovery(dirname(__DIR__));
    if ($operations[0]!=='--release') {
        $file=$config['key_file']??'';
        if (!is_string($file) || !str_starts_with($file,'/') || !is_file($file) || is_link($file) || (fileperms($file)&0077)) throw new DomainException('Private Sicherungsschlüsseldatei erforderlich.');
        $key=trim(file_get_contents($file));
    }
    if ($operations[0]==='--verify') {
        $manifest=$service->verify($config['archive'],$key);
        $result=['status'=>'verified','encrypted'=>true,'files'=>count($manifest['files']),'external_actions'=>0];
    } else {
        $db=new PDO($config['dsn'],$config['username'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
        $result=match($operations[0]) {
            '--backup'=>$service->create($config['root'],$db,$config['dsn'],$config['archive'],$key),
            '--restore'=>$service->restore($config['archive'],$key,$config,$db,in_array('--resume',$argv,true)),
            '--inspect-restore-account'=>(new Kulturhus\Native\Installer\RestoreAccounts(dirname(__DIR__)))->inspect($config['root'],$db,$config['dsn'],$key,$config['account_id']),
            '--restore-account-codes'=>(new Kulturhus\Native\Installer\RestoreAccounts(dirname(__DIR__)))->issue($config['root'],$db,$config['dsn'],$key,array_intersect_key($config,array_flip(['account_id','original_alias','identity_reviewed','account_version','request_key']))),
            '--release'=>(function()use($service,$config,$db){$service->release($config['root'],$db,($config['reviewed']??false)===true);return ['status'=>'reviewed','background_jobs'=>'released','external_actions'=>0];})()
        };
    }
    unset($config,$key); echo json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)."\n";
} catch (Throwable $error) {
    fwrite(STDERR,$error instanceof DomainException ? $error->getMessage()."\n" : "Native Wiederherstellung nicht abgeschlossen. Private Konfiguration und Archiv prüfen.\n"); exit(1);
}
