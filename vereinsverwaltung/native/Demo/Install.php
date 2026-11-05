<?php declare(strict_types=1);
namespace Kulturhus\Native\Demo;

use Kulturhus\Native\Shared\Db;
use Kulturhus\Native\Integration\{ManagerBridge,ProductionSite};
use Kulturhus\Native\Installer\SchemaRegistry;
use Kulturhus\Native\Auth\Runtime as AuthRuntime;
use Kulturhus\Verein\{Settings,EditorialPolicy,Database};

/** No reset path: a private journal binds an empty or demonstrably owned installation. */
final class Install
{
    public static function run(string $path,?callable $checkpoint=null,?Maintenance $maintenance=null):array
    {
        if(PHP_SAPI!=='cli')throw new \DomainException('Private CLI-Demoeinrichtung erforderlich.');
        $configuration=Configuration::read($path);Configuration::privateFile($configuration['private_root'].'/operator.json');
        $root=$configuration['private_root'];$db=Configuration::connect($configuration);
        $lockPath=$root.'/install.lock';if(!file_exists($lockPath))Configuration::create($lockPath,'');Configuration::privateFile($lockPath);
        $lock=fopen($lockPath,'r+');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))throw new \DomainException('Eine Demo-Einrichtung läuft bereits.');
        try{
            $journalPath=$root.'/install-journal.json';$identity=hash('sha256',json_encode($configuration,JSON_THROW_ON_ERROR));
            if(!is_file($journalPath)){
                if((int)$db->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchColumn()!==0)throw new \DomainException('Neue Demo benötigt eine eigene leere Datenbank.');
                $journal=['format'=>1,'identity'=>$identity,'instance'=>$configuration['instance'],'sample_day'=>(new \DateTimeImmutable('now',new \DateTimeZone('Europe/Berlin')))->format('Y-m-d'),'samples'=>[],'keys'=>[]];
                Configuration::create($journalPath,json_encode($journal,JSON_THROW_ON_ERROR)."\n");
            }else{
                Configuration::privateFile($journalPath);$journal=json_decode((string)file_get_contents($journalPath),true,64,JSON_THROW_ON_ERROR);
                if(($journal['format']??null)!==1||!hash_equals($identity,$journal['identity']??'')||($journal['instance']??null)!==$configuration['instance']||!is_array($journal['samples']??null))throw new \DomainException('Begonnene Demo gehört zu einer anderen privaten Konfiguration.');
            }
            if(is_file($root.'/installed.json')){
                self::assertInstance($db,$configuration);self::checkKeys($root,$journal);return ['installed'=>true,'existing'=>true,'schema'=>Database::SCHEMA_VERSION];
            }
            if(!$maintenance&&(int)$db->query('SELECT @@GLOBAL.log_bin')->fetchColumn()===1&&(int)$db->query('SELECT @@GLOBAL.log_bin_trust_function_creators')->fetchColumn()!==1)throw new \DomainException('Der MySQL-Server benötigt eine einmalige Schemavorbereitung durch einen berechtigten Einrichtungszugang. Temporäre Zugangsdaten ausschließlich privat übergeben; dem Demo-Benutzer keine globalen Rechte oder SUPER vergeben.');
            if($maintenance&&$maintenance->query('SELECT DATABASE()')->fetchColumn()!==$configuration['database']['name'])throw new \DomainException('Schemavorbereitung gehört nicht zur eigenen Demo-Datenbank.');
            $persist=static function(string $step)use(&$journal,$journalPath,$root,$checkpoint):void{
                $temporary=$root.'/journal-'.bin2hex(random_bytes(12)).'.tmp';Configuration::create($temporary,json_encode($journal,JSON_THROW_ON_ERROR)."\n");
                try{if(!rename($temporary,$journalPath))throw new \RuntimeException('Demo-Einrichtung nicht bestätigt.');}finally{if(is_file($temporary))unlink($temporary);}
                if($checkpoint)$checkpoint($step);
            };
            $hasMarker=Db::run($db,"SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='dvm_demo_instance'")->fetchColumn();
            if(!$hasMarker){
                if((int)$db->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchColumn()!==0)throw new \DomainException('Unbekannte Tabellen bleiben unverändert.');
                $db->exec('CREATE TABLE dvm_demo_instance (id TINYINT NOT NULL PRIMARY KEY,instance CHAR(24) NOT NULL,purpose VARCHAR(32) NOT NULL) ENGINE=InnoDB');
            }
            $rows=$db->query('SELECT * FROM dvm_demo_instance')->fetchAll(\PDO::FETCH_ASSOC);
            if(!$rows){
                if((int)$db->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchColumn()!==1)throw new \DomainException('Demo-Instanzmarker fehlt in einer nicht leeren Datenbank.');
                Db::run($db,"INSERT INTO dvm_demo_instance VALUES(1,?,'public-demo')",[$configuration['instance']]);
            }
            self::assertMarker($db,$configuration);$persist('instance');
            if(!is_dir($root.'/banking/fints-mock')&&!mkdir($root.'/banking/fints-mock',0700))throw new \RuntimeException('Privater FinTS-Mockup-Speicher fehlt.');
            foreach(['auth.key','private.key','member-bank-keys/v1.key','banking/fints-mock/fints-state.key'] as $name){
                $file=$root.'/'.$name;
                if(!file_exists($file))Configuration::create($file,random_bytes(32));
                Configuration::privateFile($file);if(filesize($file)!==32)throw new \DomainException('Ursprünglicher Demoschlüssel ungültig.');
                $hash=hash_file('sha256',$file);if(isset($journal['keys'][$name])&&!hash_equals($journal['keys'][$name],$hash))throw new \DomainException('Ursprünglicher Demoschlüssel bleibt unverändert.');
                $journal['keys'][$name]=$hash;$persist('key:'.$name);
            }
            Runtime::activate($configuration);
            Baseline::install($maintenance??$db,$journal,$persist,$checkpoint);
            SchemaRegistry::install($maintenance??$db);$persist('schema');
            $club=Runtime::club();$owner='demo-install-owner';
            Db::run($db,'INSERT IGNORE INTO clubs(id,name) VALUES(?,?)',[$club,'Übungsverein / Sample Club']);
            Db::run($db,'INSERT IGNORE INTO actors(id,club_id,role,is_superuser) VALUES(?,?,?,1)',[$owner,$club,'members']);
            $ownerRow=Db::run($db,'SELECT club_id,role,is_superuser FROM actors WHERE id=?',[$owner])->fetch();
            if(!$ownerRow||$ownerRow['club_id']!==$club||!$ownerRow['is_superuser'])throw new \DomainException('Privater Demo-Einrichtungszugang gehört nicht zu dieser Instanz.');
            $guard=static function():void{if(PHP_SAPI!=='cli')throw new \DomainException('Privater Demo-Einrichtungszugang.');};
            $bridge=new ManagerBridge($db,$owner,'native',$guard);$access=$bridge->access();$settings=new Settings($db,$owner);$auth=AuthRuntime::service($db,$root.'/auth.key');
            $rolesPath=$root.'/roles.json';
            if(!file_exists($rolesPath)){
                $roles=[];foreach(Configuration::ROLES as $role)$roles[$role]=['email'=>'demo-'.$role.'@example.invalid','password'=>bin2hex(random_bytes(28)).'Ab7!'];
                Configuration::create($rolesPath,json_encode($roles,JSON_THROW_ON_ERROR)."\n");
            }
            Configuration::privateFile($rolesPath);$roles=json_decode((string)file_get_contents($rolesPath),true,32,JSON_THROW_ON_ERROR);
            if(array_keys($roles)!==Configuration::ROLES)throw new \DomainException('Ursprüngliche private Demokonten erforderlich.');
            foreach(Configuration::ROLES as $role){
                $actor='demo-'.$role;Db::run($db,'INSERT IGNORE INTO actors(id,club_id,role,is_superuser) VALUES(?,?,?,0)',[$actor,$club,$role]);
                $stored=Db::run($db,'SELECT club_id,role,is_superuser FROM actors WHERE id=?',[$actor])->fetch();
                if(!$stored||$stored['club_id']!==$club||$stored['role']!==$role||$stored['is_superuser'])throw new \DomainException('Öffentliche Demo benötigt eingeschränkte eigene Rollen.');
                $roleName='Demo · '.$role;$roleId=Db::run($db,'SELECT id FROM settings_roles WHERE club_id=? AND name=?',[$club,$roleName])->fetchColumn();
                if(!$roleId)$roleId=$settings->saveRole('',0,['name'=>$roleName,'description'=>'Eingeschränkte öffentliche Demorolle','members_access'=>$role==='members'?'write':'none','finance_access'=>$role==='treasurer'?'write':'none','settings_access'=>false,'inventory_access'=>$role==='members'?'write':'none','loans_access'=>$role==='members'?'write':'none','documents_access'=>$role==='members'?'write':'none','press_access'=>$role==='editor'?'write':'none','minutes_access'=>'none','website_custom'=>true,'website_levels'=>array_fill_keys(array_keys(EditorialPolicy::AREAS),$role==='editor'?'write':'none')]);
                $assignment=Db::run($db,'SELECT role_id FROM settings_role_assignments WHERE actor_id=? AND club_id=?',[$actor,$club])->fetchColumn();
                if($assignment===false)$settings->assignRole($actor,$roleId,0);elseif($assignment!==$roleId)throw new \DomainException('Begonnene Demorolle besitzt eine andere Zuweisung.');
                $member=new ManagerBridge($db,$actor,'native',$guard);$member->access();$native=$member->nativeActorId();
                $account=Db::run($db,'SELECT email,verified FROM native_auth_accounts WHERE actor_id=?',[$native])->fetch();
                if(!$account||!$account['verified']){$invite=$auth->createInvitation($access,$native,$roles[$role]['email']);$auth->acceptInvitation($invite['token'],$roles[$role]['password']);}
                elseif($account['email']!==$roles[$role]['email'])throw new \DomainException('Ursprüngliches öffentliches Demokonto bleibt unverändert.');
                $persist('role:'.$role);
            }
            Db::run($db,'INSERT IGNORE INTO native_users(id,club_id,name) VALUES(?,?,?)',[ProductionSite::publicActor($club),$access->club(),'Öffentliche Demo-Website']);
            Db::run($db,"INSERT IGNORE INTO native_grants(actor_id,module,capability) VALUES(?,'website','read')",[ProductionSite::publicActor($club)]);
            (new Samples($db,$journal,$persist))->run($configuration);
            SchemaRegistry::assertCurrent($db);self::checkKeys($root,$journal);
            Configuration::create($root.'/installed.json',json_encode(['format'=>1,'instance'=>$configuration['instance'],'club'=>$club,'sample_day'=>$journal['sample_day'],'sample_month'=>substr($journal['sample_day'],0,7),'schema'=>Database::SCHEMA_VERSION,'keys'=>$journal['keys']],JSON_THROW_ON_ERROR)."\n");
            $persist('complete');return ['installed'=>true,'existing'=>false,'schema'=>Database::SCHEMA_VERSION];
        }finally{flock($lock,LOCK_UN);fclose($lock);}
    }

    private static function checkKeys(string $root,array $journal):void
    {
        foreach($journal['keys'] as $name=>$hash){Configuration::privateFile($root.'/'.$name);if(!hash_equals($hash,hash_file('sha256',$root.'/'.$name)))throw new \DomainException('Ursprüngliche private Demoschlüssel fehlen oder wurden verändert.');}
    }
    private static function assertMarker(\PDO $db,array $configuration):void
    {
        $rows=$db->query('SELECT * FROM dvm_demo_instance')->fetchAll(\PDO::FETCH_ASSOC);
        if(count($rows)!==1||(int)$rows[0]['id']!==1||$rows[0]['instance']!==$configuration['instance']||$rows[0]['purpose']!=='public-demo')throw new \DomainException('Datenbank gehört nicht zur öffentlichen Demo.');
    }
    public static function assertInstance(\PDO $db,array $configuration):void
    {
        self::assertMarker($db,$configuration);$path=$configuration['private_root'].'/installed.json';Configuration::privateFile($path);
        $state=json_decode((string)file_get_contents($path),true,32,JSON_THROW_ON_ERROR);
        if(($state['format']??null)!==1||($state['instance']??null)!==$configuration['instance']||($state['club']??null)!=='dvm-demo-'.$configuration['instance'])throw new \DomainException('Private Demo-Einrichtung nicht vollständig bestätigt.');
    }
}
