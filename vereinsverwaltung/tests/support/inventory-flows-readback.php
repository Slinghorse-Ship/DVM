<?php declare(strict_types=1);
require __DIR__.'/inventory-fields-environment.php';use Kulturhus\Verein\{Database,Inventory};
$path=realpath($argv[1]??'');$prefix=realpath($inventoryTestRoot.'/.local/inventory-flow-fixtures');if(!$path||!$prefix||!str_starts_with(str_replace('\\','/',$path),rtrim(str_replace('\\','/',$prefix),'/').'/'))throw new RuntimeException('Explicit private fixture required.');
$f=json_decode(file_get_contents($path),true,16,JSON_THROW_ON_ERROR);if(!str_starts_with($f['club'],'inventory-flows-'))throw new RuntimeException('Synthetic fixture only.');
$db=\Kulturhus\Verein\Tests\MySqlFixture::existing($inventoryTestDatabase);$db->exec('START TRANSACTION READ ONLY');$i=new Inventory($db,$f['actor']);$out=['items'=>$i->items(),'stock'=>$i->stock(),'summary'=>$i->summary(),'loans'=>array_map(fn($l)=>$i->loan($l['id']),$i->loans())];
foreach(['inventory_events','inventory_movements','finance_entries','finance_transactions','documents','document_links'] as $table){$out[$table]=mysqlFixtureTenantRows($db,$table,$f['club']);}echo json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
