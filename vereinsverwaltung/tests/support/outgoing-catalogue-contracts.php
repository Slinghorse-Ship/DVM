<?php declare(strict_types=1);

/** Whole native list controls have a separate contract from the legacy clamped list. */
function ownOutgoingCatalogueContracts(PDO $db,callable $check,array $fixture):void
{
    $service=$fixture['service'];$data=$fixture['data'];$key=$fixture['key'];$tables=$fixture['tables'];
    $ids=[];for($i=0;$i<7;$i++)$ids[]=$service->save('',0,array_replace($data,['description'=>'Own catalogue whole 0 '.$i]),$key());
    $catalogue=new \Kulturhus\Verein\OutgoingInvoiceCatalogue($db,$fixture['actor']);$before=ownOriginalDatabaseHash($db,$tables);$base=['invoices_q'=>'Own catalogue whole'];
    $page=$catalogue->nativePage($base);$check($page['total']===7&&$page['pages']===2&&count($page['rows'])===6,'native catalogue counts seven genuine matching invoices and displays six whole summaries');
    $second=$catalogue->nativePage($base+['invoices_page'=>'2']);$check(count($second['rows'])===1&&$second['page']===2,'native catalogue reaches its actual last short matching page');
    $absent=$catalogue->nativePage($base+['invoices_page'=>'999999']);$check($absent['controls']['invoices_page']==='999999'&&$absent['page']===null&&$absent['rows']===[]&&$absent['error']!=='','whole unavailable native invoice page cannot substitute an earlier last page');
    $check($catalogue->page($base+['invoices_page'=>'999999'])['page']===2,'legacy invoice catalogue retains its established last-page contract');
    foreach([['invoices_q'=>str_repeat('🙂',151),'invoices_page'=>'999999'],['invoices_q'=>"\n0\n"],['inv_status'=>'0'],['inv_kind'=>'0'],['invoices_page'=>'0']]as$query){
        $bad=$catalogue->nativePage(array_replace($base,$query));$whole=true;foreach($query as$name=>$value)$whole=$whole&&$bad['controls'][$name]===$value;
        $check($whole&&$bad['rows']===[]&&$bad['page']===null&&$bad['error']!=='','whole invalid native invoice list controls stay literal without a replacement search');
    }
    $spaced=$catalogue->nativePage(['invoices_q'=>' Own catalogue whole 0 0 ']);$check($spaced['controls']['invoices_q']===' Own catalogue whole 0 0 '&&$spaced['total']===1&&$spaced['rows'][0]['id']===$ids[0],'native invoice search preserves surrounding blanks separately from actual zero matching');
    $check(ownOriginalDatabaseHash($db,$tables)===$before,'all valid invalid and unavailable native invoice searches preserve every complete business row');
}
