<?php declare(strict_types=1);
namespace Kulturhus\Native\Routing;

use Kulturhus\Native\Cms\CustomFields;
use Kulturhus\Native\Shared\{Access,Db};
use PDO;

/** Current settings of the published template key; immutable content snapshots are never rewritten. */
final class TemplatePolicies
{
    public function __construct(private PDO $db,private Access $access){}
    public function forPage(array $published):Policy
    {
        $actor=$this->access->require('website','read');$key=CustomFields::key($published['template']??null);
        $json=Db::run($this->db,"SELECT definition_json FROM khcms_definitions WHERE club_id=? AND kind='template' AND definition_key=?",[$actor['club_id'],$key])->fetchColumn();
        return Policy::from($json===false?[]:(json_decode($json,true,512,JSON_THROW_ON_ERROR)['url_policy']??[]));
    }
}
