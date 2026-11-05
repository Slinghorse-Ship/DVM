<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use PDO;
use RuntimeException;

/** All application connections use native MySQL SQL; never rewrite query text. */
final class MySqlDatabaseConnection extends PDO
{
    private ?string $writerLock=null;
    public function beginTransaction():bool
    {
        if(parent::inTransaction())return parent::beginTransaction();
        $key='verein-write-'.substr(hash('sha256',(string)$this->query('SELECT DATABASE()')->fetchColumn()),0,48);
        $q=$this->prepare('SELECT GET_LOCK(?,10)');$q->execute([$key]);
        if((int)$q->fetchColumn()!==1)throw new \DomainException('Ein Speichervorgang läuft bereits. Bitte erneut versuchen.');
        $this->writerLock=$key;
        try{return parent::beginTransaction();}catch(\Throwable $e){$this->releaseWriter();throw $e;}
    }
    public function commit():bool
    {
        $result=parent::commit();$this->releaseWriter();return $result;
    }
    public function rollBack():bool
    {
        try{return parent::rollBack();}finally{$this->releaseWriter();}
    }
    private function releaseWriter():void
    {
        if($this->writerLock===null)return;$key=$this->writerLock;$this->writerLock=null;
        $q=$this->prepare('SELECT RELEASE_LOCK(?)');$q->execute([$key]);
    }
}

final class Database
{
    public const SCHEMA_VERSION=135;
    public static function connect(string $dsn, ?string $user=null, ?string $password=null): PDO
    {
        if (!str_starts_with(strtolower($dsn),'mysql:')) {
            throw new RuntimeException('MySQL-Datenbank erforderlich. Dateibasierte Datenbanken werden nicht mehr unterstützt.');
        }
        $foundRows=defined('Pdo\\Mysql::ATTR_FOUND_ROWS')?constant('Pdo\\Mysql::ATTR_FOUND_ROWS'):PDO::MYSQL_ATTR_FOUND_ROWS;
        $db=new MySqlDatabaseConnection($dsn,$user??(getenv('VEREIN_DATABASE_USER')?:null),$password??(getenv('VEREIN_DATABASE_PASSWORD')?:null),[
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES=>false,
            $foundRows=>true,
        ]);
        $db->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
        $db->exec("SET SESSION sql_mode=CONCAT_WS(',',NULLIF(REPLACE(@@sql_mode,'ONLY_FULL_GROUP_BY',''),''),'STRICT_TRANS_TABLES')");
        $db->exec("SET SESSION group_concat_max_len=1048576");
        $db->exec("SET time_zone='+00:00'");
        return $db;
    }
    public static function isMysql(PDO $db): bool { return $db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'; }
    public static function localDemo(): PDO
    {
        return self::connect(LocalRuntime::database());
    }
    public static function tableExists(PDO $db,string $table): bool
    {
        $q=$db->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
        $q->execute([$table]);return (bool)$q->fetchColumn();
    }
    public static function columns(PDO $db,string $table): array
    {
        $q=$db->prepare('SELECT column_name AS name FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? ORDER BY ordinal_position');
        $q->execute([$table]);return array_column($q->fetchAll(),'name');
    }
    /** Keep the two required annual member tariffs available on every club. */
    public static function ensureContributionRates(PDO $db): void
    {
        if (!self::tableExists($db, 'clubs') || !self::tableExists($db, 'settings_catalog') || !self::tableExists($db, 'membership_contribution_rates')) return;
        $rates = [
            'single' => ['id' => 'c1454678e76e4dbe955ed0c81ac439a1', 'name' => 'Einzelmitgliedschaft', 'amount' => '48.00'],
            'family' => ['id' => 'c1454678e76e4dbe955ed0c81ac439a2', 'name' => 'Familienmitgliedschaft', 'amount' => '72.00'],
        ];
        $catalog = $db->prepare('INSERT IGNORE INTO settings_catalog(club_id,id,kind,payload,version,active) VALUES(?,?,\'contribution\',?,?,1)');
        $link = $db->prepare('INSERT IGNORE INTO membership_contribution_rates(club_id,membership_type,catalog_id) VALUES(?,?,?)');
        foreach ($db->query('SELECT id FROM clubs')->fetchAll(PDO::FETCH_COLUMN) as $club) {
            foreach ($rates as $type => $rate) {
                $payload = json_encode(['name' => $rate['name'], 'description' => '', 'amount' => $rate['amount'], 'interval' => 'year', 'billing' => 'current', 'tax_rate' => '0', 'sphere' => '', 'tax_account' => '', 'tags' => '', 'purpose' => '{{BEZEICHNUNG}} {{ABRECHNUNGSZEITRAUM}}', 'pricing' => 'fixed', 'tiers' => [], 'active' => true], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
                $catalog->execute([$club, $rate['id'], $payload, 1]);
                $link->execute([$club, $type, $rate['id']]);
            }
        }
    }

    /** Finish the packaged empty baseline; a journaled installer may resume these idempotent steps. */
    public static function finishBaseline(PDO $db):void
    {
        if(!self::isMysql($db)||$db->inTransaction()||!self::tableExists($db,'schema_migrations'))throw new RuntimeException('Eigene vorbereitete MySQL-Baseline erforderlich.');
        $versions=$db->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
        if($versions){if(count($versions)===1&&(int)$versions[0]===86)return;throw new RuntimeException('Bestehende Migrationshistorie bleibt unverändert.');}
        self::expandTextCapacity($db);
        require_once __DIR__.'/MySqlInvariants.php';MySqlInvariants::install($db);
        $db->prepare('INSERT INTO schema_migrations(version,applied_at) VALUES(86,?)')->execute([gmdate('c')]);
    }

    /** Explicit operator entry point: DDL must never run inside a business transaction. */
    public static function migrate(PDO $db): void
    {
        if(!self::isMysql($db)||$db->inTransaction())throw new RuntimeException('MySQL-Migration benötigt eine eigene Verbindung ohne laufenden Vorgang.');
        $lock='verein-schema-'.substr(hash('sha256',(string)$db->query('SELECT DATABASE()')->fetchColumn()),0,48);
        $q=$db->prepare('SELECT GET_LOCK(?,10)');$q->execute([$lock]);
        if((int)$q->fetchColumn()!==1)throw new RuntimeException('Eine Datenbankwartung läuft bereits.');
        try{
            if(!self::tableExists($db,'schema_migrations')){
                $count=(int)$db->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchColumn();
                if($count!==0)throw new RuntimeException('Neue Installation benötigt eine leere eigene Datenbank; vorhandene Tabellen bleiben unverändert.');
                try{$db->exec((string)file_get_contents(dirname(__DIR__, 2).'/../migrations/mysql/086_baseline.sql'));}
                finally{$db->exec('SET FOREIGN_KEY_CHECKS=1');}
                self::finishBaseline($db);
            }
            $version=(int)$db->query('SELECT MAX(version) FROM schema_migrations')->fetchColumn();
            if($version<79||$version>self::SCHEMA_VERSION)throw new RuntimeException('Dieser MySQL-Stand benötigt eine separat geprüfte Übernahme. Keine automatische Alt-Migration.');
            if($version<83){
                // Native MySQL predecessors still used by the preserved local demo.
                if($version<80){
                    foreach([['settings_assets','bytes','NOT NULL'],['inventory_assets','bytes','NOT NULL'],['document_files','bytes','NOT NULL'],['minutes_files','bytes','NOT NULL'],['club_chat_messages','file_bytes','NULL'],['invoice_mail_originals','bytes','NOT NULL']] as [$table,$column,$null])$db->exec('ALTER TABLE `'.$table.'` MODIFY `'.$column.'` MEDIUMBLOB '.$null);
                    $db->prepare('INSERT INTO schema_migrations(version,applied_at) VALUES(80,?)')->execute([gmdate('c')]);
                }
                if($version<81){
                    foreach([['document_files','snapshot'],['invoice_position_details','details_json'],['invoice_approvals','office_evidence'],['invoice_approvals','snapshot']] as [$table,$column])$db->exec('ALTER TABLE `'.$table.'` MODIFY `'.$column.'` MEDIUMTEXT NOT NULL');
                    $db->prepare('INSERT INTO schema_migrations(version,applied_at) VALUES(81,?)')->execute([gmdate('c')]);
                }
                if($version<82){self::expandTextCapacity($db);$db->prepare('INSERT INTO schema_migrations(version,applied_at) VALUES(82,?)')->execute([gmdate('c')]);}
                $mode=(string)$db->query('SELECT @@sql_mode')->fetchColumn();
                try{$db->exec("SET SESSION sql_mode=CONCAT_WS(',',NULLIF(@@sql_mode,''),'NO_AUTO_VALUE_ON_ZERO')");$db->exec('ALTER TABLE accounting_revisions MODIFY id INTEGER NOT NULL AUTO_INCREMENT');}
                finally{$db->exec('SET SESSION sql_mode='.$db->quote($mode));}
                $db->prepare('INSERT INTO schema_migrations(version,applied_at) VALUES(83,?)')->execute([gmdate('c')]);
            }
            if($version<84){
                self::expandTextCapacity($db);
                $db->prepare('INSERT INTO schema_migrations(version,applied_at) VALUES(84,?)')->execute([gmdate('c')]);
            }
            self::ensureContributionRates($db);
            if($version<85){
                require_once __DIR__.'/MySqlInvariants.php';MySqlInvariants::install($db);
                $db->prepare('INSERT INTO schema_migrations(version,applied_at) VALUES(85,?)')->execute([gmdate('c')]);
            }
            if($version<86){
                require_once __DIR__.'/MySqlConstraints.php';MySqlConstraints::install($db);
                $db->prepare('INSERT INTO schema_migrations(version,applied_at) VALUES(86,?)')->execute([gmdate('c')]);
            }
            if($version<87){
                $db->exec((string)file_get_contents(dirname(__DIR__, 2).'/../migrations/mysql/087_tax_filings.sql'));
                $db->prepare('INSERT INTO schema_migrations(version,applied_at) VALUES(87,?)')->execute([gmdate('c')]);
            }
            if($version<88){
                $db->exec((string)file_get_contents(dirname(__DIR__, 2).'/../migrations/mysql/088_balance_ledger.sql'));
                $db->prepare('INSERT INTO schema_migrations(version,applied_at) VALUES(88,?)')->execute([gmdate('c')]);
            }
            if($version<89){
                $db->exec((string)file_get_contents(dirname(__DIR__, 2).'/../migrations/mysql/089_tax_auto.sql'));
                $db->prepare('INSERT INTO schema_migrations(version,applied_at) VALUES(89,?)')->execute([gmdate('c')]);
            }
            if($version<90){
                $db->exec((string)file_get_contents(dirname(__DIR__, 2).'/../migrations/mysql/090_activity_report_review.sql'));
                $db->prepare('INSERT INTO schema_migrations(version,applied_at) VALUES(90,?)')->execute([gmdate('c')]);
            }
            if($version<91){
                $db->exec((string)file_get_contents(dirname(__DIR__, 2).'/../migrations/mysql/091_tax_attachments.sql'));
                $db->prepare('INSERT INTO schema_migrations(version,applied_at) VALUES(91,?)')->execute([gmdate('c')]);
            }
            if($version<92){
                $db->exec((string)file_get_contents(dirname(__DIR__, 2).'/../migrations/mysql/092_balance_sources.sql'));
                $db->prepare('INSERT INTO schema_migrations(version,applied_at) VALUES(92,?)')->execute([gmdate('c')]);
            }
            if($version<93){
                $db->exec((string)file_get_contents(dirname(__DIR__, 2).'/../migrations/mysql/093_balance_source_history.sql'));
                if(!$db->query("SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='balance_sources' AND column_name='history_sha256'")->fetchColumn())$db->exec('ALTER TABLE balance_sources ADD COLUMN history_sha256 CHAR(64) NULL');
                $db->prepare('INSERT INTO schema_migrations(version,applied_at) VALUES(93,?)')->execute([gmdate('c')]);
            }
            if($version<94){
                $db->exec((string)file_get_contents(dirname(__DIR__, 2).'/../migrations/mysql/094_asset_sale_links.sql'));
                $db->prepare('INSERT INTO schema_migrations(version,applied_at) VALUES(94,?)')->execute([gmdate('c')]);
            }
            if($version<95){
                require_once dirname(__DIR__, 2).'/Events/EventVoucherSchema.php';EventVoucherSchema::install($db);
                $db->prepare('INSERT INTO schema_migrations(version,applied_at) VALUES(95,?)')->execute([gmdate('c')]);
            }
            if($version<96){
                require_once dirname(__DIR__, 2).'/Events/EventVoucherSchema.php';EventVoucherSchema::install($db);
                $db->prepare('INSERT INTO schema_migrations(version,applied_at) VALUES(96,?)')->execute([gmdate('c')]);
            }
            if($version<97){
                $db->exec((string)file_get_contents(dirname(__DIR__, 2).'/../migrations/mysql/097_asset_partial_reviews.sql'));
                foreach(['UPDATE','DELETE'] as $operation){
                    $name='asset_partial_reviews_no_'.strtolower($operation);
                    $body="BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Partial review history is immutable'; END";
                    $q=$db->prepare('SELECT ACTION_TIMING,EVENT_MANIPULATION,EVENT_OBJECT_TABLE,ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME=?');$q->execute([$name]);$existing=$q->fetch();
                    if(!$existing)$db->exec('CREATE TRIGGER `'.$name.'` BEFORE '.$operation.' ON asset_partial_reviews FOR EACH ROW '.$body);
                    elseif($existing!==['ACTION_TIMING'=>'BEFORE','EVENT_MANIPULATION'=>$operation,'EVENT_OBJECT_TABLE'=>'asset_partial_reviews','ACTION_STATEMENT'=>$body])throw new RuntimeException('Teilabgangs-Prüfverlauf: vorhandenen Datenbankschutz prüfen.');
                }
                $db->prepare('INSERT INTO schema_migrations(version,applied_at) VALUES(97,?)')->execute([gmdate('c')]);
            }
            if($version<98){
                $db->exec((string)file_get_contents(dirname(__DIR__, 2).'/../migrations/mysql/098_balance_segments.sql'));
                foreach(['UPDATE','DELETE'] as $operation){
                    $name='balance_segment_reviews_no_'.strtolower($operation);
                    $body="BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Balance segment review history is immutable'; END";
                    $q=$db->prepare('SELECT ACTION_TIMING,EVENT_MANIPULATION,EVENT_OBJECT_TABLE,ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME=?');$q->execute([$name]);$existing=$q->fetch();
                    if(!$existing)$db->exec('CREATE TRIGGER `'.$name.'` BEFORE '.$operation.' ON balance_segment_reviews FOR EACH ROW '.$body);
                    elseif($existing!==['ACTION_TIMING'=>'BEFORE','EVENT_MANIPULATION'=>$operation,'EVENT_OBJECT_TABLE'=>'balance_segment_reviews','ACTION_STATEMENT'=>$body])throw new RuntimeException('Betriebsaufteilung: vorhandenen Datenbankschutz prüfen.');
                }
                $db->prepare('INSERT INTO schema_migrations(version,applied_at) VALUES(98,?)')->execute([gmdate('c')]);
            }
            if($version<99){
                $db->exec((string)file_get_contents(dirname(__DIR__, 2).'/../migrations/mysql/099_balance_clearings.sql'));
                foreach(['UPDATE','DELETE'] as $operation){
                    $name='balance_clearing_reviews_no_'.strtolower($operation);
                    $body="BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Balance clearing history is immutable'; END";
                    $q=$db->prepare('SELECT ACTION_TIMING,EVENT_MANIPULATION,EVENT_OBJECT_TABLE,ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME=?');$q->execute([$name]);$existing=$q->fetch();
                    if(!$existing)$db->exec('CREATE TRIGGER `'.$name.'` BEFORE '.$operation.' ON balance_clearing_reviews FOR EACH ROW '.$body);
                    elseif($existing!==['ACTION_TIMING'=>'BEFORE','EVENT_MANIPULATION'=>$operation,'EVENT_OBJECT_TABLE'=>'balance_clearing_reviews','ACTION_STATEMENT'=>$body])throw new RuntimeException('Verrechnungsnachweis: vorhandenen Datenbankschutz prüfen.');
                }
                $db->prepare('INSERT INTO schema_migrations(version,applied_at) VALUES(99,?)')->execute([gmdate('c')]);
            }
            if($version<100){
                $db->exec((string)file_get_contents(dirname(__DIR__, 2).'/../migrations/mysql/100_balance_tax_reconciliations.sql'));
                foreach(['UPDATE','DELETE'] as $operation){
                    $name='balance_tax_reconciliation_reviews_no_'.strtolower($operation);
                    $body="BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Balance tax reconciliation history is immutable'; END";
                    $q=$db->prepare('SELECT ACTION_TIMING,EVENT_MANIPULATION,EVENT_OBJECT_TABLE,ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME=?');$q->execute([$name]);$existing=$q->fetch();
                    if(!$existing)$db->exec('CREATE TRIGGER `'.$name.'` BEFORE '.$operation.' ON balance_tax_reconciliation_reviews FOR EACH ROW '.$body);
                    elseif($existing!==['ACTION_TIMING'=>'BEFORE','EVENT_MANIPULATION'=>$operation,'EVENT_OBJECT_TABLE'=>'balance_tax_reconciliation_reviews','ACTION_STATEMENT'=>$body])throw new RuntimeException('Steuerliche Nebenrechnung: vorhandenen Datenbankschutz prüfen.');
                }
                $db->prepare('INSERT INTO schema_migrations(version,applied_at) VALUES(100,?)')->execute([gmdate('c')]);
            }
            if($version<101){
                $db->exec((string)file_get_contents(dirname(__DIR__, 2).'/../migrations/mysql/101_balance_closing_corrections.sql'));
                foreach(['UPDATE','DELETE'] as $operation){
                    foreach(['balance_closing_corrections','balance_opening_corrections'] as $table){
                    $name=$table.'_no_'.strtolower($operation);
                    $body="BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Balance closing correction history is immutable'; END";
                    $q=$db->prepare('SELECT ACTION_TIMING,EVENT_MANIPULATION,EVENT_OBJECT_TABLE,ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME=?');$q->execute([$name]);$existing=$q->fetch();
                    if(!$existing)$db->exec('CREATE TRIGGER `'.$name.'` BEFORE '.$operation.' ON '.$table.' FOR EACH ROW '.$body);
                    elseif($existing!==['ACTION_TIMING'=>'BEFORE','EVENT_MANIPULATION'=>$operation,'EVENT_OBJECT_TABLE'=>$table,'ACTION_STATEMENT'=>$body])throw new RuntimeException('Korrekturabschluss: vorhandenen Datenbankschutz prüfen.');
                    }
                }
                $db->prepare('INSERT INTO schema_migrations(version,applied_at) VALUES(101,?)')->execute([gmdate('c')]);
            }
            if($version<102){
                $db->exec((string)file_get_contents(dirname(__DIR__, 2).'/../migrations/mysql/102_balance_periods.sql'));
                foreach(['balance_periods','balance_period_events'] as $table){
                    foreach(['UPDATE','DELETE'] as $operation){
                        $name=$table.'_no_'.strtolower($operation);
                        $body="BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Balance period history is immutable'; END";
                        $q=$db->prepare('SELECT ACTION_TIMING,EVENT_MANIPULATION,EVENT_OBJECT_TABLE,ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME=?');$q->execute([$name]);$existing=$q->fetch();
                        if(!$existing)$db->exec('CREATE TRIGGER `'.$name.'` BEFORE '.$operation.' ON '.$table.' FOR EACH ROW '.$body);
                        elseif($existing!==['ACTION_TIMING'=>'BEFORE','EVENT_MANIPULATION'=>$operation,'EVENT_OBJECT_TABLE'=>$table,'ACTION_STATEMENT'=>$body])throw new RuntimeException('Abschlusszeitraum: vorhandenen Datenbankschutz prüfen.');
                    }
                }
                $db->prepare('INSERT INTO schema_migrations(version,applied_at) VALUES(102,?)')->execute([gmdate('c')]);
            }
            if($version<103){
                $db->exec((string)file_get_contents(dirname(__DIR__, 2).'/../migrations/mysql/103_balance_period_sources.sql'));
                foreach(['UPDATE','DELETE'] as $operation){
                    $name='balance_period_sources_no_'.strtolower($operation);
                    $body="BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Balance period source ownership is immutable'; END";
                    $q=$db->prepare('SELECT ACTION_TIMING,EVENT_MANIPULATION,EVENT_OBJECT_TABLE,ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME=?');$q->execute([$name]);$existing=$q->fetch();
                    if(!$existing)$db->exec('CREATE TRIGGER `'.$name.'` BEFORE '.$operation.' ON balance_period_sources FOR EACH ROW '.$body);
                    elseif($existing!==['ACTION_TIMING'=>'BEFORE','EVENT_MANIPULATION'=>$operation,'EVENT_OBJECT_TABLE'=>'balance_period_sources','ACTION_STATEMENT'=>$body])throw new RuntimeException('Zeitraum-Belegzuordnung: vorhandenen Datenbankschutz prüfen.');
                }
                $db->prepare('INSERT INTO schema_migrations(version,applied_at) VALUES(103,?)')->execute([gmdate('c')]);
            }
            if($version<104){
                $db->exec((string)file_get_contents(dirname(__DIR__, 2).'/../migrations/mysql/104_balance_period_depreciation.sql'));
                $db->prepare('INSERT INTO schema_migrations(version,applied_at) VALUES(104,?)')->execute([gmdate('c')]);
            }
            if($version<105){
                require_once dirname(__DIR__, 2).'/Documents/DocumentPurgeSchema.php';DocumentPurgeSchema::install($db);
                $db->prepare('INSERT INTO schema_migrations(version,applied_at) VALUES(105,?)')->execute([gmdate('c')]);
            }
            if($version<106){
                $db->exec((string)file_get_contents(dirname(__DIR__, 2).'/../migrations/mysql/106_balance_period_sales.sql'));
                foreach(['UPDATE','DELETE'] as $operation){
                    $name='balance_period_sales_no_'.strtolower($operation);
                    $body="BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Balance period sale ownership is immutable'; END";
                    $q=$db->prepare('SELECT ACTION_TIMING,EVENT_MANIPULATION,EVENT_OBJECT_TABLE,ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME=?');$q->execute([$name]);$existing=$q->fetch();
                    if(!$existing)$db->exec('CREATE TRIGGER `'.$name.'` BEFORE '.$operation.' ON balance_period_asset_sales FOR EACH ROW '.$body);
                    elseif($existing!==['ACTION_TIMING'=>'BEFORE','EVENT_MANIPULATION'=>$operation,'EVENT_OBJECT_TABLE'=>'balance_period_asset_sales','ACTION_STATEMENT'=>$body])throw new RuntimeException('Anlagenverkauf: vorhandenen Datenbankschutz prüfen.');
                }
                $db->prepare('INSERT INTO schema_migrations(version,applied_at) VALUES(106,?)')->execute([gmdate('c')]);
            }
            if($version<107){
                $db->exec((string)file_get_contents(dirname(__DIR__, 2).'/../migrations/mysql/107_balance_period_areas.sql'));
                foreach(['UPDATE','DELETE'] as $operation){
                    $name='balance_period_areas_no_'.strtolower($operation);
                    $body="BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Balance period area reviews are immutable'; END";
                    $q=$db->prepare('SELECT ACTION_TIMING,EVENT_MANIPULATION,EVENT_OBJECT_TABLE,ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME=?');$q->execute([$name]);$existing=$q->fetch();
                    if(!$existing)$db->exec('CREATE TRIGGER `'.$name.'` BEFORE '.$operation.' ON balance_period_area_reviews FOR EACH ROW '.$body);
                    elseif($existing!==['ACTION_TIMING'=>'BEFORE','EVENT_MANIPULATION'=>$operation,'EVENT_OBJECT_TABLE'=>'balance_period_area_reviews','ACTION_STATEMENT'=>$body])throw new RuntimeException('Bereichsaufteilung: vorhandenen Datenbankschutz prüfen.');
                }
                $db->prepare('INSERT INTO schema_migrations(version,applied_at) VALUES(107,?)')->execute([gmdate('c')]);
            }
            if($version<108){
                $db->exec((string)file_get_contents(dirname(__DIR__, 2).'/../migrations/mysql/108_balance_period_tax.sql'));
                foreach(['UPDATE','DELETE'] as $operation){
                    $name='balance_period_tax_no_'.strtolower($operation);
                    $body="BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Balance period tax reviews are immutable'; END";
                    $q=$db->prepare('SELECT ACTION_TIMING,EVENT_MANIPULATION,EVENT_OBJECT_TABLE,ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME=?');$q->execute([$name]);$existing=$q->fetch();
                    if(!$existing)$db->exec('CREATE TRIGGER `'.$name.'` BEFORE '.$operation.' ON balance_period_tax_reviews FOR EACH ROW '.$body);
                    elseif($existing!==['ACTION_TIMING'=>'BEFORE','EVENT_MANIPULATION'=>$operation,'EVENT_OBJECT_TABLE'=>'balance_period_tax_reviews','ACTION_STATEMENT'=>$body])throw new RuntimeException('Steuerprüfung: vorhandenen Datenbankschutz prüfen.');
                }
                $db->prepare('INSERT INTO schema_migrations(version,applied_at) VALUES(108,?)')->execute([gmdate('c')]);
            }
            if($version<109){
                $db->exec((string)file_get_contents(dirname(__DIR__, 2).'/../migrations/mysql/109_catalog_imports.sql'));
                $db->prepare('INSERT INTO schema_migrations(version,applied_at) VALUES(109,?)')->execute([gmdate('c')]);
            }
            if($version<110){
                $db->exec((string)file_get_contents(dirname(__DIR__, 2).'/../migrations/mysql/110_balance_mapping_reviews.sql'));
                $db->prepare('INSERT INTO schema_migrations(version,applied_at) VALUES(110,?)')->execute([gmdate('c')]);
            }
            if($version<111){
                $paypalCheck=$db->query("SELECT 1 FROM information_schema.table_constraints WHERE constraint_schema=DATABASE() AND table_name='bank_account_mappings' AND constraint_name='kh111_bank_provider'")->fetchColumn();
                if(!$paypalCheck)$db->exec((string)file_get_contents(dirname(__DIR__, 2).'/../migrations/mysql/111_paypal_account.sql'));
                $db->prepare('INSERT INTO schema_migrations(version,applied_at) VALUES(111,?)')->execute([gmdate('c')]);
            }
            if($version<112){
                $externalCheck=$db->query("SELECT 1 FROM information_schema.table_constraints WHERE constraint_schema=DATABASE() AND table_name='appointments' AND constraint_name='kh112_activity_kind'")->fetchColumn();
                if(!$externalCheck)$db->exec((string)file_get_contents(dirname(__DIR__, 2).'/../migrations/mysql/112_calendar_external_events.sql'));
                $db->prepare('INSERT INTO schema_migrations(version,applied_at) VALUES(112,?)')->execute([gmdate('c')]);
            }
            if($version<113){
                $columns=self::columns($db,'inventory_loans');
                foreach(['window_start','window_end']as$column)if(!in_array($column,$columns,true))$db->exec('ALTER TABLE inventory_loans ADD COLUMN '.$column.' DATETIME NULL');
                if(!$db->query("SELECT 1 FROM information_schema.table_constraints WHERE constraint_schema=DATABASE() AND table_name='inventory_loans' AND constraint_name='kh113_loan_window'")->fetchColumn())$db->exec("ALTER TABLE inventory_loans ADD CONSTRAINT kh113_loan_window CHECK ((window_start IS NULL AND window_end IS NULL) OR (window_start IS NOT NULL AND window_end IS NOT NULL AND window_end>window_start))");
                $db->prepare('INSERT INTO schema_migrations(version,applied_at) VALUES(113,?)')->execute([gmdate('c')]);
            }
            if($version<114){
                $db->exec((string)file_get_contents(dirname(__DIR__, 2).'/../migrations/mysql/114_appointment_series.sql'));
                $db->prepare('INSERT INTO schema_migrations(version,applied_at) VALUES(114,?)')->execute([gmdate('c')]);
            }
            if($version<115){
                $db->exec((string)file_get_contents(dirname(__DIR__, 2).'/../migrations/mysql/115_document_check_creations.sql'));
                $db->prepare('INSERT INTO schema_migrations(version,applied_at) VALUES(115,?)')->execute([gmdate('c')]);
            }
            if($version<116){
                require_once dirname(__DIR__, 2).'/Events/EventVoucherSchema.php';EventVoucherSchema::install($db);
                $db->prepare('INSERT INTO schema_migrations(version,applied_at) VALUES(116,?)')->execute([gmdate('c')]);
            }
            if($version<117){
                $db->exec((string)file_get_contents(dirname(__DIR__, 2).'/../migrations/mysql/117_internal_movements.sql'));
                $db->prepare('INSERT INTO schema_migrations(version,applied_at) VALUES(117,?)')->execute([gmdate('c')]);
            }
            if($version<118){
                $db->exec((string)file_get_contents(dirname(__DIR__, 2).'/../migrations/mysql/118_bank_import_corrections.sql'));
                $db->prepare('INSERT INTO schema_migrations(version,applied_at) VALUES(118,?)')->execute([gmdate('c')]);
            }
            if($version<119){
                $db->exec((string)file_get_contents(dirname(__DIR__, 2).'/../migrations/mysql/119_excess_payment_returns.sql'));
                $db->prepare('INSERT INTO schema_migrations(version,applied_at) VALUES(119,?)')->execute([gmdate('c')]);
            }
            if($version<120){
                $db->exec((string)file_get_contents(dirname(__DIR__, 2).'/../migrations/mysql/120_bank_payment_groups.sql'));
                $db->prepare('INSERT INTO schema_migrations(version,applied_at) VALUES(120,?)')->execute([gmdate('c')]);
            }
            // Version2 bank proof portions reuse the additive120 relations. The
            // explicit marker prevents an older manager from writing this format.
            if($version<121){
                foreach(['bank_payment_groups','bank_payment_group_items','bank_payment_group_links']as$table)if(!self::tableExists($db,$table))throw new RuntimeException('Vollständige Banknachweistabellen vor der Teilbetragsfreigabe prüfen.');
                $db->prepare('INSERT INTO schema_migrations(version,applied_at) VALUES(121,?)')->execute([gmdate('c')]);
            }
            if($version<122){
                $db->exec((string)file_get_contents(dirname(__DIR__, 2).'/../migrations/mysql/122_finance_group_reversals.sql'));
                $db->prepare('INSERT INTO schema_migrations(version,applied_at) VALUES(122,?)')->execute([gmdate('c')]);
            }
            if($version<123){
                $db->exec((string)file_get_contents(dirname(__DIR__, 2).'/../migrations/mysql/123_finance_payment_returns.sql'));
                $db->prepare('INSERT INTO schema_migrations(version,applied_at) VALUES(123,?)')->execute([gmdate('c')]);
            }
            if($version<124){
                $db->exec((string)file_get_contents(dirname(__DIR__, 2).'/../migrations/mysql/124_finance_payment_return_order_links.sql'));
                $db->prepare('INSERT INTO schema_migrations(version,applied_at) VALUES(124,?)')->execute([gmdate('c')]);
            }
            if($version<125){
                $db->exec((string)file_get_contents(dirname(__DIR__, 2).'/../migrations/mysql/125_finance_partial_returns.sql'));
                $db->prepare('INSERT INTO schema_migrations(version,applied_at) VALUES(125,?)')->execute([gmdate('c')]);
            }
            if($version<126){
                $db->exec((string)file_get_contents(dirname(__DIR__, 2).'/../migrations/mysql/126_native_debit_return_events.sql'));
                $db->prepare('INSERT INTO schema_migrations(version,applied_at) VALUES(126,?)')->execute([gmdate('c')]);
            }
            if($version<127){
                $db->exec((string)file_get_contents(dirname(__DIR__, 2).'/../migrations/mysql/127_goods_donation_corrections.sql'));
                foreach(['UPDATE','DELETE']as$operation){
                    $name='goods_correction_immutable_'.strtolower($operation);$body="BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Goods donation correction originals are immutable'; END";
                    $q=$db->prepare('SELECT ACTION_TIMING,EVENT_MANIPULATION,EVENT_OBJECT_TABLE,ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME=?');$q->execute([$name]);$existing=$q->fetch();
                    if($existing&&$existing!==['ACTION_TIMING'=>'BEFORE','EVENT_MANIPULATION'=>$operation,'EVENT_OBJECT_TABLE'=>'goods_donation_corrections','ACTION_STATEMENT'=>$body])throw new RuntimeException('Sachspendenkorrektur-Historienschutz zuerst prüfen.');
                    if(!$existing)$db->exec('CREATE TRIGGER `'.$name.'` BEFORE '.$operation.' ON goods_donation_corrections FOR EACH ROW '.$body);
                }
                $db->prepare('INSERT INTO schema_migrations(version,applied_at) VALUES(127,?)')->execute([gmdate('c')]);
            }
            if($version<128){
                $db->exec((string)file_get_contents(dirname(__DIR__, 2).'/../migrations/mysql/128_finance_payment_tags.sql'));
                foreach(['UPDATE','DELETE']as$operation){
                    $name='payment_tag_event_immutable_'.strtolower($operation);$body="BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Payment tag event originals are immutable'; END";
                    $q=$db->prepare('SELECT ACTION_TIMING,EVENT_MANIPULATION,EVENT_OBJECT_TABLE,ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME=?');$q->execute([$name]);$existing=$q->fetch();
                    if($existing&&$existing!==['ACTION_TIMING'=>'BEFORE','EVENT_MANIPULATION'=>$operation,'EVENT_OBJECT_TABLE'=>'finance_payment_tag_events','ACTION_STATEMENT'=>$body])throw new RuntimeException('Zahlungstag-Historienschutz zuerst prüfen.');
                    if(!$existing)$db->exec('CREATE TRIGGER `'.$name.'` BEFORE '.$operation.' ON finance_payment_tag_events FOR EACH ROW '.$body);
                }
                $db->prepare('INSERT INTO schema_migrations(version,applied_at) VALUES(128,?)')->execute([gmdate('c')]);
            }
            if($version<129){
                $db->exec((string)file_get_contents(dirname(__DIR__, 2).'/../migrations/mysql/129_cost_center_subdivisions.sql'));
                $db->prepare('INSERT INTO schema_migrations(version,applied_at) VALUES(129,?)')->execute([gmdate('c')]);
            }
            if($version<130){
                ReimbursementActionSchema::install($db);
                $db->prepare('INSERT INTO schema_migrations(version,applied_at) VALUES(130,?)')->execute([gmdate('c')]);
            }
            if($version<131){
                $db->exec((string)file_get_contents(dirname(__DIR__, 2).'/../migrations/mysql/131_reimbursement_corrections.sql'));
                $db->prepare('INSERT INTO schema_migrations(version,applied_at) VALUES(131,?)')->execute([gmdate('c')]);
            }
            if($version<132){
                TaxAutoRequestSchema::install($db);
                $db->prepare('INSERT INTO schema_migrations(version,applied_at) VALUES(132,?)')->execute([gmdate('c')]);
            }
            if($version<133){
                TaxFilingCommandSchema::install($db);
                $db->prepare('INSERT INTO schema_migrations(version,applied_at) VALUES(133,?)')->execute([gmdate('c')]);
            }
            if($version<134){
                BalanceFilingCommandSchema::install($db);
                $db->prepare('INSERT INTO schema_migrations(version,applied_at) VALUES(134,?)')->execute([gmdate('c')]);
            }
            if($version<135){
                MemberCardSchema::install($db);
                $db->prepare('INSERT INTO schema_migrations(version,applied_at) VALUES(135,?)')->execute([gmdate('c')]);
            }
        }finally{$q=$db->prepare('SELECT RELEASE_LOCK(?)');$q->execute([$lock]);}
    }
    private static function expandTextCapacity(PDO $db): void
    {
        $fields=$db->query("SELECT c.TABLE_NAME,c.COLUMN_NAME,c.IS_NULLABLE,c.COLUMN_DEFAULT,c.CHARACTER_MAXIMUM_LENGTH,EXISTS(SELECT 1 FROM information_schema.statistics i WHERE i.TABLE_SCHEMA=c.TABLE_SCHEMA AND i.TABLE_NAME=c.TABLE_NAME AND i.COLUMN_NAME=c.COLUMN_NAME) indexed_column FROM information_schema.columns c WHERE c.TABLE_SCHEMA=DATABASE() AND c.DATA_TYPE='varchar'")->fetchAll();
        $changes=[];
        foreach($fields as $f){
            $table=$f['TABLE_NAME'];$column=$f['COLUMN_NAME'];
            if(!preg_match('/^[a-z0-9_]+$/D',$table)||!preg_match('/^[a-z0-9_]+$/D',$column))throw new RuntimeException('Ungültige Spaltenkennung.');
            $type=null;
            if(!(int)$f['indexed_column'])$type='MEDIUMTEXT';
            elseif(in_array($column,['name','title','email'],true)&&(int)$f['CHARACTER_MAXIMUM_LENGTH']<255)$type='VARCHAR(255)';
            if($type===null)continue;
            $definition=$type.($f['IS_NULLABLE']==='YES'?' NULL':' NOT NULL');
            if($f['COLUMN_DEFAULT']!==null)$definition.=' DEFAULT ('.$db->quote((string)$f['COLUMN_DEFAULT']).')';
            $changes[$table][]='MODIFY `'.$column.'` '.$definition;
        }
        foreach($changes as $table=>$definitions)$db->exec('ALTER TABLE `'.$table.'` '.implode(', ',$definitions));
    }
}
