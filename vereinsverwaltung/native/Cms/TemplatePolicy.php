<?php declare(strict_types=1);
namespace Kulturhus\Native\Cms;

use DomainException;
use InvalidArgumentException;
use Kulturhus\Native\Shared\{Access,Db};
use PDO;

/** Closed declarative template settings, versioned with the existing content model. */
final class TemplatePolicy
{
    public const DEFAULTS=['parents'=>null,'children'=>null,'root'=>true,'inherit'=>true,'public'=>true,'read'=>'read','edit'=>'edit','publish'=>'publish','theme'=>null,'cache'=>'inherit'];

    /** Explicit maintenance only. A small club index keeps ordinary unconfigured websites fast. */
    public static function install(PDO $db):void
    {
        $db->exec('CREATE TABLE IF NOT EXISTS khcms_template_policy_state(club_id VARCHAR(64) NOT NULL PRIMARY KEY,version BIGINT UNSIGNED NOT NULL,access_rules TINYINT UNSIGNED NOT NULL,family_rules TINYINT UNSIGNED NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $clubs=Db::run($db,"SELECT club_id FROM khcms_definitions WHERE kind='template' AND JSON_CONTAINS_PATH(definition_json,'one','$.policy') UNION SELECT club_id FROM khcms_pages WHERE JSON_CONTAINS_PATH(draft_json,'one','$.page_policy')")->fetchAll(PDO::FETCH_COLUMN);
        foreach($clubs as $club)self::index($db,$club,false);
    }

    /** Persist with a changed model/page policy in the SAME transaction as its original revision. */
    public static function refresh(PDO $db,Access $access):void
    {
        Db::atomic($db,static function()use($db,$access):void{
            $actor=$access->require('website','manage');$club=$actor['club_id'];
            Db::run($db,'INSERT IGNORE INTO khcms_state(club_id,generation) VALUES(?,0)',[$club]);Db::run($db,'SELECT generation FROM khcms_state WHERE club_id=? FOR UPDATE',[$club])->fetchColumn();
            self::index($db,$club,true);Db::run($db,'UPDATE khcms_state SET generation=generation+1 WHERE club_id=?',[$club]);
        });
    }

    /** A trusted private copy may retain an older restrictive ACL without granting its editor manage. */
    public static function noteCopy(PDO $db,Access $access,array $payload):void
    {
        $actor=$access->require('website','edit');$policy=array_replace(self::DEFAULTS,self::pagePolicy($payload['page_policy']??[]));$restricted=false;
        foreach(['inherit','public','read','edit','publish'] as $key)if($policy[$key]!==self::DEFAULTS[$key])$restricted=true;
        if(!$restricted)return;
        Db::run($db,'INSERT INTO khcms_template_policy_state(club_id,version,access_rules,family_rules) VALUES(?,1,1,0) ON DUPLICATE KEY UPDATE version=IF(access_rules=0,version+1,version),access_rules=1',[$actor['club_id']]);
    }

    private static function state(PDO $db,string $club):array
    {
        return Db::run($db,'SELECT access_rules,family_rules FROM khcms_template_policy_state WHERE club_id=?',[$club])->fetch()?:['access_rules'=>0,'family_rules'=>0];
    }

    private static function index(PDO $db,string $club,bool $changed):void
    {
        $access=false;$family=false;$policies=Db::run($db,"SELECT JSON_EXTRACT(definition_json,'$.policy') AS policy FROM khcms_definitions WHERE club_id=? AND kind='template' AND JSON_CONTAINS_PATH(definition_json,'one','$.policy') UNION ALL SELECT JSON_EXTRACT(draft_json,'$.page_policy') AS policy FROM khcms_pages WHERE club_id=? AND JSON_CONTAINS_PATH(draft_json,'one','$.page_policy')",[$club,$club])->fetchAll(PDO::FETCH_COLUMN);
        foreach($policies as $json){$policy=array_replace(self::DEFAULTS,self::normalize(json_decode($json,true,512,JSON_THROW_ON_ERROR)));foreach(['inherit','public','read','edit','publish'] as $key)if($policy[$key]!==self::DEFAULTS[$key])$access=true;if($policy['parents']!==null||$policy['children']!==null||!$policy['root'])$family=true;}
        $old=Db::run($db,'SELECT access_rules,family_rules FROM khcms_template_policy_state WHERE club_id=?',[$club])->fetch();
        if(!$old)Db::run($db,'INSERT INTO khcms_template_policy_state VALUES(?,1,?,?)',[$club,(int)$access,(int)$family]);
        elseif($changed||(int)$old['access_rules']!==(int)$access||(int)$old['family_rules']!==(int)$family)Db::run($db,'UPDATE khcms_template_policy_state SET version=version+1,access_rules=?,family_rules=? WHERE club_id=?',[(int)$access,(int)$family,$club]);
    }

    public static function normalize(mixed $input):array
    {
        if(!is_array($input)||(array_is_list($input)&&$input!==[])||array_diff(array_keys($input),array_keys(self::DEFAULTS)))throw new InvalidArgumentException('Vorlagenregeln dürfen nur erlaubte Familien-, Zugriffs- und Darstellungseinstellungen enthalten.');
        if($input===[])return [];
        $policy=array_replace(self::DEFAULTS,$input);
        foreach(['parents','children'] as $key){
            $value=$policy[$key];if($value===null)continue;
            if(!is_array($value)||!array_is_list($value)||count($value)>100)throw new InvalidArgumentException('Bis zu 100 erlaubte Seitenvorlagen als Liste auswählen.');
            $value=array_map(CustomFields::key(...),$value);if(count(array_unique($value))!==count($value))throw new InvalidArgumentException('Jede Familienvorlage nur einmal auswählen.');sort($value,SORT_STRING);$policy[$key]=$value;
        }
        foreach(['root','inherit','public'] as $key)if(!is_bool($policy[$key]))throw new InvalidArgumentException('Vorlagenfreigaben und Vererbung als Ja oder Nein angeben.');
        foreach(['read'=>['read','edit','publish','manage'],'edit'=>['edit','publish','manage'],'publish'=>['publish','manage']] as $key=>$allowed)if(!in_array($policy[$key],$allowed,true))throw new InvalidArgumentException('Vorlagenzugriff muss die erforderliche Vereinsberechtigung mindestens beibehalten.');
        if($policy['theme']!==null)WebsiteDesign::template($policy['theme']);
        if(!in_array($policy['cache'],['inherit','off','standard'],true))throw new InvalidArgumentException('Zwischenspeicherung als Vereinsvorgabe, aus oder Standard wählen.');
        return $policy;
    }

    public static function pagePolicy(mixed $input):array
    {
        if(!is_array($input)||array_diff(array_keys($input),['inherit','public','read','edit','publish']))throw new InvalidArgumentException('Für eine einzelne Seite nur Zugriff und Vererbung festlegen.');
        $normalized=self::normalize($input);return array_intersect_key($normalized,$input);
    }

    /**
     * Apply BEFORE COUNT, LIMIT or pagination; current rules also revoke old grants.
     * Optional private payloadAlias must project this EXACT current root draft_json without
     * filtering, truncation, substitution or a different original. Ancestors/public sources stay independent.
     */
    public static function readFilter(PDO $db,Access $access,string $alias='p',bool $public=false,string $capability='read',?string $payloadAlias=null):array
    {
        $actor=$access->require('website','read');if($capability!=='read')$access->require('website',$capability);
        if($public&&$capability!=='read')throw new InvalidArgumentException('Öffentliche Seitenfilter erlauben nur Lesen.');
        $hasRules=(bool)self::state($db,$actor['club_id'])['access_rules'];
        if(!$hasRules)return self::compile($db,$actor['club_id'],$alias,$public,$capability,['read'],false,$payloadAlias);
        $allowed=[];
        if($public)$allowed=['read'];
        else foreach(['read','edit','publish','manage'] as $cap){try{$access->require('website',$cap);$allowed[]=$cap;}catch(DomainException){}}
        return self::compile($db,$actor['club_id'],$alias,$public,$capability,$allowed,true,$payloadAlias);
    }

    /** Trusted site/worker scope: only anonymous public reading, with no actor or admin bypass. */
    public static function publicFilter(PDO $db,string $club,string $alias='p'):array
    {
        return self::compile($db,$club,$alias,true,'read',['read']);
    }

    private static function compile(PDO $db,string $club,string $alias,bool $public,string $capability,array $allowed,?bool $hasRules=null,?string $payloadAlias=null):array
    {
        if($db->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql'||$club===''||strlen($club)>64||str_contains($club,"\0")||!preg_match('/\A[a-z][a-z0-9_]*\z/D',$alias)||!in_array($capability,['read','edit','publish'],true))throw new InvalidArgumentException('Gesicherten Seitenfilter prüfen.');
        if($payloadAlias!==null&&($public||!preg_match('/\A[a-z][a-z0-9_]*\z/D',$payloadAlias)))throw new InvalidArgumentException('Privaten Original-Dokumentkontext prüfen.');
        $revision=static fn(string $page):string=>"COALESCE($page.published_revision,(SELECT notice.revision FROM khcms_events notice JOIN native_event_sources notice_source ON notice_source.page_id=notice.page_id AND notice_source.club_id=notice.club_id AND notice_source.state='cancelled' WHERE notice.page_id=$page.id AND notice.club_id=$page.club_id AND notice.is_notice=1))";
        $target='`'.$alias.'`';$targetRevision=$revision($target);
        $publicOriginal=$public?" AND EXISTS(SELECT 1 FROM khcms_revisions original WHERE original.page_id=$target.id AND original.club_id=$target.club_id AND original.revision=$targetRevision)":'';
        if(!($hasRules??(bool)self::state($db,$club)['access_rules']))return ['`'.$alias.'`.club_id=?'.$publicOriginal,[$club]];
        $expression=static function(string $page,string $definition,string $payload,?string $currentPayload=null):string{
            $currentPayload??=$page.'.draft_json';
            return "JSON_MERGE_PATCH(COALESCE(JSON_EXTRACT($definition.definition_json,'$.policy'),JSON_EXTRACT($payload,'$.template_definition.policy'),JSON_OBJECT()),COALESCE(JSON_EXTRACT($currentPayload,'$.page_policy'),JSON_OBJECT()))";
        };
        $basePayload=$payloadAlias!==null?'`'.$payloadAlias.'`.draft_json':($public?'br.payload_json':'b.draft_json');$ancestorPayload=$public?'ar.payload_json':'a.draft_json';
        $baseOriginal=$public?' JOIN khcms_revisions br ON br.page_id=b.id AND br.club_id=b.club_id AND br.revision='.$revision('b'):'';
        $ancestorOriginal=$public?' JOIN khcms_revisions ar ON ar.page_id=a.id AND ar.club_id=a.club_id AND ar.revision='.$revision('a'):'';
        $ownerOriginal=$public?' JOIN khcms_revisions owner_revision ON owner_revision.page_id=owner.id AND owner_revision.club_id=owner.club_id AND owner_revision.revision='.$revision('owner'):'';
        $policy=$expression('b','bd',$basePayload,$payloadAlias!==null?$basePayload:null);$parentPolicy=$expression('a','ad',$ancestorPayload);$target='`'.$alias.'`';
        $inherit="COALESCE(JSON_EXTRACT(c.policy,'$.inherit'),CAST('true' AS JSON))=CAST('true' AS JSON)";
        $parent="NULLIF(JSON_UNQUOTE(c.parent_id),'null')";
        $deny=["c.cycle<>0","COALESCE(JSON_UNQUOTE(JSON_EXTRACT(c.policy,'$.".$capability."')),'".$capability."') NOT IN (".implode(',',array_fill(0,count($allowed),'?')).")",
            "(JSON_CONTAINS_PATH(c.policy,'one','$.inherit') AND JSON_TYPE(JSON_EXTRACT(c.policy,'$.inherit'))<>'BOOLEAN')",
            "($inherit AND $parent IS NOT NULL AND (c.depth>=100 OR NOT EXISTS(SELECT 1 FROM khcms_pages owner$ownerOriginal WHERE owner.id=$parent AND owner.club_id=c.club_id)))"];
        if($public)$deny[]="(JSON_CONTAINS_PATH(c.policy,'one','$.public') AND JSON_EXTRACT(c.policy,'$.public')<>CAST('true' AS JSON))";
        if($capability!=='read')$deny[]="COALESCE(JSON_UNQUOTE(JSON_EXTRACT(c.policy,'$.read')),'read') NOT IN (".implode(',',array_fill(0,count($allowed),'?')).")";
        $sql="$target.club_id=?$publicOriginal AND NOT EXISTS(WITH RECURSIVE dvm_policy_chain AS (
            SELECT b.id,b.club_id,JSON_EXTRACT($basePayload,'$.parent_id') AS parent_id,$policy AS policy,0 AS depth,JSON_ARRAY(b.id) AS seen,0 AS cycle
            FROM khcms_pages b$baseOriginal LEFT JOIN khcms_definitions bd ON bd.club_id=b.club_id AND bd.kind='template' AND bd.definition_key=JSON_UNQUOTE(JSON_EXTRACT($basePayload,'$.template'))
            WHERE b.id=$target.id AND b.club_id=$target.club_id
            UNION ALL
            SELECT a.id,a.club_id,JSON_EXTRACT($ancestorPayload,'$.parent_id'),$parentPolicy,c.depth+1,JSON_ARRAY_APPEND(c.seen,'$',a.id),JSON_CONTAINS(c.seen,JSON_QUOTE(a.id),'$')
            FROM dvm_policy_chain c JOIN khcms_pages a ON a.id=$parent AND a.club_id=c.club_id$ancestorOriginal
            LEFT JOIN khcms_definitions ad ON ad.club_id=a.club_id AND ad.kind='template' AND ad.definition_key=JSON_UNQUOTE(JSON_EXTRACT($ancestorPayload,'$.template'))
            WHERE c.cycle=0 AND c.depth<100 AND $inherit
        ) SELECT 1 FROM dvm_policy_chain c WHERE ".implode(' OR ',$deny).')';
        return [$sql,[$club,...$allowed,...($capability!=='read'?$allowed:[])]];
    }

    public static function assertCapability(PDO $db,Access $access,string $id,string $capability):void
    {
        if(!preg_match('/\A[a-f0-9]{32}\z/D',$id))throw new InvalidArgumentException('Gültige Seitenkennung angeben.');
        [$where,$parameters]=self::readFilter($db,$access,'p',false,$capability);
        if(!Db::run($db,'SELECT p.id FROM khcms_pages p WHERE p.id=? AND '.$where,[$id,...$parameters])->fetchColumn())throw new DomainException('Seite ist für diese Berechtigung nicht verfügbar.');
    }

    /** Copying a tree must fail for any unavailable selected original, never silently omit it. */
    public static function assertAllReadable(PDO $db,Access $access,array $ids):void
    {
        if(!array_is_list($ids)||count(array_unique($ids))!==count($ids))throw new InvalidArgumentException('Eindeutige Originalseiten auswählen.');
        foreach($ids as $id)if(!is_string($id)||!preg_match('/\A[a-f0-9]{32}\z/D',$id))throw new InvalidArgumentException('Originalseitenkennungen prüfen.');
        [$where,$parameters]=self::readFilter($db,$access);
        foreach(array_chunk($ids,200) as $group)if((int)Db::run($db,'SELECT COUNT(*) FROM khcms_pages p WHERE p.id IN ('.implode(',',array_fill(0,count($group),'?')).') AND '.$where,[...$group,...$parameters])->fetchColumn()!==count($group))throw new DomainException('Mindestens eine Originalseite ist für diesen Kopiervorgang nicht freigegeben.');
    }

    public static function assertChange(PDO $db,Access $access,string $id,array $payload,?array $previous=null,?array $copiedOriginal=null):void
    {
        $access->require('website','edit');
        $current=self::pagePolicy($payload['page_policy']??[]);$old=self::pagePolicy(($previous??$copiedOriginal)['page_policy']??[]);
        if($current!==$old)$access->require('website','manage');
        $state=self::state($db,$access->club());if(!(int)$state['access_rules']&&!(int)$state['family_rules']&&$current===[])return;
        if($previous!==null&&(int)$state['access_rules'])self::assertCapability($db,$access,$id,'edit');
        $model=new DataModel($db,$access);$templates=array_column($model->templates(),null,'key');
        $rows=(int)$state['family_rules']?Db::run($db,"SELECT p.id,p.draft_json,r.payload_json FROM khcms_pages p LEFT JOIN khcms_revisions r ON r.page_id=p.id AND r.club_id=p.club_id AND r.revision=p.published_revision WHERE p.club_id=? AND (p.id=? OR JSON_UNQUOTE(JSON_EXTRACT(p.draft_json,'$.parent_id'))=? OR JSON_UNQUOTE(JSON_EXTRACT(r.payload_json,'$.parent_id'))=?)",[$access->club(),$payload['parent_id']??'',$id,$id])->fetchAll():[];
        $pages=[];$published=[];foreach($rows as $row){$pages[$row['id']]=json_decode($row['draft_json'],true,512,JSON_THROW_ON_ERROR);if($row['payload_json']!==null)$published[$row['id']]=json_decode($row['payload_json'],true,512,JSON_THROW_ON_ERROR);}$pages[$id]=$payload;
        if((int)$state['family_rules'])self::family($payload,$templates,$pages);
        $policy=self::effective($payload,$templates);
        $access->require('website',$policy['read']);
        $access->require('website',$policy['edit']);
        if((int)$state['access_rules']&&($payload['parent_id']??null)){self::assertCapability($db,$access,$payload['parent_id'],'read');if($policy['inherit'])self::assertCapability($db,$access,$payload['parent_id'],'edit');}
        if((int)$state['family_rules'])foreach([...array_values($pages),...array_values($published)] as $child)if(($child['parent_id']??null)===$id)self::family($child,$templates,$pages);
    }

    /** Call before a model revision is persisted; changing family rules cannot strand existing pages. */
    public static function assertModelUpdate(PDO $db,Access $access,array $definition):void
    {
        $access->require('website','manage');$templates=array_column((new DataModel($db,$access))->templates(),null,'key');$templates[$definition['key']]=$definition;
        foreach(self::normalize($definition['policy']??[]) as $key=>$value)if(in_array($key,['parents','children'],true))foreach($value??[] as $allowed)if(!isset($templates[$allowed]))throw new InvalidArgumentException('Eine ausgewählte Familienvorlage ist nicht vorhanden.');
        $rows=Db::run($db,'SELECT p.id,p.draft_json,r.payload_json FROM khcms_pages p LEFT JOIN khcms_revisions r ON r.page_id=p.id AND r.club_id=p.club_id AND r.revision=p.published_revision WHERE p.club_id=?',[$access->club()])->fetchAll();$pages=[];$published=[];
        foreach($rows as $row){$pages[$row['id']]=json_decode($row['draft_json'],true,512,JSON_THROW_ON_ERROR);if($row['payload_json']!==null)$published[]=json_decode($row['payload_json'],true,512,JSON_THROW_ON_ERROR);}
        foreach([...array_values($pages),...$published] as $payload)self::family($payload,$templates,$pages);
    }

    public static function presentation(array $payload,?PDO $db=null,?Access $access=null):array
    {
        $settings=$payload['template_definition']['policy']??[];
        if($db!==null){if($access===null)throw new InvalidArgumentException('Vereinszugang für die Vorlagendarstellung angeben.');$access->require('website','read');$json=Db::run($db,"SELECT definition_json FROM khcms_definitions WHERE club_id=? AND kind='template' AND definition_key=?",[$access->club(),$payload['template']])->fetchColumn();if($json!==false)$settings=json_decode($json,true,512,JSON_THROW_ON_ERROR)['policy']??[];}
        $policy=array_replace(self::DEFAULTS,self::normalize($settings));return ['theme'=>$policy['theme'],'cache'=>$policy['cache']];
    }

    private static function effective(array $payload,array $templates):array
    {
        $template=$templates[$payload['template']]??throw new Conflict('Eine verwendete Seitenvorlage ist nicht mehr verfügbar.');
        return array_replace(self::DEFAULTS,self::normalize($template['policy']??[]),self::pagePolicy($payload['page_policy']??[]));
    }

    private static function family(array $payload,array $templates,array $pages):void
    {
        $own=self::effective($payload,$templates);$parent=$payload['parent_id']??null;
        if($parent===null){if(!$own['root'])throw new Conflict('Diese Vorlage benötigt eine erlaubte übergeordnete Seite.');return;}
        if(!isset($pages[$parent]))throw new Conflict('Übergeordnete Seite ist nicht verfügbar.');$ancestor=$pages[$parent];$rules=self::effective($ancestor,$templates);
        if(($own['parents']!==null&&!in_array($ancestor['template'],$own['parents'],true))||($rules['children']!==null&&!in_array($payload['template'],$rules['children'],true)))throw new Conflict('Diese Vorlagen dürfen nicht als Eltern- und Unterseite verbunden werden.');
    }
}
