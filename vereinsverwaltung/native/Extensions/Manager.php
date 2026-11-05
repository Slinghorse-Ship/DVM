<?php declare(strict_types=1);
namespace Kulturhus\Native\Extensions;
use Kulturhus\Native\Shared\Db;

/** Plugin lifecycle is one local transaction; aborted work leaves its old version usable. */
final class Manager
{
    public function __construct(private readonly \PDO $db,private readonly Access $access,private readonly Catalog $catalog) {}
    public function registryDiagnostics(): array {$this->access->actor();return $this->catalog->diagnostics();}
    private function states(string $club): array
    {
        $rows=Db::run($this->db,'SELECT * FROM native_extensions WHERE club_id=? ORDER BY plugin_id',[$club])->fetchAll();$result=[];
        foreach($rows as $row)$result[$row['plugin_id']]=$row;
        return $result;
    }
    private function revisions(string $club): array
    {
        // Successful history survives uninstall: an old tab never matches a new installation.
        return Db::run($this->db,'SELECT plugin_id,COUNT(*) FROM native_extension_history WHERE club_id=? GROUP BY plugin_id',[$club])->fetchAll(\PDO::FETCH_KEY_PAIR);
    }
    private function currentSource(string $id,array $row):bool
    {try{return hash_equals($row['source_hash'],$this->catalog->fingerprint($id));}catch(\Throwable){return false;}}
    public function listing(): array
    {
        $actor=$this->access->actor();$states=$this->states($actor['club_id']);$revisions=$this->revisions($actor['club_id']);$result=[];$runtime=new Runtime($this->db,$actor['club_id'],$this->catalog);$failed=[];
        foreach($runtime->failures() as $failure)$failed[$failure['owner']]=$failure['code'];
        foreach($this->catalog->ordered() as $id=>$plugin){
            $definition=$plugin->definition();$row=$states[$id]??null;unset($states[$id]);
            $result[]=['id'=>$id,'label'=>$definition->label,'version'=>$definition->version,'installed_version'=>$row['version']??null,'revision'=>(int)($row['revision']??$revisions[$id]??0),'enabled'=>(bool)($row['enabled']??false),'runtime_state'=>isset($failed[$id])?'failed':(in_array($id,$runtime->loaded(),true)?'active':($definition->autoload?'paused':'manual')),'needs_upgrade'=>$row&&($row['version']!==$definition->version||!$this->currentSource($id,$row)),'configuration'=>$row?json_decode($row['configuration'],true,32,JSON_THROW_ON_ERROR):$definition->configuration([]),'fields'=>$definition->fields,'dependencies'=>$definition->dependencies,'available'=>true];
        }
        foreach($states as $id=>$row)$result[]=['id'=>$id,'label'=>$id,'version'=>'','installed_version'=>$row['version'],'revision'=>(int)$row['revision'],'enabled'=>(bool)$row['enabled'],'needs_upgrade'=>true,'configuration'=>[],'fields'=>[],'dependencies'=>[],'available'=>false];
        ($this->access->guard($actor['club_id']))();return $result;
    }
    private function dependencies(Definition $definition,array $states,bool $enabled): void
    {
        foreach($definition->dependencies as $id=>$minimum){$row=$states[$id]??null;
            if(!$row||version_compare($row['version'],$minimum,'<')||($enabled&&!(bool)$row['enabled'])||$row['source_hash']!==$this->catalog->fingerprint($id))throw new \DomainException('Abhängigkeit zuerst installieren, aktualisieren und aktivieren: '.$id);
        }
    }
    public function change(string $id,string $action,int $revision,array $configuration=[]): void
    {
        if($this->db->inTransaction())throw new \DomainException('Erweiterungsverwaltung außerhalb einer Geschäftsbuchung starten.');
        if(!in_array($action,['install','enable','disable','configure','upgrade','uninstall'],true)||$revision<0||$revision>=2147483647)throw new \DomainException('Erweiterungsaktion ungültig.');
        $this->catalog->ordered();$plugin=$this->catalog->get($id);$definition=$plugin->definition();$actor=$this->access->actor();
        // Disabling invokes no package callback and must remain possible when a
        // declared helper is missing. It retains the existing stored source hash.
        $source=$action==='disable'?null:$this->catalog->fingerprint($id);
        Db::atomic($this->db,function()use($plugin,$definition,$actor,$id,$action,$revision,$configuration,$source):void{
            Db::run($this->db,'SELECT id FROM clubs WHERE id=? FOR UPDATE NOWAIT',[$actor['club_id']]);$guard=$this->access->guard($actor['club_id']);if(!$guard())throw new \DomainException('Vereinszuordnung geändert.');
            if($source!==null&&!$this->currentSource($id,['source_hash'=>$source]))throw new \DomainException('Erweiterungsquelle geändert. Bitte neu öffnen.',409);
            $states=$this->states($actor['club_id']);$row=$states[$id]??null;
            $currentRevision=(int)($row['revision']??$this->revisions($actor['club_id'])[$id]??0);
            if($currentRevision!==$revision)throw new \DomainException('Erweiterung wurde geändert. Bitte neu laden.',409);
            if(($action==='install')===(bool)$row)throw new \DomainException($row?'Erweiterung bereits installiert.':'Erweiterung zuerst installieren.');
            if($row&&$action!=='upgrade'&&$action!=='disable'&&($row['source_hash']!==$this->catalog->fingerprint($id)||$row['version']!==$definition->version))throw new \DomainException('Geänderte Erweiterung zuerst aktualisieren.');
            if(in_array($action,['disable','uninstall'],true))foreach($states as $otherId=>$other){
                if($otherId===$id)continue;
                try{$otherDefinition=$this->catalog->get($otherId)->definition();}catch(\DomainException){continue;}
                if(isset($otherDefinition->dependencies[$id])&&($action==='uninstall'||(bool)$other['enabled']))throw new \DomainException('Abhängige Erweiterung zuerst deaktivieren oder entfernen: '.$otherId);
            }
            $config=$action==='configure'?$definition->configuration($configuration):($row?json_decode($row['configuration'],true,32,JSON_THROW_ON_ERROR):$definition->configuration($configuration));
            if($action==='upgrade')$config=$definition->configuration(array_intersect_key($config,$definition->fields));
            $context=new Context($this->db,$actor['club_id'],$id,$config,$guard,true);
            $enabled=(bool)($row['enabled']??false);
            if($action==='install'){
                $this->dependencies($definition,$states,false);
                try{$plugin->install($context);}catch(\Throwable){throw new \DomainException('Installation fehlgeschlagen. Der bisherige Stand bleibt erhalten.');}
            }
            elseif($action==='upgrade'){
                $this->dependencies($definition,$states,$enabled);if(version_compare($definition->version,$row['version'],'<'))throw new \DomainException('Ein Downgrade benötigt ein geprüftes Gesamtpaket.');
                try{$plugin->upgrade($context,$row['version']);}catch(\Throwable){throw new \DomainException('Aktualisierung fehlgeschlagen. Der bisherige Stand bleibt erhalten.');}
            }
            elseif($action==='enable'){$this->dependencies($definition,$states,true);$enabled=true;}
            elseif($action==='disable')$enabled=false;
            elseif($action==='uninstall'){
                if($enabled)throw new \DomainException('Erweiterung zuerst deaktivieren.');
                try{$plugin->uninstall($context);}catch(\Throwable){throw new \DomainException('Deinstallation fehlgeschlagen. Der bisherige Stand bleibt erhalten.');}
                Db::run($this->db,'DELETE FROM native_extension_values WHERE club_id=? AND plugin_id=?',[$actor['club_id'],$id]);
                Db::run($this->db,'DELETE FROM native_extensions WHERE club_id=? AND plugin_id=?',[$actor['club_id'],$id]);
            }
            if(!$guard())throw new \DomainException('Erweiterungszugriff entzogen.');
            if($source!==null&&!$this->currentSource($id,['source_hash'=>$source]))throw new \DomainException('Erweiterungsquelle geändert. Bitte neu öffnen.',409);
            if($action!=='uninstall'){
                $version=in_array($action,['install','upgrade'],true)?$definition->version:$row['version'];$hash=in_array($action,['install','upgrade'],true)?$source:$row['source_hash'];
                Db::run($this->db,'INSERT INTO native_extensions VALUES(?,?,?,?,?,?,?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE version=VALUES(version),source_hash=VALUES(source_hash),revision=VALUES(revision),enabled=VALUES(enabled),configuration=VALUES(configuration),updated_by=VALUES(updated_by),updated_at=VALUES(updated_at)',[$actor['club_id'],$id,$version,$hash,$revision+1,(int)$enabled,json_encode($config,JSON_THROW_ON_ERROR),$actor['id']]);
                if($enabled){
                    $runtime=new Runtime($this->db,$actor['club_id'],$this->catalog,[$id]);
                    if(!in_array($id,$runtime->loaded(),true))throw new \DomainException('Erweiterung konnte nicht initialisiert werden. Der bisherige Stand bleibt erhalten.');
                }
            }
            if($source!==null&&!$this->currentSource($id,['source_hash'=>$source]))throw new \DomainException('Erweiterungsquelle geändert. Bitte neu öffnen.',409);
            Db::run($this->db,'INSERT INTO native_extension_history VALUES(?,?,?,?,?,?,UTC_TIMESTAMP())',[bin2hex(random_bytes(16)),$actor['club_id'],$id,$actor['id'],$action,$definition->version]);
        });
    }
    public function history(int $page=1): array
    {
        $actor=$this->access->actor();if($page<1||$page>100000)throw new \DomainException('Ungültige Verlaufsseite.');
        $count=(int)Db::run($this->db,'SELECT COUNT(*) FROM native_extension_history WHERE club_id=?',[$actor['club_id']])->fetchColumn();$pages=max(1,(int)ceil($count/20));$page=min($page,$pages);
        $rows=Db::run($this->db,'SELECT plugin_id,action,version,occurred_at FROM native_extension_history WHERE club_id=? ORDER BY occurred_at DESC,id DESC LIMIT 20 OFFSET '.(($page-1)*20),[$actor['club_id']])->fetchAll();return ['rows'=>$rows,'page'=>$page,'pages'=>$pages];
    }
}
