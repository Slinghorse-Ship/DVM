<?php declare(strict_types=1);
require __DIR__.'/invoice-reminder-delivery.php';
$race=static function(array $input,string $hash,array $keys)use($db,$mailKey):array{
    $root=sys_get_temp_dir().'/own-reminder-race-'.bin2hex(random_bytes(8));mkdir($root,0700);$workers=[];
    try{
        foreach($keys as$key){$process=proc_open([PHP_BINARY,__DIR__.'/support/invoice-reminder-accept-worker.php'],[0=>['pipe','r'],1=>['pipe','w'],2=>['file',$root.'/worker-'.count($workers).'.log','w']],$pipes);if(!is_resource($process))throw new RuntimeException('Owned independent reminder acceptor unavailable.');$workers[]=[$process,$pipes];fwrite($pipes[0],json_encode(['dsn'=>\Kulturhus\Verein\Tests\MySqlFixture::dsn($db),'barrier'=>$root.'/go','master'=>base64_encode($mailKey),'input'=>$input,'key'=>$key,'hash'=>$hash],JSON_THROW_ON_ERROR));fclose($pipes[0]);stream_set_timeout($pipes[1],40);}
        foreach($workers as[$process,$pipes])if(trim((string)fgets($pipes[1]))!=='READY')throw new RuntimeException('Owned independent acceptor did not become ready.');
        file_put_contents($root.'/go','owned concurrent acceptance');$results=[];
        foreach($workers as[$process,$pipes]){$output=stream_get_contents($pipes[1]);fclose($pipes[1]);if(proc_close($process)!==0)throw new RuntimeException('Owned independent acceptance failed.');$results[]=json_decode(trim($output),true,16,JSON_THROW_ON_ERROR);}
        $workers=[];return $results;
    }finally{foreach($workers as[$process,$pipes]){if(is_resource($pipes[1]))fclose($pipes[1]);if(is_resource($process)){proc_terminate($process);proc_close($process);}}foreach(glob($root.'/*')as$path)unlink($path);rmdir($root);}
};
foreach(['same_key','different_keys']as$mode){
    $invoice=$newInvoice('Own actual concurrent acceptance '.$mode);$input=$requests->initial($invoice,'2026-10-22');$input['channel']='mail';$input['reason']='Own same actual reviewed financial demand '.$mode;$review=$requests->prepare($input);$firstKey=$key();$keys=[$firstKey,$mode==='same_key'?$firstKey:$key()];
    $tables=$db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);$earlier=[];foreach($tables as$table)$earlier[$table]=array_map('serialize',$db->query('SELECT * FROM `'.$table.'`')->fetchAll(PDO::FETCH_NUM));
    $results=$race($input,$review['hash'],$keys);$check(count($results)===2&&count(array_filter($results,static fn(array $result):bool=>in_array($result['status'],['accepted',409,503],true)))===2,'actual independent simultaneously released acceptors return only canonical success or explicit current conflict for '.$mode);
    $first=null;foreach($keys as$requestKey){try{$first=$requests->accept($input,$requestKey,$review['hash'],true);break;}catch(DomainException $failure){if($failure->getCode()!==409)throw $failure;}}if(!$first)throw new RuntimeException('Owned race produced no accepted first.');$successes=[$first['sha256']];$conflict=0;
    foreach($keys as$requestKey){try{$replayed=$requests->accept($input,$requestKey,$review['hash'],true);$successes[]=$replayed['sha256'];}catch(DomainException $failure){if($failure->getCode()!==409)throw $failure;$conflict++;}}
    $check(count(array_unique($successes))===1&&$conflict===($mode==='same_key'?0:1),'actual concurrent approvals yield one immutable first and a different new key cannot create a second outstanding demand for '.$mode);
    $exact=true;foreach($earlier as$table=>$rows){$current=array_map('serialize',$db->query('SELECT * FROM `'.$table.'`')->fetchAll(PDO::FETCH_NUM));$bag=array_count_values($current);foreach($rows as$row){if(($bag[$row]??0)<1){$exact=false;break;}$bag[$row]--;}$added=match($table){'documents','document_files','document_requests','document_history','native_settings_requests','native_invoice_reminder_deliveries'=>1,'audit_events'=>2,default=>0};if(count($current)!==count($rows)+$added)$exact=false;}
    $check($exact&&hash('sha256',$requests->pdf($first['key']))===$first['result']['sha256']&&hash('sha256',$requests->eml($first['key']))===$first['result']['mail_sha256'],'actual concurrent SQL acceptance preserves every whole earlier row and creates exactly one canonical PDF private first and sealed pending MIME for '.$mode);
}
echo json_encode(['suite'=>'invoice-reminder-concurrency','checks'=>$n,'external_actions'=>0,'real_smtp_connections'=>0,'independent_acceptors'=>4],JSON_THROW_ON_ERROR)."\n";
