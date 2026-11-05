<?php declare(strict_types=1);
ini_set('zend.exception_ignore_args','1');require dirname(__DIR__,2).'/native/bootstrap.php';require dirname(__DIR__,2).'/src/bootstrap.php';
use Kulturhus\Native\TestSupport;
$config=TestSupport::config();if(($config['owner']??'')!=='isolated-native-ci'||($config['label']??'')!=='native-goods-correction-interface')throw new RuntimeException('Owned correction fixture required.');
$input=json_decode((string)fgets(STDIN),true,64,JSON_THROW_ON_ERROR);$operation=$input['operation']??null;
if(!in_array($operation,['hold','correction','issue','donor'],true)||!preg_match('/\A[a-f0-9]{32}\z/D',$input['source']??''))throw new RuntimeException('Owned source operation required.');
$actor=$input['actor']??'own-list-admin';if(!in_array($actor,['own-list-admin','own-outgoing-electronic-finance'],true))throw new RuntimeException('Owned correction actor required.');
if(!empty($input['hold'])){$directory=$input['directory']??'';if(!is_string($directory)||is_link($directory)||realpath(dirname($directory))!==realpath(dirname((string)getenv('KH_NATIVE_TEST_CONFIG')))||!preg_match('/\Agoods-correction-race-[a-f0-9]{16}\z/D',basename($directory))||!is_dir($directory))throw new RuntimeException('Owned source barrier required.');}
final class OwnGoodsCorrectionPdo extends PDO
{
 public bool $armed;
 public function __construct(array $config,public array $barrier){$this->armed=!empty($barrier['hold']);parent::__construct('mysql:host=127.0.0.1;port=13371;dbname='.$config['database'].';charset=utf8mb4',$config['username'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);$this->exec("SET time_zone='+00:00'");$this->setAttribute(PDO::ATTR_STATEMENT_CLASS,[OwnGoodsCorrectionStatement::class,[$this]]);}
}
final class OwnGoodsCorrectionStatement extends PDOStatement
{
 protected function __construct(private OwnGoodsCorrectionPdo $owner){}
 public function fetch(int $mode=PDO::FETCH_DEFAULT,int $cursorOrientation=PDO::FETCH_ORI_NEXT,int $cursorOffset=0):mixed
 {
  $row=parent::fetch($mode,$cursorOrientation,$cursorOffset);$barrier=$this->owner->barrier;
  if($this->owner->armed&&$this->owner->inTransaction()&&is_array($row)&&($row['id']??null)===$barrier['source']&&str_contains($this->queryString,'goods_donations')){
   $this->owner->armed=false;file_put_contents($barrier['directory'].'/first.ready',json_encode(['actual_transaction'=>true,'version'=>$row['version'],'query'=>$this->queryString],JSON_THROW_ON_ERROR));$deadline=microtime(true)+20;
   while(!is_file($barrier['directory'].'/release')){if(microtime(true)>$deadline)throw new RuntimeException('Owned source barrier timed out.');usleep(10000);}
  }
  return $row;
 }
}
if($operation==='hold'){
 $db=TestSupport::db();try{$db->beginTransaction();$q=$db->prepare('SELECT id FROM goods_donations WHERE club_id=? AND id=? FOR UPDATE NOWAIT');$q->execute(['own-list-club',$input['source']]);if(!$q->fetchColumn())throw new RuntimeException('Owned browser source missing.');echo "{\"ready\":true}\n";fflush(STDOUT);$read=[STDIN];$write=$except=null;if(stream_select($read,$write,$except,45)!==1||trim((string)fgets(STDIN))!=='release')throw new RuntimeException('Owned browser release missing.');}finally{if($db->inTransaction())$db->rollBack();}echo "{\"released\":true}\n";exit;
}
$db=new OwnGoodsCorrectionPdo($config,$input);
try{
 if($operation==='correction'){$result=(new Kulturhus\Verein\Finance($db,$actor))->correctGoods($input['source'],(int)$input['version'],$input['data'],$input['reason'],$input['hash'],$input['key']);$result=['id'=>$result['correction']['id'],'replayed'=>$result['replayed']];}
 elseif($operation==='issue')$result=['id'=>(new Kulturhus\Verein\DonationReceipts($db,$actor))->issue($input['values'],$input['key'])];
 else{(new Kulturhus\Verein\Finance($db,$actor))->assignDonationContact('goods',$input['source'],(int)$input['version'],$input['contact']);$result=[];}
 echo json_encode(['operation'=>$operation,'accepted'=>true,'transaction_open'=>$db->inTransaction(),'result'=>$result],JSON_THROW_ON_ERROR)."\n";
}catch(DomainException $failure){echo json_encode(['operation'=>$operation,'accepted'=>false,'code'=>$failure->getCode(),'message'=>$failure->getMessage(),'transaction_open'=>$db->inTransaction()],JSON_THROW_ON_ERROR)."\n";}
