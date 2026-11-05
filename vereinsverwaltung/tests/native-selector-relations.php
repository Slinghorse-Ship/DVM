<?php declare(strict_types=1);
require dirname(__DIR__).'/native/bootstrap.php';
require __DIR__.'/support/original-database-hash.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\{Access,Db};
use Kulturhus\Native\Cms\{Schema,Pages,DataModel,Selectors,QueryOrder,SelectorGrammar,ContentLists,PageTrash};
final class OwnRelationReadStatement extends PDOStatement
{
    public static mixed $beforeCount=null;
    protected function __construct(){}
    public function execute(?array $parameters=null):bool
    {
        if((str_starts_with($this->queryString,'SELECT COUNT(*) FROM khcms_pages')||str_starts_with($this->queryString,'SELECT ranked.* FROM (SELECT '))&&self::$beforeCount!==null){$check=self::$beforeCount;self::$beforeCount=null;$check();}
        return parent::execute($parameters);
    }
}
$db=TestSupport::db();$actors=TestSupport::seed($db);Schema::install($db);$access=new Access($db,$actors['admin']);$model=new DataModel($db,$access);$pages=new Pages($db,$access);$checks=0;
function relationCheck(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);++$GLOBALS['checks'];echo 'PASS '.$label.PHP_EOL;}
function relationReject(callable $call,string $label):void{try{$call();}catch(InvalidArgumentException|DomainException){relationCheck(true,$label);return;}throw new RuntimeException($label);}
$definitions=[
 ['key'=>'amounts','type'=>'decimal','multiple'=>true],['key'=>'days','type'=>'date','multiple'=>true],['key'=>'times','type'=>'datetime','multiple'=>true],['key'=>'flags','type'=>'boolean','multiple'=>true],['key'=>'tags','type'=>'text','multiple'=>true],
 ['key'=>'choice','type'=>'select','options'=>['Global']],['key'=>'choices','type'=>'select','options'=>['Context','Other'],'multiple'=>true],['key'=>'emails','type'=>'email','multiple'=>true],['key'=>'urls','type'=>'url','multiple'=>true],
 ['key'=>'amount','type'=>'decimal'],['key'=>'moment','type'=>'datetime'],['key'=>'secret','type'=>'password'],['key'=>'target_text','type'=>'text'],['key'=>'link','type'=>'page-reference'],['key'=>'links','type'=>'pagetable'],
 ['key'=>'rows','type'=>'repeater','fields'=>['amount','amounts','link'],'max_items'=>4],['key'=>'groups','type'=>'repeater','fields'=>['rows'],'max_items'=>4],
];
$model->saveField(['key'=>'thread','label'=>'Own separate comments','type'=>'comments']);
foreach($definitions as $definition)$model->saveField(['label'=>'Own '.$definition['key'],...$definition]);
$model->saveTemplate(['key'=>'query_target','label'=>'Own query target','fields'=>['target_text','link']]);
$model->saveTemplate(['key'=>'query_source','label'=>'Own query source','fields'=>['amounts','days','times','flags','tags','moment','secret','link','links','rows','groups','choice','choices','emails','urls'],'field_contexts'=>['choice'=>['options'=>['Context']]]]);
$alpha=$pages->save(['title'=>'Alpha target','slug'=>'relation-alpha','template'=>'query_target','custom_fields'=>['target_text'=>'Exact 100%_ Ähre']]);
$beta=$pages->save(['title'=>'Beta target','slug'=>'relation-beta','template'=>'query_target','parent_id'=>$alpha['id'],'custom_fields'=>['target_text'=>'Other target','link'=>$alpha['id']]]);
$gamma=$pages->save(['title'=>'Gamma target','slug'=>'relation-gamma','template'=>'query_target','custom_fields'=>['target_text'=>'Third target','link'=>$beta['id']]]);
$one=$pages->save(['title'=>'One source','slug'=>'relation-source-one','template'=>'query_source','parent_id'=>$alpha['id'],'event_start'=>'2026-10-15T14:30','event_start_utc'=>'2026-10-15T12:30:00Z','event_end'=>'2026-10-15T14:30:00Z','custom_fields'=>[
 'choice'=>'Context','choices'=>['Context'],'emails'=>['person@example.invalid'],'urls'=>['https://example.invalid/path%_'],'amounts'=>['9007199254740993.000001','-2.000000'],'days'=>['2026-10-15','2026-11-01'],'times'=>['2026-10-15T14:30','2026-10-16T08:15'],'flags'=>[true,false],'tags'=>['100%_ Ähre','one'],'moment'=>'2026-10-15T14:30','secret'=>'Own query secret2026!','link'=>$beta['id'],'links'=>[$alpha['id'],$beta['id']],
 'rows'=>[['values'=>['amount'=>'12.500001','amounts'=>['3.000001','-4.000001'],'link'=>$gamma['id']]]],
 'groups'=>[['values'=>['rows'=>[['values'=>['amount'=>'44.000001','amounts'=>['-8.000001','7.000001'],'link'=>$alpha['id']]]]]]],
]]);
$two=$pages->save(['title'=>'Two source','slug'=>'relation-source-two','template'=>'query_source','custom_fields'=>['amounts'=>['5.000001'],'days'=>['2026-10-14'],'times'=>['2026-10-14T08:15'],'flags'=>[false],'tags'=>['other'],'moment'=>'2026-10-14T08:15','link'=>$gamma['id'],'links'=>[$gamma['id']],'rows'=>[['values'=>['amount'=>'2.000001','amounts'=>['11.000001'],'link'=>$beta['id']]]]]]);
$empty=$pages->save(['title'=>'Empty source','slug'=>'relation-source-empty','template'=>'query_source','custom_fields'=>[]]);
$alpha=$pages->publish($alpha['id'],$alpha['version']);$one=$pages->publish($one['id'],$one['version']);$before=ownOriginalDatabaseHash($db,['khcms_pages','khcms_revisions','khcms_state']);
$ids=static fn(string $source,array $order=[]):array=>array_column($pages->search([['field'=>'template','value'=>'query_source'],...SelectorGrammar::parse($source)],25,0,$order),'id');
$only=static fn(string $source,string $expected):bool=>$ids($source)===[$expected];
relationCheck($only('custom.amounts > 9007199254740993.000000',$one['id']),'multiple exact decimals compare above JavaScript integer precision without floats');
relationCheck($only('custom.amounts < -1.999999',$one['id']),'multiple negative decimals retain numeric range semantics');
relationCheck($only('custom.days >= "2026-11-01"',$one['id']),'multiple dates compare chronologically');
relationCheck($only('custom.times > "2026-10-15T14:30"',$one['id']),'multiple datetime ranges retain validated minute precision');
relationCheck($only('custom.moment >= "2026-10-15T14:30"',$one['id']),'scalar datetime range is typed and exact');
relationCheck($only('event_start >= "2026-10-15T14:30"',$one['id']),'native event_start uses a datetime operator rather than text ordering');
relationCheck($only('custom.choice = "Context"',$one['id']),'select equality recognizes valid captured template-context options');
relationCheck($only('custom.choices contains_ci "onte"',$one['id']),'select member text supports literal substring filtering');
relationCheck($only('custom.emails contains_ci "example.invalid"',$one['id']),'email substring does not require a whole address');
relationCheck($only('custom.urls contains "path%_"',$one['id']),'URL substring remains literal without requiring a whole URL');
relationCheck($only('event_end > "2026-10-15T13:30:00Z"',$one['id']),'native event end compares exact validated UTC instants');
relationReject(fn()=>$pages->count(SelectorGrammar::parse('custom.thread = null')),'separate comment streams have no user-selectable page value');
relationReject(fn()=>$pages->search([],10,0,[['field'=>'custom.thread']]),'separate comment stream cannot expose a sort value');
relationCheck($only('custom.flags = true',$one['id']),'JSON_TABLE boolean members keep the canonical boolean comparison');
relationCheck($only('custom.tags contains_ci "100%_ ähre"',$one['id']),'collection Unicode matching escapes all SQL-looking wildcards');
relationCheck($only('custom.tags starts_with_ci "100%_"',$one['id']),'collection case-insensitive prefix uses literal Unicode strings');
relationCheck($only('custom.amounts IN [5.000001, 7.000001]',$two['id']),'collection IN matches any one correctly typed member');
relationCheck($ids('custom.tags != "one"')===[$empty['id'],$two['id']],'collection ne excludes a matching member and includes an empty allowed collection');
relationCheck($only('custom.tags = null',$empty['id']),'collection eq null means no stored members');
relationCheck($only('custom.rows.amount > 12.500000',$one['id']),'repeater numeric children use typed scalar rows');
relationCheck($only('custom.rows.amounts < -4.000000',$one['id']),'multiple values inside a repeater are flattened into actual members');
relationCheck($only('custom.groups.rows.amounts > 7.000000',$one['id']),'nested repeated groups retain precise multiple comparisons');
relationCheck($only('custom.link.title = "Beta target"',$one['id']),'scalar page-reference follows a readable target title');
relationCheck($only('custom.links.title = "Beta target"',$one['id']),'PageTable follows each explicitly stored target');
relationCheck($only('custom.rows.link.title = "Gamma target"',$one['id']),'repeater reference resolves target after source child field rights');
relationCheck($only('custom.link.custom.target_text contains_ci "other"',$one['id']),'related custom target fields use their own typed canonical guard');
relationCheck($only('custom.link.custom.link.title = "Alpha target"',$one['id']),'bounded chained page references keep distinct correlated aliases');
relationCheck($only('parent_id.title = "Alpha target"',$one['id']),'parent relation is an explicit bound hierarchy link');
relationCheck($ids('custom.tags != null',[['field'=>'custom.groups.rows','direction'=>'desc','aggregate'=>'count']])===[$one['id'],$two['id']],'nested repeater records support explicit count ordering');
relationCheck($ids('custom.groups.rows = null')===[$empty['id'],$two['id']],'nested empty repeated groups use actual member cardinality');
relationReject(fn()=>$ids('custom.amounts = ""'),'empty text cannot silently become an equality test on null multiple members');
relationCheck($ids('custom.tags != null',[['field'=>'custom.amounts','direction'=>'asc','aggregate'=>'min']])===[$one['id'],$two['id']],'minimum multiple-value ordering is numeric and stable');
relationCheck($ids('custom.tags != null',[['field'=>'custom.amounts','direction'=>'desc','aggregate'=>'max']])===[$one['id'],$two['id']],'maximum multiple-value ordering retains exact high decimals');
relationCheck($ids('custom.tags != null',[['field'=>'custom.amounts','direction'=>'desc','aggregate'=>'count']])===[$one['id'],$two['id']],'explicit count orders by stored member count');
relationCheck($ids('custom.tags != null',[['field'=>'custom.link.title','direction'=>'desc']])===[$two['id'],$one['id']],'scalar related title ordering applies target read guards before ordering');
relationCheck($ids('custom.tags != null',[['field'=>'custom.links.title','direction'=>'desc','aggregate'=>'max']])===[$two['id'],$one['id']],'PageTable ordering needs an explicit target-value aggregate');
relationCheck(count($ids('created_at >= "2000-01-01T00:00:00Z"'))===3,'actual metadata timestamps support closed UTC comparisons');
foreach(['custom.moment = "2026-02-30T12:00"','custom.times > "2026-10-15 14:30"','created_at = "2026-10-15"','custom.amounts contains "9"','custom.secret = "No secret lookup"','custom.link.custom.link.custom.link.custom.link.title = "Alpha"','custom.link.title = "x\'; DELETE FROM khcms_pages; --" OR bad.sql = "x"']as $source)relationReject(fn()=>$ids($source),'invalid type, protected value, injected path or excessive relation depth fails closed');
relationReject(fn()=>$pages->search([],25,0,[['field'=>'custom.tags']]),'multiple ordering without an aggregate remains rejected');
relationReject(fn()=>$pages->search([],25,0,[['field'=>'title','aggregate'=>'min']]),'scalar aggregate cannot silently change the order meaning');
relationReject(fn()=>$pages->search([],25,0,[['field'=>'custom.tags','aggregate'=>['injection']]]),'aggregate is a closed SQL function choice');
$tree=SelectorGrammar::parse('(custom.tags = null OR custom.amounts > 5.000000) AND status IN ["draft", "published"]');relationCheck($pages->count($tree)===$pages->count(SelectorGrammar::parse(SelectorGrammar::format($tree))),'saved nested selector grammar roundtrips null, exact decimals and IN');
foreach(['custom.rows.amounts > 1.000000','custom.links.custom.target_text contains_ci "100%_ ähre"']as $source){[$sql,$args]=(new Selectors($model))->compile(SelectorGrammar::parse($source));relationCheck(substr_count($sql,'?')===count($args),'every relation, JSON path and original literal has its own prepared binding');}
relationCheck(ownOriginalDatabaseHash($db,['khcms_pages','khcms_revisions','khcms_state'])===$before,'all comparisons, sorting, errors and grammar retain exact original publication bytes');
// Current and captured target rules are independent of the readable source link.
$reader=new Pages($db,new Access($db,$actors['reader']));$readerIds=static fn(string $source):array=>array_column($reader->search(SelectorGrammar::parse($source),1),'id');
$protected=$model->saveTemplate(['key'=>'query_protected','label'=>'Protected target','fields'=>[],'policy'=>['read'=>'manage','public'=>false]]);$private=$pages->save(['title'=>'Private relation target','slug'=>'relation-private','template'=>'query_protected']);
$linked=$pages->save(['title'=>'Readable private link','slug'=>'relation-protected-link','template'=>'query_source','custom_fields'=>['link'=>$private['id'],'links'=>[$private['id']]]]);
relationCheck($reader->count(SelectorGrammar::parse('custom.link.title = "Private relation target"'))===0&&$readerIds('custom.links.title = "Private relation target"')===[],'target object rules remove private relational existence before count and limit');
relationCheck($pages->count(SelectorGrammar::parse('custom.links.title = "Private relation target"'))===1,'authorized manager retains the same explicit target relation');
$field=$model->field('target_text');$field=$model->saveField([...$field,'access'=>['read'=>'manage','edit'=>'manage']],$field['version']);
relationCheck($reader->count(SelectorGrammar::parse('custom.link.custom.target_text contains_ci "other"'))===0,'current target field revocation removes relation matches before counting');
$captured=$pages->save(['title'=>'Captured target','slug'=>'relation-captured','template'=>'query_target','custom_fields'=>['target_text'=>'Captured management original']]);$capturedLink=$pages->save(['title'=>'Captured source','slug'=>'relation-captured-source','template'=>'query_source','custom_fields'=>['link'=>$captured['id']]]);
$field=$model->saveField([...$field,'access'=>[]],$field['version']);
relationCheck($reader->count(SelectorGrammar::parse('custom.link.custom.target_text = "Captured management original"'))===0,'relaxed current definition never bypasses the target historical field snapshot');
relationCheck($reader->count([['field'=>'template','value'=>'query_source'],...SelectorGrammar::parse('custom.link.custom.target_text = "Other target"')])===1,'an originally open target is readable again after current explicit release');
$trashTarget=$pages->save(['title'=>'Trashed target','slug'=>'relation-trash-target']);(new PageTrash($db,$access))->move($trashTarget['id'],$trashTarget['version']);
$damaged=json_decode(Db::run($db,'SELECT draft_json FROM khcms_pages WHERE id=?',[$linked['id']])->fetchColumn(),true,128,JSON_THROW_ON_ERROR);$damaged['custom_fields']['link']=$trashTarget['id'];Db::run($db,'UPDATE khcms_pages SET draft_json=? WHERE id=?',[json_encode($damaged,JSON_THROW_ON_ERROR),$linked['id']]);
relationCheck($pages->count(SelectorGrammar::parse('custom.link.title = "Trashed target"'))===0,'even a damaged reference cannot expose a trashed target');
$foreign=(new Pages($db,new Access($db,$actors['outsider'])))->save(['title'=>'Foreign relation target','slug'=>'foreign-query-target']);
$forged=json_decode(Db::run($db,'SELECT draft_json FROM khcms_pages WHERE id=?',[$linked['id']])->fetchColumn(),true,128,JSON_THROW_ON_ERROR);$forged['custom_fields']['link']=$foreign['id'];Db::run($db,'UPDATE khcms_pages SET draft_json=? WHERE id=?',[json_encode($forged,JSON_THROW_ON_ERROR),$linked['id']]);
relationCheck($pages->count(SelectorGrammar::parse('custom.link.title = "Foreign relation target"'))===0,'even a damaged cross-tenant reference cannot follow a foreign original');
$lists=new ContentLists($db,$access);$result=$lists->listing(['columns'=>['title'],'expression'=>'custom.rows.amounts < -4.000000','order'=>[['field'=>'custom.amounts','aggregate'=>'min']]]);relationCheck($result['total']===1&&$result['rows'][0]['id']===$one['id'],'actual bounded lister accepts shared nested/multiple human expressions');
relationReject(fn()=>$lists->normalize(['columns'=>['title'],'expression'=>'body contains "secret"']),'shared lister grammar cannot request whole private body projections');
relationReject(fn()=>$lists->normalize(['expression'=>'title = "x"','selectors'=>[]]),'ambiguous two filter representations cannot silently override each other');
$panel=new \Kulturhus\Native\Web\Website\ContentListPanel($db,$access,'own-query-csrf');$unknown=$panel->render(['list_apply'=>'1','filter_mode'=>'expression','selector_text'=>'custom.unknown = "human text"']);relationCheck(str_contains($unknown,'role="alert"')&&str_contains($unknown,'custom.unknown eq')===false&&str_contains($unknown,'custom.unknown = &quot;human text&quot;'),'unknown custom field is a correctable human input error without broader results');$html=$panel->render(['list_apply'=>'1','filter_mode'=>'expression','selector_text'=>'custom.amounts > "broken"']);relationCheck(str_contains($html,'role="alert"')&&str_contains($html,'custom.amounts &gt; &quot;broken&quot;')&&!str_contains($html,'data-content-list-row='),'actual lister retains invalid human query and renders no broader fallback result');

// A real independent model writer cannot change field rights halfway through one counted page.
$other=TestSupport::db();$other->exec('SET SESSION innodb_lock_wait_timeout=1');$writer=new DataModel($other,new Access($other,$actors['admin']));$openField=$writer->field('target_text');$blocked=false;
$db->setAttribute(PDO::ATTR_STATEMENT_CLASS,[OwnRelationReadStatement::class]);
OwnRelationReadStatement::$beforeCount=static function()use($writer,$openField,&$blocked):void{
    try{$writer->saveField([...$openField,'access'=>['read'=>'manage','edit'=>'manage']],$openField['version']);}
    catch(PDOException $error){if((int)($error->errorInfo[1]??0)!==1205)throw $error;$blocked=true;}
};
try{$stable=(new ContentLists($db,new Access($db,$actors['reader'])))->listing(['columns'=>['title'],'expression'=>'custom.link.custom.target_text = "Other target"']);}
finally{OwnRelationReadStatement::$beforeCount=null;$db->setAttribute(PDO::ATTR_STATEMENT_CLASS,[PDOStatement::class]);}
relationCheck($blocked&&$stable['total']>0&&count($stable['rows'])===$stable['total'],'actual concurrent field-rights writer cannot split a counted list selection');
$closedField=$writer->saveField([...$openField,'access'=>['read'=>'manage','edit'=>'manage']],$openField['version']);
relationReject(fn()=>(new ContentLists($db,new Access($db,$actors['reader'])))->listing(['columns'=>['title'],'expression'=>'custom.link.custom.target_text = "Other target"']),'rights change succeeds after read completion and closes the next relational list request');
$writer->saveField([...$closedField,'access'=>[]],$closedField['version']);

if(getenv('DVM_SELECTOR_LOAD')==='1'){
// Fifty MiB of own large drafts, queried through bounded scalar projections under16MiB.
$payload=json_decode(Db::run($db,'SELECT draft_json FROM khcms_pages WHERE id=?',[$empty['id']])->fetchColumn(),true,128,JSON_THROW_ON_ERROR);$payload['body']=str_repeat('Original high-volume content ä🙂 ',700);$payload['custom_fields']['links']=[$beta['id']];
$db->beginTransaction();try{
 $insert=$db->prepare('INSERT INTO khcms_pages(id,club_id,slug,draft_json,version,published_revision,created_by,updated_by,created_at,updated_at) VALUES(?,?,?,?,1,NULL,?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))');
 for($i=0;$i<2500;++$i){$payload['title']='Load original '.$i;$payload['slug']='query-load-'.str_pad((string)$i,4,'0',STR_PAD_LEFT);$payload['custom_fields']['amounts']=[$i.'.000000'];$insert->execute([bin2hex(random_bytes(16)),$access->club(),$payload['slug'],json_encode($payload,JSON_THROW_ON_ERROR),$actors['admin'],$actors['admin']]);}
 $db->commit();
}catch(Throwable $error){$db->rollBack();throw $error;}
$large=(int)Db::run($db,"SELECT SUM(OCTET_LENGTH(draft_json)) FROM khcms_pages WHERE slug LIKE 'query-load-%'")->fetchColumn();
$loadOriginals=ownOriginalDatabaseHash($db,['khcms_pages','khcms_revisions','khcms_definitions','khcms_definition_revisions','khcms_state','khcms_template_policy_state']);
$command=[...TestSupport::phpCommand(),'-d','memory_limit=16M',__DIR__.'/support/selector-load-worker.php'];$process=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($process))throw new RuntimeException('Own bounded query worker failed.');fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);if($exit!==0)throw new RuntimeException('Own bounded query worker: '.$out.$err);$proof=json_decode(trim($out),true,16,JSON_THROW_ON_ERROR);
relationCheck($loadOriginals===ownOriginalDatabaseHash($db,['khcms_pages','khcms_revisions','khcms_definitions','khcms_definition_revisions','khcms_state','khcms_template_policy_state']),'large bounded list preserves every exact stored original and model revision');
relationCheck($large>16*1024*1024&&$proof['peak_memory_bytes']<16*1024*1024,'actual16MiB process counts/sorts related multiple values over2500 own drafts larger than memory');echo json_encode(['selector_load'=>$proof,'stored_payload_bytes'=>$large,'external_actions'=>0],JSON_THROW_ON_ERROR).PHP_EOL;
}
echo json_encode(['suite'=>'native-selector-relations','checks'=>$checks,'driver'=>'mysql','original_bytes'=>'exact','external_actions'=>0],JSON_THROW_ON_ERROR).PHP_EOL;
