<?php declare(strict_types=1);
namespace Kulturhus\Native\Api;

use Kulturhus\Native\Shared\Db;

/** Original lifecycle services own serializable commits and their own durable recovery IDs. */
final class RecoverableOperations
{
    public function __construct(private readonly \PDO $db,private readonly Context $context){}
    public function run(string $key,string $method,string $path,array $input,\Closure $operation):array
    {
        if($this->db->inTransaction())throw new \LogicException('Dateilebenszyklus benötigt einen eigenen Vorgang.');
        if(!preg_match('/^[a-zA-Z0-9_-]{16,80}$/D',$key))throw new Problem('idempotency_key_required',422,'Für Änderungen einen unveränderten Idempotency-Key mit 16–80 Zeichen angeben.');
        $owner=$this->context->accountId;$hash=hash('sha256',$key);$request=hash_hmac('sha256',json_encode([$method,$path,Input::canonical($input)],JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION),Factory::operationKey());
        $cached=Db::atomic($this->db,function()use($owner,$hash,$request):?array{
            $this->context->require('files.write');Db::run($this->db,'INSERT INTO native_api_operations VALUES(?,?,?,NULL,?) ON DUPLICATE KEY UPDATE key_hash=VALUES(key_hash)',[$owner,$hash,$request,time()]);
            $row=Db::run($this->db,'SELECT request_hash,result_json FROM native_api_operations WHERE account_id=? AND key_hash=? FOR UPDATE',[$owner,$hash])->fetch();
            if(!hash_equals($row['request_hash'],$request))throw new Problem('idempotency_conflict',409,'Dieser Idempotency-Key gehört zu einer anderen Anfrage.');
            return $row['result_json']===null?null:json_decode($row['result_json'],true,128,JSON_THROW_ON_ERROR);
        });
        if($cached!==null){$cached['replayed']=true;return $cached;}
        // A lost acknowledgement leaves the committed reservation. The original
        // service can safely repeat exactly this operation instead of inventing a new one.
        $operationId=substr(hash_hmac('sha256',$owner.'\0'.$hash.'\0'.$request,Factory::operationKey()),0,32);
        $result=$operation($operationId);$this->context->require('files.write');$encoded=json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE,128);
        if(strlen($encoded)>2097152)throw new Problem('response_too_large',413,'Antwort zu groß. Einen kleineren Datensatz verwenden.');
        return Db::atomic($this->db,function()use($owner,$hash,$request,$encoded,$result):array{
            $this->context->require('files.write');$row=Db::run($this->db,'SELECT request_hash,result_json FROM native_api_operations WHERE account_id=? AND key_hash=? FOR UPDATE',[$owner,$hash])->fetch();
            if(!$row||!hash_equals($row['request_hash'],$request))throw Problem::conflict();
            if($row['result_json']!==null){$prior=json_decode($row['result_json'],true,128,JSON_THROW_ON_ERROR);$prior['replayed']=true;return $prior;}
            Db::run($this->db,'UPDATE native_api_operations SET result_json=? WHERE account_id=? AND key_hash=?',[$encoded,$owner,$hash]);return $result;
        });
    }
}
