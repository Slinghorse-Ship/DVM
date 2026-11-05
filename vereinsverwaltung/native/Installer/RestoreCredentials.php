<?php declare(strict_types=1);
namespace Kulturhus\Native\Installer;

use PDO;
use DomainException;
use Kulturhus\Native\Shared\Db;

/** Post-import revocation; its durable report belongs to this one owned restore. */
final class RestoreCredentials
{
    private const COUNTS = [
        'sessions_revoked', 'challenges_consumed', 'api_tokens_revoked',
        'login_email_codes_consumed', 'reset_tokens_consumed',
        'invite_tokens_consumed', 'recovery_codes_revoked', 'totp_counters_advanced',
    ];

    public static function review(PDO $db, array $state): array
    {
        self::owner($db, $state);
        if ($db->inTransaction()) throw new DomainException('Anmeldeprüfung benötigt eine eigene Wiederherstellungstransaktion.');
        self::authSchema($db);
        foreach (['security_review_at'=>'BIGINT NULL', 'credentials_json'=>'MEDIUMTEXT NULL'] as $column=>$definition) {
            if (!Db::run($db,"SHOW COLUMNS FROM native_restore_operation LIKE '".$column."'")->fetch()) {
                $db->exec('ALTER TABLE native_restore_operation ADD '.$column.' '.$definition);
            }
        }
        try {
            return Db::atomic($db, static function() use ($db,$state): array {
                $marker=Db::run($db,'SELECT intent,security_review_at,credentials_json FROM native_restore_operation WHERE id=1 FOR UPDATE')->fetch();
                if (!$marker || !hash_equals($state['intent'],$marker['intent'])) throw new DomainException('Eigener Wiederherstellungsnachweis fehlt.');
                if ($marker['credentials_json']!==null) return self::report($marker,$state);
                if ($marker['security_review_at']!==null) throw new DomainException('Unvollständigen Anmeldeprüfungsnachweis prüfen.');
                $at=$state['security_review_at'];
                $report=['format'=>1,'intent'=>$state['intent'],'security_review_at'=>$at];
                $report['sessions_revoked']=Db::run($db,'UPDATE native_auth_sessions SET revoked_at=? WHERE revoked_at IS NULL',[$at])->rowCount();
                $report['challenges_consumed']=Db::run($db,'UPDATE native_auth_challenges SET consumed_at=? WHERE consumed_at IS NULL',[$at])->rowCount();
                $api=(bool)Db::run($db,"SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='native_api_tokens'")->fetchColumn();
                $report['api_tokens_revoked']=$api?Db::run($db,'UPDATE native_api_tokens SET revoked_at=? WHERE revoked_at IS NULL',[$at])->rowCount():0;
                // Session-bound step-up/address/enrollment records remain original history.
                $report['login_email_codes_consumed']=Db::run($db,"UPDATE native_auth_email_codes SET consumed_at=?,cipher=NULL,status=IF(status='queued','blocked',status) WHERE purpose='login' AND consumed_at IS NULL",[$at])->rowCount();
                $report['reset_tokens_consumed']=Db::run($db,"UPDATE native_auth_tokens SET consumed_at=? WHERE purpose='reset' AND consumed_at IS NULL",[$at])->rowCount();
                $report['invite_tokens_consumed']=Db::run($db,"UPDATE native_auth_tokens SET consumed_at=? WHERE purpose='invite' AND consumed_at IS NULL",[$at])->rowCount();
                $report['recovery_codes_revoked']=Db::run($db,'UPDATE native_auth_recovery SET revoked_at=? WHERE revoked_at IS NULL',[$at])->rowCount();
                // Retain the seed, but never reaccept a step in the restored drift window.
                $floor=intdiv($at,30)+1;
                $report['totp_counters_advanced']=Db::run($db,'UPDATE native_auth_accounts SET totp_counter=GREATEST(totp_counter,?) WHERE totp_secret IS NOT NULL AND totp_counter<?',[$floor,$floor])->rowCount();
                $report['total']=array_sum(array_intersect_key($report,array_flip(self::COUNTS)));
                $report['tables_absent']=$api?[]:['native_api_tokens'];
                Db::run($db,'UPDATE native_restore_operation SET security_review_at=?,credentials_json=? WHERE id=1 AND intent=?',[$at,json_encode($report,JSON_THROW_ON_ERROR),$state['intent']]);
                return $report;
            });
        } catch (\Throwable $failure) {
            // COMMIT may be durable even when its acknowledgement was lost.
            if (!$db->inTransaction()) {
                try {
                    $marker=Db::run($db,'SELECT intent,security_review_at,credentials_json FROM native_restore_operation WHERE id=1')->fetch();
                    if ($marker && $marker['credentials_json']!==null) return self::report($marker,$state);
                } catch (\Throwable) {}
            }
            throw $failure;
        }
    }

    private static function authSchema(PDO $db): void
    {
        // Same lock as the canonical registry; only this auth migration is owned here.
        $lock=substr('native-schema-'.hash('sha256',(string)$db->query('SELECT DATABASE()')->fetchColumn()),0,64);
        if ((int)Db::run($db,'SELECT GET_LOCK(?,10)',[$lock])->fetchColumn()!==1) throw new DomainException('Eine Schemaverwaltung läuft bereits.');
        try {
            $version=Db::run($db,"SELECT version FROM native_schema_versions WHERE module='auth'")->fetchColumn();
            $target=SchemaRegistry::VERSIONS['auth'];
            if ($version===false || !in_array((int)$version,[7,8],true) || $target!==8) throw new DomainException('Unterstützten Anmeldeschemastand prüfen.');
            // Explicit private CLI maintenance; authentication requests never perform DDL.
            \Kulturhus\Native\Auth\Schema::install($db);
            if (!Db::run($db,"SHOW COLUMNS FROM native_auth_recovery LIKE 'revoked_at'")->fetch()) throw new DomainException('Aktuelles Anmeldeschema vor der Wiederherstellungsprüfung bereitstellen.');
            if ((int)$version<$target && Db::run($db,"UPDATE native_schema_versions SET version=?,applied_at=UTC_TIMESTAMP() WHERE module='auth' AND version=?",[$target,(int)$version])->rowCount()!==1) throw new DomainException('Anmeldeschemastand wurde inzwischen geändert.');
        } finally {
            Db::run($db,'SELECT RELEASE_LOCK(?)',[$lock]);
        }
    }

    private static function owner(PDO $db,array $state): void
    {
        if (PHP_SAPI!=='cli' || $db->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql'
            || ($state['phase']??null)!=='review' || !is_string($state['intent']??null)
            || !preg_match('/\A[a-f0-9]{48}\z/D',$state['intent'])
            || !is_int($state['security_review_at']??null) || $state['security_review_at']<1
            || ($state['database']??null)!==$db->query('SELECT DATABASE()')->fetchColumn()
            || ($state['server']??null)!==$db->query('SELECT @@server_uuid')->fetchColumn()) {
            throw new DomainException('Eigene abgeschlossene SQL-Wiederherstellung erforderlich.');
        }
        $marker=Db::run($db,'SELECT intent FROM native_restore_operation WHERE id=1')->fetchColumn();
        if (!is_string($marker) || !hash_equals($state['intent'],$marker)
            || (int)$db->query('SELECT COUNT(*) FROM native_restore_operation')->fetchColumn()!==1) {
            throw new DomainException('Fremder Wiederherstellungsnachweis bleibt unverändert.');
        }
    }

    private static function report(array $marker,array $state): array
    {
        $report=json_decode($marker['credentials_json'],true,16,JSON_THROW_ON_ERROR);
        $keys=['format','intent','security_review_at',...self::COUNTS,'total','tables_absent'];
        if (!is_array($report) || array_diff(array_keys($report),$keys) || array_diff($keys,array_keys($report))
            || $marker['intent']!==$state['intent'] || (int)$marker['security_review_at']!==$state['security_review_at']
            || ($report['format']??null)!==1 || ($report['intent']??null)!==$state['intent']
            || ($report['security_review_at']??null)!==$state['security_review_at']
            || !in_array($report['tables_absent']??null,[[],['native_api_tokens']],true)) {
            throw new DomainException('Ursprünglichen Anmeldeprüfungsnachweis prüfen.');
        }
        foreach ([...self::COUNTS,'total'] as $key) if (!is_int($report[$key]) || $report[$key]<0) throw new DomainException('Ursprünglichen Anmeldeprüfungsnachweis prüfen.');
        if ($report['total']!==array_sum(array_intersect_key($report,array_flip(self::COUNTS)))) throw new DomainException('Ursprünglichen Anmeldeprüfungsnachweis prüfen.');
        return $report;
    }
}
