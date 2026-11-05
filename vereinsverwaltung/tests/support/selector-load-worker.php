<?php declare(strict_types=1);
require dirname(__DIR__,2).'/native/bootstrap.php';
if(getenv('KH_NATIVE_CI')!=='1')throw new RuntimeException('Own fresh native CI required.');
$db=\Kulturhus\Native\TestSupport::db();$access=new \Kulturhus\Native\Shared\Access($db,'admin');$settings=$db->query('SELECT @@SESSION.max_sort_length,@@SESSION.sort_buffer_size')->fetch(PDO::FETCH_NUM);
$order=[['field'=>'custom.amounts','aggregate'=>'max','direction'=>'desc']];$compiler=new \Kulturhus\Native\Cms\QueryOrder(new \Kulturhus\Native\Cms\DataModel($db,$access));$compiler->compile($order);if($compiler->requiresTextBudget())throw new RuntimeException('Exact decimal sort must not request the longtext session budget.');$started=microtime(true);
$list=(new \Kulturhus\Native\Cms\ContentLists($db,$access))->listing(['columns'=>['title'],'expression'=>'title starts_with "Load original " AND custom.amounts >= 1250.000000 AND custom.links.title = "Beta target"','order'=>[['field'=>'custom.amounts','aggregate'=>'max','direction'=>'desc']]],9999,10);
if($list['total']!==1250||$list['page']!==125||count($list['rows'])!==10||$list['rows'][0]['c0']!=='Load original 1259')throw new RuntimeException('Bounded high-volume relational list changed totals/order or pagination.');
$elapsed=microtime(true)-$started;if($settings!==$db->query('SELECT @@SESSION.max_sort_length,@@SESSION.sort_buffer_size')->fetch(PDO::FETCH_NUM))throw new RuntimeException('Native list changed original MySQL session settings.');
echo json_encode(['mysql_session'=>'exact','decimal_text_budget'=>false,'total'=>$list['total'],'rows'=>count($list['rows']),'page'=>$list['page'],'memory_limit'=>ini_get('memory_limit'),'peak_memory_bytes'=>memory_get_peak_usage(true),'seconds'=>round($elapsed,3)],JSON_THROW_ON_ERROR).PHP_EOL;
