<?php declare(strict_types=1);
namespace Kulturhus\Native\Api;
final class Schema
{
    public static function install(\PDO $db):void
    {
        if($db->getAttribute(\PDO::ATTR_DRIVER_NAME)!=='mysql'||$db->inTransaction())throw new \DomainException('API-Schema nur im privaten Wartungsweg installieren.');
        $db->exec("CREATE TABLE IF NOT EXISTS native_api_tokens (id CHAR(32) PRIMARY KEY, token_hash CHAR(64) NOT NULL UNIQUE, account_id CHAR(32) NOT NULL, native_actor VARCHAR(64) NOT NULL, manager_actor VARCHAR(128) NOT NULL, manager_club VARCHAR(128) NOT NULL, security_stamp CHAR(64) NOT NULL, label VARCHAR(100) NOT NULL, scopes JSON NOT NULL, created_at BIGINT NOT NULL, expires_at BIGINT NOT NULL, revoked_at BIGINT NULL, last_used_at BIGINT NULL, INDEX api_owner(account_id,created_at,id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin");
        $db->exec("CREATE TABLE IF NOT EXISTS native_api_token_requests (id CHAR(32) PRIMARY KEY, account_id CHAR(32) NOT NULL, session_hash CHAR(64) NOT NULL, security_stamp CHAR(64) NOT NULL, auth_stamp CHAR(64) NOT NULL, label VARCHAR(100) NOT NULL, scopes JSON NOT NULL, ttl_days INT NOT NULL, needs_factor TINYINT NOT NULL, created_at BIGINT NOT NULL, expires_at BIGINT NOT NULL, consumed_at BIGINT NULL, INDEX api_request_owner(account_id,created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin");
        $db->exec("CREATE TABLE IF NOT EXISTS native_api_operations (account_id CHAR(32) NOT NULL, key_hash CHAR(64) NOT NULL, request_hash CHAR(64) NOT NULL, result_json JSON NULL, created_at BIGINT NOT NULL, PRIMARY KEY(account_id,key_hash)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin");
    }
}
