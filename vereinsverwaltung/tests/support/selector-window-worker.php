<?php declare(strict_types=1);
require dirname(__DIR__,2).'/native/bootstrap.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\{Access,Db};
use Kulturhus\Native\Cms\{ContentLists,Pages,QueryOrder,DataModel};
$db=TestSupport::db();$access=new Access($db,'admin');$settings=$db->query('SELECT @@SESSION.max_sort_length,@@SESSION.sort_buffer_size')->fetch(PDO::FETCH_NUM);$checks=0;
$mode=$argv[1]??'';
if($mode==='cells'){
 $service=new ContentLists($db,$access);
$columns=array_map(static fn(int$i):string=>'custom.long'.$i,range(1,8));
$result=$service->listing(['columns'=>$columns,'expression'=>'title starts_with "Own bounded clone " AND custom.long1 contains "TAIL"','order'=>[['field'=>'custom.long2','direction'=>'desc']]],9999,100);
if($result['total']!==25||$result['page']!==1||count($result['rows'])!==25)throw new RuntimeException('Bounded display changed exact selection.');
$bytes=0;foreach($result['rows']as$row)foreach(range(0,7)as$i){$value=$row['c'.$i];$bytes+=strlen($value);if(mb_strlen($value)!==1000||$value!==str_repeat('Ä',1000))throw new RuntimeException('Multibyte display cell was not bounded safely.');}
if($result['rows'][0]['id']!==md5('own-lister-bounded-row-0'))throw new RuntimeException('Full uncut value must still determine exact order.');
if($settings!==$db->query('SELECT @@SESSION.max_sort_length,@@SESSION.sort_buffer_size')->fetch(PDO::FETCH_NUM))throw new RuntimeException('Successful query changed the caller session.');

 $checks+=5;
}elseif($mode==='richsort'){
foreach(['single'=>[['field'=>'custom.rich1','direction'=>'desc']],'three'=>[['field'=>'custom.rich1'],['field'=>'custom.rich2'],['field'=>'custom.rich3','direction'=>'desc']]]as$kind=>$order){$selectors=[['field'=>'title','op'=>'starts_with','value'=>'Own long sort '.$kind.' ']];$expected=\Kulturhus\Native\Shared\Db::run($db,'SELECT id FROM khcms_pages WHERE slug=?',['own-long-sort-'.$kind.'-z'])->fetchColumn();$page=(new Pages($db,$access))->search($selectors,1,0,$order);if($page[0]['id']!==$expected)throw new RuntimeException('Canonical page LONGTEXT order was truncated: '.$kind);unset($page);++$checks;$page=(new Pages($db,$access))->searchBounded($selectors,1,0,$order);if($page[0]['id']!==$expected)throw new RuntimeException('Budgeted page LONGTEXT order was truncated: '.$kind);unset($page);++$checks;$list=(new ContentLists($db,$access))->listing(['columns'=>['title'],'selectors'=>$selectors,'order'=>$order],1,1);if($list['total']!==2||$list['rows'][0]['id']!==$expected)throw new RuntimeException('Window LONGTEXT order was truncated: '.$kind);++$checks;}
if($settings!==$db->query('SELECT @@SESSION.max_sort_length,@@SESSION.sort_buffer_size')->fetch(PDO::FETCH_NUM))throw new RuntimeException('Sort changed original session variables.');++$checks;

}elseif($mode==='session'){
 $compiler=new QueryOrder(new DataModel($db,$access));
 foreach([[],[['field'=>'title']],[['field'=>'id']],[['field'=>'created_at']],[['field'=>'published']],[['field'=>'nav_order']],[['field'=>'custom.scalar_amount']],[['field'=>'custom.rich_many','aggregate'=>'count']]]as$order){$compiler->compile($order);if($compiler->requiresTextBudget())throw new RuntimeException('Metadata/IDs/short title acquired a longtext session budget.');++$checks;}
 $compiler->compile([['field'=>'custom.rich1']]);if(!$compiler->requiresTextBudget())throw new RuntimeException('Valid long HTML ordering lost the exact full key budget.');++$checks;
 try{QueryOrder::run($db,'SELECT own_invalid_name FROM own_missing_table',[],true);throw new RuntimeException('Expected SQL error absent.');}catch(PDOException){}if($settings!==$db->query('SELECT @@SESSION.max_sort_length,@@SESSION.sort_buffer_size')->fetch(PDO::FETCH_NUM))throw new RuntimeException('Failed SQL changed the original caller session.');++$checks;
 class OwnFailingSortSession extends PDO{
  public string $failFor='';public bool $fired=false;
  public function exec(string $sql):int|false{if(!$this->fired&&str_starts_with($sql,'SET SESSION '.$this->failFor.'=')){$this->fired=true;throw new PDOException('Own synthetic SET rejection');}return parent::exec($sql);}
 }
 $config=TestSupport::config();foreach(['max_sort_length','sort_buffer_size']as$key){$fault=new OwnFailingSortSession('mysql:host=127.0.0.1;port=13371;dbname='.$config['database'].';charset=utf8mb4',$config['username'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);$fault->exec('SET SESSION max_sort_length=1024');$fault->exec('SET SESSION sort_buffer_size=262144');$original=$fault->query('SELECT @@SESSION.max_sort_length,@@SESSION.sort_buffer_size')->fetch(PDO::FETCH_NUM);$fault->failFor=$key;try{QueryOrder::run($fault,'SELECT 1',[],true);throw new RuntimeException('Synthetic SET rejection was bypassed.');}catch(PDOException$error){if($error->getMessage()!=='Own synthetic SET rejection')throw$error;}if(!$fault->fired||$original!==$fault->query('SELECT @@SESSION.max_sort_length,@@SESSION.sort_buffer_size')->fetch(PDO::FETCH_NUM))throw new RuntimeException('Rejected SET did not restore already changed original session values.');++$checks;unset($fault);}
}else throw new RuntimeException('Unknown own test mode.');
if($settings!==$db->query('SELECT @@SESSION.max_sort_length,@@SESSION.sort_buffer_size')->fetch(PDO::FETCH_NUM))throw new RuntimeException('Original session was changed.');++$checks;
echo json_encode(['mode'=>$mode,'checks'=>$checks,'memory_limit'=>ini_get('memory_limit'),'peak_memory_bytes'=>memory_get_peak_usage(true),'original_session'=>'exact','external_actions'=>0],JSON_THROW_ON_ERROR).PHP_EOL;
