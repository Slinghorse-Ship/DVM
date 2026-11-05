<?php declare(strict_types=1);
require dirname(__DIR__).'/native/bootstrap.php';require dirname(__DIR__).'/src/bootstrap.php';
use Kulturhus\Native\TestSupport;use Kulturhus\Native\Pos\Register;
if(PHP_SAPI!=='cli'||getenv('KH_NATIVE_CI')!=='1'||(TestSupport::config()['label']??'')!=='native-pos')throw new RuntimeException('Owned isolated POS regression required.');
final class PosRaceStatement extends PDOStatement
{
    protected function __construct(private string $probe){}
    public function execute(?array $params=null):bool
    {
        if(str_contains($this->queryString,'FROM pos_shifts')&&str_contains($this->queryString,"state='open'")&&str_contains($this->queryString,'ORDER BY id FOR SHARE'))file_put_contents($this->probe,'before-current-shift-read');
        return parent::execute($params);
    }
}
$data=json_decode(stream_get_contents(STDIN),true,32,JSON_THROW_ON_ERROR);$db=TestSupport::db();$mixedClose=$data['operation']==='mixed-close';$mixedOpen=$data['operation']==='mixed-open';if($mixedOpen)$db->setAttribute(PDO::ATTR_STATEMENT_CLASS,[PosRaceStatement::class,[$data['probe']]]);$register=new Register($db,'cashier');if($mixedClose){$db->beginTransaction();$register->shift($data['shift'],true);}file_put_contents($data['ready'],'ready');$deadline=microtime(true)+30;while(!is_file($data['start'])){if(microtime(true)>$deadline)throw new RuntimeException('Own register race gate timed out.');usleep(1000);}
try{
    if($mixedClose)while(!is_file($data['probe'])){if(microtime(true)>$deadline)throw new RuntimeException('Own open did not reach the current shift read.');usleep(1000);}
    $result=in_array($data['operation'],['open','mixed-open'],true)?$register->open($data['event'],$data['account'],0,$data['station']):$register->close($data['shift'],$data['counted']);if($mixedClose)$db->commit();echo json_encode(['ok'=>true,'result'=>$result],JSON_THROW_ON_ERROR);
}catch(DomainException $e){if($mixedClose&&$db->inTransaction())$db->rollBack();echo json_encode(['ok'=>false,'message'=>$e->getMessage()],JSON_THROW_ON_ERROR);}
