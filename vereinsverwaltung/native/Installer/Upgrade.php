<?php declare(strict_types=1);
namespace Kulturhus\Native\Installer;

use PDO;
use Kulturhus\Native\Shared\{OperationGate,DatabaseHealth};

/** Durable package replacement for an existing native deployment; no initialization or transport. */
final class Upgrade
{
    public const BATCH=200;
    public function __construct(private string $root,private string $package,private ?\Closure $checkpoint=null)
    {
        if (realpath($root)!==$root || realpath($package)!==$root.'/private/upgrade-package') throw new \DomainException('Eigenes installiertes Ziel und separates privates Upgradepaket erforderlich.');
    }

    public static function connection(PDO $db,#[\SensitiveParameter] array $environment): void
    {
        if ($db->inTransaction() || $db->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql' || !preg_match('/(?:mysql:|;)dbname=([a-zA-Z0-9_]+)(?:;|$)/',$environment['VEREIN_DATABASE']??'',$match) || $match[1]!==$db->query('SELECT DATABASE()')->fetchColumn()) throw new \DomainException('Upgradeverbindung gehört nicht zur installierten MySQL-Datenbank.');
        $server=(string)$db->query('SELECT VERSION()')->fetchColumn();
        if (stripos($server,'MariaDB')!==false || version_compare(preg_replace('/[^0-9.].*$/','',$server),'8.0.16','<')) throw new \DomainException('MySQL ab 8.0.16 erforderlich.');
    }

    public static function readState(string $root): array
    {
        UpgradeFiles::privateFile($root.'/private/upgrade-state.json');
        UpgradeFiles::privateFile($root.'/private/auth.key');
        $envelope=\PackageStore::json($root.'/private/upgrade-state.json');
        $bytes=json_encode($envelope['state']??null,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
        if (!hash_equals(hash_hmac('sha256',$bytes,self::stateKey($root)),$envelope['mac']??'') || ($envelope['state']['root']??'')!==$root) throw new \DomainException('Upgradefortschritt gehört nicht zu dieser Installation.');
        return $envelope['state'];
    }

    private function save(array $state): array
    {
        $bytes=json_encode($state,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
        UpgradeFiles::atomic($this->root.'/private/upgrade-state.json',json_encode(['state'=>$state,'mac'=>hash_hmac('sha256',$bytes,self::stateKey($this->root))],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n");
        if ($this->checkpoint) ($this->checkpoint)('state-saved:'.$state['phase']);
        return $this->result($state);
    }

    private function result(array $state): array
    {
        $labels=['verify-old'=>'Bisheriges Paket prüfen','verify-new'=>'Neues Paket prüfen','stage'=>'Neues Paket vorbereiten','snapshot'=>'Rückkehr vorbereiten','switch'=>'Programm wechseln','schema'=>'Datenbank aktualisieren','verify-active'=>'Aktualisierung prüfen','finish'=>'Aktualisierung abschließen','done'=>'Aktualisierung abgeschlossen','rollback-db'=>'Datenbank zurücksetzen','rollback-app'=>'Programm zurücksetzen','rollback-finish'=>'Rückkehr abschließen','rolled-back'=>'Bisheriger Stand wiederhergestellt'];
        $count=count($this->proof()['files']);
        return ['status'=>$state['phase'],'label'=>$labels[$state['phase']]??'Upgrade prüfen','cursor'=>$state['cursor']??0,'files'=>$count,'complete'=>in_array($state['phase'],['done','rolled-back'],true)&&!is_file($this->root.'/private/upgrade-hold'),'external_actions'=>0];
    }

    private function proof(): array { return UpgradeFiles::proof($this->package.'/private/app-files.json'); }
    private static function stateKey(string $root): string
    {
        UpgradeFiles::privateFile($root.'/private/auth.key');
        $key=(string)file_get_contents($root.'/private/auth.key');
        if (strlen($key)!==32) throw new \DomainException('Ursprünglichen Authentifizierungsschlüssel prüfen.');
        return hash_hkdf('sha256',$key,32,'kulturhus-package-upgrade-state-v1');
    }
    private function directory(array $state): string { return $this->root.'/private/upgrade-operations/'.$state['intent']; }
    private function environment(): array { UpgradeFiles::privateFile($this->root.'/private/environment.json'); return \PackageStore::json($this->root.'/private/environment.json'); }

    /** A legacy CLI receipt requires its verified original source package once. */
    private function oldManaged(array $installation,?string $previousSource): array
    {
        $file=$this->root.'/private/app-files.json';
        if (isset($installation['app_files_sha256'])) {
            $proof=UpgradeFiles::proof($file);
            if (!hash_equals($installation['app_files_sha256'],hash_file('sha256',$file)) || $proof['source']!==$installation['source']) throw new \DomainException('Bisheriger Paketnachweis wurde verändert.');
            if (isset($installation['app_managed_sha256'])) {
                $managed=UpgradeFiles::proof($this->root.'/private/app-managed.json');
                if (!hash_equals($installation['app_managed_sha256'],hash_file('sha256',$this->root.'/private/app-managed.json'))) throw new \DomainException('Bisherige Paketzuordnung wurde verändert.');
                return $managed;
            }
            return $proof;
        }
        if (!$previousSource || !is_file($previousSource.'/distribution/source-files.json') || !hash_equals($installation['source'],hash_file('sha256',$previousSource.'/distribution/source-files.json'))) throw new \DomainException('Ältere CLI-Installation: das geprüfte bisherige Quellpaket einmal als previous_source angeben.');
        $verified=(new \PackageStore($previousSource.'/distribution'))->verify();
        $entries=$verified['source']['files'];
        foreach ($verified['php']['components'] as $component) {
            $prefix=match($component['id']) {'root-vendor'=>'','banking-vendor'=>'banking/','push-vendor'=>'vereinsverwaltung/push-runtime/',default=>null};
            if ($prefix===null) continue;
            UpgradeFiles::privateFile($this->root.'/private/'.$component['id'].'.json');
            if (\PackageStore::json($this->root.'/private/'.$component['id'].'.json')!==['sha256'=>$component['sha256']]) throw new \DomainException('Bisherige Abhängigkeit gehört zu einem anderen Paket.');
            foreach (\PackageStore::json($previousSource.'/distribution/packages/php-runtime/'.$component['file_manifest']) as $entry) { $entry['path']=$prefix.$entry['path']; $entries[]=$entry; }
        }
        foreach ($entries as $entry) \PackageStore::check($this->root.'/app/'.\PackageStore::relative($entry['path']),$entry);
        return ['format'=>1,'source'=>$installation['source'],'files'=>$entries];
    }

    public function begin(PDO $db,UpgradePermit $permit,?string $previousSource=null): array
    {
        $permit->assertActive($this->root); Requirements::assertReady();
        $gate=OperationGate::exclusive($this->root.'/private');
        try {
            if (is_file($this->root.'/private/upgrade-state.json')) {
                $state=self::readState($this->root);
                if ($state['package']===hash_file('sha256',$this->package.'/private/app-files.json') && ($state['phase']!=='rolled-back' || is_file($this->root.'/private/upgrade-hold'))) return $this->result($state);
                if (!in_array($state['phase'],['done','rolled-back'],true)) throw new \DomainException('Begonnenes Upgrade zuerst fortsetzen oder zurücksetzen.');
            }
            if (file_exists($this->root.'/private/upgrade-hold') || is_link($this->root.'/private/upgrade-hold')) throw new \DomainException('Bestehende Wartung zuerst prüfen.');
            $environment=$this->environment(); self::connection($db,$environment);
            UpgradeFiles::privateFile($this->root.'/installation.json');
            $installation=\PackageStore::json($this->root.'/installation.json');
            if (($installation['provider']??'')!=='native' || ($installation['target']??'')!==$this->root || ($installation['club_id']??'')!==($environment['VEREIN_CLUB']??'') || ($environment['VEREIN_PRIVATE_ROOT']??'')!==$this->root.'/private') throw new \DomainException('Installationsidentität stimmt nicht mit dem Upgradeziel überein.');
            $club=$db->prepare('SELECT id FROM clubs WHERE id=?'); $club->execute([$installation['club_id']]);
            if (!$club->fetchColumn()) throw new \DomainException('Verein fehlt in der eigenen Datenbank.');
            $versions=$db->query('SELECT module,version FROM native_schema_versions')->fetchAll(PDO::FETCH_KEY_PAIR);
            // Status is the sole newly introduced module. All original modules stay mandatory.
            $required=array_intersect_key(SchemaRegistry::VERSIONS,array_flip(['identity','cms','auth','communication','delivery','chat','pos','workflows']));
            if (array_diff_key($versions,SchemaRegistry::VERSIONS) || array_diff_key($required,$versions)) throw new \DomainException('Installierte Fachmodule unvollständig.');
            foreach ($versions as $module=>$version) if ((int)$version<1 || (int)$version>SchemaRegistry::VERSIONS[$module]) throw new \DomainException('Paket unterstützt diese Modulversion nicht; kein Downgrade möglich.');
            if ((int)$db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='native_package_operation'")->fetchColumn()) throw new \DomainException('Datenbank enthält bereits einen anderen Paketvorgang.');
            $new=$this->proof();
            if (($new['modules']??null)!==SchemaRegistry::VERSIONS || ($new['generation']??0)!==PackageVersion::GENERATION || ($new['generation']??0)<=(int)($installation['package_generation']??0)) throw new \DomainException('Paketgeneration und Fachmodule prüfen; gleiches oder älteres Paket wird nicht über eine neuere Installation geschrieben.');
            foreach (['Upgrade.php','UpgradeFiles.php','UpgradePermit.php','SchemaRegistry.php','SqlSnapshot.php','PackageVersion.php'] as $name) if (hash_file('sha256',__DIR__.'/'.$name)!==hash_file('sha256',$this->package.'/app/vereinsverwaltung/native/Installer/'.$name)) throw new \DomainException('Aktualisierung mit dem zu diesem Paket gehörenden Assistenten ausführen.');
            $managed=$this->oldManaged($installation,$previousSource);
            foreach ($managed['files'] as $entry) \PackageStore::check($this->root.'/app/'.\PackageStore::relative($entry['path']),$entry);
            $old=UpgradeFiles::inventory($this->root.'/app',$installation['source']);
            $newPaths=array_fill_keys(array_column($new['files'],'path'),true); $owned=array_fill_keys(array_column($managed['files'],'path'),true); $extras=[];
            foreach ($old['files'] as $entry) {
                $name=$entry['path'];
                if ((!isset($owned[$name]) || str_starts_with($name,'runtime/')) && !isset($newPaths[$name])) $extras[]=$entry;
                elseif (!isset($owned[$name]) && isset($newPaths[$name])) {
                    $expected=$new['files'][array_search($name,array_column($new['files'],'path'),true)];
                    if ($entry['sha256']!==$expected['sha256']) throw new \DomainException('Neue Paketdatei würde eine eigene zusätzliche Datei ersetzen.');
                }
            }
            $originals=[];
            foreach (['environment.json','runtime.php','worker.php','auth.key','private.key','member-bank-keys/v1.key'] as $name) {
                UpgradeFiles::privateFile($this->root.'/private/'.$name);
                if (str_ends_with($name,'.key') && filesize($this->root.'/private/'.$name)!==32) throw new \DomainException('Bisheriger privater Schlüssel beschädigt.');
                $originals[$name]=hash_file('sha256',$this->root.'/private/'.$name);
            }
            $workerBytes=(string)file_get_contents($this->root.'/private/worker.php');
            $keyLiteral=preg_quote(var_export($this->root.'/private/private.key',true),'~');
            if (($environment['KH_NATIVE_PRIVATE_KEY_FILE']??'')!==$this->root.'/private/private.key' || !preg_match('~[\'\"]key_file[\'\"]\s*=>\s*'.$keyLiteral.'~',$workerBytes)) throw new \DomainException('Bestehenden nativen Worker mit dem eigenen ursprünglichen privaten Schlüssel konfigurieren; externe oder dynamische Schlüsselpfade zuerst prüfen.');
            $web=$environment['VEREIN_WEB_ROOT']??$this->root.'/web';
            if (realpath($web)!==$this->root.'/'.basename($web) || in_array(basename($web),['app','private'],true) || is_link($web.'/index.php') || !is_file($web.'/index.php')) throw new \DomainException('Eigener Domain-Webroot neben app/ und private/ erforderlich.');
            $state=['format'=>1,'root'=>$this->root,'intent'=>bin2hex(random_bytes(24)),'package'=>hash_file('sha256',$this->package.'/private/app-files.json'),'old_source'=>$installation['source'],'new_source'=>$new['source'],'database'=>(string)$db->query('SELECT DATABASE()')->fetchColumn(),'server'=>(string)$db->query('SELECT @@server_uuid')->fetchColumn(),'environment'=>$originals['environment.json'],'originals'=>$originals,'web'=>$web,'phase'=>'verify-old','cursor'=>0,'started_at'=>gmdate(DATE_ATOM)];
            $dir=$this->directory($state); \PackageStore::directory($dir);
            foreach (['old-files'=>$old,'old-managed'=>$managed,'extras'=>['format'=>1,'source'=>$installation['source'],'files'=>$extras]] as $name=>$value) Deployment::file($dir.'/'.$name.'.json',json_encode($value,JSON_THROW_ON_ERROR));
            Deployment::file($dir.'/installation.json',(string)file_get_contents($this->root.'/installation.json'));
            foreach (['worker.php','app-files.json','app-managed.json'] as $name) if (is_file($this->root.'/private/'.$name)) Deployment::file($dir.'/'.$name,(string)file_get_contents($this->root.'/private/'.$name));
            Deployment::file($dir.'/web-index.php',(string)file_get_contents($web.'/index.php'));
            Deployment::file($dir.'/web-htaccess',(string)(is_file($web.'/.htaccess')?file_get_contents($web.'/.htaccess'):''));
            $state['artifacts']=[];
            foreach (['old-files.json','old-managed.json','extras.json','installation.json','worker.php','web-index.php','web-htaccess','app-files.json','app-managed.json'] as $name) if (is_file($dir.'/'.$name)) $state['artifacts'][$name]=hash_file('sha256',$dir.'/'.$name);
            $this->save($state);
            // The early guard is independent of app/, so a killed directory switch serves 503.
            UpgradeFiles::atomic($web.'/index.php',Deployment::guardedEntry($this->root,(string)file_get_contents($dir.'/web-index.php')),0644);
            UpgradeFiles::atomic($web.'/.htaccess',Deployment::upgradeRewrite((string)file_get_contents($dir.'/web-htaccess')),0644);
            Deployment::file($this->root.'/private/upgrade-hold',$state['intent']."\n");
            UpgradeFiles::atomic($this->root.'/private/worker.php',"<?php\nif (is_file(".var_export($this->root.'/private/upgrade-hold',true).")) throw new \\DomainException('Aktualisierung läuft.');\n?>".(string)file_get_contents($dir.'/worker.php'));
            $this->quarantineKey($state);
            return $this->result($state);
        } finally { $gate->close(); }
    }

    public function status(UpgradePermit $permit): array
    {
        $permit->assertActive($this->root);
        if (!is_file($this->root.'/private/upgrade-state.json')) return ['status'=>'ready','label'=>'Paket bereit','complete'=>false,'external_actions'=>0];
        $state=self::readState($this->root);
        if ($state['package']!==hash_file('sha256',$this->package.'/private/app-files.json')) throw new \DomainException('Paketfassung passt nicht zum begonnenen Upgrade.');
        return $this->result($state);
    }

    public function step(PDO $db,UpgradePermit $permit): array
    {
        $permit->assertActive($this->root);
        $gate=OperationGate::exclusive($this->root.'/private');
        try {
            $state=self::readState($this->root); $dir=$this->directory($state);
            if ($state['package']!==hash_file('sha256',$this->package.'/private/app-files.json')) throw new \DomainException('Paketfassung passt nicht zum begonnenen Upgrade.');
            $environment=$this->environment(); self::connection($db,$environment);
            if ($state['environment']!==hash_file('sha256',$this->root.'/private/environment.json') || $state['database']!==$db->query('SELECT DATABASE()')->fetchColumn() || $state['server']!==$db->query('SELECT @@server_uuid')->fetchColumn()) throw new \DomainException('Wiederaufnahme gehört zu einer anderen Datenbank oder Konfiguration.');
            foreach ($state['artifacts'] as $name=>$hash) { UpgradeFiles::privateFile($dir.'/'.$name); if ($hash!==hash_file('sha256',$dir.'/'.$name)) throw new \DomainException('Gesichertes Upgradeoriginal wurde verändert.'); }
            foreach ($state['originals'] as $name=>$hash) {
                if ($name==='worker.php') continue;
                $path=$this->root.'/private/'.$name;
                if ($name==='private.key' && !is_file($path)) $path=$dir.'/private.key';
                UpgradeFiles::privateFile($path);
                if ($hash!==hash_file('sha256',$path)) throw new \DomainException('Privates Original wurde während des Upgrades verändert.');
            }
            if (in_array($state['phase'],['done','rolled-back'],true)) {
                if (is_file($this->root.'/private/upgrade-hold')) return $this->complete($db,$state,$state['phase']);
                return $this->result($state);
            }
            // Also closes the small begin/acknowledgement interruption before the hold was saved.
            if (!is_file($this->root.'/private/upgrade-hold')) Deployment::file($this->root.'/private/upgrade-hold',$state['intent']."\n");
            if (!hash_equals($state['intent'],trim((string)file_get_contents($this->root.'/private/upgrade-hold')))) throw new \DomainException('Andere Wartung bleibt unverändert.');
            UpgradeFiles::atomic($state['web'].'/index.php',Deployment::guardedEntry($this->root,(string)file_get_contents($dir.'/web-index.php')),0644);
            $this->quarantineKey($state);
            $proof=$this->proof(); $phase=$state['phase'];
            if (in_array($phase,['verify-old','verify-new','stage','verify-active'],true)) {
                $entries=$phase==='verify-old'?UpgradeFiles::proof($dir.'/old-files.json')['files']:$proof['files'];
                if ($phase==='stage') $entries=[...$entries,...\PackageStore::json($dir.'/extras.json')['files']];
                $end=min(count($entries),$state['cursor']+self::BATCH);
                $newPaths=array_fill_keys(array_column($proof['files'],'path'),true);
                for ($i=$state['cursor'];$i<$end;++$i) {
                    $entry=$entries[$i]; $name=\PackageStore::relative($entry['path']);
                    if ($phase==='stage') UpgradeFiles::copy((isset($newPaths[$name])?$this->package.'/app/':$this->root.'/app/').$name,$dir.'/new-app/'.$name,$entry,$dir.'/copy.partial');
                    else \PackageStore::check(($phase==='verify-new'?$this->package.'/app/':$this->root.'/app/').$name,$entry);
                }
                $state['cursor']=$end;
                if ($end===count($entries)) { $state['phase']=match($phase) {'verify-old'=>'verify-new','verify-new'=>'stage','stage'=>'snapshot','verify-active'=>'finish'}; $state['cursor']=0; }
                return $this->save($state);
            }
            if ($phase==='snapshot') {
                // Originals may have been changed by a hosting uploader between bounded calls.
                foreach (UpgradeFiles::proof($dir.'/old-files.json')['files'] as $entry) \PackageStore::check($this->root.'/app/'.$entry['path'],$entry);
                if (UpgradeFiles::inventory($this->root.'/app',$state['old_source'])!==UpgradeFiles::proof($dir.'/old-files.json')) throw new \DomainException('Bisheriges Programm wurde während der Vorbereitung ergänzt oder verändert.');
                foreach ([...$proof['files'],...\PackageStore::json($dir.'/extras.json')['files']] as $entry) \PackageStore::check($dir.'/new-app/'.$entry['path'],$entry);
                $expectedPaths=array_column([...$proof['files'],...\PackageStore::json($dir.'/extras.json')['files']],'path'); sort($expectedPaths);
                if (array_column(UpgradeFiles::inventory($dir.'/new-app',$proof['source'])['files'],'path')!==$expectedPaths) throw new \DomainException('Vorbereitetes Programm enthält zusätzliche fremde Dateien.');
                $this->snapshot($db,$environment,$dir);
                $state['snapshot']=hash_file('sha256',$dir.'/database.zip');
                $state['assembled']=hash('sha256',json_encode(UpgradeFiles::inventory($dir.'/new-app',$proof['source']),JSON_THROW_ON_ERROR));
                $state['phase']='switch'; return $this->save($state);
            }
            if ($phase==='switch') {
                if (!is_dir($dir.'/old-app')) {
                    foreach (UpgradeFiles::proof($dir.'/old-files.json')['files'] as $entry) \PackageStore::check($this->root.'/app/'.$entry['path'],$entry);
                    if (UpgradeFiles::inventory($this->root.'/app',$state['old_source'])!==UpgradeFiles::proof($dir.'/old-files.json')) throw new \DomainException('Bisheriges Programm wurde nach der Prüfung verändert.');
                    if (!rename($this->root.'/app',$dir.'/old-app')) throw new \DomainException('Bisheriges Programm nicht gesichert.');
                    if ($this->checkpoint) ($this->checkpoint)('old-app-saved');
                }
                if (is_dir($dir.'/new-app')) {
                    if (file_exists($this->root.'/app') || !rename($dir.'/new-app',$this->root.'/app')) throw new \DomainException('Neues Programm nicht übernommen.');
                }
                if ($state['assembled']!==hash('sha256',json_encode(UpgradeFiles::inventory($this->root.'/app',$proof['source']),JSON_THROW_ON_ERROR))) throw new \DomainException('Übernommenes Programm wurde verändert.');
                $this->marker($db,$state);
                $state['phase']='schema'; return $this->save($state);
            }
            if ($phase==='schema') {
                $permit->assertMaintenance($this->root,$db);
                SchemaRegistry::install($db,$permit,$this->root);
                $state['phase']='verify-active'; $state['cursor']=0; return $this->save($state);
            }
            if ($phase==='finish') {
                SchemaRegistry::assertCurrent($db); DatabaseHealth::assertHealthy($db);
                $assembled=UpgradeFiles::inventory($this->root.'/app',$proof['source']);
                if ($state['assembled']!==hash('sha256',json_encode($assembled,JSON_THROW_ON_ERROR))) throw new \DomainException('Aktualisiertes Paket wurde verändert.');
                $files=json_encode($assembled,JSON_THROW_ON_ERROR)."\n"; $managed=json_encode($proof,JSON_THROW_ON_ERROR)."\n";
                UpgradeFiles::atomic($this->root.'/private/app-files.json',$files);
                UpgradeFiles::atomic($this->root.'/private/app-managed.json',$managed);
                $installation=\PackageStore::json($dir.'/installation.json');
                $installation['source']=$proof['source']; $installation['package_mode']='webspace';
                $installation['app_files_sha256']=hash('sha256',$files); $installation['app_managed_sha256']=hash('sha256',$managed);
                $installation['package_generation']=$proof['generation'];
                $installation['modules']=$db->query('SELECT module,version FROM native_schema_versions ORDER BY module')->fetchAll(PDO::FETCH_ASSOC);
                $installation['upgrade']=['intent'=>$state['intent'],'previous_source'=>$state['old_source'],'completed_at'=>gmdate(DATE_ATOM)];
                UpgradeFiles::atomic($this->root.'/installation.json',json_encode($installation,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT)."\n");
                return $this->complete($db,$state,'done');
            }
            if ($phase==='rollback-db') {
                $permit->assertMaintenance($this->root,$db);
                $sql=$this->openSnapshot($dir,$state);
                try {
                    $db->exec('SET FOREIGN_KEY_CHECKS=0');
                    foreach ($db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) if ($table!=='native_package_operation') $db->exec('DROP TABLE `'.str_replace('`','``',$table).'`');
                    $db->exec('SET FOREIGN_KEY_CHECKS=1');
                    if ($this->checkpoint) ($this->checkpoint)('rollback-tables-cleared');
                    SqlSnapshot::import($db,$sql,$permit,$this->root);
                    DatabaseHealth::assertHealthy($db);
                } finally { $db->exec('SET FOREIGN_KEY_CHECKS=1'); if (is_file($sql)) unlink($sql); }
                $state['phase']='rollback-app'; return $this->save($state);
            }
            if ($phase==='rollback-app') {
                if (is_dir($dir.'/old-app')) {
                    foreach (UpgradeFiles::proof($dir.'/old-files.json')['files'] as $entry) \PackageStore::check($dir.'/old-app/'.$entry['path'],$entry);
                    if (is_dir($this->root.'/app')) { if (file_exists($dir.'/rejected-app') || !rename($this->root.'/app',$dir.'/rejected-app')) throw new \DomainException('Neues Programm kann nicht zurückgenommen werden.'); }
                    if (!rename($dir.'/old-app',$this->root.'/app')) throw new \DomainException('Bisheriges Programm nicht wiederhergestellt.');
                }
                foreach (UpgradeFiles::proof($dir.'/old-files.json')['files'] as $entry) \PackageStore::check($this->root.'/app/'.$entry['path'],$entry);
                $state['phase']='rollback-finish'; return $this->save($state);
            }
            if ($phase==='rollback-finish') {
                UpgradeFiles::atomic($this->root.'/installation.json',(string)file_get_contents($dir.'/installation.json'));
                foreach (['app-files.json','app-managed.json'] as $name) {
                    if (is_file($dir.'/'.$name)) UpgradeFiles::atomic($this->root.'/private/'.$name,(string)file_get_contents($dir.'/'.$name));
                    elseif (is_file($this->root.'/private/'.$name)) unlink($this->root.'/private/'.$name);
                }
                return $this->complete($db,$state,'rolled-back');
            }
            throw new \DomainException('Unbekannter Upgrade-Schritt.');
        } finally { $gate->close(); }
    }

    public function rollback(PDO $db,UpgradePermit $permit): array
    {
        $permit->assertActive($this->root); $gate=OperationGate::exclusive($this->root.'/private');
        try {
            $state=self::readState($this->root); self::connection($db,$this->environment());
            if ($state['package']!==hash_file('sha256',$this->package.'/private/app-files.json') || $state['server']!==$db->query('SELECT @@server_uuid')->fetchColumn()) throw new \DomainException('Rückkehr gehört zu einer anderen Paketfassung oder Datenbank.');
            if ($state['phase']==='done') throw new \DomainException('Abgeschlossenes Upgrade nicht nachträglich zurücksetzen; neue Betriebsdaten bleiben unverändert.');
            if ($state['phase']==='rolled-back' || str_starts_with($state['phase'],'rollback-')) return $this->result($state);
            $dir=$this->directory($state);
            if (is_dir($dir.'/old-app')) {
                $this->marker($db,$state);
                $state['phase']='rollback-db';
            } else $state['phase']='rollback-finish';
            $state['cursor']=0; return $this->save($state);
        } finally { $gate->close(); }
    }

    private function marker(PDO $db,array $state): void
    {
        $exists=(int)$db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='native_package_operation'")->fetchColumn();
        if (!$exists) $db->exec('CREATE TABLE native_package_operation (id INT PRIMARY KEY,intent VARCHAR(48) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $intent=$db->query('SELECT intent FROM native_package_operation WHERE id=1')->fetchColumn();
        if ($intent===false) { $query=$db->prepare('INSERT INTO native_package_operation VALUES(1,?)'); $query->execute([$state['intent']]); }
        elseif (!hash_equals($state['intent'],(string)$intent)) throw new \DomainException('Andere Datenbankwartung bleibt unverändert.');
    }

    private function snapshot(PDO $db,array $environment,string $dir): void
    {
        foreach (scandir($dir) as $name) if (preg_match('/\Adatabase\.sql\.partial-[a-f0-9]{16}\z/D',$name)) { UpgradeFiles::privateFile($dir.'/'.$name); unlink($dir.'/'.$name); }
        if (is_file($dir.'/database.zip')) { $this->openSnapshot($dir,['snapshot'=>hash_file('sha256',$dir.'/database.zip')]); unlink($dir.'/rollback.sql'); return; }
        require_once dirname(__DIR__,2).'/src/Infrastructure/Database/Database.php';
        require_once dirname(__DIR__,2).'/src/Infrastructure/Database/MySqlBackup.php';
        $sql=$dir.'/database.sql'; if (is_file($sql)) unlink($sql);
        \Kulturhus\Verein\MySqlBackup::create($db,$environment['VEREIN_DATABASE'],$sql);
        $zip=new \ZipArchive(); $temporary=$dir.'/database.partial.zip'; if (is_file($temporary)) unlink($temporary);
        try {
            if ($zip->open($temporary,\ZipArchive::CREATE|\ZipArchive::EXCL)!==true || !$zip->setPassword(bin2hex((string)file_get_contents($this->root.'/private/auth.key'))) || !$zip->addFile($sql,'database.sql') || !$zip->setEncryptionName('database.sql',\ZipArchive::EM_AES_256)) throw new \DomainException('Private Upgrade-Rückkehr nicht verfügbar.');
            $proof=json_encode(['bytes'=>filesize($sql),'sha256'=>hash_file('sha256',$sql)],JSON_THROW_ON_ERROR);
            if (!$zip->addFromString('proof.json',$proof) || !$zip->setEncryptionName('proof.json',\ZipArchive::EM_AES_256) || !$zip->close() || !chmod($temporary,0600) || !rename($temporary,$dir.'/database.zip')) throw new \DomainException('Private Upgrade-Rückkehr nicht gespeichert.');
        } finally { if (is_file($sql)) unlink($sql); }
    }

    private function openSnapshot(string $dir,array $state): string
    {
        UpgradeFiles::privateFile($dir.'/database.zip');
        if (!hash_equals($state['snapshot'],hash_file('sha256',$dir.'/database.zip'))) throw new \DomainException('Upgrade-Snapshot wurde verändert.');
        $zip=new \ZipArchive(); $sql=$dir.'/rollback.sql';
        if ($zip->open($dir.'/database.zip',\ZipArchive::RDONLY)!==true || !$zip->setPassword(bin2hex((string)file_get_contents($this->root.'/private/auth.key')))) throw new \DomainException('Upgrade-Snapshot nicht verfügbar.');
        try {
            $proof=json_decode((string)$zip->getFromName('proof.json'),true,8,JSON_THROW_ON_ERROR);
            if ($zip->numFiles!==2 || !is_int($proof['bytes']??null) || !preg_match('/\A[a-f0-9]{64}\z/D',$proof['sha256']??'')) throw new \DomainException('Upgrade-Snapshot unvollständig.');
            if (is_file($sql)) unlink($sql);
            $input=$zip->getStream('database.sql'); if (!$input) throw new \DomainException('Upgrade-Snapshot nicht lesbar.');
            try { Deployment::copy($input,$sql,$proof,0600); } finally { fclose($input); }
            return $sql;
        } finally { $zip->close(); }
    }

    private function complete(PDO $db,array $state,string $phase): array
    {
        $dir=$this->directory($state);
        UpgradeFiles::atomic($this->root.'/private/worker.php',(string)file_get_contents($dir.'/worker.php'));
        if (!is_file($this->root.'/private/private.key')) {
            UpgradeFiles::privateFile($dir.'/private.key');
            if ($state['originals']['private.key']!==hash_file('sha256',$dir.'/private.key') || !rename($dir.'/private.key',$this->root.'/private/private.key')) throw new \DomainException('Ursprünglicher privater Schlüssel nicht freigegeben.');
        }
        // Commit completion before releasing the durable hold; a lost response is idempotent.
        $state['phase']=$phase; $state['completed_at']=gmdate(DATE_ATOM);
        $result=$this->save($state);
        if ((int)$db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='native_package_operation'")->fetchColumn()) {
            $intent=$db->query('SELECT intent FROM native_package_operation WHERE id=1')->fetchColumn();
            if (!hash_equals($state['intent'],(string)$intent)) throw new \DomainException('Andere Wartung bleibt gesperrt.');
            $db->exec('DROP TABLE native_package_operation');
        }
        if (is_file($this->root.'/private/upgrade-hold')) unlink($this->root.'/private/upgrade-hold');
        return $this->result($state);
    }

    /** Old workers read this key only after obtaining their request gate. Keep even pre-upgrade code inert after SIGKILL. */
    private function quarantineKey(array $state): void
    {
        $original=$this->root.'/private/private.key'; $held=$this->directory($state).'/private.key';
        if (is_file($held)) { UpgradeFiles::privateFile($held); if (is_file($original)) throw new \DomainException('Privater Schlüssel ist während Wartung doppelt vorhanden.'); }
        else {
            UpgradeFiles::privateFile($original);
            if ($state['originals']['private.key']!==hash_file('sha256',$original) || !rename($original,$held)) throw new \DomainException('Bisheriger Schlüssel konnte nicht privat gesperrt werden.');
        }
    }
}
