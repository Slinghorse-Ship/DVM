<?php declare(strict_types=1);
use Kulturhus\Native\Api\{Engine,Problem};
use Kulturhus\Native\Cms\{Pages,SelectorGrammar};
use Kulturhus\Native\Shared\Db;
/** Same closed query contract over the real API dispatcher; no writes/idempotency record. */
function ownApiSelectorProof(PDO $db,Engine $engine,Engine $reader,callable $check):void
{
    $originals=ownApiOriginalRecords($db);$operations=(int)Db::run($db,'SELECT COUNT(*) FROM native_api_operations')->fetchColumn();
    $expression='title contains_ci "API" AND (status = "published" OR status = "draft" OR status = "unpublished_changes")';$common=['order'=>[['field'=>'slug','direction'=>'desc']],'page'=>1,'limit'=>1];
    $human=$engine->dispatch('POST','/api/v1/pages/query',[],['expression'=>$expression,...$common]);$typed=$engine->dispatch('POST','/api/v1/pages/query',[],['selectors'=>SelectorGrammar::parse($expression),...$common]);
    $check($human===$typed&&$human['meta']['total']>0&&count($human['data'])===1,'human API syntax and typed selector tree share exact bounded rows, order and totals');
    foreach([['expression'=>[]],['expression'=>'title = "x"','selectors'=>[]],['expression'=>'title = "unterminated'],['expression'=>str_repeat('x',16385)],['expression'=>'custom.api_password = "secret"'],['expression'=>'title = "x"; DROP TABLE khcms_pages']] as $body){
        try{$engine->dispatch('POST','/api/v1/pages/query',[],$body);throw new RuntimeException('Invalid selector API accepted.');}catch(Problem|InvalidArgumentException){$check(true,'query API rejects malformed, ambiguous, protected or excessive human syntax before SQL');}
    }
    $secret='custom.api_acl = "Own captured management original"';$privileged=$engine->dispatch('POST','/api/v1/pages/query',[],['expression'=>$secret]);$public=$reader->dispatch('POST','/api/v1/pages/query',[],['expression'=>$secret]);
    $check($privileged['meta']['total']===1&&$public['meta']['total']===0&&$public['data']===[],'human API syntax applies captured field permission before count and pagination');
    $check((int)Db::run($db,'SELECT COUNT(*) FROM native_api_operations')->fetchColumn()===$operations,'read-only human API query never creates a mutation replay record');
    ownApiAssertOriginalRecords($db,$originals);$check(true,'query success, failures and rights checks preserve all original API records exactly');
}
