<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use PDO;
use DomainException;
use Kulturhus\Verein\DocumentWorkflow\{WorkflowStore,WorkflowSignatures};
require_once dirname(__DIR__, 1).'/Auth/ManagerWorkflowAccess.php';
require_once dirname(__DIR__, 1).'/DocumentWorkflow/WorkflowSignatures.php';
require_once __DIR__.'/DocumentWorkflowPage.php';
require_once __DIR__.'/InspectionPage.php';

/** Host integration only. Installing the optional schema is always a separate CLI action. */
final class ManagerDocumentWorkflows
{
    public readonly WorkflowStore $store;
    public readonly WorkflowSignatures $signatures;
    public readonly \Kulturhus\Verein\DocumentWorkflow\InspectionStore $inspections;
    private ManagerWorkflowAccess $access;
    public function __construct(private PDO $db,private string $actor,?callable $guard=null)
    {
        $this->access=new ManagerWorkflowAccess($db,$actor,$guard);
        $this->store=new WorkflowStore($db,$this->access);
        $this->signatures=new WorkflowSignatures($db,$this->access);
        $this->inspections=new \Kulturhus\Verein\DocumentWorkflow\InspectionStore($db,$this->access);
    }
    public static function installed(PDO $db):bool
    {
        $q=$db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('dwf_folders','dwf_templates','dwf_template_versions','dwf_documents','dwf_document_versions','dwf_activities','dwf_attachments','dwf_signatures','dwf_signature_files','dwf_reminders')");
        return (int)$q->fetchColumn()===10;
    }
    public function allows(bool $personal=false):bool
    {
        $a=$this->access->actor();
        return $personal?in_array($a['id'],array_column($this->signatures->members(),'id'),true):in_array($a['access'],['read','write'],true);
    }
    /** Read-through catalogue. Metadata and links only; no copies or PDF rendering. */
    public function library():array
    {
        if(!$this->allows())throw new DomainException('Keine Berechtigung für die HSE-Dokumentenablage.');
        $actor=$this->access->actor();
        $q=$this->db->prepare('SELECT id FROM dwf_documents WHERE club_id=? ORDER BY updated_at DESC,id');$q->execute([$actor['club_id']]);
        $ids=$q->fetchAll(PDO::FETCH_COLUMN);$rows=[];
        $statuses=['draft'=>'Entwurf','submitted'=>'In Prüfung','returned'=>'Zur Überarbeitung','approved'=>'Freigegeben'];
        foreach($ids as $id){
            // The source service rechecks the current account, club and module rights.
            $document=$this->store->librarySummary($id);$revision=(int)$document['version'];
            $route=['view'=>'documents','tab'=>'workflows','wf_doc'=>$id,'wf_revision'=>$revision];
            $base=['folder_id'=>'','path'=>'/HSE / Gefährdungsbeurteilungen','state'=>'active','revision'=>$revision,'version'=>$revision,
                'document_date'=>substr($document['updated_at'],0,10),'created_at'=>$document['updated_at'],'origin'=>'workflow',
                'links'=>[['label'=>$document['title'].' · '.($statuses[$document['status']]??$document['status'])]],'references'=>[],
                'route'=>$route,'folder_route'=>null,'generated_output'=>true];
            $rows[]=array_replace($base,['id'=>'assessment:'.$id.':'.$revision,'title'=>$document['title'],'name'=>'PDF der Fassung '.$revision,
                'kind'=>'assessment','size'=>null,'file_route'=>['wf_export'=>$id,'wf_revision'=>$revision,'wf_format'=>'pdf']]);
            foreach($document['attachments'] as $file)$rows[]=array_replace($base,[
                'id'=>'assessment-attachment:'.$file['id'],'title'=>$file['name'],'name'=>$file['name'],'kind'=>'assessment-attachment',
                'size'=>$file['size_bytes'],'created_at'=>$file['created_at'],'generated_output'=>false,
                'file_route'=>['wf_doc'=>$id,'wf_attachment'=>$file['id'],'wf_revision'=>$revision]
            ]);
        }
        return $rows;
    }
    /** Archive only the authenticated signer's exact PDF; never all signatures in a member file. */
    public function archive(string $id):void
    {
        $request=$this->signatures->ownRequest($id);
        if(!$request['signed_at'])throw new DomainException('Zuerst unterschreiben.');
        if($this->fileExists($id))return;
        $file=$this->signatures->ownPdf($id);
        // Revalidate after rendering (permissions and evidence may have changed).
        $current=$this->signatures->ownRequest($id);
        if($current['signed_at']!==$request['signed_at']||!hash_equals($current['document_sha256'],$request['document_sha256']))throw new DomainException('Unterschriftennachweis erneut prüfen.');
        $q=$this->db->prepare('INSERT INTO dwf_signature_files(signature_id,name,file_bytes,sha256,created_at) VALUES(?,?,?,?,UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE signature_id=signature_id');
        $q->execute([$id,$file['name'],$file['bytes'],hash('sha256',$file['bytes'])]);
    }
    private function fileExists(string $id):bool
    {
        $q=$this->db->prepare('SELECT sha256,file_bytes FROM dwf_signature_files WHERE signature_id=?');$q->execute([$id]);$r=$q->fetch(PDO::FETCH_ASSOC);
        if(!$r)return false;
        if(!hash_equals($r['sha256'],hash('sha256',$r['file_bytes'])))throw new DomainException('Gespeichertes PDF bitte prüfen lassen.');
        return true;
    }
    /** Metadata only; the actual private download verifies the archived bytes again. */
    public function hasOwnFile(string $id):bool
    {
        $request=$this->signatures->ownRequest($id);if(!$request['signed_at'])return false;
        $q=$this->db->prepare('SELECT 1 FROM dwf_signature_files WHERE signature_id=?');$q->execute([$id]);return $q->fetchColumn()!==false;
    }
    public function ownFiles():array
    {
        if(!$this->allows(true))throw new DomainException('Eigene Dokumente nicht verfügbar.');
        $a=$this->access->actor();$q=$this->db->prepare('SELECT s.id,s.revision,s.signed_at,v.snapshot_json,f.signature_id archived FROM dwf_signatures s JOIN dwf_document_versions v ON v.club_id=s.club_id AND v.document_id=s.document_id AND v.revision=s.revision LEFT JOIN dwf_signature_files f ON f.signature_id=s.id WHERE s.club_id=? AND s.recipient_id=? AND s.signed_at IS NOT NULL ORDER BY s.signed_at DESC,s.id');$q->execute([$a['club_id'],$a['id']]);
        $rows=$q->fetchAll(PDO::FETCH_ASSOC);foreach($rows as &$r){$r['title']=json_decode($r['snapshot_json'],true,64,JSON_THROW_ON_ERROR)['title'];unset($r['snapshot_json']);}unset($r);return $rows;
    }
    public function ownFilesPage(mixed $page=1):array
    {
        if(!$this->allows(true))throw new DomainException('Eigene Dokumente nicht verfügbar.');
        $a=$this->access->actor();$from=' FROM dwf_signatures s JOIN dwf_document_versions v ON v.club_id=s.club_id AND v.document_id=s.document_id AND v.revision=s.revision LEFT JOIN dwf_signature_files f ON f.signature_id=s.id WHERE s.club_id=? AND s.recipient_id=? AND s.signed_at IS NOT NULL';
        return \Kulturhus\Verein\DocumentWorkflow\WorkflowList::query($this->db,"SELECT s.id,s.revision,s.signed_at,JSON_UNQUOTE(JSON_EXTRACT(v.snapshot_json,'$.title')) title,f.signature_id archived".$from.' ORDER BY s.signed_at DESC,s.id DESC','SELECT COUNT(*)'.$from,[$a['club_id'],$a['id']],$page);
    }
    public function ownFile(string $id):array
    {
        $request=$this->signatures->ownRequest($id);
        if(!$request['signed_at'])throw new DomainException('Unterschriebenes PDF nicht verfügbar.');
        $this->signatures->evidence($request['document_id'],(int)$request['revision'],$this->actor);
        if(!$this->fileExists($id))throw new DomainException('PDF noch nicht abgelegt. Bitte bei der Unterschrift erneut ablegen.');
        $q=$this->db->prepare('SELECT name,file_bytes FROM dwf_signature_files WHERE signature_id=?');$q->execute([$id]);$f=$q->fetch(PDO::FETCH_ASSOC);
        return ['name'=>$f['name'],'mime'=>'application/pdf','bytes'=>$f['file_bytes']];
    }
}
