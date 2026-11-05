<?php declare(strict_types=1);
ini_set('zend.exception_ignore_args','1');require dirname(__DIR__,2).'/native/bootstrap.php';
$c=\Kulturhus\Native\TestSupport::config();if(($c['owner']??'')!=='isolated-native-ci'||($c['label']??'')!=='native-api-interface')throw new RuntimeException('Exact owned API worker required.');
$db=\Kulturhus\Native\TestSupport::db();$rows=$db->query('SELECT id,label,revoked_at FROM native_api_tokens ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);$requests=$db->query('SELECT id,consumed_at FROM native_api_token_requests ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);echo json_encode(['tokens'=>$rows,'requests'=>$requests],JSON_THROW_ON_ERROR).PHP_EOL;
