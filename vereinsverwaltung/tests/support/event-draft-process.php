<?php declare(strict_types=1);
ini_set('zend.exception_ignore_args','1');
require dirname(__DIR__,2).'/native/bootstrap.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\Access;
use Kulturhus\Native\Communication\EventDraftRequests;
$input=json_decode(stream_get_contents(STDIN),true,32,JSON_THROW_ON_ERROR);$cfg=TestSupport::config();
if(($cfg['label']??'')!=='native-event-draft-requests'||($cfg['owner']??'')!=='isolated-native-ci'||!preg_match('/\A[a-f0-9]{32}\z/D',$input['gate']??'')||!in_array($input['mode']??null,['hold','normal'],true))throw new RuntimeException('Owned event-draft process required.');
final class OwnEventProcessPdo extends PDO
{
 public ?string $gate=null;
 public function commit():bool{
  if($this->gate!==null){file_put_contents($this->gate.'.waiting','owned original writer');chmod($this->gate.'.waiting',0600);$ready=false;for($i=0;$i<1000;$i++){clearstatcache(true,$this->gate.'.release');if(is_file($this->gate.'.release')){$ready=true;break;}usleep(10000);}if(!$ready)throw new RuntimeException('Owned original writer release missing.');}
  return parent::commit();
 }
}
$db=new OwnEventProcessPdo('mysql:host=127.0.0.1;port=13371;dbname='.$cfg['database'].';charset=utf8mb4',$cfg['username'],$cfg['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);$db->exec("SET time_zone='+00:00'");
if($input['mode']==='hold')$db->gate=dirname(getenv('KH_NATIVE_TEST_CONFIG')).'/event-commit-'.$input['gate'];
try{$result=(new EventDraftRequests($db,new Access($db,'admin'),''))->save($input['key'],$input['input']);echo json_encode(['saved'=>$result],JSON_THROW_ON_ERROR);}
catch(DomainException $error){echo json_encode(['busy'=>$error->getMessage()],JSON_THROW_ON_ERROR);}
