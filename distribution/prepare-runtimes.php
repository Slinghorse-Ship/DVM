<?php declare(strict_types=1);
/** Offline preparation only: no installation changes, credentials, execution or transport. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('zend.exception_ignore_args','1');
require_once __DIR__.'/src/PackageStore.php';

function dvmRuntimeCopy(string $from,string $to): void {
    if(is_link($from)||!is_file($from))throw new RuntimeException('Regular package file required.');
    PackageStore::directory(dirname($to));
    $in=fopen($from,'rb');$out=fopen($to,'xb');
    if(!$in||!$out)throw new RuntimeException('New private output required.');
    try{if(stream_copy_to_stream($in,$out)!==filesize($from))throw new RuntimeException('Incomplete file copy.');}
    finally{fclose($in);fclose($out);}chmod($to,0600);
}


try {
    if($argc===2&&$argv[1]==='--help'){
        echo "Private Zusatzlaufzeiten vorbereiten (Linux x86_64)\nphp distribution/prepare-runtimes.php --target=/NEUES/PRIVATES/VERZEICHNIS --components=ocr,kosit,eric\n\nKomponenten einzeln oder kombiniert. Ziel neu und außerhalb des Checkouts/Webroots wählen.\nOffline, mit Prüfsummen und Lizenzquellen. Keine Programme werden gestartet, keine bestehende Installation verändert.\nAnschließende Einrichtung: docs/ZUSATZLAUFZEITEN.md\n";exit;
    }
    $args=[];foreach(array_slice($argv,1)as$arg){
        if(!preg_match('/^--(target|components)=(.+)$/Ds',$arg,$match)||isset($args[$match[1]]))throw new DomainException('Mit --help die Optionen prüfen.');
        $args[$match[1]]=$match[2];
    }
    if(count($args)!==2)throw new DomainException('Neues privates Ziel und Komponenten angeben.');
    $groups=explode(',',$args['components']);
    if(count($groups)!==count(array_unique($groups))||array_diff($groups,['ocr','kosit','eric']))throw new DomainException('Nur ocr,kosit,eric jeweils einmal auswählen.');
    $source=realpath(dirname(__DIR__));$raw=$args['target'];
    if(!str_starts_with($raw,'/')||preg_match('/[\x00-\x1f\x7f]/',$raw)||str_contains($raw,'\\')||preg_match('~(?:^|/)\.\.?(/|$)~',$raw)||is_link($raw)||file_exists($raw))throw new DomainException('Ein neues absolutes privates Ziel ohne Verknüpfungen wählen.');
    for($walk=$raw;;$walk=dirname($walk)){
        if(is_link($walk))throw new DomainException('Verknüpfungen im Zielpfad sind nicht erlaubt.');
        if($walk===dirname($walk))break;
    }
    $parent=realpath(dirname($raw));if(!$parent)throw new DomainException('Der private Elternordner muss bestehen.');
    $target=$parent.'/'.basename($raw);
    if($target===$source||str_starts_with($target,$source.'/'))throw new DomainException('Ziel außerhalb des Checkouts und Webroots wählen.');
    $store=new PackageStore(__DIR__);$verified=$store->verify();
    $selected=[];
    if(in_array('ocr',$groups,true))$selected=array_merge($selected,['pdf-python','ocr','ocr-models','heic','glibc-compat-2.36']);
    if(in_array('kosit',$groups,true))$selected=array_merge($selected,['java17','kosit','kosit-config','glibc-compat-2.41']);
    if(in_array('eric',$groups,true))$selected[]='eric-runtime';
    $components=[];foreach($verified['linux']['components']as$c)if(in_array($c['id'],$selected,true))$components[$c['id']]=$c;
    if(array_diff($selected,array_keys($components)))throw new RuntimeException('Selected runtime package incomplete.');
    PackageStore::directory($target);chmod($target,0700);PackageStore::directory($target.'/runtime');PackageStore::directory($target.'/.stage');
    $sourceIds=[];$artifacts=[];
    foreach($components as$c){
        $archive=$store->materialize($c,$target.'/.stage');$install=$c['install'];
        PackageStore::extract($archive,$target.'/runtime/'.PackageStore::relative($install['target']),$install['type'],(int)($install['strip_components']??0));
        unlink($archive);$sourceIds=array_merge($sourceIds,$c['source_ids']??[]);
        $artifacts[]=['id'=>$c['id'],'sha256'=>$c['sha256']];
    }
    if(in_array('kosit',$groups,true)){
        $kosit=$target.'/runtime/kosit-1.6.3-xrechnung-20260831';
        PackageStore::directory($kosit.'/java-tmp');PackageStore::directory($kosit.'/results');
        PackageStore::writeNew($kosit.'/java-private',"#!/bin/sh\nset -eu\numask 077\nruntime=\$(CDPATH= cd -- \"\$(dirname -- \"\$0\")\" && pwd)\njre=\"\$runtime/jdk-17.0.20.1+1-jre\"\nexport LD_LIBRARY_PATH=\"\$runtime/glibc-compat-2.41:\$jre/lib:\$jre/lib/server\"\nexec \"\$jre/bin/java\" \"-Djava.io.tmpdir=\$runtime/java-tmp\" \"\$@\"\n",0700);
    }
    foreach($verified['linux']['sources']as$c)if(in_array($c['id'],$sourceIds,true))foreach($c['parts']as$part){
        $relative=PackageStore::relative($part['file']);dvmRuntimeCopy(__DIR__.'/packages/linux-runtime/'.$relative,$target.'/distribution/'.$relative);
    }
    foreach(['LICENSES','provenance']as$sub){
        $base=__DIR__.'/packages/linux-runtime/'.$sub;
        foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base,FilesystemIterator::SKIP_DOTS))as$file){
            if($file->isLink())throw new RuntimeException('License package links refused.');
            if($file->isFile())dvmRuntimeCopy($file->getPathname(),$target.'/distribution/'.$sub.'/'.PackageStore::relative(substr($file->getPathname(),strlen($base)+1)));
        }
    }
    rmdir($target.'/.stage');$files=[];
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($target,FilesystemIterator::SKIP_DOTS))as$file){
        if($file->isLink()||!$file->isFile())throw new RuntimeException('Regular private runtime files required.');
        $name=substr($file->getPathname(),strlen($target)+1);
        $files[$name]=['path'=>$name,'bytes'=>$file->getSize(),'sha256'=>hash_file('sha256',$file->getPathname()),'mode'=>$file->getPerms()&0777];
    }
    ksort($files,SORT_STRING);
    $receipt=['format'=>1,'source_manifest_sha256'=>hash_file('sha256',__DIR__.'/source-files.json'),'groups'=>$groups,'artifacts'=>$artifacts,'files'=>array_values($files),'developer_documentation_included'=>false,'programs_executed'=>false,'installation_modified'=>false,'network_actions'=>0,'activated'=>false];
    PackageStore::writeNew($target.'/runtime-files.json',json_encode($receipt,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
    PackageStore::writeNew($target.'/README.txt',"DVM Zusatzlaufzeiten: ".implode(', ',$groups)."\n\nPrivat vorbereitet, nicht aktiviert. Dateiprüfsummen: runtime-files.json.\nLinux x86_64 und Ausführungsrechte erforderlich. OCR benötigt Landlock ABI6 und seccomp.\nJava/KoSIT: runtime/kosit-1.6.3-xrechnung-20260831/\nERiC: runtime/eric-44.3.6.0/lib/libericapi.so (nur bei Auswahl)\nEinrichtung, private Umgebungsvariablen und ERiC-Vertrag: docs/ZUSATZLAUFZEITEN.md im Quellpaket.\nHersteller-ID, Formularkatalog und Zertifikate separat privat einrichten.\nERiC erst nach Erfüllung der Pflichtinformationen und bestätigter Kenntnisnahme nutzen.\n");
    echo json_encode(['prepared'=>true,'groups'=>$groups,'files'=>count($files),'receipt'=>$target.'/runtime-files.json','activated'=>false,'network_actions'=>0],JSON_THROW_ON_ERROR)."\n";
} catch(Throwable $error){fwrite(STDERR,($error instanceof DomainException?$error->getMessage():'Zusatzlaufzeiten nicht vollständig vorbereitet; vorhandene Dateien bleiben erhalten.')."\n");exit(1);}
