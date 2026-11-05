<?php declare(strict_types=1);
namespace Kulturhus\Native\Api;
use Kulturhus\Native\Shared\Db;
final class Operations
{
    public function __construct(private readonly \PDO $db,private readonly Context $context,#[\SensitiveParameter]private readonly string $key){if(strlen($key)!==32)throw new \LogicException('Privater API-Schlüssel erforderlich.');}
    public function run(string $key,string $method,string $path,array $input,\Closure $operation,?\Closure $beforeGuard=null):array
    {
        if(!preg_match('/^[a-zA-Z0-9_-]{16,80}$/D',$key))throw new Problem('idempotency_key_required',422,'Für Änderungen einen unveränderten Idempotency-Key mit 16–80 Zeichen angeben.');
        $hash=hash('sha256',$key);$requestHash=hash_hmac('sha256',json_encode([$method,$path,Input::canonical($input)],JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION),$this->key);
        return Db::atomic($this->db,function()use($hash,$requestHash,$operation,$beforeGuard):array{
            if($beforeGuard!==null)$beforeGuard();
            $this->context->active();$owner=$this->context->accountId;
            Db::run($this->db,'INSERT INTO native_api_operations VALUES(?,?,?,NULL,?) ON DUPLICATE KEY UPDATE key_hash=VALUES(key_hash)',[$owner,$hash,$requestHash,time()]);
            $row=Db::run($this->db,'SELECT request_hash,result_json FROM native_api_operations WHERE account_id=? AND key_hash=? FOR UPDATE',[$owner,$hash])->fetch();
            if(!hash_equals($row['request_hash'],$requestHash))throw new Problem('idempotency_conflict',409,'Dieser Idempotency-Key gehört zu einer anderen Anfrage.');
            if($row['result_json']!==null){$result=json_decode($row['result_json'],true,128,JSON_THROW_ON_ERROR);$result['replayed']=true;return $result;}
            $result=$operation();$this->context->active();$encoded=json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE,128);
            if(strlen($encoded)>2097152)throw new Problem('response_too_large',413,'Antwort zu groß. Einen kleineren Datensatz verwenden.');
            Db::run($this->db,'UPDATE native_api_operations SET result_json=? WHERE account_id=? AND key_hash=?',[$encoded,$owner,$hash]);return $result;
        });
    }
}
