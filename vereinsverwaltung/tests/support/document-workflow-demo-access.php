<?php declare(strict_types=1);
require_once __DIR__.'/../../src/DocumentWorkflow/WorkflowSignatureAccess.php';
/** Synthetic identity provider used only by the gated local demo and tests. */
final class DocumentWorkflowDemoAccess implements \Kulturhus\Verein\DocumentWorkflow\WorkflowSignatureAccess
{
    public const PEOPLE=[
        ['id'=>'wf-author','name'=>'Demo · Bearbeitung','access'=>'write'],
        ['id'=>'wf-assignee','name'=>'Demo · Beauftragte Person','access'=>'write'],
        ['id'=>'wf-reviewer','name'=>'Demo · Prüfung','access'=>'write'],
        ['id'=>'wf-reader','name'=>'Demo · Lesezugang','access'=>'read'],
        ['id'=>'wf-member','name'=>'Mara Muster · Mitglied (Testdaten)','access'=>'none'],
    ];
    public function __construct(public string $id='wf-author',public string $club='workflow-demo') {}
    public function actor(): array
    {
        foreach(self::PEOPLE as $person) if($person['id']===$this->id) return $person+['club_id'=>$this->club];
        throw new DomainException('Demo-Zugang nicht verfügbar.');
    }
    public function participants(): array { return self::PEOPLE; }
    public function signatureMembers(): array { return array_map(static fn(array $p):array=>['id'=>$p['id'],'name'=>$p['name']],self::PEOPLE); }
}
