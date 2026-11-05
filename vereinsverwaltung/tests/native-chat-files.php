<?php declare(strict_types=1);
ini_set('zend.exception_ignore_args','1');require dirname(__DIR__).'/native/bootstrap.php';
require __DIR__.'/support/original-database-hash.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\{Access,Db};
use Kulturhus\Native\Chat\{Workspace,Attachments};
$db=TestSupport::db();Kulturhus\Native\Installer\SchemaRegistry::install($db);TestSupport::seed($db);
$cfg=TestSupport::config();$root=dirname(getenv('KH_NATIVE_TEST_CONFIG')).'/chat-files-'.$cfg['database'];mkdir($root,0700);
$n=0;$check=static function(bool $ok,string $label)use(&$n){if(!$ok)throw new RuntimeException($label);$n++;echo'PASS '.$label."\n";};
$deny=static function(callable $f,string $label)use($check){try{$f();}catch(DomainException){$check(true,$label);return;}throw new RuntimeException($label);};
try{
    file_put_contents($root.'/source.txt',"Own exact human file ä🙂\n");file_put_contents($root.'/second.txt',"Own separate original text\n");file_put_contents($root.'/bad.bin',"\x00\x01\x02\xff\xfe");
    $oldChat=new Workspace($db,new Access($db,'admin'));$oldThread=$oldChat->create('Earlier unrelated original files',['editor']);$oldStore=new Attachments($db,new Access($db,'admin'),$root.'/private');$old=$oldStore->send($oldThread['id'],'Earlier human original',bin2hex(random_bytes(16)),$root.'/source.txt','Earlier original.txt');
    $oldRows=$db->query('SELECT * FROM native_chat_files')->fetchAll();$oldFile=$oldStore->file($oldThread['id'],(int)$old['seq']);$oldBytes=hash_file('sha256',$oldFile['path']);
    $db->exec('DROP TABLE native_chat_file_extras');Db::run($db,"UPDATE native_schema_versions SET version=2 WHERE module='chat'");
    Kulturhus\Native\Installer\SchemaRegistry::install($db);
    $check((int)$db->query('SELECT COUNT(*) FROM native_chat_file_extras')->fetchColumn()===0&&$db->query('SELECT * FROM native_chat_files')->fetchAll()===$oldRows&&hash_file('sha256',$oldFile['path'])===$oldBytes,'actual Chat 2 to 3 upgrade adds only an empty table and retains every earlier single-file row and private byte');
    $tables=$db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);$keys=[];foreach($tables as$table){$columns=Db::run($db,"SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME='PRIMARY' ORDER BY ORDINAL_POSITION",[$table])->fetchAll(PDO::FETCH_COLUMN);$values=$db->query('SELECT `'.implode('`,`',$columns).'` FROM `'.$table.'`')->fetchAll(PDO::FETCH_NUM);$keys[$table]=compact('columns','values');}
    $filter=static function(string $table)use($keys){$k=$keys[$table];if(!$k['values'])return[' WHERE 0=1',[]];$args=[];foreach($k['values']as$row)array_push($args,...$row);return[' WHERE (`'.implode('`,`',$k['columns']).'`) IN ('.implode(',',array_fill(0,count($k['values']),'('.implode(',',array_fill(0,count($k['columns']),'?')).')')).')',$args];};$before=ownOriginalDatabaseHash($db,$tables,$filter);
    foreach(['own-chat-files-admin','own-chat-files-peer']as$actor){Db::run($db,'INSERT INTO native_users(id,club_id,name) VALUES(?,?,?)',[$actor,'own-chat-files-club',$actor]);foreach(['read','edit','manage']as$cap)Db::run($db,'INSERT INTO native_grants VALUES(?,?,?)',[$actor,'chat',$cap]);}
    $access=new Access($db,'own-chat-files-admin');$peer=new Access($db,'own-chat-files-peer');$chat=new Workspace($db,$access);$thread=$chat->create('Own multiple human files',[$peer->user()['id']]);$original=(new Workspace($db,$peer))->send($thread['id'],'Own original reply target',bin2hex(random_bytes(16)));$store=new Attachments($db,$access,$root.'/private');
    $image=imagecreatetruecolor(16,8);imagefill($image,0,0,imagecolorallocate($image,21,115,65));imagepng($image,$root.'/source.png');imagedestroy($image);
    $files=[['source'=>$root.'/source.txt','name'=>'First human.txt'],['source'=>$root.'/source.png','name'=>'Actual pixels.HEIC'],['source'=>$root.'/second.txt','name'=>'Second human.txt']];$key=bin2hex(random_bytes(16));$message=$store->sendMany($thread['id'],'Actual human multiple files ä🙂',$key,$files,(int)$original['seq']);$seq=(int)$message['seq'];$rows=$chat->messages($thread['id']);$row=$rows[array_key_last($rows)];
    $check(count($row['attachments'])===3&&$row['body']==='Actual human multiple files ä🙂'&&(int)$row['reply_to']===(int)$original['seq'],'three ordered attachments share one human message, one exact reply origin and one retry identity');
    $check(array_column($row['attachments'],'name')===['First human.txt','Actual pixels.png','Second human.txt'],'real MIME and pixel data determine each filename rather than claimed Apple extension');
    $physical=[];for($i=1;$i<=3;$i++){$file=$store->file($thread['id'],$seq,$i);$physical[$i]=[$file['path'],hash_file('sha256',$file['path'])];$check($file['sha256']===$physical[$i][1]&&$file['bytes']===filesize($file['path']),'ordered private original '.$i.' has exact size and independent hash proof');}
    $check(file_get_contents($physical[1][0])===file_get_contents($root.'/source.txt')&&file_get_contents($physical[3][0])===file_get_contents($root.'/second.txt'),'non-image human originals remain byte-identical independent downloads');
    $directory=dirname($physical[1][0]);$inventory=glob($directory.'/*.bin');$snapshot=$chat->messages($thread['id']);
    $check($store->sendMany($thread['id'],'Actual human multiple files ä🙂',$key,$files,(int)$original['seq'])['duplicate']===true&&glob($directory.'/*.bin')===$inventory&&$chat->messages($thread['id'])===$snapshot,'real repeat retains exactly one original group without additional files, messages or notices');
    $deny(fn()=>$store->sendMany($thread['id'],'Actual human multiple files ä🙂',$key,array_reverse($files),(int)$original['seq']),'changed file order cannot reuse an accepted original send key');
    $deny(fn()=>$store->sendMany($thread['id'],'Changed human text',$key,$files,(int)$original['seq']),'changed human body cannot adopt an earlier file group');
    $deny(fn()=>$store->sendMany($thread['id'],'Partial files',bin2hex(random_bytes(16)),[$files[0],['source'=>$root.'/bad.bin','name'=>'Bad binary.bin']]),'invalid later file rejects the entire group before creating any message');
    $check(glob($directory.'/*.bin')===$inventory&&$chat->messages($thread['id'])===$snapshot,'every rejected group removes only its staged copies and preserves previous whole originals');
    foreach([0,4,21]as$i)$deny(fn()=>$store->file($thread['id'],$seq,$i),'invalid or missing ordinal cannot expose a different original file');
    $deny(fn()=>(new Attachments($db,new Access($db,'outsider'),$root.'/private'))->file($thread['id'],$seq,2),'foreign club cannot obtain a known second attachment');
    $other=$chat->create('Own different conversation',[$peer->user()['id']]);$deny(fn()=>$store->file($other['id'],$seq,2),'known message sequence cannot be moved to another thread');
    $twenty=[];for($i=0;$i<20;$i++)$twenty[]=['source'=>$root.'/source.txt','name'=>'Own selection '.$i.'.txt'];$twentyMessage=$store->sendMany($thread['id'],'Actual twenty selected files',bin2hex(random_bytes(16)),$twenty);$last=$chat->messages($thread['id']);$check(count($last[array_key_last($last)]['attachments'])===20,'ordinary twenty-file selection remains one atomic accepted original');
    $deny(fn()=>$store->sendMany($thread['id'],'Too many',bin2hex(random_bytes(16)),[...$twenty,$files[0]]),'oversized selection is rejected before staging a partial group');
    $deny(fn()=>Attachments::uploads(['error'=>[0],'tmp_name'=>[],'name'=>['Fake.txt']]),'malformed PHP parallel file arrays cannot discard an upload silently');
    $deny(fn()=>Attachments::uploads(['error'=>0,'tmp_name'=>$root.'/source.txt','name'=>'Fake.txt']),'HTTP adapter cannot adopt a local file that was never uploaded');
    $check(Attachments::uploads(['error'=>UPLOAD_ERR_NO_FILE,'tmp_name'=>'','name'=>''])===[],'empty original single-file form remains a normal human text message');
    file_put_contents($root.'/source.pdf',"%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n");
    $pdfGroup=$store->sendMany($thread['id'],'Own independent PDF and text',bin2hex(random_bytes(16)),[$files[0],['source'=>$root.'/source.pdf','name'=>'Own original PDF.pdf']]);$pdf=$store->file($thread['id'],(int)$pdfGroup['seq'],2);
    $check($pdf['mime']==='application/pdf'&&file_get_contents($pdf['path'])===file_get_contents($root.'/source.pdf'),'passive PDF and independent text remain separate byte-exact group originals');
    $input=['actor'=>$access->user()['id'],'thread'=>$thread['id'],'root'=>$root.'/private','body'=>'Own concurrent original file group','key'=>bin2hex(random_bytes(16)),'files'=>$files];$children=[];
    for($i=0;$i<2;$i++){$process=proc_open([...TestSupport::phpCommand(),__DIR__.'/support/chat-file-worker.php'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fwrite($pipes[0],json_encode($input,JSON_THROW_ON_ERROR));fclose($pipes[0]);$children[]=[$process,$pipes];}
    $concurrent=[];foreach($children as[$process,$pipes]){$result=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if(proc_close($process)!==0||$error!=='')throw new RuntimeException('Own concurrent file process failed.');$concurrent[]=json_decode($result,true,8,JSON_THROW_ON_ERROR);}
    $check($concurrent[0]['seq']===$concurrent[1]['seq']&&count(array_filter($concurrent,static fn($row)=>$row['duplicate']))===1&&(int)Db::run($db,'SELECT COUNT(*) FROM native_chat_messages WHERE request_key=?',[$input['key']])->fetchColumn()===1,'two actual parallel PHP workers create one whole original group and return one exact duplicate');
    // Accepted COMMIT followed by a real lost acknowledgement must retain all files.
    $fault=new class($cfg)extends PDO{
        public function __construct(array $cfg){parent::__construct('mysql:host=127.0.0.1;port=13371;dbname='.$cfg['database'].';charset=utf8mb4',$cfg['username'],$cfg['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);}
        public function commit():bool{parent::commit();throw new PDOException('Own accepted file COMMIT acknowledgement lost');}
    };
    $lostKey=bin2hex(random_bytes(16));try{(new Attachments($fault,new Access($fault,$access->user()['id']),$root.'/private'))->sendMany($thread['id'],'Own accepted human file group',$lostKey,$files);throw new RuntimeException('Own commit loss missing.');}catch(PDOException){}
    $accepted=Db::run($db,'SELECT seq FROM native_chat_messages WHERE request_key=?',[$lostKey])->fetchColumn();for($i=1;$i<=3;$i++)$check(is_file($store->file($thread['id'],(int)$accepted,$i)['path']),'accepted lost COMMIT retains exact original group file '.$i);
    $inventory=glob($directory.'/*.bin');$check($store->sendMany($thread['id'],'Own accepted human file group',$lostKey,$files)['duplicate']===true&&glob($directory.'/*.bin')===$inventory,'real accepted loss resumes the same original group without a second send or byte deletion');
    $singleKey=bin2hex(random_bytes(16));try{(new Attachments($fault,new Access($fault,$access->user()['id']),$root.'/private'))->send($thread['id'],'Own accepted single file',$singleKey,$root.'/source.txt','Own old-compatible original.txt');}catch(PDOException){}
    $check($store->send($thread['id'],'Own accepted single file',$singleKey,$root.'/source.txt','Own old-compatible original.txt')['duplicate']===true,'existing single-file send contract also survives a lost accepted COMMIT');
    $rollback=new class($cfg)extends PDO{
        public function __construct(array $cfg){parent::__construct('mysql:host=127.0.0.1;port=13371;dbname='.$cfg['database'].';charset=utf8mb4',$cfg['username'],$cfg['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);}
        public function commit():bool{throw new PDOException('Own definite file transaction failure before COMMIT');}
    };
    $inventory=glob($directory.'/*.bin');$rejectedKey=bin2hex(random_bytes(16));try{(new Attachments($rollback,new Access($rollback,$access->user()['id']),$root.'/private'))->sendMany($thread['id'],'Own rolled-back human file group',$rejectedKey,$files);}catch(PDOException){}
    $check(!Db::run($db,'SELECT 1 FROM native_chat_messages WHERE request_key=?',[$rejectedKey])->fetchColumn()&&glob($directory.'/*.bin')===$inventory,'real failed pre-COMMIT transaction rolls back all original rows and removes only its unaccepted staged bytes');
    // Genuine JPEG orientation 6, not a repeated implementation assertion.
    $image=imagecreatetruecolor(16,8);imagefill($image,0,0,imagecolorallocate($image,220,20,15));imagefilledrectangle($image,8,0,15,7,imagecolorallocate($image,15,20,220));ob_start();imagejpeg($image,null,100);$jpeg=ob_get_clean();imagedestroy($image);
    $exif="Exif\0\0".'II'.pack('vV',42,8).pack('v',1).pack('vvV',0x112,3,1).pack('v',6)."\0\0".pack('V',0);file_put_contents($root.'/oriented.jpg',substr($jpeg,0,2)."\xff\xe1".pack('n',strlen($exif)+2).$exif.substr($jpeg,2));
    $oriented=$store->send($thread['id'],'Own actual camera original',bin2hex(random_bytes(16)),$root.'/oriented.jpg','Own camera.jpg');$output=$store->file($thread['id'],(int)$oriented['seq']);$decoded=imagecreatefromjpeg($output['path']);$top=imagecolorsforindex($decoded,imagecolorat($decoded,4,2));$bottom=imagecolorsforindex($decoded,imagecolorat($decoded,4,13));
    $check(imagesx($decoded)===8&&imagesy($decoded)===16&&$top['red']>180&&$bottom['blue']>180,'real small camera JPEG is upright with independently decoded top and bottom colors');imagedestroy($decoded);
    $check(ownOriginalDatabaseHash($db,$tables,$filter)===$before&&hash_file('sha256',$oldFile['path'])===$oldBytes,'all earlier complete database originals and unrelated private file bytes remain exact');
    if(getenv('DVM_CHAT_FILES_BROWSER')==='1'){
        require __DIR__.'/support/chat-catalogue-browser-fixture.php';$browser=ownChatCatalogueBrowser($db);
        copy($root.'/source.txt',$browser['root'].'/source.txt');copy($root.'/source.png',$browser['root'].'/source.png');copy($root.'/bad.bin',$browser['root'].'/bad.bin');
        foreach(['heic','dng']as$extension)copy(__DIR__.'/fixtures/photo-import/own-camera.'.$extension,$browser['root'].'/own-camera.'.$extension);
        $large=imagecreatetruecolor(3200,2400);imagefill($large,0,0,imagecolorallocate($large,16,120,68));imagejpeg($large,$browser['root'].'/large-phone.jpg',95);imagedestroy($large);
        try{
            $process=proc_open(['node',__DIR__.'/chat-files-browser.mjs'],[0=>['pipe','r'],1=>['pipe','w']],$pipes);fwrite($pipes[0],json_encode($browser['input'],JSON_THROW_ON_ERROR));fclose($pipes[0]);while(($line=fgets($pipes[1]))!==false)echo$line;fclose($pipes[1]);if(proc_close($process)!==0)throw new RuntimeException('Actual chat attachment browser failed.');
            foreach($browser['input']['accounts']as$mode=>$flows)foreach($flows as$flow=>$account){
                $messages=Db::run($db,'SELECT seq,body,request_key FROM native_chat_messages WHERE club_id=? AND thread_id=? AND actor_id=? AND seq>? ORDER BY seq',[$account['club'],$account['thread'],$account['actor'],$account['source_seq']])->fetchAll();
                $check(count($messages)===1,$mode.'/'.$flow.' actual upload and retry create exactly one original human message');
                $sources=Db::run($db,'SELECT * FROM native_chat_file_extras WHERE club_id=? AND thread_id=? AND message_seq=? ORDER BY ordinal',[$account['club'],$account['thread'],$messages[0]['seq']])->fetchAll();
                $check(count($sources)===($flow==='js'?4:1),$mode.'/'.$flow.' actual original contains its complete ordered multipart selection');
                $fileRoot=$mode==='native'?dirname(getenv('KH_NATIVE_TEST_CONFIG')).'/runtime-'.$cfg['database'].'/chat-files':$browser['root'].'/chat-files';$originalStore=new Attachments($db,new Access($db,$account['actor']),$fileRoot);
                $text=$originalStore->file($account['thread'],(int)$messages[0]['seq'],$flow==='js'?5:2);
                $check(file_get_contents($text['path'])===file_get_contents($root.'/source.txt'),$mode.'/'.$flow.' actual download retains the independent exact original text bytes');
                if($flow==='js'){for($i=1;$i<=4;$i++)$check(in_array($originalStore->file($account['thread'],(int)$messages[0]['seq'],$i)['mime'],['image/jpeg','image/png'],true),$mode.' real raster, HEIC and RAW multipart file '.$i.' has independently detected image MIME');}
            }
        }finally{proc_terminate($browser['process']);proc_close($browser['process']);fclose($browser['log']);}
        $check(ownOriginalDatabaseHash($db,$tables,$filter)===$before&&hash_file('sha256',$oldFile['path'])===$oldBytes,'actual four browser upload workflows preserve all earlier complete unrelated originals and private bytes');
    }
    echo json_encode(['suite'=>'native-chat-files','checks'=>$n,'external_actions'=>0],JSON_THROW_ON_ERROR)."\n";
}finally{foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST)as$f)$f->isDir()&&!$f->isLink()?rmdir($f->getPathname()):unlink($f->getPathname());rmdir($root);}
