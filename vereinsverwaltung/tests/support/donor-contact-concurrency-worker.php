<?php declare(strict_types=1);
ini_set('zend.exception_ignore_args','1');
require dirname(__DIR__,2).'/native/bootstrap.php';require dirname(__DIR__,2).'/src/bootstrap.php';
use Kulturhus\Native\TestSupport;
$ownedConfig=TestSupport::config();
if(($ownedConfig['owner']??'')!=='isolated-native-ci'||!in_array($ownedConfig['label']??'',['native-donor-contact-concurrency','native-donor-contact-interface'],true))throw new RuntimeException('Owned donor test required.');
$input=json_decode((string)fgets(STDIN),true,32,JSON_THROW_ON_ERROR);
if(!in_array($input['type']??null,['entry','goods'],true)||!preg_match('/\A[a-f0-9]{32}\z/D',$input['source']??''))throw new RuntimeException('Owned donation source required.');
if(!in_array($input['mode']??null,['assign','hold'],true))throw new RuntimeException('Owned worker operation required.');
$directory=$input['directory']??null;
if($directory!==null&&(!is_string($directory)||is_link($directory)||realpath(dirname($directory))!==realpath(dirname((string)getenv('KH_NATIVE_TEST_CONFIG')))||!preg_match('/\Adonor-race-[a-f0-9]{16}\z/D',basename($directory))||!is_dir($directory)))throw new RuntimeException('Owned barrier directory required.');
final class OwnDonorConcurrentPdo extends PDO
{
 public bool $armed=true;
 public function __construct(array $config,public array $barrier){parent::__construct('mysql:host=127.0.0.1;port=13371;dbname='.$config['database'].';charset=utf8mb4',$config['username'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);$this->exec("SET time_zone='+00:00'");$this->setAttribute(PDO::ATTR_STATEMENT_CLASS,[OwnDonorConcurrentStatement::class,[$this]]);}
}
final class OwnDonorConcurrentStatement extends PDOStatement
{
 protected function __construct(private OwnDonorConcurrentPdo $owner){}
 public function fetch(int $mode=PDO::FETCH_DEFAULT,int $cursorOrientation=PDO::FETCH_ORI_NEXT,int $cursorOffset=0):mixed
 {
  $row=parent::fetch($mode,$cursorOrientation,$cursorOffset);$barrier=$this->owner->barrier;
  if($this->owner->armed&&is_array($row)&&($row['id']??null)===$barrier['source']&&(str_contains($this->queryString,'goods_donations')||str_contains($this->queryString,'finance_entries'))){
   $this->owner->armed=false;file_put_contents($barrier['directory'].'/'.$barrier['tag'].'.ready',json_encode(['version'=>$row['version'],'transaction'=>$this->owner->inTransaction()],JSON_THROW_ON_ERROR));
   $deadline=microtime(true)+15;while(!is_file($barrier['directory'].'/release')){if(microtime(true)>$deadline)throw new RuntimeException('Owned source-read barrier timed out.');usleep(10000);}
  }
  return $row;
 }
}
if($input['mode']==='hold'){
 $db=TestSupport::db();try{$db->beginTransaction();$table=$input['type']==='goods'?'goods_donations':'finance_entries';$q=$db->prepare('SELECT id FROM '.$table.' WHERE club_id=? AND id=? FOR UPDATE NOWAIT');$q->execute(['own-list-club',$input['source']]);if(!$q->fetchColumn())throw new RuntimeException('Owned browser source missing.');echo "{\"ready\":true}\n";fflush(STDOUT);$read=[STDIN];$write=$except=null;if(stream_select($read,$write,$except,45)!==1||trim((string)fgets(STDIN))!=='release')throw new RuntimeException('Owned lock release missing.');}finally{if($db->inTransaction())$db->rollBack();}echo "{\"released\":true}\n";exit;
}
if($directory===null||!in_array($input['tag']??null,['a','b'],true)||!in_array($input['actor']??null,['own-donor-race-a','own-donor-race-b'],true))throw new RuntimeException('Owned assignment worker required.');
$db=new OwnDonorConcurrentPdo($ownedConfig,$input);
try{(new Kulturhus\Verein\Finance($db,$input['actor']))->assignDonationContact($input['type'],$input['source'],(int)$input['version'],$input['contact']);$result=['accepted'=>true];}
catch(DomainException $failure){$result=['accepted'=>false,'code'=>$failure->getCode(),'message'=>$failure->getMessage()];}
echo json_encode($result+['transaction_open'=>$db->inTransaction()],JSON_THROW_ON_ERROR)."\n";
