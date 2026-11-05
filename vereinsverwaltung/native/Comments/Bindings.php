<?php declare(strict_types=1);
namespace Kulturhus\Native\Comments;

use Kulturhus\Native\Cms\{DataModel,FieldPolicy};
use Kulturhus\Native\Shared\{Access,Db};
use PDO;

/** A stream is scoped by actual page, club and optional declarative field, never by submitted JSON. */
final class Bindings
{
    /** Caller joins p and c; anonymous readers also join the exact published revision as r. */
    public static function filter(PDO $db,Access $access,bool $public=false):array
    {
        $fields=(new DataModel($db,$access))->fields();$parts=[];$args=[];
        $column=$public?'r.payload_json':'p.draft_json';
        if(!in_array('comments',array_column($fields,'key'),true)){
            // Removing a field declaration cannot silently reopen its older private conversation.
            $parts[]="(c.stream='comments' AND JSON_CONTAINS_PATH($column,'one','$.field_definitions.comments')=0)";
        }
        foreach($fields as $field){
            if($field['type']!=='comments')continue;
            [$guard,$parameters]=FieldPolicy::queryGuard($db,$access,$field['key'],'p',$public);
            $parts[]='(c.stream=? AND ('.$guard.'))';array_push($args,$field['key'],...$parameters);
        }
        return [$parts?'('.implode(' OR ',$parts).')':'0=1',$args];
    }

    public static function assert(PDO $db,Access $access,string $page,string $stream,bool $public=false):void
    {
        Values::id($page);Values::stream($stream);$access->require('website','read');
        [$guard,$args]=self::filter($db,$access,$public);
        $revision=$public?' JOIN khcms_revisions r ON r.club_id=p.club_id AND r.page_id=p.id AND r.revision=p.published_revision':'';
        if(!Db::run($db,'SELECT 1 FROM khcms_pages p'.$revision.' CROSS JOIN (SELECT ? AS stream) c WHERE p.club_id=? AND p.id=? AND ('.$guard.')',[$stream,$access->club(),$page,...$args])->fetchColumn())throw new \DomainException('Dieses Kommentarfeld ist für die ausgewählte Seite nicht verfügbar.');
    }

    /** Only visible original-bound field names reach settings/navigation. Saving never activates a stream. */
    public static function choices(PDO $db,Access $access,string $page):array
    {
        $source=(new \Kulturhus\Native\Cms\Pages($db,$access))->get($page);$choices=[];
        $definitions=FieldPolicy::editorDefinitions($db,$access,(new DataModel($db,$access))->templateFields($source['template']),$source['field_definitions']??[]);
        foreach(['comments'=>['label'=>'Kommentare','type'=>'comments'],...$definitions] as $key=>$field){
            if($field['type']!=='comments')continue;
            try{self::assert($db,$access,$page,$key);$choices[$key]=$field['label'];}catch(\DomainException){}
        }
        return $choices;
    }
}
