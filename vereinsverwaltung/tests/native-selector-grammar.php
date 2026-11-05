<?php declare(strict_types=1);
require dirname(__DIR__).'/native/bootstrap.php';
require __DIR__.'/support/original-database-hash.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Cms\{Schema,Pages,DataModel,SelectorGrammar};
use Kulturhus\Native\Shared\Access;

$db=TestSupport::db();TestSupport::seed($db);Schema::install($db);$access=new Access($db,'admin');$pages=new Pages($db,$access);$model=new DataModel($db,$access);$checks=0;
function grammarCheck(bool $okay,string $label):void{if(!$okay)throw new RuntimeException($label);++$GLOBALS['checks'];echo 'PASS '.$label.PHP_EOL;}
function grammarReject(callable $call,string $label):void{try{$call();}catch(InvalidArgumentException){grammarCheck(true,$label);return;}throw new RuntimeException($label);}
$model->saveField(['key'=>'query_price','label'=>'Exact query price','type'=>'decimal']);$model->saveField(['key'=>'query_active','label'=>'Query flag','type'=>'boolean']);$model->saveTemplate(['key'=>'query_original','label'=>'Own query original','renderer'=>'standard','fields'=>['query_price','query_active']]);
$one=$pages->save(['title'=>'Literal Äpfel 100%_!','slug'=>'grammar-one','template'=>'query_original','custom_fields'=>['query_price'=>'9007199254740993.000001','query_active'=>true]]);
$two=$pages->save(['title'=>'Literal Äpfel second','slug'=>'grammar-two','template'=>'query_original','custom_fields'=>['query_price'=>'2.000000','query_active'=>false]]);
$three=$pages->save(['title'=>'Other original','slug'=>'grammar-three','template'=>'query_original','custom_fields'=>['query_price'=>'1.250000','query_active'=>true]]);
$tables=['khcms_pages','khcms_revisions','khcms_state','khcms_definitions','khcms_definition_revisions'];$before=ownOriginalDatabaseHash($db,$tables);
$ids=static fn(array $selectors):array=>array_column($pages->search($selectors,25,0,[['field'=>'slug','direction'=>'asc']]),'id');
grammarCheck($ids(SelectorGrammar::parse('title contains "Äpfel" AND custom.query_active = true'))===[$one['id']],'literal Unicode and typed boolean use actual canonical page selection');
grammarCheck($ids(SelectorGrammar::parse('custom.query_price >= 9007199254740993.000001'))===[$one['id']],'unquoted exact decimal never crosses a floating-point conversion');
grammarCheck($ids(SelectorGrammar::parse('title *= "100%_!"'))===[$one['id']],'SQL wildcard-looking punctuation remains literal original text');
grammarCheck($ids(SelectorGrammar::parse('(slug = "grammar-one" OR slug = "grammar-three") AND custom.query_active = true'))===[$one['id'],$three['id']],'parenthesized OR and outer AND retain canonical precedence');
grammarCheck($ids(SelectorGrammar::parse('slug = "grammar-two" OR slug = "grammar-three" AND custom.query_active = false'))===[$two['id']],'AND binds more tightly than OR in actual SQL');
grammarCheck($ids(SelectorGrammar::parse('slug IN ["grammar-one", "grammar-three"]'))===[$one['id'],$three['id']],'closed literal IN lists select exact original IDs');
grammarCheck($ids(SelectorGrammar::parse('parent_id = null'))===[$one['id'],$three['id'],$two['id']],'native null remains distinct from a quoted null string');
$escapedOriginal="a\"b\\c";grammarCheck(SelectorGrammar::parse('title = '.json_encode($escapedOriginal,JSON_THROW_ON_ERROR))[0]['value']===$escapedOriginal,'JSON quoting preserves an original quote and backslash');
grammarCheck($pages->count(SelectorGrammar::parse('title = "x\'; DROP TABLE khcms_pages; --"'))===0,'a SQL-looking human string stays one bound search value');
grammarCheck(SelectorGrammar::parse(" \n\t")===[],'empty query uses the existing empty-selector contract');
foreach(['title = "unterminated','title = "bad\\q"','title = text','title "=" "x"','title = "x"; DELETE','title = 01','title = 1e9','slug IN []','slug IN ["x",]','(title = "x"','title = "x")','title = "x" OR','title = "x" AND ()','((((title = "x"))))','title = "x" OR 1 = 1',"title = \"x\"\0",'title = "'.str_repeat('x',16385).'"']as$i=>$source)grammarReject(fn()=>SelectorGrammar::parse($source),'malformed or excessive grammar fails before SQL '.$i);
grammarReject(fn()=>$pages->count(SelectorGrammar::parse('unknown.field = "x"')),'unknown query field still uses the canonical typed field guard');
grammarReject(fn()=>$pages->count(SelectorGrammar::parse('custom.query_active contains "true"')),'syntactically valid grammar cannot bypass operator/type validation');
grammarReject(fn()=>SelectorGrammar::parse(implode(' OR ',array_fill(0,21,'title = "x"'))),'twenty-first condition is rejected before SQL');
grammarCheck(ownOriginalDatabaseHash($db,$tables)===$before,'all human syntax, errors and actual SQL retain every original byte');
echo json_encode(['suite'=>'native-selector-grammar','checks'=>$checks,'driver'=>'mysql','live_transport'=>false],JSON_THROW_ON_ERROR).PHP_EOL;
