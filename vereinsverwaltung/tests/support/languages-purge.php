<?php declare(strict_types=1);
use Kulturhus\Native\Shared\Db;

// Runs in the synthetic suite database. No existing page, language or media row is rewritten.
$purgeConfig=$settings->state();
$longLocale='abc-abcdefgh-xyz';$longSlug=str_repeat('x',180);$longPath=$longLocale.'/'.$longSlug;
$purgeConfig['config']['languages'][]=['code'=>$longLocale,'label'=>'Regionaler Prüftext','enabled'=>true,'fallback'=>'de','messages'=>[]];
$purgeConfig['config']['languages'][]=['code'=>'zh-hant-tw','label'=>'Traditional Chinese','enabled'=>true,'fallback'=>'de','messages'=>[]];
$settings->save($purgeConfig['config'],$purgeConfig['version']);
languageCheck(strlen($longPath)===197&&$settings->language('zh-hant-tw')['fallback']==='de','script and region languages use one bounded sixteen-character locale contract');
$purgePage=$pages->save(['title'=>'Gezielte Sprachlöschung','slug'=>'sprachloeschung']);$purgePage=$pages->publish($purgePage['id'],$purgePage['version']);$purgeId=$purgePage['id'];
$translations->save($purgeId,'en',['slug'=>'retained-old-language-path','fields'=>['title'=>'Historical language address']],$purgePage['published_revision'],0);$translations->publish($purgeId,'en',1);
$translations->save($purgeId,'en',['slug'=>'retained-new-language-path','fields'=>['title'=>'Current language address']],$purgePage['published_revision'],2);$translations->publish($purgeId,'en',3);
$translations->save($purgeId,'en',['slug'=>'private-proposed-language-path','fields'=>['title'=>'Unpublished proposal']],$purgePage['published_revision'],4);
$translations->save($purgeId,$longLocale,['slug'=>$longSlug,'fields'=>['title'=>'Longest supported language path']],$purgePage['published_revision'],0);$translations->publish($purgeId,$longLocale,1);
$retained=$translations->retainedPaths($purgeId);
languageCheck(in_array($longPath,$retained,true)&&in_array('en/retained-old-language-path',$retained,true)&&in_array('en/private-proposed-language-path',$retained,true),'terminal page removal retains published historical and private proposed language paths');
languageDeny(fn()=>$translations->purgeForOriginal($purgeId,$purgePage['version'],true),'language originals cannot be removed outside original page transaction');
$db->beginTransaction();try{languageDeny(fn()=>$translations->purgeForOriginal($purgeId,$purgePage['version'],true),'active source language originals cannot be independently purged');}finally{$db->rollBack();}
$purgePage=(new \Kulturhus\Native\Cms\PageTrash($db,$access))->move($purgeId,$purgePage['version']);
$snapshot=function()use($db,$purgeId):string{$rows=[];foreach(['khcms_pages','khcms_revisions','native_language_pages','native_language_revisions','native_language_routes']as$table){$column=$table==='khcms_pages'?'id':'page_id';$rows[$table]=Db::run($db,'SELECT * FROM '.$table.' WHERE '.$column.'<>? ORDER BY '.($table==='khcms_pages'?'id':($table==='khcms_revisions'?'page_id,revision':($table==='native_language_pages'?'page_id,locale':($table==='native_language_revisions'?'page_id,locale,revision':'page_id,locale,slug')))),[$purgeId])->fetchAll();}return hash('sha256',json_encode($rows,JSON_THROW_ON_ERROR));};
$unrelatedBefore=$snapshot();$ownedBefore=[];foreach(['native_language_pages','native_language_revisions','native_language_routes']as$table)$ownedBefore[$table]=Db::run($db,'SELECT * FROM '.$table.' WHERE page_id=? ORDER BY locale',[$purgeId])->fetchAll();
$pagePurge=new \Kulturhus\Native\Cms\PagePurge($db,$access);
languageDeny(fn()=>$pagePurge->purge($purgeId,$purgePage['version'],false),'language page purge requires explicit terminal confirmation');
languageDeny(fn()=>$pagePurge->purge($purgeId,$purgePage['version']-1,true),'language page purge rejects stale original version');
foreach($ownedBefore as$table=>$rows)languageCheck(Db::run($db,'SELECT * FROM '.$table.' WHERE page_id=? ORDER BY locale',[$purgeId])->fetchAll()===$rows,'rejected terminal request preserves exact '.$table.' bytes');
$result=$pagePurge->purge($purgeId,$purgePage['version'],true);
$remaining=0;foreach(array_keys($ownedBefore)as$table)$remaining+=(int)Db::run($db,'SELECT COUNT(*) FROM '.$table.' WHERE page_id=?',[$purgeId])->fetchColumn();
languageCheck($result['purged']===true&&$remaining===0&&!Db::run($db,'SELECT 1 FROM khcms_pages WHERE id=?',[$purgeId])->fetchColumn(),'confirmed page purge atomically removes exactly its own language originals and original source');
$tombstonePaths=Db::run($db,'SELECT slug FROM khcms_purged_paths WHERE page_id=?',[$purgeId])->fetchAll(PDO::FETCH_COLUMN);
languageCheck(!array_diff($retained,$tombstonePaths)&&in_array($longPath,$tombstonePaths,true),'all old and longest language addresses remain permanently reserved after confirmed deletion');
languageCheck($snapshot()===$unrelatedBefore,'confirmed language purge preserves every unrelated source revision and language row byte exactly');
languageCheck($resolver->resolve($longPath)===null&&$resolver->resolve('en/retained-old-language-path')===null,'deleted language routes never expose removed originals');
languageCheck($pagePurge->purge($purgeId,$purgePage['version'],true)['replayed']===true,'lost multilingual page purge response replays without another mutation');
$replacement=$pages->save(['title'=>'Unabhängiger Inhalt','slug'=>'unabhaengiger-ersatz']);$replacement=$pages->publish($replacement['id'],$replacement['version']);
$translations->save($replacement['id'],$longLocale,['slug'=>$longSlug,'fields'=>['title'=>'Unrelated replacement']],$replacement['published_revision'],0);
languageDeny(fn()=>$translations->publish($replacement['id'],$longLocale,1),'deleted longest language address cannot identify an unrelated source later');

$provenance=$pages->save(['title'=>'Kopierquelle mit Sprachen','slug'=>'sprachkopie-loeschschutz']);$provenance=$pages->publish($provenance['id'],$provenance['version']);
$translations->save($provenance['id'],'en',['slug'=>'copy-original-language','fields'=>['title'=>'Retained copy provenance']],$provenance['published_revision'],0);
$copy=$pages->copyFrom($provenance['id'],$provenance['version'],['title'=>'Private geprüfte Kopie','slug'=>'sprachkopie-provenienz','basis'=>'draft']);
$provenance=(new \Kulturhus\Native\Cms\PageTrash($db,$access))->move($provenance['id'],$provenance['version']);
languageDeny(fn()=>$pagePurge->purge($provenance['id'],$provenance['version'],true),'foreign immutable translation-copy provenance prevents deleting its retained original source');
languageCheck($translations->get($copy['id'],'en')['payload']['copied_from']['page_id']===$provenance['id']&&Db::run($db,'SELECT 1 FROM khcms_pages WHERE id=?',[$provenance['id']])->fetchColumn()!==false,'blocked copy-provenance purge preserves the source and private copied language revision');
