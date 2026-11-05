<?php declare(strict_types=1);
namespace Kulturhus\Native\Pos;
use PDO;
final class Schema
{
    public static function install(PDO $db):void
    {
        foreach([
            "CREATE TABLE IF NOT EXISTS pos_products(club_id VARCHAR(64) NOT NULL,id CHAR(32) NOT NULL,label VARCHAR(160) NOT NULL,price_cents INT NOT NULL,color CHAR(7) NOT NULL,kind VARCHAR(16) NOT NULL,sphere VARCHAR(24) NOT NULL,active TINYINT NOT NULL,version INT NOT NULL,PRIMARY KEY(club_id,id))",
            "CREATE TABLE IF NOT EXISTS pos_shifts(club_id VARCHAR(64) NOT NULL,id CHAR(32) NOT NULL,event_id CHAR(32) NOT NULL,cash_account CHAR(32) NOT NULL,opened_by VARCHAR(128) NOT NULL,state VARCHAR(16) NOT NULL,opening_cents INT NOT NULL,counted_cents INT NULL,transfer_id CHAR(32) NULL,opened_at DATETIME NOT NULL,closed_at DATETIME NULL,PRIMARY KEY(club_id,id))",
            "CREATE TABLE IF NOT EXISTS pos_sales(club_id VARCHAR(64) NOT NULL,id CHAR(32) NOT NULL,shift_id CHAR(32) NOT NULL,state VARCHAR(24) NOT NULL,payload JSON NOT NULL,total_cents INT NOT NULL,cash_cents INT NOT NULL DEFAULT 0,voucher_cents INT NOT NULL DEFAULT 0,request_key CHAR(32) NOT NULL,fingerprint CHAR(64) NOT NULL,actor_id VARCHAR(128) NOT NULL,version INT NOT NULL,created_at DATETIME NOT NULL,receipt_json JSON NULL,PRIMARY KEY(club_id,id),UNIQUE(club_id,request_key))",
            "CREATE TABLE IF NOT EXISTS pos_attempts(club_id VARCHAR(64) NOT NULL,id CHAR(32) NOT NULL,sale_id CHAR(32) NOT NULL,method VARCHAR(24) NOT NULL,amount_cents INT NOT NULL,state VARCHAR(24) NOT NULL,reference VARCHAR(64) NOT NULL,external_id VARCHAR(100) NULL,result_json JSON NULL,created_at DATETIME NOT NULL,PRIMARY KEY(club_id,id),UNIQUE(reference))",
            "CREATE TABLE IF NOT EXISTS pos_transactions(provider VARCHAR(16) NOT NULL,merchant VARCHAR(100) NOT NULL,transaction_id VARCHAR(100) NOT NULL,club_id VARCHAR(64) NOT NULL,attempt_id CHAR(32) NOT NULL,PRIMARY KEY(provider,merchant,transaction_id))",
            "CREATE TABLE IF NOT EXISTS pos_value_vouchers(club_id VARCHAR(64) NOT NULL,id CHAR(32) NOT NULL,code_hash CHAR(64) NOT NULL,issued_sale CHAR(32) NOT NULL,face_cents INT NOT NULL,remaining_cents INT NOT NULL,state VARCHAR(24) NOT NULL,PRIMARY KEY(club_id,id),UNIQUE(club_id,code_hash))",
            "CREATE TABLE IF NOT EXISTS pos_value_moves(club_id VARCHAR(64) NOT NULL,id CHAR(32) NOT NULL,voucher_id CHAR(32) NOT NULL,sale_id CHAR(32) NOT NULL,cents INT NOT NULL,kind VARCHAR(16) NOT NULL,PRIMARY KEY(club_id,id),UNIQUE(club_id,voucher_id,sale_id,kind))",
            "CREATE TABLE IF NOT EXISTS pos_refunds(club_id VARCHAR(64) NOT NULL,sale_id CHAR(32) NOT NULL,state VARCHAR(24) NOT NULL,reason VARCHAR(500) NOT NULL,actor_id VARCHAR(128) NOT NULL,result_json JSON NULL,created_at DATETIME NOT NULL,PRIMARY KEY(club_id,sale_id))"
        ] as $sql)$db->exec($sql.' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin');
        if(!\Kulturhus\Native\Shared\Db::run($db,"SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='pos_products' AND column_name='benefit_label'")->fetchColumn())$db->exec("ALTER TABLE pos_products ADD benefit_label VARCHAR(160) NOT NULL DEFAULT ''");
    }
}
