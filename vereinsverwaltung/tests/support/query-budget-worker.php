<?php declare(strict_types=1);
require dirname(__DIR__,2).'/native/bootstrap.php';
use Kulturhus\Native\{TestSupport};
use Kulturhus\Native\Shared\{Access,Db};
use Kulturhus\Native\Cms\{Pages,QuerySize,Conflict};
if(getenv('KH_NATIVE_CI')!=='1')throw new RuntimeException('Own native CI only.');
final class OwnQueryBudgetStatement extends PDOStatement
{
    public static int $payloadReads=0;
    public static mixed $mutation=null;
    protected function __construct(){}
    public function execute(?array $parameters=null):bool
    {
        if(str_starts_with($this->queryString,'SELECT p.*,a.archived_at')){++self::$payloadReads;if(self::$mutation!==null){$change=self::$mutation;self::$mutation=null;$change($parameters);}}
        return parent::execute($parameters);
    }
}
$db=TestSupport::db();$db->setAttribute(PDO::ATTR_STATEMENT_CLASS,[OwnQueryBudgetStatement::class]);$mode=$argv[1]??'';$pages=new Pages($db,new Access($db,$mode==='rights'?'reader':'admin'));$denied=false;$rows=[];
if(in_array($mode,['version','bytes'],true))OwnQueryBudgetStatement::$mutation=static function(array $args)use($db,$mode):void{$id=$args[count($args)-2];Db::run($db,$mode==='version'?'UPDATE khcms_pages SET version=version+1 WHERE id=?':"UPDATE khcms_pages SET draft_json=CONCAT(draft_json,' ') WHERE id=?",[$id]);};
if($mode==='rights')OwnQueryBudgetStatement::$mutation=static fn()=>Db::run($db,"DELETE FROM native_grants WHERE actor_id='reader' AND module='website' AND capability='read'");
try{$rows=$pages->searchBounded([],match($mode){'oversize'=>50,'one','version','bytes','rights'=>1,'two'=>2,'eight'=>8,default=>throw new RuntimeException('Own query mode required.')});}
catch(QuerySize $error){if($mode!=='oversize')throw $error;$denied=true;}
catch(Conflict $error){if(!in_array($mode,['version','bytes'],true))throw $error;$denied=true;}
catch(DomainException $error){if($mode!=='rights')throw $error;$denied=true;}
if($mode==='oversize'&&(!$denied||OwnQueryBudgetStatement::$payloadReads!==0))throw new RuntimeException('Oversized response buffered an original payload.');
if(in_array($mode,['version','bytes','rights'],true)&&!$denied)throw new RuntimeException('Original drift or revoked authority was accepted.');
if(in_array($mode,['one','two','eight'],true)&&count($rows)!==['one'=>1,'two'=>2,'eight'=>8][$mode])throw new RuntimeException('Safe query lost selected originals.');
$encoded=$rows?json_encode($rows,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE):'';
echo json_encode(['mode'=>$mode,'rejected'=>$denied,'rows'=>count($rows),'payload_reads'=>OwnQueryBudgetStatement::$payloadReads,'encoded_bytes'=>strlen($encoded),'memory_limit'=>ini_get('memory_limit'),'peak_memory_bytes'=>memory_get_peak_usage(true)],JSON_THROW_ON_ERROR).PHP_EOL;
