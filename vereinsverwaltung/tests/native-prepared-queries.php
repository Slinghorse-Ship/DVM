<?php declare(strict_types=1);
require dirname(__DIR__).'/native/bootstrap.php';
require dirname(__DIR__).'/src/bootstrap.php';
use Kulturhus\Native\Shared\Db;
use Kulturhus\Verein\DocumentCatalog;

final class QueryFaultConnection extends PDO
{
    public array $prepared=[];public array $executed=[];public int $writes=0;
    public function __construct(public array $faults,public string $phase='execute',public string $state='HY000'){}
    public function prepare(string $query,array $options=[]):PDOStatement|false
    {
        $this->prepared[]=$query;
        if($this->phase==='prepare')$this->fault();
        return new QueryFaultStatement($this);
    }
    public function fault():void
    {
        $code=array_shift($this->faults)??0;if($code===0)return;
        $error=new PDOException('Controlled statement boundary');$error->errorInfo=[$this->state,$code,'Controlled statement boundary'];throw $error;
    }
}
final class QueryFaultStatement extends PDOStatement
{
    public function __construct(private QueryFaultConnection $connection){}
    public function execute(?array $params=null):bool{$this->connection->executed[]=$params;foreach($params??[]as$value){if(is_resource($value))stream_get_contents($value);elseif($value instanceof Stringable)(string)$value;}if($this->connection->phase==='execute')$this->connection->fault();$this->connection->writes++;return true;}
}
$n=0;$check=static function(bool $ok,string $label)use(&$n):void{if(!$ok)throw new RuntimeException($label);$n++;echo 'PASS '.$label.PHP_EOL;};
$params=['actual_club'=>'own-test','actual_null'=>null,'actual_number'=>17,'actual_text'=>'Own bound ä🙂'];
foreach(['native'=>[Db::class,'run'],'documents'=>[DocumentCatalog::class,'query']]as$name=>$query){
 foreach(['prepare','execute']as$phase){$db=new QueryFaultConnection([1615,0],$phase);$statement=$query($db,'INSERT INTO own_synthetic_query VALUES (:actual_club,:actual_null,:actual_number,:actual_text)',$params);$check($statement instanceof PDOStatement&&count($db->prepared)===2&&$db->writes===1&&end($db->executed)===$params,$name.' recreates only one metadata-invalidated '.$phase.' statement and retains exact typed parameters');}
 $db=new QueryFaultConnection([1615,1615,1615,0]);$exception=null;try{$query($db,'UPDATE own_synthetic_query SET value=?',[1]);}catch(PDOException $error){$exception=$error;}$check($exception!==null&&$exception->errorInfo[1]===1615&&count($db->prepared)===3&&$db->writes===0,$name.' stops after three unexecuted invalidated attempts and preserves the actual exception');
 foreach([1205,1213,2006,2013,1062,1142,9999]as$code){$db=new QueryFaultConnection([$code,0]);$exception=null;try{$query($db,'UPDATE own_synthetic_query SET value=?',[1]);}catch(PDOException $error){$exception=$error;}$check($exception!==null&&$exception->errorInfo[1]===$code&&count($db->prepared)===1&&$db->writes===0,$name.' never retries lock, connection, constraint or permission outcome '.$code);}
 $db=new QueryFaultConnection([1615,0],'execute','23000');$rejected=false;try{$query($db,'INSERT INTO own_synthetic_query VALUES (?)',[1]);}catch(PDOException){$rejected=true;}$check($rejected&&count($db->prepared)===1,$name.' cannot treat a different SQL state as an unexecuted catalog invalidation');
 $stream=fopen('php://temp','w+b');fwrite($stream,'Exact owned original stream');rewind($stream);$db=new QueryFaultConnection([1615,0]);$failed=false;try{$query($db,'INSERT INTO own_synthetic_query VALUES (?)',[$stream]);}catch(PDOException){$failed=true;}$check($failed&&count($db->prepared)===1&&$db->writes===0&&ftell($stream)===strlen('Exact owned original stream'),$name.' never replays an already consumed resource parameter');fclose($stream);
 $value=new class implements Stringable{public int $calls=0;public function __toString():string{return 'Own bound value '.++$this->calls;}};$db=new QueryFaultConnection([1615,0]);$failed=false;try{$query($db,'INSERT INTO own_synthetic_query VALUES (?)',[$value]);}catch(PDOException){$failed=true;}$check($failed&&count($db->prepared)===1&&$db->writes===0&&$value->calls===1,$name.' does not reevaluate a mutable object parameter after the statement error');
 $db=new QueryFaultConnection([0,0]);$query($db,'INSERT INTO own_synthetic_query VALUES (?)',[1]);$check(count($db->prepared)===1&&$db->writes===1,$name.' successful statement executes once without replay');
}
echo json_encode(['suite'=>'native-prepared-queries','checks'=>$n,'provider_calls'=>0,'shared_database_mutations'=>0],JSON_THROW_ON_ERROR).PHP_EOL;
