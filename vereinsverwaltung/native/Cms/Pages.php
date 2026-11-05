<?php
declare(strict_types=1);

namespace Kulturhus\Native\Cms;

use InvalidArgumentException;
use Kulturhus\Native\Shared\Access;
use Kulturhus\Native\Shared\Db;
use PDO;
use RuntimeException;

final class Pages
{
    use ClubPublicationLock;
    public function __construct(private readonly PDO $db, private readonly Access $access)
    {
    }

    public function list(): array
    {
        $this->access->require('website', 'read');
        [$policy,$parameters]=TemplatePolicy::readFilter($this->db,$this->access);
        $rows = Db::run($this->db, 'SELECT p.*,a.archived_at FROM khcms_pages p LEFT JOIN khcms_archives a ON a.page_id=p.id AND a.club_id=p.club_id WHERE (' .$policy.') AND ' . PageTrash::activeSql() . ' ORDER BY p.updated_at DESC,p.id', $parameters)->fetchAll(PDO::FETCH_ASSOC);
        return array_map(fn (array $row): array => $this->present($row), $rows);
    }

    public function get(string $id): array
    {
        $this->access->require('website', 'read');
        $row=$this->row($id, false, true);
        TemplatePolicy::assertCapability($this->db,$this->access,$id,'read');
        return $this->present($row);
    }

    /** Original-bound controls only; hidden values and definitions never leave this method. */
    public function editorDefinitions(string $id,string $template):array
    {
        $this->access->require('website','read');$row=$this->row($id,false,true);
        TemplatePolicy::assertCapability($this->db,$this->access,$id,'read');
        $original=$this->decode($row['draft_json']);
        $definitions=FieldPolicy::editorDefinitions($this->db,$this->access,(new DataModel($this->db,$this->access))->templateFields($template),$original['field_definitions']??[]);
        return \Kulturhus\Native\Fieldtypes\Runtime::editorDefinitions($this->db,$this->access,$definitions,$original['field_definitions']??[]);
    }

    /** Private preview reads the current authorized original without normalizing or persisting it. */
    public function previewSource(string $id,int $version):array
    {
        $this->access->require('website','edit');
        $page=$this->get($id);
        TemplatePolicy::assertCapability($this->db,$this->access,$id,'edit');
        if($page['version']!==$version)throw new Conflict('Die Seite wurde inzwischen geändert. Vorschau neu öffnen.');
        return $page;
    }

    public function presentation(array $published):array
    {
        return TemplatePolicy::presentation($published,$this->db,$this->access);
    }

    /** Full replacement of editable fields. A missing id creates a page with expectedVersion=0. */
    public function save(array $data, int $expectedVersion = 0): array
    {
        return $this->write($data,$expectedVersion);
    }

    /** Available originals for the copy form; all actual writes repeat these current checks. */
    public function copyBases(string $id):array
    {
        try{$this->access->require('website','edit');TemplatePolicy::assertCapability($this->db,$this->access,$id,'read');}catch(\DomainException){return [];}
        $row=$this->row($id,false,true);if($row['trashed_at']!==null)return [];$result=[];
        foreach(['draft','published'] as $basis){
            if($basis==='published'&&$row['published_revision']===null)continue;
            $json=$basis==='draft'?$row['draft_json']:Db::run($this->db,'SELECT payload_json FROM khcms_revisions WHERE page_id=? AND club_id=? AND revision=?',[$id,$this->access->club(),$row['published_revision']])->fetchColumn();
            if(!is_string($json))continue;
            try{FieldPolicy::assertCopy($this->db,$this->access,$this->decode($json));(new \Kulturhus\Native\Language\Translations($this->db,$this->access))->sourceFingerprint($id,$basis);$result[]=$basis;}catch(\DomainException){}
        }
        return $result;
    }

    /** A trusted original binds copied item rights and versions; no form can request this mode. */
    public function copyFrom(string $sourceId,int $expectedVersion,array $options):array
    {
        $this->access->require('website','edit');
        return Db::atomic($this->db,function()use($sourceId,$expectedVersion,$options):array{
            $this->access->require('website','edit');$this->lockClub();
            $payload=(new PageCopies($this->db,$this->access))->prepare($sourceId,$expectedVersion,$options);
            $copy=$this->write($payload,0,$payload);
            (new \Kulturhus\Native\Language\Translations($this->db,$this->access))->cloneDrafts($sourceId,$copy['id'],$options['basis']??'draft');
            return $copy;
        });
    }

    /** Server-only confirmed import. The callback may finish newly created model declarations.
     * All identities must be locally reserved by the import plan; existing pages are never targets.
     * @param list<array{id:string,payload:array,field_policy_history?:array}> $prepared
     * @return list<array>
     */
    public function importDraftSet(array $prepared,?callable $beforeNormalize=null):array
    {
        $this->access->require('website','manage');$this->access->require('website','edit');
        if(!array_is_list($prepared)||!$prepared||count($prepared)>5000)throw new InvalidArgumentException('Ein bis 5000 neue Importseiten vorbereiten.');
        $ids=[];$bytes=0;
        foreach($prepared as $item){
            if(!is_array($item)||array_diff(array_keys($item),['id','payload','field_policy_history'])||!is_string($item['id']??null)||!preg_match('/\A[a-f0-9]{32}\z/D',$item['id'])||!is_array($item['payload']??null)||isset($ids[$item['id']]))throw new InvalidArgumentException('Importseiten benötigen eindeutige neue Kennungen und vollständige Inhalte.');
            $history=FieldPolicy::normalizeHistory($item['field_policy_history']??[]);
            if(isset($item['payload']['id'])&&$item['payload']['id']!==$item['id'])throw new InvalidArgumentException('Importkennung und Seiteninhalt gehören nicht zusammen.');
            $ids[$item['id']]=true;$bytes+=strlen($this->json($item['payload']))+strlen($this->json($history));if($bytes>64*1024*1024)throw new InvalidArgumentException('Import auf 64 MiB Seiteninhalt begrenzen.');
        }
        return Db::atomic($this->db,function()use($prepared,$beforeNormalize,$ids):array{
            $actor=$this->access->require('website','manage');$this->access->require('website','edit');$this->lockClub();
            foreach(array_chunk(array_keys($ids),100) as $chunk)if(Db::run($this->db,'SELECT id FROM khcms_pages WHERE id IN ('.implode(',',array_fill(0,count($chunk),'?')).') LIMIT 1',$chunk)->fetchColumn())throw new Conflict('Eine vorbereitete Importkennung ist bereits vergeben.');
            foreach($prepared as $item){
                $input=$item['payload'];$key=CustomFields::key($input['template']??'standard');$core=Templates::normalize($input,[$key=>true]);
                PathHistory::assertAvailable($this->db,$actor['club_id'],$item['id'],$core['slug']);
                Db::run($this->db,'INSERT INTO khcms_pages(id,club_id,slug,version,draft_json,created_by,updated_by,created_at,updated_at) VALUES(?,?,?,0,?,?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))',[$item['id'],$actor['club_id'],$core['slug'],$this->json($core),$actor['id'],$actor['id']]);
            }
            if($beforeNormalize!==null)$beforeNormalize(array_keys($ids));
            $this->access->require('website','manage');$this->access->require('website','edit');$model=new DataModel($this->db,$this->access);
            foreach($prepared as $item){
                $payload=$model->normalizePage($item['payload'],null,false,true);
                $payload=FieldPolicy::bindHistory($this->db,$this->access,$payload,$item['field_policy_history']??[]);
                if(array_key_exists('document_links',$payload))$payload['document_links']=WebsiteDocuments::capture($this->db,$actor['club_id'],$payload['document_links']);
                $metadata=MediaMetadata::capture($this->db,$actor['club_id'],$payload);if($metadata)$payload['media_metadata']=$metadata;
                if(strlen($this->json($payload))>2*1024*1024)throw new InvalidArgumentException('Ein Importinhalt darf maximal 2 MiB umfassen.');
                $affected=Db::run($this->db,'UPDATE khcms_pages SET slug=?,draft_json=? WHERE id=? AND club_id=? AND version=0 AND published_revision IS NULL',[$payload['slug'],$this->json($payload),$item['id'],$actor['club_id']])->rowCount();
                // MySQL may report zero when the normalized core did not change; identity/version remains exact.
                if(!$affected&&(int)$this->row($item['id'])['version']!==0)throw new Conflict('Die neue Importreservierung wurde verändert.');
                TemplatePolicy::noteCopy($this->db,$this->access,$payload);
            }
            foreach($prepared as $item){
                $row=$this->row($item['id'],true);if((int)$row['version']!==0||$row['published_revision']!==null)throw new Conflict('Die neue Importreservierung wurde verändert.');$payload=$this->decode($row['draft_json']);
                TemplatePolicy::assertChange($this->db,$this->access,$item['id'],$payload);$this->validateReferences($item['id'],$payload);
            }
            $result=[];
            foreach($prepared as $item){$payload=$this->decode($this->row($item['id'])['draft_json']);Db::run($this->db,'UPDATE khcms_pages SET version=1 WHERE id=? AND club_id=?',[$item['id'],$actor['club_id']]);$this->revision($item['id'],1,$payload,(string)$actor['id'],'import-draft');$result[]=$this->present($this->row($item['id']));}
            return $result;
        });
    }

    /** The review list contains paths only; replacement bindings always come from the live model. */
    public function fieldtypeChanges(string $id):array
    {
        $this->access->require('website','manage');$this->access->require('website','edit');
        $row=$this->row($id,false,true);TemplatePolicy::assertCapability($this->db,$this->access,$id,'edit');
        $payload=$this->decode($row['draft_json']);
        return \Kulturhus\Native\Fieldtypes\Review::changes($this->db,$this->access,$payload,(new DataModel($this->db,$this->access))->templateFields($payload['template']));
    }

    public function adoptFieldtypes(string $id,int $expectedVersion,array $paths,bool $confirmed):array
    {
        $this->access->require('website','manage');$this->access->require('website','edit');
        if(!$confirmed)throw new InvalidArgumentException('Übernahme der Typfassungen bewusst bestätigen.');
        $paths=\Kulturhus\Native\Fieldtypes\Review::paths($paths);
        return Db::atomic($this->db,function()use($id,$expectedVersion,$paths):array{
            $this->lockClub();$row=$this->row($id,true);$this->expect($row,$expectedVersion);
            if($row['trashed_at']!==null||$row['archived_at']!==null)throw new Conflict('Original zuerst zurück in die Bearbeitung holen.');
            $payload=$this->decode($row['draft_json']);$payload['id']=$id;
            return $this->write($payload,$expectedVersion,null,$paths);
        });
    }

    private function write(array $data,int $expectedVersion,?array $copiedOriginal=null,?array $providerPaths=null):array
    {
        $this->access->require('website', 'edit');
        $id = $data['id'] ?? '';
        if (!is_string($id) || ($id !== '' && !preg_match('/\A[a-f0-9]{32}\z/D', $id))) {
            throw new InvalidArgumentException('Ungültige Seiten-ID.');
        }
        return Db::atomic($this->db, function () use ($id, $data, $expectedVersion, $copiedOriginal,$providerPaths): array {
            $actor = $this->access->require('website', 'edit');
            $this->lockClub();
            $creating = $id === '';
            $pageId = $creating ? bin2hex(random_bytes(16)) : $id;
            if ($creating && $expectedVersion !== 0) {
                throw new Conflict('Neue Seiten benötigen Versionsstand 0.');
            }
            $old = $creating ? null : $this->row($pageId, true);
            if ($old !== null) {
                $this->expect($old, $expectedVersion);
                TemplatePolicy::assertCapability($this->db,$this->access,$pageId,'edit');
            }
            $previous=$old===null?null:$this->decode($old['draft_json']);
            $model=new DataModel($this->db,$this->access);$reviews=[];
            if($creating&&($data['auto_address']??null)==='1'&&array_key_exists('slug',$data)&&!is_string($data['slug']))throw new InvalidArgumentException('Adresse als gültigen Text eingeben.');
            if($creating&&($data['auto_address']??null)==='1'&&in_array(trim(is_string($data['slug']??null)?$data['slug']:''),['','galerie/'],true)){
                if(!is_string($data['title']??null)||trim($data['title'])==='')throw new InvalidArgumentException('Einen Titel eingeben.');
                $renderer=$model->template(is_string($data['template']??null)?$data['template']:'standard')['renderer'];
                $data['slug']=SuggestedAddress::choose($this->db,$actor['club_id'],$data['title'],$renderer==='gallery'?'galerie/':($renderer==='event'?'veranstaltungen/':''));
            }
            unset($data['auto_address']);
            if($providerPaths!==null){if($previous===null)throw new Conflict('Typübernahme benötigt ein gespeichertes Original.');$reviews=\Kulturhus\Native\Fieldtypes\Review::authorize($this->db,$this->access,$previous,$model->templateFields($previous['template']),$providerPaths);}
            $payload = $model->normalizePage($data, $previous??$copiedOriginal,false,false,$reviews);
            TemplatePolicy::assertChange($this->db,$this->access,$pageId,$payload,$previous,$copiedOriginal);
            if (array_key_exists('document_links',$payload)) $payload['document_links']=WebsiteDocuments::capture($this->db,$this->access->club(),$payload['document_links']);
            $this->validateReferences($pageId, $payload);
            $mediaMetadata = MediaMetadata::capture($this->db, $this->access->club(), $payload);
            if ($mediaMetadata) $payload['media_metadata'] = $mediaMetadata;
            if (strlen($this->json($payload)) > 2*1024*1024) throw new InvalidArgumentException('Inhalt einschließlich Bildangaben darf maximal 2 MiB umfassen.');
            $payload = NewsReview::afterEdit($payload, $old === null ? [] : $this->decode($old['draft_json']));
            $version = $creating ? 1 : $expectedVersion + 1;
            $json = $this->json($payload);
            if ($creating) {
                Db::run($this->db, 'INSERT INTO khcms_pages (id,club_id,slug,version,draft_json,created_by,updated_by,created_at,updated_at) VALUES (?,?,?,?,?,?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))', [$pageId, $this->access->club(), $payload['slug'], $version, $json, $actor['id'], $actor['id']]);
            } else {
                Db::run($this->db, 'UPDATE khcms_pages SET slug=?,version=?,draft_json=?,updated_by=?,updated_at=UTC_TIMESTAMP(6) WHERE id=? AND club_id=?', [$payload['slug'], $version, $json, $actor['id'], $pageId, $this->access->club()]);
            }
            $this->revision($pageId, $version, $payload, (string) $actor['id'], $creating ? 'create' : ($providerPaths===null?'save':'fieldtypes-review'));
            if(($payload['page_policy']??[])!==($previous['page_policy']??[])){
                if($copiedOriginal!==null)TemplatePolicy::noteCopy($this->db,$this->access,$payload);
                else TemplatePolicy::refresh($this->db,$this->access);
            }
            return $this->present($this->row($pageId));
        });
    }

    /** Explicit review of the current saved news revision; never changes the public projection. */
    public function review(string $id, string $status, int $expectedVersion): array
    {
        $status = NewsReview::normalize($status);
        $capability = $status === 'approved' ? 'publish' : 'edit';
        $this->access->require('website', $capability);
        if ($expectedVersion < 1 || $expectedVersion > 4294967294) throw new InvalidArgumentException('Gültigen Versionsstand angeben.');
        return Db::atomic($this->db, function () use ($id, $status, $expectedVersion, $capability): array {
            $actor = $this->access->require('website', $capability);
            $this->lockClub();
            $row = $this->row($id, true);
            $this->expect($row, $expectedVersion);
            TemplatePolicy::assertCapability($this->db,$this->access,$id,$capability);
            if ($row['archived_at'] !== null) throw new Conflict('Archivierten Beitrag zuerst zurück in die Bearbeitung holen.');
            $payload = (new DataModel($this->db, $this->access))->publication($this->decode($row['draft_json']));
            if (!NewsChannels::isNews($payload)) throw new InvalidArgumentException('Redaktionelle Prüfung ist nur für Beiträge verfügbar.');
            $policy = new NewsPolicy($this->db, $this->access);
            $review = $policy->state($id, $payload);
            if (NewsReview::status($payload) === $status && !($status === 'approved' && $review['enabled'] && !$review['valid'])) throw new Conflict('Dieser Redaktionsstand ist bereits gespeichert.');
            if ($status === 'approved' && $review['enabled'] && $review['self']) throw new \DomainException('Eine andere Person mit Veröffentlichungsrecht muss diesen Beitrag freigeben.');
            if ($status === 'approved') {
                NewsReview::assertReady($payload);
                MediaMetadata::assertPublication($this->db, $this->access->club(), $payload);
            }
            $payload['news_review'] = $status;
            $payload = (new DataModel($this->db, $this->access))->publication($payload);
            $version = $expectedVersion + 1;
            $this->revision($id, $version, $payload, (string) $actor['id'], 'news-' . ($status === 'approved' ? 'approve' : $status));
            if ($status === 'approved') $policy->record($id, $payload, $version);
            Db::run($this->db, 'UPDATE khcms_pages SET version=?,draft_json=?,updated_by=?,updated_at=UTC_TIMESTAMP(6) WHERE id=? AND club_id=?', [$version, $this->json($payload), $actor['id'], $id, $this->access->club()]);
            return $this->present($this->row($id));
        });
    }

    public function publish(string $id, int $expectedVersion): array
    {
        $this->access->require('website', 'publish');
        return Db::atomic($this->db, function () use ($id, $expectedVersion): array {
            $actor = $this->access->require('website', 'publish');
            $this->lockClub();
            $row = $this->row($id, true);
            $this->expect($row, $expectedVersion);
            TemplatePolicy::assertCapability($this->db,$this->access,$id,'publish');
            if ($row['archived_at'] !== null) throw new Conflict('Archivierten Inhalt zuerst zurück in die Bearbeitung holen.');
            $payload = (new DataModel($this->db, $this->access))->publication($this->decode($row['draft_json']));
            (new NewsPolicy($this->db, $this->access))->assertPublishable($id, $payload);
            if (NewsChannels::isNews($payload) && strcasecmp(trim($payload['title']), 'Neue Website-Neuigkeit') === 0) {
                throw new \DomainException('Den Platzhaltertitel vor der Veröffentlichung durch einen Beitragstitel ersetzen.');
            }
            $this->validateReferences($id, $payload);
            MediaMetadata::assertPublication($this->db, $this->access->club(), $payload);
            \Kulturhus\Native\Files\FileReferences::assertPublication($this->db,$this->access->club(),$payload['custom_file_metadata']??[]);
            $this->validatePublishedParent($id, $payload['parent_id']);
            if ($row['published_slug'] !== null && $row['published_slug'] !== $payload['slug']) {
                (new PathHistory($this->db, $this->access))->retainPublished($id, $row['published_slug'], $expectedVersion);
            }
            $version = $expectedVersion + 1;
            $this->revision($id, $version, $payload, (string) $actor['id'], 'publish');
            Db::run($this->db, 'UPDATE khcms_pages SET version=?,published_revision=?,published_slug=?,draft_json=?,published_at=UTC_TIMESTAMP(6),updated_by=?,updated_at=UTC_TIMESTAMP(6) WHERE id=? AND club_id=?', [$version, $version, $payload['slug'], $this->json($payload), $actor['id'], $id, $this->access->club()]);
            Db::run($this->db, 'DELETE FROM khcms_published_media WHERE page_id=? AND club_id=?', [$id, $this->access->club()]);
            foreach ((NewsChannels::visible($payload, 'website') || NewsChannels::visible($payload, 'rss')) ? $this->mediaIds($payload,true) : [] as $mediaId) {
                Db::run($this->db, 'INSERT INTO khcms_published_media (page_id,media_id,club_id) VALUES (?,?,?)', [$id, $mediaId, $this->access->club()]);
            }
            EventIndex::publish($this->db, $this->access->club(), $id, $version, $payload);
            EventPublications::published($this->db, $this->access->club(), $id);
            $this->invalidate();
            return $this->present($this->row($id));
        });
    }

    public function unpublish(string $id, int $expectedVersion): array
    {
        $this->access->require('website', 'publish');
        return Db::atomic($this->db, function () use ($id, $expectedVersion): array {
            $actor = $this->access->require('website', 'publish');
            $this->lockClub();
            $row = $this->row($id, true);
            $this->expect($row, $expectedVersion);
            TemplatePolicy::assertCapability($this->db,$this->access,$id,'publish');
            foreach ($this->publishedRows() as $published) {
                if ($this->decode($published['payload_json'])['parent_id'] === $id) {
                    throw new Conflict('Zuerst untergeordnete veröffentlichte Seiten umhängen oder zurückziehen.');
                }
            }
            $version = $expectedVersion + 1;
            $this->revision($id, $version, $this->decode($row['draft_json']), (string) $actor['id'], 'unpublish');
            (new PathHistory($this->db, $this->access))->retainPublished($id, $row['published_slug'], $expectedVersion);
            Db::run($this->db, 'UPDATE khcms_pages SET version=?,published_revision=NULL,published_slug=NULL,published_at=NULL,updated_by=?,updated_at=UTC_TIMESTAMP(6) WHERE id=? AND club_id=?', [$version, $actor['id'], $id, $this->access->club()]);
            Db::run($this->db, 'DELETE FROM khcms_published_media WHERE page_id=? AND club_id=?', [$id, $this->access->club()]);
            EventIndex::withdraw($this->db, $this->access->club(), $id);
            EventPublications::removed($this->db, $this->access->club(), $id);
            $this->invalidate();
            return $this->present($this->row($id));
        });
    }

    /** Archiving is an explicit lifecycle change and retracts the public projection atomically. */
    public function archive(string $id, int $expectedVersion): array
    {
        return $this->setArchived($id, $expectedVersion, true);
    }

    /** Returning to the workspace never republishes an old public snapshot. */
    public function unarchive(string $id, int $expectedVersion): array
    {
        return $this->setArchived($id, $expectedVersion, false);
    }

    private function setArchived(string $id, int $expectedVersion, bool $archive): array
    {
        $capability = $archive ? 'publish' : 'edit';
        $this->access->require('website', $capability);
        return Db::atomic($this->db, function () use ($id, $expectedVersion, $archive, $capability): array {
            $actor = $this->access->require('website', $capability);
            $this->lockClub();
            $row = $this->row($id, true); $this->expect($row, $expectedVersion);
            TemplatePolicy::assertCapability($this->db,$this->access,$id,$capability);
            if (($row['archived_at'] !== null) === $archive) throw new Conflict('Archivstatus wurde bereits geändert. Bitte neu laden.');
            if ($archive) {
                (new PathHistory($this->db, $this->access))->retainPublished($id, $row['published_slug'], $expectedVersion);
                foreach ($this->publishedRows() as $published) {
                    if ($this->decode($published['payload_json'])['parent_id'] === $id) throw new Conflict('Zuerst untergeordnete veröffentlichte Seiten umhängen oder zurückziehen.');
                }
                Db::run($this->db, 'INSERT INTO khcms_archives(page_id,club_id,archived_by,archived_at) VALUES(?,?,?,UTC_TIMESTAMP(6))', [$id,$this->access->club(),$actor['id']]);
                Db::run($this->db, 'DELETE FROM khcms_published_media WHERE page_id=? AND club_id=?', [$id,$this->access->club()]);
            } else {
                Db::run($this->db, 'DELETE FROM khcms_archives WHERE page_id=? AND club_id=?', [$id,$this->access->club()]);
            }
            $version = $expectedVersion + 1;
            $payload = $this->decode($row['draft_json']);
            if (!$archive) $payload = NewsReview::afterEdit($payload);
            $this->revision($id,$version,$payload,(string)$actor['id'],$archive?'archive':'unarchive');
            Db::run($this->db, 'UPDATE khcms_pages SET version=?,draft_json=?,published_revision=NULL,published_slug=NULL,published_at=NULL,updated_by=?,updated_at=UTC_TIMESTAMP(6) WHERE id=? AND club_id=?', [$version,$this->json($payload),$actor['id'],$id,$this->access->club()]);
            EventIndex::withdraw($this->db, $this->access->club(), $id);
            EventPublications::removed($this->db, $this->access->club(), $id, $archive);
            $this->invalidate();
            return $this->present($this->row($id));
        });
    }

    public function revisions(string $id): array
    {
        $this->access->require('website', 'read');
        $this->row($id, false, true);
        TemplatePolicy::assertCapability($this->db,$this->access,$id,'read');
        $rows = Db::run($this->db, 'SELECT revision,payload_json,actor_id,action,created_at FROM khcms_revisions WHERE page_id=? AND club_id=? ORDER BY revision DESC', [$id, $this->access->club()])->fetchAll(PDO::FETCH_ASSOC);
        return array_map(function (array $row): array {
            $row['revision'] = (int) $row['revision'];
            $row['payload'] = FieldPolicy::internalSource($this->db,$this->access,$this->decode($row['payload_json']));
            unset($row['payload_json']);
            return $row;
        }, $rows);
    }

    public function restore(string $id, int $revision, int $expectedVersion): array
    {
        $this->access->require('website', 'edit');
        return Db::atomic($this->db, function () use ($id, $revision, $expectedVersion): array {
            $actor = $this->access->require('website', 'edit');
            $this->lockClub();
            $row = $this->row($id, true);
            $this->expect($row, $expectedVersion);
            TemplatePolicy::assertCapability($this->db,$this->access,$id,'edit');
            $snapshot = Db::run($this->db, 'SELECT payload_json FROM khcms_revisions WHERE page_id=? AND revision=? AND club_id=?', [$id, $revision, $this->access->club()])->fetch(PDO::FETCH_ASSOC);
            if (!$snapshot) {
                throw new RuntimeException('Revision nicht gefunden.');
            }
            $original = $this->decode($snapshot['payload_json']);
            $payload = (new DataModel($this->db, $this->access))->restorePage($original, $this->decode($row['draft_json']));
            TemplatePolicy::assertChange($this->db,$this->access,$id,$payload,$this->decode($row['draft_json']));
            if (array_key_exists('media_metadata', $original)) $payload['media_metadata'] = $original['media_metadata'];
            if (array_key_exists('document_links', $original)) $payload['document_links'] = $original['document_links'];
            $payload = NewsReview::afterEdit($payload, $this->decode($row['draft_json']));
            $this->validateReferences($id, $payload);
            $version = $expectedVersion + 1;
            Db::run($this->db, 'UPDATE khcms_pages SET slug=?,version=?,draft_json=?,updated_by=?,updated_at=UTC_TIMESTAMP(6) WHERE id=? AND club_id=?', [$payload['slug'], $version, $this->json($payload), $actor['id'], $id, $this->access->club()]);
            $this->revision($id, $version, $payload, (string) $actor['id'], 'restore');
            if(($payload['page_policy']??[])!==($this->decode($row['draft_json'])['page_policy']??[]))TemplatePolicy::refresh($this->db,$this->access);
            return $this->present($this->row($id));
        });
    }

    public function navigation(): array
    {
        $this->access->require('website', 'read');
        return (new Navigation($this->db,$this->access))->publicLinks();
    }

    public function footerNavigation(): array
    {
        return (new Navigation($this->db,$this->access))->footerLinks();
    }

    public function publicPage(string $slug, string $channel = 'website'): ?array
    {
        $this->access->require('website', 'read');
        [$policy,$parameters]=TemplatePolicy::readFilter($this->db,$this->access,'p',true);
        $row = Db::run($this->db, 'SELECT p.id,p.published_revision,p.published_at,r.payload_json FROM khcms_pages p JOIN khcms_revisions r ON r.page_id=p.id AND r.revision=p.published_revision AND r.club_id=p.club_id WHERE ('.$policy.') AND p.published_slug=? AND ' . NewsChannels::where($channel), [...$parameters, $slug])->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        return FieldPolicy::publicSource($this->db,$this->access->club(),$this->decode($row['payload_json'])) + ['id' => $row['id'], 'version' => (int) $row['published_revision'], 'published_revision' => (int) $row['published_revision'], 'published_version' => (int) $row['published_revision'], 'published_at' => $row['published_at']];
    }

    /** Bounded immutable public originals; never loads private draft_json for feed/search projections. */
    public function publicList(int $limit = 100, int $offset = 0, string $channel = 'website', array $renderers = []): array
    {
        $this->access->require('website', 'read');
        if ($limit < 1 || $limit > 100 || $offset < 0 || $offset > 1000000) {
            throw new InvalidArgumentException('Öffentliche Seiten: Limit 1–100 und Offset 0–1000000 erforderlich.');
        }
        if (!array_is_list($renderers) || count($renderers) > 4) throw new InvalidArgumentException('Öffentliche Renderer als Liste angeben.');
        foreach ($renderers as $renderer) {
            if (!is_string($renderer) || !array_key_exists($renderer, Templates::all())) throw new InvalidArgumentException('Unbekannter öffentlicher Renderer.');
        }
        $filter = $renderers ? " AND COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(r.payload_json,'$.template_definition.renderer')),'null'),JSON_UNQUOTE(JSON_EXTRACT(r.payload_json,'$.template'))) IN (" . implode(',', array_fill(0, count($renderers), '?')) . ')' : '';
        [$policy,$parameters]=TemplatePolicy::readFilter($this->db,$this->access,'p',true);
        $rows = Db::run($this->db, 'SELECT p.id,p.published_revision,p.published_at,r.payload_json FROM khcms_pages p JOIN khcms_revisions r ON r.page_id=p.id AND r.revision=p.published_revision AND r.club_id=p.club_id WHERE ('.$policy.') AND ' . NewsChannels::where($channel) . $filter . ' ORDER BY p.published_at DESC,p.id COLLATE utf8mb4_bin LIMIT ' . $limit . ' OFFSET ' . $offset, [...$parameters, ...$renderers])->fetchAll(PDO::FETCH_ASSOC);
        return array_map(fn (array $row): array => FieldPolicy::publicSource($this->db,$this->access->club(),$this->decode($row['payload_json'])) + ['id' => $row['id'], 'version' => (int) $row['published_revision'], 'published_revision' => (int) $row['published_revision'], 'published_version' => (int) $row['published_revision'], 'published_at' => $row['published_at']], $rows);
    }

    public function events(): Events
    {
        $this->access->require('website', 'read');
        return new Events($this->db, $this->access->club(), $this->access);
    }

    /** Public feed input: at most 100 metadata rows and one exact current payload in PHP memory. */
    public function publicOriginals(string $channel='website',array $renderers=[],?int $maximum=null):\Generator
    {
        $club=$this->access->require('website','read')['club_id'];
        if(!array_is_list($renderers)||count($renderers)>4||($maximum!==null&&($maximum<1||$maximum>100)))throw new InvalidArgumentException('Ungültige öffentliche Auswahl.');
        foreach($renderers as$renderer)if(!is_string($renderer)||!array_key_exists($renderer,Templates::all()))throw new InvalidArgumentException('Unbekannter öffentlicher Renderer.');
        $filter=$renderers?" AND COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(r.payload_json,'$.template_definition.renderer')),'null'),JSON_UNQUOTE(JSON_EXTRACT(r.payload_json,'$.template'))) IN (".implode(',',array_fill(0,count($renderers),'?')).')':'';
        $channelWhere=NewsChannels::where($channel);$cursor=null;$count=0;
        do{
            if($this->access->require('website','read')['club_id']!==$club)throw new \DomainException('Öffentliche Inhalte nicht verfügbar.');
            [$policy,$policyParameters]=TemplatePolicy::readFilter($this->db,$this->access,'p',true);
            $after=$cursor?' AND (p.published_at<? OR (p.published_at=? AND p.id COLLATE utf8mb4_bin>?))':'';
            $args=[...$policyParameters,...$renderers];if($cursor)array_push($args,$cursor['published_at'],$cursor['published_at'],$cursor['id']);
            $limit=$maximum===null?100:min(100,$maximum-$count);
            $rows=Db::run($this->db,'SELECT p.id,p.published_revision,p.published_at FROM khcms_pages p JOIN khcms_revisions r ON r.page_id=p.id AND r.revision=p.published_revision AND r.club_id=p.club_id WHERE ('.$policy.') AND '.$channelWhere.$filter.$after.' ORDER BY p.published_at DESC,p.id COLLATE utf8mb4_bin LIMIT '.$limit,$args)->fetchAll(PDO::FETCH_ASSOC);
            foreach($rows as$meta){
                if($this->access->require('website','read')['club_id']!==$club)throw new \DomainException('Öffentliche Inhalte nicht verfügbar.');
                [$currentPolicy,$currentParameters]=TemplatePolicy::readFilter($this->db,$this->access,'p',true);
                $json=Db::run($this->db,'SELECT r.payload_json FROM khcms_pages p JOIN khcms_revisions r ON r.page_id=p.id AND r.revision=p.published_revision AND r.club_id=p.club_id WHERE ('.$currentPolicy.') AND p.id=? AND p.published_revision=? AND '.$channelWhere.$filter,[...$currentParameters,$meta['id'],$meta['published_revision'],...$renderers])->fetchColumn();
                if($json===false)continue;
                $page=FieldPolicy::publicSource($this->db,$club,$this->decode($json))+['id'=>$meta['id'],'version'=>(int)$meta['published_revision'],'published_revision'=>(int)$meta['published_revision'],'published_version'=>(int)$meta['published_revision'],'published_at'=>$meta['published_at']];unset($json);
                ++$count;yield $page;unset($page);
            }
            if($rows)$cursor=$rows[array_key_last($rows)];
        }while(count($rows)===$limit&&($maximum===null||$count<$maximum));
    }

    /** Bind feed consistency to both the current club and its publication generation. */
    public function publicState():array
    {
        $club=$this->access->require('website','read')['club_id'];
        return ['club'=>$club,'generation'=>(int)(Db::run($this->db,'SELECT generation FROM khcms_state WHERE club_id=?',[$club])->fetchColumn()?:0)];
    }

    public function publicSearch(string $query,mixed $page=1,int $limit=PublicSearch::PAGE_SIZE,?string $locale=null):array
    {
        return (new PublicSearch($this->db,$this->access))->page($query,$page,$limit,$locale);
    }

    public function generation(): int
    {
        $this->access->require('website', 'read');
        return (int) (Db::run($this->db, 'SELECT generation FROM khcms_state WHERE club_id=?', [$this->access->club()])->fetchColumn() ?: 0);
    }

    /** Bounded typed groups/order over drafts; status predicates describe publication state. */
    public function search(array $selectors = [], int $limit = 50, int $offset = 0, array $order = []): array
    {
        $this->access->require('website', 'read');
        if ($limit < 1 || $limit > 500 || $offset < 0 || $offset > 1000000) {
            throw new InvalidArgumentException('Suchlimit 1–500 und Offset 0–1000000 erforderlich.');
        }
        $model = new DataModel($this->db, $this->access);
        [$where, $parameters] = (new Selectors($model))->compile($selectors);
        [$policy,$policyParameters]=TemplatePolicy::readFilter($this->db,$this->access);
        $queryOrder=new QueryOrder($model);[$ordering, $orderParameters] = $queryOrder->compile($order);
        $sql = 'SELECT p.*,a.archived_at FROM khcms_pages p LEFT JOIN khcms_archives a ON a.page_id=p.id AND a.club_id=p.club_id WHERE ('.$policy.') AND ' . PageTrash::activeSql() . ($where !== '' ? ' AND ' . $where : '') . ' ORDER BY '.$ordering.' LIMIT ' . $limit . ' OFFSET ' . $offset;
        $rows = QueryOrder::run($this->db, $sql, [...$policyParameters, ...$parameters, ...$orderParameters],$queryOrder->requiresTextBudget())->fetchAll(PDO::FETCH_ASSOC);
        return array_map(fn (array $row): array => $this->present($row), $rows);
    }

    /** Budget before PDO payload buffering; original selection/order/rights stay canonical. */
    public function searchBounded(array $selectors=[],int $limit=50,int $offset=0,array $order=[],int $maximumBytes=8388608):array
    {
        $this->access->require('website','read');
        if($limit<1||$limit>500||$offset<0||$offset>1000000||$maximumBytes<1||$maximumBytes>8388608)throw new InvalidArgumentException('Suchgrenze und Antwortbudget prüfen.');
        return Db::atomic($this->db,function()use($selectors,$limit,$offset,$order,$maximumBytes):array{
            $actor=$this->access->require('website','read');
            // Shared, read-only counterpart of the canonical publication/model mutex.
            Db::run($this->db,'SELECT generation FROM khcms_state WHERE club_id=? FOR SHARE',[$actor['club_id']])->fetchColumn();
            $compile=function()use($selectors,$order):array{
                $model=new DataModel($this->db,$this->access);[$where,$args]=(new Selectors($model))->compile($selectors);
                [$policy,$policyArgs]=TemplatePolicy::readFilter($this->db,$this->access);$queryOrder=new QueryOrder($model);[$ordering,$orderArgs]=$queryOrder->compile($order);
                return [$where,$args,$policy,$policyArgs,$ordering,$orderArgs,$queryOrder->requiresTextBudget()];
            };
            $plan=$compile();[$where,$args,$policy,$policyArgs,$ordering,$orderArgs,$textBudget]=$plan;
            $base=' FROM khcms_pages p LEFT JOIN khcms_archives a ON a.page_id=p.id AND a.club_id=p.club_id WHERE ('.$policy.') AND '.PageTrash::activeSql().($where!==''?' AND '.$where:'');
            $parameters=[...$policyArgs,...$args];
            $selection=QueryOrder::run($this->db,'SELECT p.id,p.version,OCTET_LENGTH(p.draft_json) AS draft_bytes'.$base.' ORDER BY '.$ordering.' LIMIT '.$limit.' OFFSET '.$offset,[...$parameters,...$orderArgs],$textBudget)->fetchAll(PDO::FETCH_ASSOC);
            if($compile()!==$plan)throw new Conflict('Suchrechte oder Inhaltsmodell wurden inzwischen geändert. Suche erneut öffnen.');
            $bytes=0;
            foreach($selection as $item){$size=(int)$item['draft_bytes'];if($size<0||$size>$maximumBytes-$bytes)throw new QuerySize();$bytes+=$size;}
            $result=[];$actualBytes=0;
            foreach($selection as $item){
                // One buffered original only. A version drift never substitutes a different row.
                $row=Db::run($this->db,'SELECT p.*,a.archived_at'.$base.' AND p.id=? AND p.version=? FOR SHARE',[...$parameters,$item['id'],(int)$item['version']])->fetch(PDO::FETCH_ASSOC);
                if(!$row)throw new Conflict('Ein ausgewähltes Original wurde inzwischen geändert. Suche erneut öffnen.');
                $size=strlen((string)$row['draft_json']);
                if($size!==(int)$item['draft_bytes'])throw new Conflict('Die ausgewählte Originalgröße wurde inzwischen geändert. Suche erneut öffnen.');
                if($size>$maximumBytes-$actualBytes)throw new QuerySize();$actualBytes+=$size;
                $result[]=$this->present($row);unset($row);
            }
            $this->access->require('website','read');if($compile()!==$plan)throw new Conflict('Suchrechte oder Inhaltsmodell wurden inzwischen geändert. Suche erneut öffnen.');return $result;
        });
    }

    public function count(array $selectors = []): int
    {
        $this->access->require('website', 'read');
        [$where, $parameters] = (new Selectors(new DataModel($this->db, $this->access)))->compile($selectors);
        [$policy,$policyParameters]=TemplatePolicy::readFilter($this->db,$this->access);
        return (int)Db::run($this->db,
            'SELECT COUNT(*) FROM khcms_pages p LEFT JOIN khcms_archives a ON a.page_id=p.id AND a.club_id=p.club_id WHERE ('.$policy.') AND '.PageTrash::activeSql().($where !== '' ? ' AND '.$where : ''),
            [...$policyParameters, ...$parameters])->fetchColumn();
    }

    public function children(?string $parentId = null): array
    {
        $this->access->require('website', 'read');
        if ($parentId !== null) {
            $this->row($parentId);
        }
        return $this->search([['field' => 'parent_id', 'op' => 'eq', 'value' => $parentId]], 500);
    }

    /** Entire draft tree, bounded to 500 nodes. References to pages are not hierarchy edges. */
    public function tree(): array
    {
        $this->access->require('website', 'read');
        [$policy,$parameters]=TemplatePolicy::readFilter($this->db,$this->access);
        $rows = Db::run($this->db, 'SELECT p.*,a.archived_at FROM khcms_pages p LEFT JOIN khcms_archives a ON a.page_id=p.id AND a.club_id=p.club_id WHERE ('.$policy.') AND ' . PageTrash::activeSql() . ' ORDER BY p.slug COLLATE utf8mb4_bin,p.id LIMIT 501', $parameters)->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) > 500) {
            throw new InvalidArgumentException('Baumansicht auf 500 Seiten begrenzt; children()/search() verwenden.');
        }
        $groups = [];
        foreach ($rows as $row) {
            $page = $this->present($row);
            $groups[$page['parent_id'] ?? ''][] = $page;
        }
        $walk = function (string $parent, int $depth = 0) use (&$walk, $groups): array {
            if ($depth > 100) {
                throw new RuntimeException('Ungültige Seitentiefe.');
            }
            $result = [];
            foreach ($groups[$parent] ?? [] as $page) {
                $page['children'] = $walk($page['id'], $depth + 1);
                $result[] = $page;
            }
            return $result;
        };
        return $walk('');
    }

    private function row(string $id, bool $lock = false, bool $includeTrash = false): array
    {
        $row = Db::run($this->db, 'SELECT p.*,a.archived_at,(SELECT t.trashed_at FROM khcms_trash t WHERE t.page_id=p.id AND t.club_id=p.club_id) AS trashed_at FROM khcms_pages p LEFT JOIN khcms_archives a ON a.page_id=p.id AND a.club_id=p.club_id WHERE p.id=? AND p.club_id=?' . ($lock ? ' FOR UPDATE' : ''), [$id, $this->access->club()])->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException('Seite nicht gefunden.');
        }
        if (!$includeTrash && $row['trashed_at'] !== null) throw new Conflict('Seite zuerst aus dem Papierkorb zurückholen.');
        return $row;
    }

    private function expect(array $row, int $version): void
    {
        if ($version < 1 || (int) $row['version'] !== $version) {
            throw new Conflict('Die Seite wurde inzwischen geändert. Aktuellen Stand neu laden.');
        }
    }

    private function present(array $row): array
    {
        return FieldPolicy::internalSource($this->db,$this->access,$this->decode($row['draft_json'])) + [
            'id' => $row['id'], 'version' => (int) $row['version'],
            'trashed' => ($row['trashed_at'] ?? null) !== null, 'trashed_at' => $row['trashed_at'] ?? null,
            'archived' => ($row['archived_at'] ?? null) !== null, 'archived_at' => $row['archived_at'] ?? null,
            'published_revision' => $row['published_revision'] === null ? null : (int) $row['published_revision'],
            'published_version' => $row['published_revision'] === null ? null : (int) $row['published_revision'],
            'published_slug' => $row['published_slug'], 'published_at' => $row['published_at'],
            'created_at' => $row['created_at'], 'updated_at' => $row['updated_at'],
            'has_unpublished_changes' => $row['published_revision'] === null || (int) $row['version'] !== (int) $row['published_revision'],
        ];
    }

    private function validateReferences(string $id, array $payload): void
    {
        (new \Kulturhus\Native\Language\Settings($this->db,$this->access))->assertSourcePath($payload['slug'],$id);
        PathHistory::assertAvailable($this->db, $this->access->club(), $id, $payload['slug']);
        $visited = [$id => true];
        $parent = $payload['parent_id'];
        while ($parent !== null) {
            if (isset($visited[$parent]) || count($visited) > 100) {
                throw new InvalidArgumentException('Eine Seitenhierarchie darf keinen Kreis bilden oder mehr als 100 Ebenen enthalten.');
            }
            $visited[$parent] = true;
            $parentRow = $this->row($parent);
            $parent = $this->decode($parentRow['draft_json'])['parent_id'];
        }
        (new PageReferences($this->db, $this->access))->assertAvailable($id, $payload);
        MediaAvailability::assertContentSelectable($this->db,$this->access->club(),$payload);
        if (array_key_exists('document_links',$payload) && WebsiteDocuments::capture($this->db,$this->access->club(),$payload['document_links'])!==$payload['document_links']) throw new Conflict('Dokumentenoriginal wurde verändert. Bitte erneut prüfen.');
        foreach ($this->mediaIds($payload) as $mediaId) {
            if (!Db::run($this->db, 'SELECT id FROM khcms_media WHERE id=? AND club_id=?', [$mediaId, $this->access->club()])->fetchColumn()) {
                throw new InvalidArgumentException('Galerie-Bild ist in diesem Verein nicht verfügbar.');
            }
        }
    }

    private function validatePublishedParent(string $id, ?string $parent): void
    {
        $visited = [$id => true];
        while ($parent !== null) {
            if (isset($visited[$parent]) || count($visited) > 100) {
                throw new Conflict('Die veröffentlichten Seiten würden einen Kreis bilden.');
            }
            $visited[$parent] = true;
            $row = Db::run($this->db, 'SELECT r.payload_json FROM khcms_pages p JOIN khcms_revisions r ON r.page_id=p.id AND r.revision=p.published_revision AND r.club_id=p.club_id WHERE p.id=? AND p.club_id=?', [$parent, $this->access->club()])->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                throw new Conflict('Die übergeordnete Seite muss zuerst veröffentlicht werden.');
            }
            $parent = $this->decode($row['payload_json'])['parent_id'];
        }
    }

    private function publishedRows(): array
    {
        return Db::run($this->db, 'SELECT p.id,r.payload_json FROM khcms_pages p JOIN khcms_revisions r ON r.page_id=p.id AND r.revision=p.published_revision AND r.club_id=p.club_id WHERE p.club_id=?', [$this->access->club()])->fetchAll(PDO::FETCH_ASSOC);
    }

    private function mediaIds(array $payload, bool $public = false): array
    {
        $ids = [...$payload['media_ids'], ...ContentSections::mediaIds($payload)];
        $ids = [...$ids, ...FieldValues::references($payload, 'media', $public)];
        return array_values(array_unique($ids));
    }

    private function invalidate(): void
    {
        Db::run($this->db, 'UPDATE khcms_state SET generation=generation+1 WHERE club_id=?', [$this->access->club()]);
    }

    private function revision(string $id, int $version, array $payload, string $actor, string $action): void
    {
        Db::run($this->db, 'INSERT INTO khcms_revisions (page_id,revision,club_id,payload_json,actor_id,action,created_at) VALUES (?,?,?,?,?,?,UTC_TIMESTAMP(6))', [$id, $version, $this->access->club(), $this->json($payload), $actor, $action]);
    }

    private function decode(string $json): array
    {
        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    private function json(array $payload): string
    {
        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
