<?php declare(strict_types=1);
namespace Kulturhus\Native\System;
use Kulturhus\Native\Installer\{PackageVersion,SchemaRegistry,Upgrade};
use Kulturhus\Native\Shared\Db;

/** Observes existing receipts and schema history. It never installs, repairs or claims an absent proof. */
final class Installation
{
    public function __construct(private readonly \PDO $db,private readonly Authority $authority,private readonly PrivateFiles $private){}
    public function snapshot():array
    {
        $this->authority->require();$versions=Db::run($this->db,'SELECT module,version,applied_at FROM native_schema_versions ORDER BY module')->fetchAll();$rows=[];$map=[];
        foreach($versions as$row){$expected=SchemaRegistry::VERSIONS[$row['module']]??null;$map[$row['module']]=(int)$row['version'];$rows[]=['module'=>$row['module'],'version'=>(int)$row['version'],'expected'=>$expected,'applied_at'=>$row['applied_at'],'current'=>$expected===(int)$row['version']];}
        foreach(SchemaRegistry::VERSIONS as$module=>$expected)if(!isset($map[$module]))$rows[]=['module'=>$module,'version'=>null,'expected'=>$expected,'applied_at'=>null,'current'=>false];$current=count($rows)===count(SchemaRegistry::VERSIONS)&&!array_filter($rows,static fn(array $row):bool=>!$row['current']);
        $result=['generation'=>PackageVersion::GENERATION,'mode'=>'source','schema_current'=>$current,'schemas'=>$rows,'maintenance'=>$this->private->maintenance(),'installation'=>null,'upgrade'=>null];
        if(basename($this->private->root)==='private'){
            $root=dirname($this->private->root);$file=$root.'/installation.json';
            if(is_link($file))throw new \DomainException('Ursprünglichen Installationsnachweis prüfen.');
            if(is_file($file)){
                if((fileperms($file)&0077)||filesize($file)>1048576||(function_exists('posix_geteuid')&&fileowner($file)!==posix_geteuid()))throw new \DomainException('Geschützten Installationsnachweis prüfen.');$receipt=json_decode((string)file_get_contents($file),true,32,JSON_THROW_ON_ERROR);$club=$this->authority->managerClub();
                if(($receipt['provider']??null)!=='native'||($receipt['target']??null)!==$root||$club===null||($receipt['club_id']??null)!==$club||!preg_match('/^[a-f0-9]{64}$/D',$receipt['source']??''))throw new \DomainException('Installationsnachweis gehört nicht zum aktuellen Verein.');
                $result['mode']='installed';$result['installation']=['source'=>$receipt['source'],'installed_at'=>$receipt['installed_at']??null,'package_generation'=>isset($receipt['package_generation'])?(int)$receipt['package_generation']:null,'receipt_sha256'=>hash_file('sha256',$file)];
                if($this->private->file('upgrade-state.json',true)!==null){
                    require_once dirname(__DIR__,3).'/distribution/src/PackageStore.php';$state=Upgrade::readState($root);if(($state['database']??null)!==$this->db->query('SELECT DATABASE()')->fetchColumn())throw new \DomainException('Aktualisierungsnachweis gehört nicht zur eigenen Datenbank.');
                    $result['upgrade']=self::upgrade($state);
                }
            }
        }
        $this->authority->require();return $result;
    }
    public static function upgrade(array $state):array
    {
        $phases=['verify-old','verify-new','stage','snapshot','switch','schema','verify-active','finish','done','rollback-db','rollback-app','rollback-finish','rolled-back'];if(!in_array($state['phase']??null,$phases,true)||!preg_match('/^[a-f0-9]{48}$/D',$state['intent']??'')||!preg_match('/^[a-f0-9]{64}$/D',$state['old_source']??'')||!preg_match('/^[a-f0-9]{64}$/D',$state['new_source']??''))throw new \DomainException('Ursprünglichen Aktualisierungsstand prüfen.');
        return ['intent'=>$state['intent'],'phase'=>$state['phase'],'old_source'=>$state['old_source'],'new_source'=>$state['new_source'],'started_at'=>$state['started_at'],'completed_at'=>$state['completed_at']??null,'authenticated'=>true];
    }
    public function migrations(mixed $raw=1):array
    {
        $this->authority->require();$page=Journal::pageNumber($raw);$total=(int)Db::run($this->db,'SELECT COUNT(*) FROM schema_migrations')->fetchColumn();$pages=max(1,(int)ceil($total/10));$page=min($page,$pages);$rows=Db::run($this->db,'SELECT version,applied_at FROM schema_migrations ORDER BY version DESC LIMIT 10 OFFSET '.(($page-1)*10))->fetchAll();foreach($rows as&$row)$row['version']=(int)$row['version'];$this->authority->require();return compact('rows','total','page','pages');
    }
    public function receipts(string $after=''):array
    {
        if($after!==''&&!preg_match('/^[a-f0-9]{48}$/D',$after))throw new \DomainException('Ursprünglichen Belegcursor prüfen.');$snapshot=$this->snapshot();$rows=[];$next='';$more=false;$total=0;if($snapshot['mode']!=='installed')return compact('rows','next','more','total');$directory=$this->private->root.'/upgrade-operations';if(is_link($directory))throw new \DomainException('Privaten Belegpfad prüfen.');if(!is_dir($directory))return compact('rows','next','more','total');$candidates=[];
        // Keep only the next eleven IDs in memory, irrespective of filesystem iteration order.
        foreach(new \DirectoryIterator($directory)as$entry){$id=$entry->getFilename();if(!preg_match('/^[a-f0-9]{48}$/D',$id))continue;if($entry->isLink()||!$entry->isDir())throw new \DomainException('Ursprünglichen Aktualisierungsbeleg prüfen.');$total++;if($id<=$after)continue;$candidates[]=$id;sort($candidates,SORT_STRING);if(count($candidates)>11)array_pop($candidates);}
        $more=count($candidates)>10;if($more)array_pop($candidates);$club=$this->authority->managerClub();$root=dirname($this->private->root);
        foreach($candidates as$id){$receipt=$this->private->json('upgrade-operations/'.$id.'/installation.json');if(($receipt['provider']??null)!=='native'||($receipt['target']??null)!==$root||($receipt['club_id']??null)!==$club||!preg_match('/^[a-f0-9]{64}$/D',$receipt['source']??''))throw new \DomainException('Aktualisierungsbeleg gehört nicht zum eigenen Original.');$path=$this->private->file('upgrade-operations/'.$id.'/installation.json');$rows[]=['intent'=>$id,'source'=>$receipt['source'],'package_generation'=>isset($receipt['package_generation'])?(int)$receipt['package_generation']:null,'receipt_sha256'=>hash_file('sha256',$path),'kind'=>'before-upgrade'];$next=$id;}
        $this->authority->require();return compact('rows','next','more','total');
    }
}
