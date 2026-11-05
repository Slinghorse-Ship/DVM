<?php declare(strict_types=1);
require dirname(__DIR__,2).'/native/bootstrap.php';require dirname(__DIR__,2).'/src/bootstrap.php';
$config=\Kulturhus\Native\TestSupport::config();if(($config['owner']??'')!=='isolated-native-ci'||($config['label']??'')!=='native-bank-catalogue')throw new RuntimeException('Owned catalogue probe required');
$page=(new \Kulturhus\Verein\Finance(\Kulturhus\Native\TestSupport::db(),'catalogue-admin'))->transactionsPage(['account'=>$argv[1]],51,20);
if(count($page['rows'])!==5||array_filter($page['rows'],static fn($row)=>strlen($row['description'])!==32768))throw new RuntimeException('Oldest catalogue page required');
echo json_encode(['total'=>$page['total'],'peak_bytes'=>memory_get_peak_usage(true)],JSON_THROW_ON_ERROR);
