<?php declare(strict_types=1);
// Exercise the production list over real saved sources and original accounting snapshots.
$finance->saveTaxAccount('40401','Own original list account',true,0,true);
$listSourceIds=[];$listOriginalPositions=[];
foreach([['80','2026-09-01','Bravo','Zulu'],['90','2026-09-02','Charlie','Alpha'],['70','2026-09-03','Alpha','Bravo']]as[$listAmount,$listDay,$listDonor,$listItem]){
 $listId=$finance->createGoods(['donor'=>'Own list sorting '.$listDonor,'designation'=>'Own sorted '.$listItem,'description'=>'Own complete list item facts','received_on'=>$listDay,'valuation'=>'Own original list valuation','origin'=>'private','evidence_present'=>'1','positions'=>[['description'=>'Own first original list position','amount'=>'50','sphere'=>'1','account'=>'40401','tax_rate'=>'0'],['description'=>'Own second original list position','amount'=>(string)((int)$listAmount-50),'sphere'=>'','account'=>'','tax_rate'=>'0']]],$key());
 $listSourceIds[]=$listId;$listOriginalPositions[$listId]=$finance->positions('goods',$listId);
}
$finance->saveTaxAccount('40401','Own changed current catalogue name',true,1);
$listSortOrders=['recent'=>array_reverse($listSourceIds),'oldest'=>$listSourceIds,'donor'=>[$listSourceIds[2],$listSourceIds[0],$listSourceIds[1]],'item'=>[$listSourceIds[1],$listSourceIds[2],$listSourceIds[0]],'value_desc'=>[$listSourceIds[1],$listSourceIds[0],$listSourceIds[2]],'value_asc'=>[$listSourceIds[2],$listSourceIds[0],$listSourceIds[1]]];
$listTables=array_values(array_diff($db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN),['native_language_preferences']));$listBefore=ownOriginalDatabaseHash($db,$listTables);
foreach($listSortOrders as$listSort=>$listExpected){
 $list=\Kulturhus\Verein\Web\GoodsDonationState::catalogue($finance,['goods_q'=>'Own list sorting','goods_sort'=>$listSort,'goods_columns'=>'accounting']);
 $check($list['available']&&$list['sort']===$listSort&&array_column($list['rows'],'id')===$listExpected,'actual goods list applies '.$listSort.' to its filtered complete sources');
 $check(count($list['positions'])===3,'actual sorted list includes each source accounting snapshot '.$listSort);
 foreach($listExpected as$listId)$check($list['positions'][$listId]===$listOriginalPositions[$listId]&&$list['positions'][$listId][0]['account_name']==='Own original list account','actual list preserves both whole old positions instead of the renamed catalogue '.$listSort);
}
foreach(['de','en']as$listLocale){
 $listXp=$render('own-list-admin',$listLocale,['goods_q'=>'Own list sorting','goods_sort'=>'value_desc','goods_columns'=>'accounting']);
 $check(array_map(static fn(DOMElement$n):string=>$n->getAttribute('data-goods-original'),iterator_to_array($listXp->query('//*[@data-goods-original]')))===$listSortOrders['value_desc'],'actual localized list renders requested descending values '.$listLocale);
 $check($listXp->query('//*[@data-goods-list-position]')->length===6&&str_contains($listXp->document->textContent,'Own original list account')&&!str_contains($listXp->document->textContent,'Own changed current catalogue name'),'actual optional accounting shows original saved names and six original positions '.$listLocale);
 $listXp=$render('own-list-admin',$listLocale,['goods_q'=>'Own list sorting','goods_sort'=>'value_asc']);$check($listXp->query('//*[@data-goods-accounting]')->length===0&&$listXp->evaluate('string(//select[@name="goods_sort"]/option[@selected]/@value)')==='value_asc','actual compact list retains selected order without extra accounting '.$listLocale);
 $listXp=$render('own-list-admin',$listLocale,['goods_sort'=>'Own original invalid sorting','goods_columns'=>'Own original invalid columns']);$check($listXp->query('//*[@data-goods-original]')->length===0&&$listXp->evaluate('string(//select[@name="goods_sort"]/option[@selected]/@value)')==='Own original invalid sorting'&&$listXp->evaluate('string(//select[@name="goods_columns"]/option[@selected]/@value)')==='Own original invalid columns','invalid list choices remain fully visible without fabricated default results '.$listLocale);
 $listXp=$render('own-list-admin',$listLocale,['goods_sort'=>['whole'=>'Own unsupported sort'],'goods_columns'=>['whole'=>'Own unsupported columns']]);$check($listXp->query('//*[@data-goods-original]')->length===0&&str_contains($listXp->evaluate('string(//*[@data-goods-unsupported])'),'Own unsupported sort')&&str_contains($listXp->evaluate('string(//*[@data-goods-unsupported])'),'Own unsupported columns'),'actual invalid choice shapes retain both originals '.$listLocale);
}
$check(ownOriginalDatabaseHash($db,$listTables)===$listBefore,'all list orders views failures and original tax snapshots preserve every complete business row and schema');
$db->beginTransaction();$db->exec("UPDATE clubs SET name='Own list caller work' WHERE id='own-list-club'");$listCaller=ownOriginalDatabaseHash($db,$listTables);\Kulturhus\Verein\Web\GoodsDonationState::catalogue($finance,['goods_sort'=>'value_desc','goods_columns'=>'accounting']);$check($db->inTransaction()&&ownOriginalDatabaseHash($db,$listTables)===$listCaller,'real caller transaction and preceding work survive optional list accounting');$db->rollBack();
$listReader=\Kulturhus\Verein\Web\GoodsDonationState::catalogue(new \Kulturhus\Verein\Finance($db,'own-list-reader'),['goods_q'=>'Own list sorting','goods_sort'=>'value_desc','goods_columns'=>'accounting']);$check(array_column($listReader['rows'],'id')===$listSortOrders['value_desc'],'financial read access can use all list accounting without unrelated directory writing');
$listForeign=\Kulturhus\Verein\Web\GoodsDonationState::catalogue($foreign,['goods_q'=>'Own list sorting','goods_columns'=>'accounting']);$check($listForeign['rows']===[]&&$listForeign['positions']===[],'same filter never exposes another club original accounting');
$check(ownOriginalDatabaseHash($db,$listTables)===$listBefore,'read access and foreign selections leave all originals exact');
