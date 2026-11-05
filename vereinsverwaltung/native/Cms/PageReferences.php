<?php declare(strict_types=1);
namespace Kulturhus\Native\Cms;

use Kulturhus\Native\Shared\{Access, Db};
use PDO;

/** Dependencies use saved field types, including public originals with an older model. */
final class PageReferences
{
    public function __construct(private PDO $db, private Access $access) {}

    public function incoming(string $id): array
    {
        $this->access->require('website', 'read');
        $rows = [];
        foreach (['draft' => 'p.draft_json', 'published' => 'r.payload_json'] as $basis => $payload) {
            $from = 'khcms_pages p';
            if ($basis === 'published') $from .= ' JOIN khcms_revisions r ON r.page_id=p.id AND r.club_id=p.club_id AND r.revision=p.published_revision';
            $active = $basis === 'draft' ? ' AND '.PageTrash::activeSql() : '';
            $found = Db::run($this->db, "SELECT p.id,p.slug,$payload AS payload_json FROM $from WHERE p.club_id=? AND p.id<>?$active AND JSON_SEARCH($payload,'one',?) IS NOT NULL ORDER BY p.id", [$this->access->club(),$id,$id]);
            $count=0;
            while($row=$found->fetch(PDO::FETCH_ASSOC)){
                $saved=json_decode($row['payload_json'],true,512,JSON_THROW_ON_ERROR);
                if(($saved['parent_id']??null)!==$id&&!in_array($id,FieldValues::references($saved,'page'),true))continue;
                $readable=FieldPolicy::internalSource($this->db,$this->access,$saved);$restricted=($saved['parent_id']??null)!==$id&&!in_array($id,FieldValues::references($readable,'page'),true);
                $rows[]=['id'=>$row['id'],'slug'=>$row['slug'],'title'=>$saved['title'],'basis'=>$basis,'field_restricted'=>$restricted];
                if(++$count===26)break;
            }
        }
        if(!$rows)return [];
        $ids=array_values(array_unique(array_column($rows,'id')));[$policy,$parameters]=TemplatePolicy::readFilter($this->db,$this->access);
        $visible=array_fill_keys(Db::run($this->db,'SELECT p.id FROM khcms_pages p WHERE p.id IN ('.implode(',',array_fill(0,count($ids),'?')).') AND ('.$policy.')',[...$ids,...$parameters])->fetchAll(PDO::FETCH_COLUMN),true);
        return array_map(static function(array $row)use($visible):array{if(!isset($visible[$row['id']])||$row['field_restricted'])return ['id'=>null,'slug'=>null,'title'=>'Geschützter Verweis','basis'=>$row['basis'],'restricted'=>true];unset($row['field_restricted']);return $row;},$rows);
    }

    /** Restoring an old definition keeps its bytes, while current destinations must be available. */
    public function assertAvailable(string $id, array $payload): void
    {
        $seen = [$id => true];
        $parent = $payload['parent_id'] ?? null;
        while ($parent !== null) {
            if (isset($seen[$parent]) || count($seen) >= 100) throw new \InvalidArgumentException('Die Seitenhierarchie muss kreisfrei und auf 100 Ebenen begrenzt bleiben.');
            $seen[$parent] = true;
            $json = Db::run($this->db, 'SELECT p.draft_json FROM khcms_pages p WHERE p.id=? AND p.club_id=? AND '.PageTrash::activeSql(), [$parent,$this->access->club()])->fetchColumn();
            if (!is_string($json)) throw new Conflict('Übergeordnete Seite zuerst aus dem Papierkorb zurückholen oder die Zuordnung prüfen.');
            TemplatePolicy::assertCapability($this->db,$this->access,$parent,'read');
            $parent = json_decode($json,true,512,JSON_THROW_ON_ERROR)['parent_id'] ?? null;
        }
        foreach (FieldValues::references($payload,'page') as $value) {
            if ($value !== $id) {
                TemplatePolicy::assertCapability($this->db,$this->access,$value,'read');
                if (!Db::run($this->db, 'SELECT p.id FROM khcms_pages p WHERE p.id=? AND p.club_id=? AND '.PageTrash::activeSql(), [$value,$this->access->club()])->fetchColumn()) throw new Conflict('Verknüpfte Seite zuerst aus dem Papierkorb zurückholen oder den Verweis prüfen.');
            }
        }
        $media = [...($payload['media_ids'] ?? []), ...ContentSections::mediaIds($payload)];
        $media = [...$media, ...FieldValues::references($payload,'media')];
        foreach (array_unique($media) as $mid) {
            MediaAvailability::assertSelectable($this->db,$this->access->club(),$mid);
            if (!Db::run($this->db, 'SELECT id FROM khcms_media WHERE id=? AND club_id=?', [$mid,$this->access->club()])->fetchColumn()) throw new \InvalidArgumentException('Originalbild ist in diesem Verein nicht verfügbar.');
        }
    }
}
