<?php declare(strict_types=1);
require __DIR__.'/inventory-invoice-integration.php';
// Removing a navigational document link cannot erase acquisition provenance.
$controller=$integrated['items']['controller'];$beforeItem=$inventory->item($controller);
$inventory->archiveItem($controller,(int)$beforeItem['version'],(int)$beforeItem['stock_version'],['reason'=>'Synthetic archival lifecycle'],$key());
$db->prepare("DELETE FROM document_links WHERE club_id='s' AND source_type='item' AND source_id=?")->execute([$controller]);$archived=$inventory->item($controller);
denied(fn()=>$inventory->purgeArchived($controller,(int)$archived['version'],(int)$archived['stock_version'],'Synthetic removal attempt',$key()),'original acquisition evidence prevents final removal even without document navigation link');
check($inventory->item($controller)['deleted_at']===null&&(int)$db->query('SELECT COUNT(*) FROM inventory_acquisition_sources')->fetchColumn()>0,'archive retains item and original acquisition references');
$inventory->restoreItem($controller,(int)$archived['version'],$key());
check(\Kulturhus\Verein\Tests\MySqlFixture::integrity($db)==='ok'&&!(\Kulturhus\Verein\Tests\MySqlFixture::foreignKeyErrors($db)[0]??false),'acquisition lifecycle retains referential integrity');echo "$n lifecycle checks passed\n";
