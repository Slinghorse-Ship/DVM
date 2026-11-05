<?php declare(strict_types=1);
/** Exact owned loopback adapter to the existing native entry; never installed as a route. */
if(($cfg['owner']??'')!=='isolated-native-ci'||($cfg['label']??'')!=='native-api-interface'||PHP_SAPI!=='cli-server'||($_SERVER['REMOTE_ADDR']??'')!=='127.0.0.1')throw new RuntimeException('Exact owned API entry required.');
putenv('VEREIN_PRIVATE_ROOT='.$f['root']);\Kulturhus\Native\Integration\ManagerSession::start();$db=\Kulturhus\Native\TestSupport::db();
$marker=$f['root'].'/own-api-lose-commit';
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'&&($_POST['action']??null)==='api-token-confirm'&&is_file($marker)){
 unlink($marker);$db=new class($cfg)extends PDO{
  private int$initial;private bool$lost=false;
  public function __construct(array$c){parent::__construct('mysql:host=127.0.0.1;port=13371;dbname='.$c['database'].';charset=utf8mb4',$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);$this->initial=(int)$this->query('SELECT COUNT(*) FROM native_api_tokens')->fetchColumn();}
  public function commit():bool{$lose=!$this->lost&&(int)$this->query('SELECT COUNT(*) FROM native_api_tokens')->fetchColumn()>$this->initial;$ok=parent::commit();if($lose){$this->lost=true;throw new PDOException('Own accepted API HTTP COMMIT acknowledgement lost');}return$ok;}
 };
}
if(!\Kulturhus\Native\Api\NativeEntry::serve($db,$f['club'],$f['origin'],$f['root'].'/media',is_string($_SESSION['auth_token']??null)?$_SESSION['auth_token']:null,($_GET['owned_empty_csrf']??null)==='1'?'':(string)($_SESSION['csrf']??'')))http_response_code(404);
