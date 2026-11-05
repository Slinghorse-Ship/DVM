<?php declare(strict_types=1);
namespace Kulturhus\Native\Installer;

use PDO;
use Kulturhus\Native\Integration\{ManagerBridge,ProductionSite};
use Kulturhus\Native\Shared\Db;
use Kulturhus\Native\Auth\Runtime;

/** Verified native deployment into a NEW private root and empty database. */
final class Install
{
    public function __construct(private string $source) {}

    public function run(array $config, PDO $db, bool $resume=false): array
    {
        if (PHP_SAPI !== 'cli' || $db->inTransaction()) throw new \DomainException('Private CLI-Installation erforderlich.');
        require_once $this->source.'/distribution/src/PackageStore.php';
        Requirements::assertReady();
        $config = $this->validate($config);
        preg_match('/(?:mysql:|;)dbname=([a-zA-Z0-9_]+)/',$config['dsn'],$databaseName);
        if (($databaseName[1]??null)!==(string)$db->query('SELECT DATABASE()')->fetchColumn()) throw new \DomainException('Verbindung gehört nicht zur konfigurierten Datenbank.');
        $target = $config['target'];
        $verified = (new \PackageStore($this->source.'/distribution'))->verify();
        $identity = hash('sha256',json_encode([
            $target,$config['dsn'],$config['username'],$config['club_id'],$config['club_name'],$config['hosts'],$config['admin_email'],$config['scheduler_php']
        ],JSON_THROW_ON_ERROR));
        $sourceHash = hash_file('sha256',$this->source.'/distribution/source-files.json');
        if (is_dir($target) && array_diff(scandir($target),['.','..'])) {
            if (!$resume || !is_file($target.'/progress.json')) throw new \DomainException('Ziel nicht leer; bestehende Dateien bleiben unverändert.');
        } else {
            if ($resume) throw new \DomainException('Kein begonnener Installationsvorgang vorhanden.');
            if ((int)$db->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchColumn() !== 0) {
                throw new \DomainException('Neue Installation benötigt eine leere eigene Datenbank.');
            }
            \PackageStore::directory($target);
            chmod($target,0700);
            self::write($target.'/progress.json',json_encode(['format'=>1,'identity'=>$identity,'source'=>$sourceHash],JSON_THROW_ON_ERROR),0600);
        }
        $lock = fopen($target.'/install.lock','c');
        if (!$lock || !flock($lock,LOCK_EX|LOCK_NB)) throw new \DomainException('Eine Installation läuft bereits.');
        try {
            $state = \PackageStore::json($target.'/progress.json');
            if (($state['format']??0)!==1 || !hash_equals($identity,$state['identity']??'') || !hash_equals($sourceHash,$state['source']??'')) {
                throw new \DomainException('Wiederaufnahme gehört zu einer anderen Konfiguration oder Paketfassung.');
            }
            if (is_file($target.'/installation.json')) return $this->check($target,$db);
            foreach (['app','private','private/media','private/sessions','web'] as $dir) {
                \PackageStore::directory($target.'/'.$dir); chmod($target.'/'.$dir,0700);
            }
            foreach ($verified['source']['files'] as $entry) {
                $name = \PackageStore::relative($entry['path']);
                $data = file_get_contents($this->source.'/'.$name);
                if ($entry['normalized_lf']??false) $data = str_replace("\r\n","\n",$data);
                if (strlen($data)!==$entry['bytes'] || !hash_equals($entry['sha256'],hash('sha256',$data))) throw new \DomainException('Quelldatei wurde während der Installation verändert.');
                self::write($target.'/app/'.$name,$data,($entry['executable']??false)?0755:0644);
            }
            foreach ($verified['php']['components'] as $component) {
                if (!in_array($component['id'],['root-vendor','banking-vendor','push-vendor'],true)) continue;
                $base = match ($component['id']) {
                    'root-vendor'=>$target.'/app', 'banking-vendor'=>$target.'/app/banking',
                    'push-vendor'=>$target.'/app/vereinsverwaltung/push-runtime'
                };
                $destination = $base.'/vendor';
                $receipt = $target.'/private/'.$component['id'].'.json';
                if (is_file($receipt)) {
                    if (\PackageStore::json($receipt)!==['sha256'=>$component['sha256']]) throw new \DomainException('Abhängigkeit gehört zu einer anderen Paketfassung.');
                    $this->checkDependency($destination,$component); continue;
                }
                if (is_dir($destination)) {
                    // Rename succeeded before receipt persistence: validate every packaged file.
                    $this->checkDependency($destination,$component);
                } else {
                    $stage = $target.'/private/stage-'.bin2hex(random_bytes(12));
                    \PackageStore::extract($this->source.'/distribution/packages/php-runtime/'.$component['archive'],$stage);
                    $this->checkDependency($stage.'/vendor',$component);
                    if (!rename($stage.'/vendor',$destination)) throw new \RuntimeException('Abhängigkeit konnte nicht übernommen werden.');
                    rmdir($stage);
                }
                self::write($receipt,json_encode(['sha256'=>$component['sha256']],JSON_THROW_ON_ERROR),0600);
            }
            $modules = Initialize::run($this->source,$target,$config,$db);
            $appProof=json_encode(UpgradeFiles::inventory($target.'/app',$sourceHash),JSON_THROW_ON_ERROR)."\n";
            self::write($target.'/private/app-files.json',$appProof,0600);
            self::write($target.'/installation.json',json_encode([
                'format'=>1,'target'=>$target,'source'=>$sourceHash,'club_id'=>$config['club_id'],'modules'=>$modules,'provider'=>'native',
                'app_files_sha256'=>hash('sha256',$appProof),
                'package_generation'=>PackageVersion::GENERATION,'scheduler_php'=>$config['scheduler_php'],
                'optional_integrations'=>'disabled-until-configured-and-validated'
            ],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n",0600);
            return $this->check($target,$db);
        } finally { flock($lock,LOCK_UN); fclose($lock); }
    }

    private function validate(array $config): array
    {
        foreach (['target','dsn','username','password','club_id','club_name','hosts','admin_email','admin_password'] as $key) {
            if (!is_string($config[$key]??null) || $config[$key]==='' || str_contains($config[$key],"\0")) throw new \DomainException('Einrichtungsangabe fehlt: '.$key);
        }
        if (!preg_match('/\A[a-z][a-z0-9-]{0,62}\z/D',$config['club_id']) || mb_strlen($config['club_name'])>160
            || !filter_var($config['admin_email'],FILTER_VALIDATE_EMAIL) || strlen($config['admin_password'])<16) throw new \DomainException('Vereins- und Kontoangaben prüfen.');
        require_once $this->source.'/vereinsverwaltung/src/Infrastructure/Runtime/ProductionRuntime.php';
        if (!\Kulturhus\Verein\ProductionRuntime::allowedHosts($config['hosts'])) throw new \DomainException('HTTPS-Domain ohne Schema, Port oder Pfad erforderlich.');
        if (!preg_match('/\Amysql:[^\r\n]*dbname=[a-zA-Z0-9_]+(?:;[^\r\n]*)?\z/D',$config['dsn']) || preg_match('/(?:password|passwd|user)=/i',$config['dsn'])) throw new \DomainException('MySQL-DSN ohne Zugangsdaten erforderlich.');
        $target = rtrim($config['target'],'/');
        if (!str_starts_with($target,'/') || $target==='' || str_contains($target,'\\') || preg_match('~(?:^|/)\.\.?(/|$)~',$target)) throw new \DomainException('Absolutes neues Zielverzeichnis erforderlich.');
        for ($path=$target;$path!=='/';$path=dirname($path)) if (is_link($path)) throw new \DomainException('Keine symbolischen Links im Installationsziel.');
        $parent = realpath(dirname($target)); $source = realpath($this->source);
        if (!$parent || !$source) throw new \DomainException('Quell- und Elternverzeichnis müssen bestehen.');
        $target = $parent.'/'.basename($target);
        if ($target===$source || str_starts_with($target,$source.'/')) throw new \DomainException('Private Installation außerhalb des Quellcheckouts wählen.');
        $config['target']=$target;
        $config['scheduler_php']=ScheduledJobs::executable($config['scheduler_php']??null);
        ScheduledJobs::plan($target,$config['scheduler_php']);
        return $config;
    }

    private static function write(string $file,string $data,int $mode):void
    {
        if (file_exists($file)) {
            if (is_link($file) || !is_file($file) || !hash_equals(hash('sha256',$data),hash_file('sha256',$file))) throw new \DomainException('Bestehende Datei bleibt unverändert: '.basename($file));
            return;
        }
        \PackageStore::writeNew($file,$data,$mode);
    }

    private function checkDependency(string $vendor,array $component):void
    {
        $manifest = \PackageStore::json($this->source.'/distribution/packages/php-runtime/'.$component['file_manifest']);
        foreach ($manifest['files'] ?? $manifest as $entry) {
            $name=\PackageStore::relative($entry['path']);
            $base=dirname($vendor);
            \PackageStore::check($base.'/'.$name,$entry);
        }
    }

    public function check(string $target,PDO $db):array
    {
        $state = \PackageStore::json($target.'/installation.json');
        if (($state['provider']??'')!=='native' || ($state['target']??'')!==realpath($target)) throw new \DomainException('Installationsnachweis passt nicht zum Ziel.');
        if (!hash_equals($state['source']??'',hash_file('sha256',$this->source.'/distribution/source-files.json'))) throw new \DomainException('Installationsnachweis gehört zu einer anderen Paketfassung.');
        $verified=(new \PackageStore($this->source.'/distribution'))->verify();
        if (($state['package_mode']??'')==='webspace') {
            $proof=UpgradeFiles::proof($target.'/private/app-files.json');
            if ($proof['source']!==$state['source'] || !hash_equals($state['app_files_sha256']??'',hash_file('sha256',$target.'/private/app-files.json'))) throw new \DomainException('Installiertes PHP-Paketinventar wurde verändert.');
            foreach ($proof['files'] as $entry) \PackageStore::check($target.'/app/'.\PackageStore::relative($entry['path']),$entry);
        } else foreach($verified['source']['files'] as $entry)\PackageStore::check($target.'/app/'.\PackageStore::relative($entry['path']),$entry);
        foreach($verified['php']['components'] as $component){
            $base=match($component['id']){'root-vendor'=>$target.'/app','banking-vendor'=>$target.'/app/banking','push-vendor'=>$target.'/app/vereinsverwaltung/push-runtime',default=>null};
            if($base!==null)$this->checkDependency($base.'/vendor',$component);
        }
        SchemaRegistry::assertCurrent($db);
        foreach (['environment.json','runtime.php','worker.php','auth.key','private.key','member-bank-keys/v1.key'] as $name) {
            $file=$target.'/private/'.$name;
            if (!is_file($file) || is_link($file) || (fileperms($file)&0077)) throw new \DomainException('Private Laufzeitdatei fehlt oder ist öffentlich lesbar.');
            if (str_ends_with($name,'.key') && strlen((string)file_get_contents($file))!==32) throw new \DomainException('Privater Schlüssel beschädigt.');
        }
        return ['status'=>'installed','provider'=>'native','webroot'=>$target.'/web','modules'=>count(SchemaRegistry::VERSIONS),'external_actions'=>0,'scheduler'=>ScheduledJobs::plan($target,$state['scheduler_php']??'php'),'scheduler_text'=>ScheduledJobs::text(ScheduledJobs::plan($target,$state['scheduler_php']??'php'))];
    }
}
