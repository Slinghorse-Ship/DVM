<?php declare(strict_types=1);
namespace Kulturhus\Native\Cms;

use DomainException;
use InvalidArgumentException;
use PDO;
use Kulturhus\Native\Shared\{Access,Db};

/** Read-only, bounded references. Empty visible results are never a deletion authorisation. */
final class MediaUsage
{
    public const PAGE_SIZE=6;
    public const MODULES=['website'=>'Website','social'=>'Social Media','newsletter'=>'Newsletter','press'=>'Presse'];
    public function __construct(private PDO $db,private Access $access){}

    public function listing(string $id,array $query=[]):array
    {
        if(!preg_match('/\A[a-f0-9]{32}\z/D',$id))throw new InvalidArgumentException('Bildkennung prüfen.');
        $page=$query['media_usage_page']??1;$module=$query['media_usage_source']??'all';
        if((!is_int($page)&&!is_string($page))||!preg_match('/\A[1-9][0-9]{0,6}\z/D',(string)$page)||(int)$page>1000000||!is_string($module)||!in_array($module,['all',...array_keys(self::MODULES)],true))throw new InvalidArgumentException('Bildverwendungsseite und Bereich prüfen.');
        return Db::atomic($this->db,function()use($id,$page,$module):array{
            $actor=$this->access->require('website','read');$club=$actor['club_id'];
            if(!Db::run($this->db,'SELECT id FROM khcms_media WHERE club_id=? AND id=?',[$club,$id])->fetchColumn())throw new DomainException('Bild nicht verfügbar.');
            $tables=Db::run($this->db,"SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND (TABLE_NAME LIKE 'native_%' OR TABLE_NAME LIKE 'khn_%')")->fetchAll(PDO::FETCH_COLUMN);
            $allowed=['website'=>'Website'];foreach(['social','newsletter','press']as$key){if(!in_array('khn_'.$key.'_items',$tables,true))continue;try{if($this->access->require($key,'read')['club_id']!==$club)throw new DomainException('Vereinszuordnung geändert.');$allowed[$key]=self::MODULES[$key];}catch(DomainException){}}
            if($module!=='all'&&!isset($allowed[$module]))throw new DomainException('Bildverwendungsbereich nicht verfügbar.');
            [$parts,$parameters]=$this->sources($club,$id,$module==='all'?array_keys($allowed):[$module],$tables);
            $from=' FROM ('.implode(' UNION ALL ',$parts).') AS media_references';
            $total=(int)Db::run($this->db,'SELECT COUNT(*)'.$from,$parameters)->fetchColumn();$pages=max(1,(int)ceil($total/self::PAGE_SIZE));$page=min((int)$page,$pages);
            $rows=Db::run($this->db,'SELECT *'.$from.' ORDER BY created_at DESC,module,source,source_id,version DESC,record_id LIMIT '.self::PAGE_SIZE.' OFFSET '.(($page-1)*self::PAGE_SIZE),$parameters)->fetchAll();
            foreach($allowed as$key=>$_)if($this->access->require($key,'read')['club_id']!==$club)throw new DomainException('Bildverwendungszugang geändert.');
            return ['rows'=>$rows,'total'=>$total,'pages'=>$pages,'page'=>$page,'source'=>$module,'modules'=>$allowed,'restricted'=>count($allowed)!==count(self::MODULES)];
        });
    }

    /** SQL only projects small labels and original IDs, never complete bodies or consent proofs. */
    private function sources(string $club,string $id,array $modules,array $tables):array
    {
        $parts=[];$args=[];
        $add=static function(string $module,string $source,string $from,string $owner,string $sourceId,string $recordId,string $version,string $title,string $date,string $condition,array $values,string $renderer="''")use(&$parts,&$args,$club):void{
            $cast=static fn($value,$length)=>'CAST('.$value.' AS CHAR('.$length.')) COLLATE utf8mb4_bin';
            $parts[]="SELECT '$module' AS module,'$source' AS source,".$cast($sourceId,64).' AS source_id,'.$cast($recordId,64).' AS record_id,'.$version.' AS version,'.$cast($title,200).' AS title,'.$date.' AS created_at,'.$cast($renderer,32).' AS renderer FROM '.$from.' WHERE '.$owner.'=? AND ('.$condition.')';
            array_push($args,$club,...$values);
        };
        $title=static fn($json)=>"COALESCE(JSON_UNQUOTE(JSON_EXTRACT($json,'$.title')),'')";
        $renderer=static fn($json)=>"COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT($json,'$.template_definition.renderer')),'null'),JSON_UNQUOTE(JSON_EXTRACT($json,'$.template')),'standard')";
        if(in_array('website',$modules,true)){
            $add('website','photo-copy','khcms_media m','m.club_id','m.id','m.id','1','m.original_name','m.created_at',"JSON_UNQUOTE(JSON_EXTRACT(m.variants_json,'$.original.derived_from.media_id'))=?",[$id]);
            if(in_array('native_event_sources',$tables,true))$add('website','provider-image',"native_event_sources s JOIN khcms_media m ON m.club_id=s.club_id AND m.id=JSON_UNQUOTE(JSON_EXTRACT(s.metadata_json,'$.image_media_id'))",'s.club_id','s.page_id','s.external_id','s.imported_version',"CONCAT('Eventbrite · ',s.external_id)",'m.created_at',"s.source='eventbrite' AND m.id=?",[$id],"'event'");
            [$condition,$values]=$this->pageCondition('p.draft_json',$id);
            $add('website','draft','khcms_pages p','p.club_id','p.id','p.id','p.version',$title('p.draft_json'),'p.updated_at',$condition,$values,$renderer('p.draft_json'));
            [$condition,$values]=$this->pageCondition('r.payload_json',$id);
            $join='khcms_revisions r JOIN khcms_pages p ON p.club_id=r.club_id AND p.id=r.page_id';
            // Route to the current editor while retaining the original title and revision.
            $add('website','published',$join,'r.club_id','r.page_id','r.page_id','r.revision',$title('r.payload_json'),'r.created_at','r.revision=p.published_revision AND ('.$condition.')',$values,$renderer('p.draft_json'));
            $add('website','history',$join,'r.club_id','r.page_id','r.page_id','r.revision',$title('r.payload_json'),'r.created_at','r.revision<>p.version AND (p.published_revision IS NULL OR r.revision<>p.published_revision) AND ('.$condition.')',$values,$renderer('p.draft_json'));
            if(in_array('native_club_settings',$tables,true))$add('website','logo','native_club_settings c','c.club_id','c.club_id','c.club_id','c.version',"'Vereinslogo'",'c.updated_at',"JSON_UNQUOTE(JSON_EXTRACT(c.payload,'$.logo_id'))=?",[$id]);
        }
        foreach(['social','newsletter','press']as$module){
            if(!in_array($module,$modules,true))continue;
            [$condition,$values]=$this->communicationCondition('i.payload',$id);
            $items='khn_'.$module.'_items';$revisions='khn_'.$module.'_revisions';$templates='khn_'.$module.'_templates';$templateHistory='khn_'.$module.'_template_revisions';$jobs='khn_'.$module.'_jobs';
            $add($module,'draft',$items.' i','i.club_id','i.id','i.id','i.version',$title('i.payload'),'i.updated_at',$condition,$values);
            [$condition,$values]=$this->communicationCondition('r.payload',$id);
            $add($module,'history',$revisions.' r JOIN '.$items.' i ON i.club_id=r.club_id AND i.id=r.item_id','r.club_id','r.item_id','r.item_id','r.version',$title('r.payload'),'r.created_at','r.version<>i.version AND ('.$condition.')',$values);
            [$condition,$values]=$this->communicationCondition('t.payload',$id);
            $add($module,'template',$templates.' t','t.club_id','t.id','t.id','t.version',$title('t.payload'),'t.updated_at',$condition,$values);
            [$condition,$values]=$this->communicationCondition('r.payload',$id);
            $add($module,'template-history',$templateHistory.' r JOIN '.$templates.' t ON t.club_id=r.club_id AND t.id=r.item_id','r.club_id','r.item_id','r.item_id','r.version',$title('r.payload'),'r.created_at','r.version<>t.version AND ('.$condition.')',$values);
            [$condition,$values]=$this->communicationCondition("JSON_EXTRACT(j.payload,'$.snapshot')",$id);
            $add($module,'plan',$jobs.' j','j.club_id','j.item_id','j.id','j.item_version',$title("JSON_EXTRACT(j.payload,'$.snapshot')"),'j.created_at',$condition,$values);
        }
        foreach(['social','newsletter']as$module){
            if(!in_array($module,$modules,true)||!in_array('native_social_images',$tables,true))continue;
            $path='$.sources.media."'.$id.'"';$imageCondition="i.kind ".($module==='social'?"IN ('post','story','carousel')":"='newsletter'")." AND (JSON_CONTAINS_PATH(i.metadata_json,'one',?) OR JSON_UNQUOTE(JSON_EXTRACT(i.metadata_json,'$.media_ref'))=? OR JSON_SEARCH(i.metadata_json,'one',?,NULL,'$.document.layers[*].media_ref') IS NOT NULL)";
            $add($module,'image','native_social_images i','i.club_id','i.item_id','i.id','i.item_version',"CASE WHEN i.kind='story' THEN CONCAT('Story · Folie ',i.slide+1) WHEN i.kind='carousel' THEN CONCAT('Karussell · Bild ',i.slide+1) WHEN i.kind='newsletter' THEN 'Newsletterbild' ELSE 'Beitragsbild' END",'i.created_at',$imageCondition,[$path,$id,$id]);
        }
        foreach(['social','press']as$module)if(in_array($module,$modules,true)&&in_array('native_'.$module.'_event_origins',$tables,true)){
            [$condition,$values]=$this->pageCondition('o.snapshot_json',$id);
            $add($module,'event-original','native_'.$module.'_event_origins o','o.club_id','o.item_id','o.item_id','o.revision',$title('o.snapshot_json'),'o.created_at',$condition,$values);
        }
        if(in_array('newsletter',$modules,true)&&in_array('native_campaign_views',$tables,true)){
            [$condition,$values]=$this->communicationCondition('v.snapshot_json',$id);
            $add('newsletter','browser-original','native_campaign_views v','v.club_id','v.item_id','v.token_hash','v.item_version',$title('v.snapshot_json'),'v.created_at',$condition,$values);
        }
        return [$parts,$args];
    }

    private function pageCondition(string $json,string $id):array
    {
        $condition="JSON_CONTAINS(JSON_EXTRACT($json,'$.media_ids'),JSON_QUOTE(?)) OR JSON_SEARCH($json,'one',?,NULL,'$.content_sections[*].media_id') IS NOT NULL OR EXISTS(SELECT 1 FROM JSON_TABLE(COALESCE(JSON_KEYS(JSON_EXTRACT($json,'$.field_definitions')),JSON_ARRAY()), '\$[*]' COLUMNS(field_key VARCHAR(64) PATH '\$')) f WHERE JSON_UNQUOTE(JSON_EXTRACT($json,CONCAT('\$.field_definitions.',f.field_key,'.type')))='media-reference' AND JSON_UNQUOTE(JSON_EXTRACT($json,CONCAT('\$.custom_fields.',f.field_key)))=?)";
        $condition.=" OR JSON_CONTAINS(JSON_EXTRACT($json,'$.custom_media_ids'),JSON_QUOTE(?))";
        return [$condition,[$id,$id,$id,$id]];
    }

    private function communicationCondition(string $json,string $id):array
    {
        $condition="JSON_CONTAINS(JSON_EXTRACT($json,'$.attachment_media_ids'),JSON_QUOTE(?)) OR EXISTS(SELECT 1 FROM JSON_TABLE(COALESCE(JSON_EXTRACT($json,'$.blocks'),JSON_ARRAY()), '\$[*]' COLUMNS(block_type VARCHAR(32) PATH '\$.type',media_ref VARCHAR(64) PATH '\$.media_ref')) b WHERE b.block_type='image' AND b.media_ref=?) OR JSON_SEARCH($json,'one',?,NULL,'$.story[*].media_ref') IS NOT NULL OR JSON_SEARCH($json,'one',?,NULL,'$.carousel[*].media_ref') IS NOT NULL OR JSON_CONTAINS_PATH($json,'one',?)";
        return [$condition,[$id,$id,$id,$id,'$._media_usage.media."'.$id.'"']];
    }
}
