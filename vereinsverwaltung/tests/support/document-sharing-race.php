<?php declare(strict_types=1);
/** Two independent owned MySQL clients prove original/grant row locking in both orders. */
if(PHP_SAPI!=='cli')exit(1);
require_once dirname(__DIR__,2).'/native/bootstrap.php';require_once dirname(__DIR__,2).'/src/bootstrap.php';
if(($argv[1]??'')==='--child'){
 $cfg=\Kulturhus\Native\TestSupport::config();if(($cfg['label']??'')!=='native-document-sharing'||($cfg['owner']??'')!=='isolated-native-ci')throw new RuntimeException('Owned sharing race required.');
 $input=json_decode(file_get_contents($argv[2]),true,16,JSON_THROW_ON_ERROR);$db=\Kulturhus\Native\TestSupport::db();
 final class OwnShareRaceStatement extends PDOStatement{
  protected function __construct(){}public static string $ready='';public static int $connection=0;
  public function execute(?array $params=null):bool{if(str_contains($this->queryString,'SELECT d.*,f.name')&&str_contains($this->queryString,'FOR UPDATE'))file_put_contents(self::$ready,(string)self::$connection);return parent::execute($params);}
 }
 OwnShareRaceStatement::$ready=$input['ready'];OwnShareRaceStatement::$connection=(int)$db->query('SELECT CONNECTION_ID()')->fetchColumn();$db->setAttribute(PDO::ATTR_STATEMENT_CLASS,[OwnShareRaceStatement::class,[]]);
 try{
  if($input['kind']==='grant-first')(new \Kulturhus\Verein\Documents($db,'own-list-admin'))->newVersion($input['id'],1,'Own racing second.pdf',base64_decode($input['bytes'],true),'Own independent later revision');
  else(new \Kulturhus\Verein\PortalDocuments($db,'own-list-admin'))->share($input['id'],$input['person'],true,1,1);
  $result=['accepted'=>true];
 }catch(DomainException $error){$result=['accepted'=>false,'exception'=>get_class($error)];}
 echo json_encode($result,JSON_THROW_ON_ERROR);exit;
}
function ownDocumentSharingRace(PDO $db,string $kind,string $id,string $person,string $bytes):array{
 $root=dirname(getenv('KH_NATIVE_TEST_CONFIG')).'/sharing-race-'.bin2hex(random_bytes(6));mkdir($root,0700);$input=['kind'=>$kind,'id'=>$id,'person'=>$person,'bytes'=>base64_encode($bytes),'ready'=>$root.'/ready'];$file=$root.'/input.json';file_put_contents($file,json_encode($input,JSON_THROW_ON_ERROR));chmod($file,0600);$process=null;$pipes=[];
 $db->beginTransaction();
 try{
  $docs=new \Kulturhus\Verein\Documents($db,'own-list-admin');$docs->document($id,true);
  $process=proc_open([...\Kulturhus\Native\TestSupport::phpCommand(),__FILE__,'--child',$file],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($process))throw new RuntimeException('Actual sharing child required.');fclose($pipes[0]);$blocked=false;
  for($i=0;$i<400;$i++){
   if(is_file($input['ready'])){$connection=(int)file_get_contents($input['ready']);foreach($db->query('SHOW FULL PROCESSLIST')->fetchAll(PDO::FETCH_ASSOC)as$row)if((int)$row['Id']===$connection&&str_contains((string)$row['Info'],'SELECT d.*,f.name')&&str_contains((string)$row['Info'],'FOR UPDATE')){$blocked=true;break;}}
   if($blocked)break;usleep(20000);
  }
  if(!$blocked)throw new RuntimeException('Independent writer did not reach the actual locked original query.');
  if($kind==='grant-first')(new \Kulturhus\Verein\PortalDocuments($db,'own-list-admin'))->share($id,$person,true,1,1);
  else $docs->newVersion($id,1,'Own racing second.pdf',$bytes,'Own consciously committed original revision');
  $db->commit();$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);$process=null;if($exit!==0||$err!=='')throw new RuntimeException('Actual sharing child failed: '.substr($err,0,900));return ['blocked'=>$blocked,'result'=>json_decode($out,true,16,JSON_THROW_ON_ERROR)];
 }finally{if($db->inTransaction())$db->rollBack();if(is_resource($process)){proc_terminate($process);foreach($pipes as$pipe)if(is_resource($pipe))fclose($pipe);proc_close($process);}foreach([$file,$input['ready']]as$path)if(is_file($path))unlink($path);rmdir($root);}
}
