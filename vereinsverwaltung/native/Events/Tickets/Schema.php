<?php declare(strict_types=1);
namespace Kulturhus\Native\Events\Tickets;
use PDO;

/** Explicit module maintenance only. Ticket originals are never reconstructed from browser input. */
final class Schema
{
    public static function install(PDO $db):void
    {
        foreach([
            "CREATE TABLE IF NOT EXISTS native_ticket_refunds (club_id VARCHAR(64) NOT NULL,order_id CHAR(32) NOT NULL,operation_id CHAR(32) NOT NULL,state VARCHAR(24) NOT NULL,reason VARCHAR(500) NOT NULL,actor_id VARCHAR(64) NOT NULL,proof_json JSON NULL,created_at DATETIME(6) NOT NULL,updated_at DATETIME(6) NOT NULL,PRIMARY KEY(club_id,order_id),UNIQUE(club_id,operation_id))",
            "CREATE TABLE IF NOT EXISTS native_ticket_mail (club_id VARCHAR(64) NOT NULL,order_id CHAR(32) NOT NULL,status VARCHAR(24) NOT NULL,created_at DATETIME(6) NOT NULL,updated_at DATETIME(6) NOT NULL,sent_at DATETIME(6) NULL,PRIMARY KEY(club_id,order_id),INDEX(club_id,status,created_at))",
            "CREATE TABLE IF NOT EXISTS native_ticket_offers (club_id VARCHAR(64) NOT NULL,page_id CHAR(32) NOT NULL,version INT UNSIGNED NOT NULL,capacity INT UNSIGNED NOT NULL,price_cents INT UNSIGNED NOT NULL,accounting_sphere VARCHAR(24) NOT NULL DEFAULT 'zweck',active TINYINT UNSIGNED NOT NULL,updated_by VARCHAR(64) NOT NULL,updated_at DATETIME(6) NOT NULL,PRIMARY KEY(club_id,page_id))",
            "CREATE TABLE IF NOT EXISTS native_ticket_orders (club_id VARCHAR(64) NOT NULL,id CHAR(32) NOT NULL,page_id CHAR(32) NOT NULL,version INT UNSIGNED NOT NULL,status VARCHAR(32) NOT NULL,quantity TINYINT UNSIGNED NOT NULL,unit_price_cents INT UNSIGNED NOT NULL,amount_cents INT UNSIGNED NOT NULL,payment_provider VARCHAR(16) NOT NULL,access_hash CHAR(64) NOT NULL,request_hash CHAR(64) NOT NULL,buyer_hash CHAR(64) NOT NULL,ciphertext MEDIUMTEXT NOT NULL,checkout_id VARCHAR(200) NULL,payment_merchant VARCHAR(100) NULL,transaction_id VARCHAR(200) NULL,proof_json JSON NULL,operation_token CHAR(32) NULL,expires_at BIGINT UNSIGNED NOT NULL,created_at DATETIME(6) NOT NULL,updated_at DATETIME(6) NOT NULL,PRIMARY KEY(club_id,id),UNIQUE(club_id,access_hash),UNIQUE(club_id,request_hash),UNIQUE(payment_provider,payment_merchant,transaction_id),INDEX(club_id,page_id,status),INDEX(club_id,buyer_hash,created_at))",
            "CREATE TABLE IF NOT EXISTS native_ticket_passes (club_id VARCHAR(64) NOT NULL,id CHAR(32) NOT NULL,page_id CHAR(32) NOT NULL,order_id CHAR(32) NOT NULL,ordinal TINYINT UNSIGNED NOT NULL,token_hash CHAR(64) NOT NULL,ciphertext TEXT NOT NULL,status VARCHAR(24) NOT NULL,version INT UNSIGNED NOT NULL,checked_in_by VARCHAR(64) NULL,checked_in_at DATETIME(6) NULL,created_at DATETIME(6) NOT NULL,PRIMARY KEY(club_id,id),UNIQUE(club_id,order_id,ordinal),UNIQUE(club_id,token_hash),INDEX(club_id,page_id,status))",
            "CREATE TABLE IF NOT EXISTS native_ticket_history (club_id VARCHAR(64) NOT NULL,order_id CHAR(32) NOT NULL,version INT UNSIGNED NOT NULL,status VARCHAR(32) NOT NULL,reason VARCHAR(64) NOT NULL,actor_id VARCHAR(64) NULL,created_at DATETIME(6) NOT NULL,PRIMARY KEY(club_id,order_id,version))",
            "CREATE TABLE IF NOT EXISTS native_ticket_webhook_events (club_id VARCHAR(64) NOT NULL,provider VARCHAR(16) NOT NULL,event_id VARCHAR(100) NOT NULL,body_hash CHAR(64) NOT NULL,order_id CHAR(32) NOT NULL,created_at DATETIME(6) NOT NULL,PRIMARY KEY(club_id,provider,event_id))"
        ]as$sql)$db->exec($sql.' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin');
    }
}
