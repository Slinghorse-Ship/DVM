<?php declare(strict_types=1);
require_once __DIR__.'/editorial-browser-fixture.php';
$f=ownEditorialBrowserFixture($db);$settings=new \Kulturhus\Native\Language\Settings($db,$f['admin']);$config=$settings->state()['config'];$config['languages'][]=['code'=>'en','label'=>'English','enabled'=>true,'fallback'=>'de','messages'=>[]];$settings->save($config,0);
$registry=new \Kulturhus\Verein\Registry($db,$f['accounts']['admin']['actor']);
$f['person']=$registry->saveContact(null,0,'person','Betreff <&> Family 🙂','family@example.invalid','Own postal street 1');
$f['second']=$registry->saveContact(null,0,'person','Nachricht <&> Original ä','family@example.invalid','Own postal street 1');
$service=new \Kulturhus\Verein\ClubMailings($db,$f['accounts']['admin']['actor']);
$f['attachment']=(new \Kulturhus\Verein\Documents($db,$f['accounts']['admin']['actor']))->upload('own-mailing-original.txt','Own exact mailing attachment bytes ä🙂',['title'=>'Anhänge <&> Original 🙂','kind'=>'other','scope'=>'orga'],bin2hex(random_bytes(16)));
for($i=1;$i<=27;$i++){
 $id=$service->save('','Own earlier literal %_! ä🙂 '.$i,'Own earlier complete message',[''.$f['person']],bin2hex(random_bytes(16)));
 \Kulturhus\Native\Shared\Db::run($db,'UPDATE club_mailings SET created_at=? WHERE id=?',['2026-01-01T00:00:'.str_pad((string)$i,2,'0',STR_PAD_LEFT).'+00:00',$id]);if($i===1)$f['oldest']=$id;
}
\Kulturhus\Verein\DocumentCatalog::folder($db,$f['club'],['Orga',(new DateTimeImmutable('now',new DateTimeZone('Europe/Berlin')))->format('Y'),'Exporte'],'orga');
$auditFloor=(int)$db->query('SELECT COALESCE(MAX(sequence),0) FROM audit_events')->fetchColumn();
$tables=array_values(array_diff($db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN),['native_auth_sessions','native_auth_limits','native_language_preferences','club_mailings','documents','document_files','document_history','document_requests','audit_events']));$before=ownOriginalDatabaseHash($db,$tables);
$old=[];$fields=[];foreach(['club_mailings','documents','document_files','document_history','document_requests','audit_events']as$table){$fields[$table]=implode(',',array_map(static fn($name)=>'`'.$name.'`',$db->query('SHOW COLUMNS FROM `'.$table.'`')->fetchAll(PDO::FETCH_COLUMN)));$old[$table]=$db->query('SELECT '.$fields[$table].' FROM `'.$table.'`')->fetchAll(PDO::FETCH_ASSOC);}
$keys=[];foreach(['auth','private']as$name)$keys[$name]=hash_file('sha256',$f['root'].'/'.$name.'.key');
$server=ownEditorialBrowserStart($f,['mailings_interface'=>true]);$public=$f;unset($public['admin']);$input=$f['root'].'/mailings-ui-fixture.json';file_put_contents($input,json_encode($public,JSON_THROW_ON_ERROR));chmod($input,0600);
try{
 $process=proc_open(['node',dirname(__DIR__).'/mailings-interface-browser.mjs',$input],[0=>STDIN,1=>STDOUT,2=>STDERR],$pipes);
 if(!is_resource($process)||proc_close($process)!==0)throw new RuntimeException('Actual mailing interface browser failed.');
 $check(ownOriginalDatabaseHash($db,$tables)===$before,'mailing browser retains all unrelated complete business and schema tables');
 $count=0;foreach($old as$table=>$rows){$primary=$db->query("SHOW KEYS FROM `$table` WHERE Key_name='PRIMARY'")->fetchAll(PDO::FETCH_ASSOC);usort($primary,static fn($a,$b)=>$a['Seq_in_index']<=>$b['Seq_in_index']);$columns=array_column($primary,'Column_name');foreach($rows as$row){$q=$db->prepare('SELECT '.$fields[$table].' FROM `'.$table.'` WHERE '.implode(' AND ',array_map(static fn($name)=>'`'.$name.'`=?',$columns)));$q->execute(array_map(static fn($name)=>$row[$name],$columns));$check($q->fetch(PDO::FETCH_ASSOC)===$row,'complete earlier mailing or document original '.$table.' '.++$count);}}
 $result=json_decode(file_get_contents($f['root'].'/mailings-ui-result.json'),true,64,JSON_THROW_ON_ERROR);
 $documents=array_column($result['prints'],'document');$check(count($documents)===4&&count(array_unique($documents))===4,'four intentional print requests create exactly four distinct original documents');
 $newAudit=\Kulturhus\Native\Shared\Db::run($db,'SELECT * FROM audit_events WHERE sequence>? ORDER BY sequence',[$auditFloor])->fetchAll();
 $check(count($newAudit)===4&&!array_filter($newAudit,static fn($row)=>$row['club_id']!==$f['club']||$row['actor_id']!==$f['accounts']['admin']['actor']||$row['action']!=='document.generated'||!in_array($row['object_id'],$documents,true)),'only the four explicitly created PDF originals append their own generated-document audit events');
 foreach($result['prints']as$print){$original=(new \Kulturhus\Verein\Documents($db,$f['accounts']['admin']['actor']))->file($print['document']);$check(str_starts_with($original['bytes'],'%PDF-')&&hash('sha256',$original['bytes'])===$original['sha256'],'actual serial-letter original has complete authenticated PDF bytes');$check($service->printed($print['id'],$service->get($print['id'])['preview_hash'],$print['key'])===$print['document'],'explicit print outcome lookup returns the original document without regeneration');}
 foreach($result['creations']as$creation)$check($service->byRequest($creation['key'])['id']===$creation['id'],'one exact create key retains its one actual mailing after all outcome checks');
 foreach($keys as$name=>$hash)$check(hash_file('sha256',$f['root'].'/'.$name.'.key')===$hash,'mailing browser retains original '.$name.' key');
}finally{
 proc_terminate($server['process']);proc_close($server['process']);fclose($server['log']);
 $evidence=rtrim(getenv('VEREIN_TEST_RESULTS')?:sys_get_temp_dir(),DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'dvm-mailings-interface-'.gmdate('Ymd-His').'-'.getmypid();mkdir($evidence,0700,true);foreach(glob($f['root'].'/mailings-ui-*')as$file)if(is_file($file)&&basename($file)!=='mailings-ui-fixture.json')copy($file,$evidence.'/'.basename($file));copy($f['root'].'/http.log',$evidence.'/http.log');echo json_encode(['mailings_interface_evidence'=>$evidence],JSON_THROW_ON_ERROR).PHP_EOL;
}
