<?php declare(strict_types=1);
/** The shared distribution entry point installs only the native CMS and manager. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
ini_set('zend.exception_ignore_args','1');
if(in_array('--verify',$argv,true)){
    try{
        if(count($argv)!==2)throw new DomainException('Paketprüfung nur mit --verify aufrufen.');
        require_once __DIR__.'/src/PackageStore.php';
        $result=(new PackageStore(__DIR__))->verify();
        echo json_encode(['status'=>'verified','files'=>$result['files'],'bytes'=>$result['bytes']],JSON_THROW_ON_ERROR).PHP_EOL;
    }catch(Throwable $error){fwrite(STDERR,'Paketprüfung nicht abgeschlossen: '.$error->getMessage().PHP_EOL);exit(1);}
    exit;
}
if(in_array('--help',$argv,true)){
    echo "DVM offline einrichten\n\nphp distribution/install.php --verify\nphp distribution/install.php --config-stdin [--resume]\n\nEigener PHP-/MySQL-Betrieb mit einer leeren MySQL-Datenbank und neuem privaten Ziel.\nKonfiguration: target, dsn, username, password, club_id, club_name, hosts, admin_email, admin_password.\nPHP-Webspace ohne Hosting-Shell: php distribution/build-webspace.php --target=/ABS/NEUES-PAKET\nKeine Downloads bei der Installation; Zugangsdaten ausschließlich privat über STDIN.\n";
    exit;
}
require __DIR__.'/install-native.php';
