<?php declare(strict_types=1);
namespace Kulturhus\Native\Cms;
use Kulturhus\Native\Shared\{Access,Db};
use PDO;

/** Main-menu settings are independent of content drafts and their publication. */
final class Navigation
{
    public function __construct(private PDO $db,private Access $access){}
    public static function install(PDO $db):void
    {
        $db->exec('CREATE TABLE IF NOT EXISTS khcms_navigation (club_id VARCHAR(64) PRIMARY KEY,version INT UNSIGNED NOT NULL,config_json MEDIUMTEXT NOT NULL,updated_by VARCHAR(64) NOT NULL,updated_at DATETIME(6) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $db->exec('CREATE TABLE IF NOT EXISTS khcms_navigation_revisions (club_id VARCHAR(64) NOT NULL,version INT UNSIGNED NOT NULL,config_json MEDIUMTEXT NOT NULL,actor_id VARCHAR(64) NOT NULL,created_at DATETIME(6) NOT NULL,PRIMARY KEY(club_id,version)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    }
    public function state():array
    {
        $this->access->require('website','read');
        $stored=$this->stored();$items=[];
        foreach($this->published() as $row){
            $p=json_decode($row['payload_json'],true,512,JSON_THROW_ON_ERROR);
            if($p['slug']==='start'||!NewsChannels::visible($p))continue;
            $override=$stored['config']['items'][$row['id']]??[];
            $renderer=$p['template_definition']['renderer']??$p['template'];
            $mainPage=!str_contains($p['slug'],'/')&&($renderer==='standard'||in_array($p['slug'],['aktuelles','galerie','veranstaltungen'],true));
            if(!$mainPage&&!$p['nav_visible']&&$override===[])continue;
            $items[]=['id'=>$row['id'],'title'=>$p['title'],'slug'=>$p['slug'],'label'=>$override['label']??($p['nav_label']?:$p['title']),'visible'=>$override['visible']??(bool)$p['nav_visible'],'position'=>$override['position']??(int)$p['nav_order']];
        }
        usort($items,static fn($a,$b)=>[$a['position'],$a['label'],$a['id']]<=>[$b['position'],$b['label'],$b['id']]);
        return ['version'=>$stored['version'],'automatic_program_year'=>$stored['config']['automatic_program_year']??true,'items'=>$items];
    }
    public function save(array $items,bool $automatic,int $expected):void
    {
        $this->access->require('website','publish');
        if($expected<0||count($items)>1000)throw new \InvalidArgumentException('Ungültiger Menüstand.');
        Db::atomic($this->db,function()use($items,$automatic,$expected){
            $actor=$this->access->require('website','publish');$club=$this->access->club();
            Db::run($this->db,'INSERT IGNORE INTO khcms_state (club_id,generation) VALUES (?,0)',[$club]);
            Db::run($this->db,'SELECT generation FROM khcms_state WHERE club_id=? FOR UPDATE',[$club]);
            $stored=$this->stored(true);
            if($stored['version']!==$expected)throw new Conflict('Das Menü wurde inzwischen geändert. Bitte neu laden.');
            if($automatic!==($stored['config']['automatic_program_year']??true))$this->assertProgrammePublish();
            $known=[];foreach($this->published() as $row){$p=json_decode($row['payload_json'],true,512,JSON_THROW_ON_ERROR);if($p['slug']!=='start')$known[$row['id']]=true;}
            $clean=$stored['config']['items']??[];$seen=[];
            foreach($items as $item){
                if(!is_array($item)||!is_string($item['id']??null)||!isset($known[$item['id']])||isset($seen[$item['id']]))throw new \InvalidArgumentException('Menüeintrag ist nicht mehr verfügbar. Bitte neu laden.');
                $id=$item['id'];$seen[$id]=true;$label=$item['label']??null;$position=$item['position']??null;
                TemplatePolicy::assertCapability($this->db,$this->access,$id,'publish');
                if(!is_string($label)||trim($label)===''||mb_strlen($label)>100||preg_match('/[<>\x00-\x1f]/u',$label)||!is_int($position)||$position<0||$position>10000||!is_bool($item['visible']??null))throw new \InvalidArgumentException('Menüname, Sichtbarkeit oder Position ungültig.');
                $clean[$id]=['label'=>trim($label),'position'=>$position,'visible'=>$item['visible']];
            }
            $json=json_encode(['automatic_program_year'=>$automatic,'items'=>$clean],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);$version=$expected+1;
            Db::run($this->db,'INSERT INTO khcms_navigation (club_id,version,config_json,updated_by,updated_at) VALUES (?,?,?,?,UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE version=VALUES(version),config_json=VALUES(config_json),updated_by=VALUES(updated_by),updated_at=VALUES(updated_at)',[$club,$version,$json,$actor['id']]);
            Db::run($this->db,'INSERT INTO khcms_navigation_revisions (club_id,version,config_json,actor_id,created_at) VALUES (?,?,?,?,UTC_TIMESTAMP(6))',[$club,$version,$json,$actor['id']]);
            Db::run($this->db,'UPDATE khcms_state SET generation=generation+1 WHERE club_id=?',[$club]);
        });
    }
    /** A boolean capability only; unpublished/private programme identities never enter a DTO. */
    public function canChangeAutomaticYear():bool
    {
        try{$this->access->require('website','publish');$this->assertProgrammePublish();return true;}
        catch(\DomainException){return false;}
    }

    private function assertProgrammePublish():void
    {
        // Deliberately no read filter: hiding a protected original must not remove its guard.
        $ids=Db::run($this->db,"SELECT p.id FROM khcms_pages p JOIN khcms_revisions r ON r.page_id=p.id AND r.club_id=p.club_id AND r.revision=p.published_revision WHERE p.club_id=? AND JSON_UNQUOTE(JSON_EXTRACT(r.payload_json,'$.slug'))='veranstaltungen' ORDER BY p.id",[$this->access->club()])->fetchAll(PDO::FETCH_COLUMN);
        try{foreach($ids as$id)TemplatePolicy::assertCapability($this->db,$this->access,$id,'publish');}
        catch(\DomainException $error){throw new \DomainException('Für die automatische Programmbeschriftung fehlt die Berechtigung.',0,$error);}
    }

    /** Called with the current published payloads; never use draft labels or dates. */
    public function publicLinks():array
    {
        $this->access->require('website','read');
        return $this->links($this->published());
    }

    /** Published footer pages remain available independently of main-menu visibility. */
    public function footerLinks():array
    {
        $this->access->require('website','read');
        $slugs=['galerie','aktuelles','newsletter','kontakt','impressum','datenschutz','satzung','verein/satzung','datenloeschung','nutzungsbedingungen'];
        [$policy,$parameters]=TemplatePolicy::readFilter($this->db,$this->access,'p',true);
        return Db::run($this->db,"SELECT p.id,JSON_UNQUOTE(JSON_EXTRACT(r.payload_json,'$.slug')) AS slug FROM khcms_pages p JOIN khcms_revisions r ON r.page_id=p.id AND r.club_id=p.club_id AND r.revision=p.published_revision WHERE (".$policy.") AND ".NewsChannels::where('website')." AND JSON_UNQUOTE(JSON_EXTRACT(r.payload_json,'$.slug')) IN (".implode(',',array_fill(0,count($slugs),'?')).") ORDER BY p.id LIMIT 10",[...$parameters,...$slugs])->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Count visible original menu entries without loading labels, bodies or event dates. */
    public function count(?string $excludeSlug=null):int
    {
        $this->access->require('website','read');
        $visible="COALESCE(JSON_UNQUOTE(JSON_EXTRACT(n.config_json,CONCAT('$.items.\"',p.id,'\".visible'))),JSON_UNQUOTE(JSON_EXTRACT(r.payload_json,'$.nav_visible')))='true'";
        // Same bound owner lookup as stored(); table-default collations may differ on hosting.
        [$policy,$parameters]=TemplatePolicy::readFilter($this->db,$this->access,'p',true);
        $sql='SELECT COUNT(*) FROM khcms_pages p JOIN khcms_revisions r ON r.page_id=p.id AND r.club_id=p.club_id AND r.revision=p.published_revision LEFT JOIN khcms_navigation n ON n.club_id=? WHERE ('.$policy.') AND '.NewsChannels::where('website').' AND '.$visible;
        $parameters=[$this->access->club(),...$parameters];if($excludeSlug!==null)$parameters[]=$excludeSlug;
        return (int)Db::run($this->db,$sql.($excludeSlug===null?'':' AND JSON_UNQUOTE(JSON_EXTRACT(r.payload_json,\'$.slug\'))<>?'),$parameters)->fetchColumn();
    }

    public function links(array $rows):array
    {
        $this->access->require('website','read');$stored=$this->stored();$links=[];$year=null;$today=(new \DateTimeImmutable('now',new \DateTimeZone('Europe/Berlin')))->format('Y-m-d');
        // Public projection callers can pass already selected rows; never trust their visibility.
        $ids=array_column($rows,'id');$visible=[];[$policy,$parameters]=TemplatePolicy::readFilter($this->db,$this->access,'p',true);
        foreach(array_chunk($ids,200) as $chunk)foreach(Db::run($this->db,'SELECT p.id FROM khcms_pages p WHERE p.id IN ('.implode(',',array_fill(0,count($chunk),'?')).') AND ('.$policy.')',[...$chunk,...$parameters])->fetchAll(PDO::FETCH_COLUMN) as $id)$visible[$id]=true;
        $next=(new Events($this->db,$this->access->club(),$this->access))->next();
        if($next)$year=EventDetails::start($next)->setTimezone(new \DateTimeZone('Europe/Berlin'))->format('Y-m-d');
        foreach($rows as $row){
            if(!isset($visible[$row['id']]))continue;
            $p=json_decode($row['payload_json'],true,512,JSON_THROW_ON_ERROR);$o=$stored['config']['items'][$row['id']]??[];
            if(!NewsChannels::visible($p))continue;
            if(!($o['visible']??$p['nav_visible']))continue;
            $label=$o['label']??($p['nav_label']?:$p['title']);
            if(($stored['config']['automatic_program_year']??true)&&$p['slug']==='veranstaltungen')$label='Programm '.substr($year??$today,0,4);
            $links[]=['id'=>$row['id'],'slug'=>$p['slug'],'title'=>$p['title'],'label'=>$label,'parent_id'=>isset($visible[$p['parent_id']??''])?$p['parent_id']:null,'nav_order'=>$o['position']??$p['nav_order']];
        }
        usort($links,static fn($a,$b)=>[$a['nav_order'],$a['label'],$a['id']]<=>[$b['nav_order'],$b['label'],$b['id']]);return $links;
    }
    private function stored(bool $lock=false):array
    {
        $row=Db::run($this->db,'SELECT version,config_json FROM khcms_navigation WHERE club_id=?'.($lock?' FOR UPDATE':''),[$this->access->club()])->fetch(PDO::FETCH_ASSOC);
        return $row?['version'=>(int)$row['version'],'config'=>json_decode($row['config_json'],true,512,JSON_THROW_ON_ERROR)]:['version'=>0,'config'=>[]];
    }
    private function published():array
    {
        // Menu candidates need no article body, gallery metadata or large field definitions.
        [$policy,$parameters]=TemplatePolicy::readFilter($this->db,$this->access,'p',true);
        return Db::run($this->db,"SELECT p.id,JSON_OBJECT('title',JSON_EXTRACT(r.payload_json,'$.title'),'slug',JSON_EXTRACT(r.payload_json,'$.slug'),'template',JSON_EXTRACT(r.payload_json,'$.template'),'template_definition',JSON_OBJECT('renderer',JSON_EXTRACT(r.payload_json,'$.template_definition.renderer')),'news_channels',IF(JSON_CONTAINS_PATH(r.payload_json,'one','$.news_channels')=0,JSON_ARRAY('website','rss'),JSON_EXTRACT(r.payload_json,'$.news_channels')),'nav_visible',JSON_EXTRACT(r.payload_json,'$.nav_visible'),'nav_label',JSON_EXTRACT(r.payload_json,'$.nav_label'),'nav_order',JSON_EXTRACT(r.payload_json,'$.nav_order'),'parent_id',JSON_EXTRACT(r.payload_json,'$.parent_id')) AS payload_json FROM khcms_pages p JOIN khcms_revisions r ON r.page_id=p.id AND r.revision=p.published_revision AND r.club_id=p.club_id WHERE (".$policy.")",$parameters)->fetchAll(PDO::FETCH_ASSOC);
    }
}
