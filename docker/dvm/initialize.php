<?php declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(2);umask(0077);
$root='/var/lib/dvm';$seed='/opt/dvm/seed';$action=$argv[1]??'initialize';
if($action==='setup-key'){
    if(is_file($root.'/installation.json')||!is_file($root.'/SETUP-KEY.txt'))throw new RuntimeException('Setup key is available only before completing installation.');
    echo file_get_contents($root.'/SETUP-KEY.txt');exit;
}
$image=json_decode(file_get_contents($seed.'/app-files.json'),true,512,JSON_THROW_ON_ERROR);
if($action==='status'){
    $installed=is_file($root.'/installation.json')?json_decode(file_get_contents($root.'/installation.json'),true,512,JSON_THROW_ON_ERROR):null;
    echo json_encode(['installed'=>$installed!==null,'image_generation'=>$image['generation']??null,'application_generation'=>$installed['package_generation']??null,'application_source'=>$installed['source']??null,'image_source'=>$image['source'],'persistent_root'=>$root,'image_replaces_application'=>false],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";exit;
}
if($action!=='initialize')throw new RuntimeException('Unknown container command.');
if(is_link($root)||!is_dir($root))throw new RuntimeException('Own persistent DVM directory required.');
$lock=fopen($root.'/.docker.lock','c+');if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('Persistent volume is busy.');
try{
    if(is_file($root.'/.docker-initialized.json')){
        foreach(['app','private','web']as$name)if(is_link($root.'/'.$name)||!is_dir($root.'/'.$name))throw new RuntimeException('Persistent installation is incomplete; inspect it without replacing its files.');
        if(is_file($root.'/installation.json')&&is_file($root.'/SETUP-KEY.txt'))unlink($root.'/SETUP-KEY.txt');
        $live=json_decode(file_get_contents($root.'/private/app-files.json'),true,512,JSON_THROW_ON_ERROR);
        if(($live['source']??null)!==$image['source'])fwrite(STDERR,"DVM: application in the volume differs from the image seed; retained unchanged. Use the package upgrade assistant for application updates.\n");
        exit;
    }
    if(array_diff(scandir($root),['.','..','.docker.lock']))throw new RuntimeException('Nonempty unrecognized volume retained. Restore or inspect it; automatic initialization refused.');
    require $seed.'/app/distribution/src/PackageStore.php';
    foreach($image['files']as$entry)PackageStore::check($seed.'/app/'.PackageStore::relative($entry['path']),$entry);
    $stage=$root.'/.docker-seed-'.bin2hex(random_bytes(8));mkdir($stage,0700);
    foreach(['app','web']as$name){mkdir($stage.'/'.$name,0700);foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($seed.'/'.$name,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::SELF_FIRST)as$file){if($file->isLink())throw new RuntimeException('Seed links refused.');$to=$stage.'/'.$name.'/'.substr($file->getPathname(),strlen($seed.'/'.$name.'/'));if($file->isDir())mkdir($to,0700);elseif(!copy($file->getPathname(),$to)||!chmod($to,$file->getPerms()&0777))throw new RuntimeException('Initial volume copy failed.');}}
    mkdir($stage.'/private',0700);copy($seed.'/app-files.json',$stage.'/private/app-files.json');chmod($stage.'/private/app-files.json',0600);
    $token=bin2hex(random_bytes(32));file_put_contents($stage.'/private/setup-token',hash('sha256',$token)."\n");file_put_contents($stage.'/private/setup-state.key',random_bytes(32));file_put_contents($stage.'/SETUP-KEY.txt',$token."\n");
    foreach(['app','private','web','SETUP-KEY.txt']as$name)if(!rename($stage.'/'.$name,$root.'/'.$name))throw new RuntimeException('Initial volume activation interrupted; original contents retained for inspection.');
    rmdir($stage);file_put_contents($root.'/.docker-initialized.json',json_encode(['format'=>1,'initial_source'=>$image['source']],JSON_THROW_ON_ERROR)."\n");
    fwrite(STDERR,"DVM initialized with a new private setup key. Read it explicitly with: docker compose exec app dvm setup-key\n");
}finally{flock($lock,LOCK_UN);fclose($lock);}
