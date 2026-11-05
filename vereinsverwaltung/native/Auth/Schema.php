<?php declare(strict_types=1);
namespace Kulturhus\Native\Auth;
use PDO;
final class Schema
{
    public static function install(PDO $db):void
    {
        if($db->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql')throw new \DomainException('Anmeldung benötigt MySQL.');
        $tables=[
            'native_auth_accounts'=>'id CHAR(32) PRIMARY KEY,actor_id VARCHAR(64) COLLATE utf8mb4_unicode_ci NOT NULL UNIQUE,email VARCHAR(254) NOT NULL,
                login_name VARCHAR(90) NULL UNIQUE, INDEX(email),
                password_hash VARCHAR(255) NULL,active TINYINT NOT NULL DEFAULT 0,verified TINYINT NOT NULL DEFAULT 0,
                suspended TINYINT NOT NULL DEFAULT 0,email_factor TINYINT NOT NULL DEFAULT 0,factor_email VARCHAR(254) NULL,
                version INT UNSIGNED NOT NULL DEFAULT 1,totp_secret TEXT NULL,totp_counter BIGINT NOT NULL DEFAULT -1,
                pending_secret TEXT NULL,pending_until BIGINT NULL,pending_session CHAR(64) NULL,created_at BIGINT NOT NULL,
                FOREIGN KEY(actor_id) REFERENCES native_users(id)',
            'native_auth_tokens'=>'token_hash CHAR(64) PRIMARY KEY,account_id CHAR(32) NOT NULL,purpose VARCHAR(16) NOT NULL,
                expires_at BIGINT NOT NULL,consumed_at BIGINT NULL,created_at BIGINT NOT NULL,
                INDEX(account_id,purpose),FOREIGN KEY(account_id) REFERENCES native_auth_accounts(id)',
            'native_auth_sessions'=>'token_hash CHAR(64) PRIMARY KEY,account_id CHAR(32) NOT NULL,
                expires_at BIGINT NOT NULL,created_at BIGINT NOT NULL,revoked_at BIGINT NULL,
                duration_mode VARCHAR(16) NOT NULL DEFAULT \'standard\',last_seen BIGINT NULL,
                INDEX(account_id),FOREIGN KEY(account_id) REFERENCES native_auth_accounts(id)',
            'native_auth_challenges'=>'token_hash CHAR(64) PRIMARY KEY,account_id CHAR(32) NOT NULL,
                client_hash CHAR(64) NOT NULL,expires_at BIGINT NOT NULL,consumed_at BIGINT NULL,
                INDEX(account_id),FOREIGN KEY(account_id) REFERENCES native_auth_accounts(id)',
            'native_auth_recovery'=>'account_id CHAR(32) NOT NULL,code_hash CHAR(64) NOT NULL,used_at BIGINT NULL,revoked_at BIGINT NULL,
                PRIMARY KEY(account_id,code_hash),FOREIGN KEY(account_id) REFERENCES native_auth_accounts(id)',
            'native_auth_email_codes'=>'id CHAR(64) PRIMARY KEY,account_id CHAR(32) NOT NULL,purpose VARCHAR(16) NOT NULL,
                email VARCHAR(254) NOT NULL,account_version INT UNSIGNED NOT NULL,session_hash CHAR(64) NULL,client_hash CHAR(64) NULL,
                code_hash CHAR(64) NOT NULL,expires_at BIGINT NOT NULL,consumed_at BIGINT NULL,cipher MEDIUMTEXT NULL,
                status VARCHAR(16) NOT NULL,created_at BIGINT NOT NULL,sent_at BIGINT NULL,
                INDEX(account_id,purpose,status),FOREIGN KEY(account_id) REFERENCES native_auth_accounts(id)',
            'native_auth_limits'=>'scope_hash CHAR(64) PRIMARY KEY,attempts INT UNSIGNED NOT NULL,expires_at BIGINT NOT NULL',
            'native_auth_mail'=>'id CHAR(32) PRIMARY KEY,account_id CHAR(32) NOT NULL,club_id VARCHAR(64) NOT NULL,
                token_hash CHAR(64) NOT NULL UNIQUE,expires_at BIGINT NOT NULL,cipher MEDIUMTEXT NULL,
                status VARCHAR(16) NOT NULL,created_at DATETIME NOT NULL,sent_at DATETIME NULL,
                INDEX(club_id,status,created_at),FOREIGN KEY(account_id) REFERENCES native_auth_accounts(id)',
            'native_auth_login_names'=>'account_id CHAR(32) NOT NULL,account_version INT UNSIGNED NOT NULL,
                previous_name VARCHAR(90) NULL,login_name VARCHAR(90) NOT NULL,changed_at BIGINT NOT NULL,
                PRIMARY KEY(account_id,account_version),FOREIGN KEY(account_id) REFERENCES native_auth_accounts(id)',
        ];
        foreach($tables as $name=>$columns)$db->exec('CREATE TABLE IF NOT EXISTS '.$name.' ('.$columns.') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin');
        // Explicit installation/upgrade only. Preserve each original hash and use timestamp.
        if(!$db->query("SHOW COLUMNS FROM native_auth_recovery LIKE 'revoked_at'")->fetch())$db->exec('ALTER TABLE native_auth_recovery ADD revoked_at BIGINT NULL');
        foreach(['suspended'=>'TINYINT NOT NULL DEFAULT 0','email_factor'=>'TINYINT NOT NULL DEFAULT 0','factor_email'=>'VARCHAR(254) NULL'] as $field=>$definition){
            if(!$db->query("SHOW COLUMNS FROM native_auth_accounts LIKE '$field'")->fetch())$db->exec('ALTER TABLE native_auth_accounts ADD '.$field.' '.$definition);
        }
        foreach(['duration_mode'=>"VARCHAR(16) NOT NULL DEFAULT 'standard'",'last_seen'=>'BIGINT NULL'] as $field=>$definition){
            if(!$db->query("SHOW COLUMNS FROM native_auth_sessions LIKE '$field'")->fetch())$db->exec('ALTER TABLE native_auth_sessions ADD '.$field.' '.$definition);
        }
        // Explicit schema maintenance preserves account IDs, password hashes, MFA and sessions.
        $column=$db->query("SHOW COLUMNS FROM native_auth_accounts LIKE 'login_name'")->fetch();
        if(!$column)$db->exec('ALTER TABLE native_auth_accounts ADD login_name VARCHAR(90) NULL, ADD UNIQUE KEY login_name(login_name)');
        $emailIndexes=$db->query("SHOW INDEX FROM native_auth_accounts WHERE Column_name='email'")->fetchAll(PDO::FETCH_ASSOC);
        foreach($emailIndexes as $index){
            if((int)$index['Non_unique']===0){
                if($index['Key_name']!=='email')throw new \DomainException('E-Mail-Index vor der Kontoumstellung prüfen.');
                $db->exec('ALTER TABLE native_auth_accounts DROP INDEX email, ADD INDEX email(email)');
            }
        }
        // Explicit schema maintenance only; web authentication never creates retention indexes.
        foreach(['native_auth_limits'=>'auth_limits_expiry','native_auth_sessions'=>'auth_sessions_expiry','native_auth_tokens'=>'auth_tokens_expiry','native_auth_challenges'=>'auth_challenges_expiry']as$table=>$name){
            $indexes=$db->query("SHOW INDEX FROM ".$table." WHERE Key_name='".$name."'")->fetchAll(PDO::FETCH_ASSOC);
            if(!$indexes)$db->exec('ALTER TABLE '.$table.' ADD INDEX '.$name.'(expires_at)');
        }
    }
}
