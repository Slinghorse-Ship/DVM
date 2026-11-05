<?php declare(strict_types=1);
namespace Kulturhus\Native\Installer;

use PDO;
use ZipArchive;
use Kulturhus\Native\Shared\{DatabaseHealth,OperationGate};

/** Encrypted deployment + logical MySQL snapshot; recovered transports stay on hold. */
final class Recovery
{
    public function __construct(private string $source,private ?\Closure $checkpoint=null) {}

    private function dependencies(): void
    {
        if (PHP_SAPI!=='cli') throw new \DomainException('Private CLI-Wiederherstellung erforderlich.');
        require_once $this->source.'/distribution/src/PackageStore.php';
        require_once $this->source.'/vereinsverwaltung/src/Infrastructure/Database/Database.php';
        require_once $this->source.'/vereinsverwaltung/src/Infrastructure/Database/MySqlBackup.php';
        if (!class_exists(ZipArchive::class) || !ZipArchive::isEncryptionMethodSupported(ZipArchive::EM_AES_256)) throw new \DomainException('AES-256-Archivunterstützung erforderlich.');
    }

    private static function key(#[\SensitiveParameter] string $key): void
    {
        if (!preg_match('/\A[a-f0-9]{64}\z/D',$key)) throw new \DomainException('Privaten Sicherungsschlüssel mit 64 Hexzeichen angeben.');
    }

    public function create(string $root,PDO $db,string $dsn,string $archive,#[\SensitiveParameter] string $key): array
    {
        $this->dependencies(); self::key($key);
        $root=realpath($root)?:throw new \DomainException('Native Installation fehlt.');
        self::connection($db,$dsn);
        $gate=OperationGate::exclusive($root.'/private');
        $temporary=null; $zip=null; $sql=null;
        try {
            if (file_exists($root.'/private/upgrade-hold') || is_link($root.'/private/upgrade-hold')) throw new \DomainException('Begonnene Paketaktualisierung zuerst abschließen oder zurücksetzen.');
            if (file_exists($root.'/restore-progress.json') || file_exists($root.'/private/restore-hold')) throw new \DomainException('Begonnene Gesamtwiederherstellung zuerst fortsetzen.');
            (new Install($this->source))->check($root,$db);
            $environment=\PackageStore::json($root.'/private/environment.json');
            if (($environment['VEREIN_DATABASE']??'')!==$dsn) throw new \DomainException('Sicherungsverbindung gehört nicht zur Installation.');
            foreach ($environment as $value) if (is_string($value) && str_starts_with($value,'/') && !str_starts_with($value,$root.'/')) throw new \DomainException('Externe Laufzeit-/Schlüsselpfade benötigen eine gesonderte Sicherung.');
            $parent=realpath(dirname($archive));
            if (!str_starts_with($archive,'/') || !$parent || (fileperms($parent)&0077) || $parent===$root || str_starts_with($parent,$root.'/') || file_exists($archive) || is_link($archive)) throw new \DomainException('Neue Archivdatei in einem privaten Verzeichnis außerhalb der Installation erforderlich.');
            \PackageStore::relative(basename($archive));
            \PackageStore::directory($parent); // Checks every path component for links.
            $temporary=$parent.'/.native-recovery-'.bin2hex(random_bytes(12)).'.zip';
            $sql=$parent.'/.native-recovery-'.bin2hex(random_bytes(12)).'.sql';
            \Kulturhus\Verein\MySqlBackup::create($db,$dsn,$sql);
            $zip=new ZipArchive();
            if ($zip->open($temporary,ZipArchive::CREATE|ZipArchive::EXCL)!==true || !$zip->setPassword($key)) throw new \DomainException('Privates Sicherungsarchiv nicht verfügbar.');
            $manifest=['format'=>1,'provider'=>'native','started_at'=>gmdate(DATE_ATOM),'root'=>$root,'installation'=>\PackageStore::json($root.'/installation.json'),'files'=>[]];
            $total=0;
            $add=static function(string $path,string $name) use($zip,&$manifest,&$total): void {
                $bytes=filesize($path); $total+=$bytes;
                if ($bytes>134217728 || $total>3000000000 || count($manifest['files'])>=100000) throw new \DomainException('Sicherung überschreitet die Dateigrenzen.');
                if (!$zip->addFile($path,$name) || !$zip->setEncryptionName($name,ZipArchive::EM_AES_256)) throw new \DomainException('Datei konnte nicht verschlüsselt gesichert werden.');
                $manifest['files'][$name]=['bytes'=>$bytes,'sha256'=>hash_file('sha256',$path),'mode'=>fileperms($path)&0777];
            };
            $add($sql,'database.sql');
            foreach (['app','private'] as $directory) {
                $iterator=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/'.$directory,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::SELF_FIRST);
                foreach ($iterator as $file) {
                    $name=substr($file->getPathname(),strlen($root)+1);
                    if ($file->isLink() || (!$file->isFile() && !$file->isDir())) throw new \DomainException('Links und Spezialdateien können nicht gesichert werden.');
                    if (in_array($name,['private/upgrade-state.json','private/upgrade-hold','private/restore-receipt.json','private/restore-hold'],true) || str_starts_with($name,'private/upgrade-package/') || str_starts_with($name,'private/upgrade-sessions/') || str_starts_with($name,'private/upgrade-operations/') || $name==='private/restore-account-codes' || str_starts_with($name,'private/restore-account-codes/')) continue;
                    if ($file->isDir() || str_starts_with($name,'private/sessions/') || str_starts_with($name,'private/cache/') || str_starts_with($name,'private/pdf-tmp/') || in_array($name,['private/operations.lock','private/recovery-hold'],true) || (str_starts_with($name,'private/') && str_ends_with($name,'.lock'))) continue;
                    $add($file->getPathname(),\PackageStore::relative($name));
                }
            }
            if (!$zip->addFromString('manifest.json',json_encode($manifest,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)) || !$zip->setEncryptionName('manifest.json',ZipArchive::EM_AES_256)) throw new \DomainException('Sicherung konnte nicht abgeschlossen werden.');
            $old=umask(0077);
            try { $closed=$zip->close(); } finally { umask($old); }
            if (!$closed) throw new \DomainException('Sicherung konnte nicht abgeschlossen werden.');
            chmod($temporary,0600);
            $zip=null;
            $verified=$this->verify($temporary,$key); // Detect file changes while libzip read sources.
            if (!link($temporary,$archive)) throw new \DomainException('Archivziel wurde inzwischen belegt.');
            return ['status'=>'backed-up','encrypted'=>true,'files'=>count($verified['files']),'bytes'=>filesize($archive),'external_actions'=>0];
        } finally {
            if ($zip) try { $zip->close(); } catch (\Throwable) {}
            foreach ([$temporary,$sql] as $file) if ($file && is_file($file)) unlink($file);
            $gate->close();
        }
    }

    /** Authenticate every byte and every path before creating any restore target. */
    public function verify(string $archive,#[\SensitiveParameter] string $key): array
    {
        $this->dependencies(); self::key($key);
        if (!is_file($archive) || is_link($archive) || (fileperms($archive)&0077)) throw new \DomainException('Privates Sicherungsarchiv fehlt.');
        $zip=new ZipArchive();
        if ($zip->open($archive)!==true) throw new \DomainException('Archiv nicht lesbar.');
        try {
            if (!$zip->setPassword($key)) throw new \DomainException('Sicherungsschlüssel prüfen.');
            $raw=$zip->getFromName('manifest.json',16777217);
            if (!is_string($raw) || strlen($raw)>16777216) throw new \DomainException('Archivmanifest fehlt oder Schlüssel ist ungültig.');
            $manifest=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
            if (($manifest['format']??null)!==1 || ($manifest['provider']??'')!=='native' || !is_array($manifest['files']??null) || count($manifest['files'])>100000 || $zip->numFiles!==count($manifest['files'])+1) throw new \DomainException('Archivbestand ist unvollständig oder enthält zusätzliche Dateien.');
            $seen=[]; $total=0;
            for ($i=0;$i<$zip->numFiles;++$i) {
                $stat=$zip->statIndex($i); $name=\PackageStore::relative($stat['name']);
                if (isset($seen[$name]) || ($stat['encryption_method']??null)!==ZipArchive::EM_AES_256) throw new \DomainException('Doppelte oder unverschlüsselte Archivdatei.');
                $seen[$name]=true;
                $zip->getExternalAttributesIndex($i,$os,$attributes); $kind=($attributes>>16)&0170000;
                if (!in_array($kind,[0,0100000],true)) throw new \DomainException('Keine Links oder Spezialdateien im Archiv.');
                if ($name==='manifest.json') continue;
                if ($name!=='database.sql' && !str_starts_with($name,'app/') && !str_starts_with($name,'private/')) throw new \DomainException('Datei gehört nicht zur nativen Sicherung.');
                $entry=$manifest['files'][$name]??null;
                if (!is_array($entry) || !is_int($entry['bytes']??null) || $entry['bytes']<0 || $entry['bytes']>134217728 || $stat['size']!==$entry['bytes'] || !preg_match('/\A[a-f0-9]{64}\z/D',$entry['sha256']??'') || !is_int($entry['mode']??null) || ($entry['mode']&~0777)) throw new \DomainException('Dateinachweis im Archiv ist ungültig.');
                $total+=$entry['bytes']; if ($total>3000000000) throw new \DomainException('Archiv überschreitet die Entpackgrenze.');
                $input=$zip->getStream($name); if (!$input) throw new \DomainException('Archivdatei kann nicht authentifiziert werden.');
                $hash=hash_init('sha256'); $bytes=0;
                try { while (!feof($input)) { $chunk=fread($input,65536); if ($chunk===false) throw new \DomainException('Archivdatei beschädigt.'); $bytes+=strlen($chunk); if ($bytes>$entry['bytes']) throw new \DomainException('Archivdatei überschreitet die deklarierte Größe.'); hash_update($hash,$chunk); } } finally { fclose($input); }
                if ($bytes!==$entry['bytes'] || !hash_equals($entry['sha256'],hash_final($hash))) throw new \DomainException('Archivdatei wurde verändert.');
            }
            foreach (['database.sql','private/environment.json','private/auth.key','private/private.key','private/member-bank-keys/v1.key'] as $required) if (!isset($seen[$required])) throw new \DomainException('Sicherung enthält nicht alle Laufzeitdateien.');
            foreach (array_keys($manifest['files']) as $name) for ($parent=dirname($name);$parent!=='.';$parent=dirname($parent)) if (isset($seen[$parent])) throw new \DomainException('Archiv verwendet eine Datei gleichzeitig als Verzeichnis.');
            return $manifest;
        } finally { $zip->close(); }
    }

    public function restore(string $archive,#[\SensitiveParameter] string $key,#[\SensitiveParameter] array $config,PDO $db,bool $resume=false): array
    {
        $manifest=$this->verify($archive,$key);
        self::connection($db,$config['dsn']??'');
        foreach (['target','username','password'] as $name) if (!is_string($config[$name]??null) || $config[$name]==='' || str_contains($config[$name],"\0")) throw new \DomainException('Private Wiederherstellungsangabe fehlt: '.$name);
        $path=$config['target']; $parent=realpath(dirname($path));
        if (!str_starts_with($path,'/') || !$parent || is_link($path) || preg_match('~(?:^|/)\.\.?(/|$)~',$path) || (file_exists($path)&&!$resume) || ($resume&&!is_dir($path))) throw new \DomainException('Neues eigenes Ziel oder ausdrückliche Wiederaufnahme desselben begonnenen Ziels erforderlich.');
        \PackageStore::relative(basename($path)); \PackageStore::directory($parent);
        $target=$parent.'/'.basename($path); $source=realpath($this->source);
        if ($target===$source || str_starts_with($target,$source.'/')) throw new \DomainException('Wiederherstellung außerhalb des Quellcheckouts wählen.');
        (new \PackageStore($this->source.'/distribution'))->verify();
        if (!hash_equals($manifest['installation']['source']??'',hash_file('sha256',$this->source.'/distribution/source-files.json'))) throw new \DomainException('Das verifizierte Installationspaket derselben Fassung wird benötigt.');
        $lock='native-restore-'.substr(hash('sha256',(string)$db->query('SELECT DATABASE()')->fetchColumn()),0,48);
        $query=$db->prepare('SELECT GET_LOCK(?,0)'); $query->execute([$lock]);
        if ((int)$query->fetchColumn()!==1) throw new \DomainException('Eine Wiederherstellung läuft bereits.');
        $zip=new ZipArchive(); $sql=null; $opened=false;
        $progress=new RestoreProgress($target,$key);
        $identity=hash_hmac('sha256',json_encode([$target,$config['dsn'],$config['username'],$config['password'],$config['hosts']??null],JSON_THROW_ON_ERROR),hash_hkdf('sha256',hex2bin($key),32,'kulturhus-native-restore-config-v1'));
        $archiveHash=hash_file('sha256',$archive); $sourceHash=hash_file('sha256',$this->source.'/distribution/source-files.json');
        try {
            $opened=$zip->open($archive)===true;
            if (!$opened || !$zip->setPassword($key)) throw new \DomainException('Verifiziertes Archiv nicht verfügbar.');
            $environment=json_decode($zip->getFromName('private/environment.json'),true,32,JSON_THROW_ON_ERROR);
            foreach ($environment as $name=>$value) {
                if (!is_string($value)) throw new \DomainException('Ungültige Laufzeitkonfiguration.');
                if (str_starts_with($value,'/')) {
                    if (!str_starts_with($value,$manifest['root'].'/')) throw new \DomainException('Externe Laufzeitpfade können nicht automatisch umgezogen werden.');
                    $environment[$name]=$target.substr($value,strlen($manifest['root']));
                }
            }
            $environment['VEREIN_DATABASE']=$config['dsn']; $environment['VEREIN_DATABASE_USER']=$config['username']; $environment['VEREIN_DATABASE_PASSWORD']=$config['password'];
            if (isset($config['hosts'])) {
                require_once $this->source.'/vereinsverwaltung/src/Infrastructure/Runtime/ProductionRuntime.php';
                if (!\Kulturhus\Verein\ProductionRuntime::allowedHosts($config['hosts'])) throw new \DomainException('HTTPS-Domain prüfen.');
                $environment['VEREIN_ALLOWED_HOSTS']=$config['hosts'];
            }
            if ($resume) {
                if ((fileperms($target)&0077) || !is_dir($target)) throw new \DomainException('Eigenes privates Wiederherstellungsziel prüfen.');
                $state=$progress->read(!is_file($target.'/restore-progress.json'));
                if ($state['identity']!==$identity || $state['archive']!==$archiveHash || $state['source']!==$sourceHash || $state['database']!==$db->query('SELECT DATABASE()')->fetchColumn() || $state['server']!==$db->query('SELECT @@server_uuid')->fetchColumn()) throw new \DomainException('Wiederaufnahme gehört zu einer anderen Konfiguration, Paketfassung, Archivdatei oder Datenbank.');
                if ($state['phase']!=='done') $progress->marker($db,$state,$state['phase']==='prepare');
            } else {
                if ((int)$db->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchColumn()) throw new \DomainException('Vorhandene Datenbank bleibt unverändert; leeres Ziel erforderlich.');
                \PackageStore::directory($target);chmod($target,0700);
                $state=['format'=>2,'target'=>$target,'intent'=>bin2hex(random_bytes(24)),'identity'=>$identity,'archive'=>$archiveHash,'source'=>$sourceHash,'database'=>$db->query('SELECT DATABASE()')->fetchColumn(),'server'=>$db->query('SELECT @@server_uuid')->fetchColumn(),'phase'=>'prepare','cursor'=>0,'current_file'=>null,'started_at'=>gmdate(DATE_ATOM)];
                $progress->save($state);
                $progress->marker($db,$state,true);
            }
            if ($state['phase']!=='done') {
                \PackageStore::directory($target.'/private');
                Deployment::file($target.'/private/restore-hold',$state['intent']."\n");
                UpgradeFiles::privateFile($target.'/private/restore-hold');
            }
            if ($state['phase']==='prepare') {
                $state['phase']='files';$progress->save($state);
            }
            $sql=$target.'/private/database.restore.sql';
            if ($state['phase']==='files') {
                if ($db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN)!==[RestoreProgress::MARKER]) throw new \DomainException('Zusätzliche fremde Tabellen während der Dateiwiederherstellung bleiben unverändert.');
                $members=array_filter($manifest['files'],static fn($name)=>!in_array($name,['private/environment.json','private/runtime.php','private/worker.php','private/restore-receipt.json','private/restore-hold'],true)&&$name!=='private/restore-account-codes'&&!str_starts_with($name,'private/restore-account-codes/'),ARRAY_FILTER_USE_KEY);
                $names=array_keys($members);
                for ($i=$state['cursor'];$i<count($names);++$i) {
                    $name=$names[$i];$entry=$members[$name];
                    $dest=$name==='database.sql'?$sql:$target.'/'.$name;
                    $owned=$state['current_file']===$name&&($state['file_owned']??false);
                    if ($state['current_file']!==$name) $state['file_owned']=!file_exists($dest)&&!is_link($dest);
                    $state['current_file']=$name;$progress->save($state);
                    $input=$zip->getStream($name);if(!$input)throw new \DomainException('Verifizierte Archivdatei fehlt.');
                    try { RestoreCopy::copy($input,$dest,$entry,str_starts_with($name,'private/')||$name==='database.sql'?0600:(($entry['mode']&0111)?0755:0644),$owned,$this->checkpoint); }
                    finally { fclose($input); }
                    $state['cursor']=$i+1;$state['current_file']=null;$progress->save($state);
                    if ($this->checkpoint) ($this->checkpoint)('file-completed');
                }
                $state['tables']=SqlSnapshot::tables($sql);$state['phase']='sql';$progress->save($state);
            }
            if ($state['phase']==='sql') {
                $progress->assertImport($db);\PackageStore::check($sql,$manifest['files']['database.sql']);
                $allowed=SqlSnapshot::tables($sql);
                if ($allowed!==$state['tables']) throw new \DomainException('Ursprüngliche SQL-Tabellenfassung verändert.');
                $existing=$db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
                if (array_diff($existing,[...$allowed,RestoreProgress::MARKER])) throw new \DomainException('Fremde Tabellen bleiben bei der SQL-Fortsetzung unverändert.');
                $oldFk=(int)$db->query('SELECT @@FOREIGN_KEY_CHECKS')->fetchColumn();
                try {
                    $db->exec('SET FOREIGN_KEY_CHECKS=0');
                    foreach ($existing as $table) if ($table!==RestoreProgress::MARKER) $db->exec('DROP TABLE `'.str_replace('`','``',$table).'`');
                } finally { $db->exec('SET FOREIGN_KEY_CHECKS='.$oldFk); }
                SqlSnapshot::import($db,$sql,$progress,$target,$this->checkpoint);DatabaseHealth::assertHealthy($db);
                $state['phase']='review';$progress->save($state);
            }
            if ($state['phase']==='review') {
                if (!isset($state['security_review_at'])) {
                    $state['security_review_at']=time();$progress->save($state);
                }
                if ($this->checkpoint) ($this->checkpoint)('credentials-review-saved');
                $state['credentials']=RestoreCredentials::review($db,$state);
                if ($this->checkpoint) ($this->checkpoint)('credentials-committed');
                $progress->save($state);
                $db->exec("UPDATE native_delivery_jobs SET status='uncertain',reason='recovery_processing_review' WHERE status='processing'");
                if(\Kulturhus\Native\Shared\Db::run($db,"SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='native_chat_push_deliveries'")->fetchColumn())$db->exec("UPDATE native_chat_push_deliveries SET state='uncertain' WHERE state='sending'");
                Deployment::file($target.'/private/recovery-hold',json_encode(['reason'=>'restore-requires-operator-review','archive'=>$archiveHash,'created_at'=>$state['started_at']],JSON_THROW_ON_ERROR));
                $state['phase']='runtime';$progress->save($state);
            }
            if ($state['phase']==='runtime') {
                Deployment::write($target,$environment);
                $installation=$manifest['installation'];$installation['target']=$target;
                Deployment::file($target.'/installation.json',json_encode($installation,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT)."\n");
                $state['phase']='finish';$progress->save($state);
            }
            if (!in_array($state['phase'],['finish','done'],true)) throw new \DomainException('Unbekannter Wiederherstellungsstand.');
            $result=(new Install($this->source))->check($target,$db);
            DatabaseHealth::assertHealthy($db);
            if ($state['phase']==='finish') {
                $state['phase']='done';$state['completed_at']=gmdate(DATE_ATOM);$progress->save($state);$progress->save($state,true);
                if ($this->checkpoint) ($this->checkpoint)('restore-completed-saved');
            }
            $progress->save($state,true);
            if ((int)$db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='native_restore_operation'")->fetchColumn()) {$progress->marker($db,$state);$db->exec('DROP TABLE native_restore_operation');}
            if (is_file($target.'/private/restore-hold')) unlink($target.'/private/restore-hold');
            if (is_file($target.'/restore-progress.json')) unlink($target.'/restore-progress.json');
            if ($sql && is_file($sql)) unlink($sql);
            return $result+['restored'=>true,'background_jobs'=>is_file($target.'/private/recovery-hold')?'held-for-review':'released','credentials'=>$state['credentials']??null,'external_actions'=>0];
        } finally {
            if ($opened) $zip->close();
            $query=$db->prepare('SELECT RELEASE_LOCK(?)'); $query->execute([$lock]);
        }
    }

    public function release(string $root,PDO $db,bool $reviewed): void
    {
        $this->dependencies();
        if (!$reviewed) throw new \DomainException('Wiederherstellung und offene Zustellungen zuerst prüfen.');
        if (file_exists($root.'/restore-progress.json') || file_exists($root.'/private/restore-hold')) throw new \DomainException('Begonnene Wiederherstellung zuerst vollständig fortsetzen; Prüfung allein gibt eine unterbrochene Kopie nicht frei.');
        $gate=OperationGate::exclusive($root.'/private');
        try {
            $environment=\PackageStore::json($root.'/private/environment.json');
            self::connection($db,$environment['VEREIN_DATABASE']??'');
            (new Install($this->source))->check($root,$db); DatabaseHealth::assertHealthy($db);
            $file=$root.'/private/recovery-hold';
            if (!is_file($file) || is_link($file)) throw new \DomainException('Keine gesperrte Wiederherstellung vorhanden.');
            unlink($file);
        } finally { $gate->close(); }
    }

    private static function connection(PDO $db,string $dsn): void
    {
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql' || $db->inTransaction() || !preg_match('/(?:mysql:|;)dbname=([a-zA-Z0-9_]+)(?:;|$)/',$dsn,$match) || ($match[1]??'')!==(string)$db->query('SELECT DATABASE()')->fetchColumn()) throw new \DomainException('Eigene MySQL-Verbindung muss zur konfigurierten Datenbank gehören.');
    }
}
