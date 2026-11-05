<?php declare(strict_types=1);
namespace Kulturhus\Verein\DocumentWorkflow;
require_once __DIR__.'/WorkflowAccess.php';
/** The authenticated host supplies current eligible accounts of this club, including members without module rights. */
interface WorkflowSignatureAccess extends WorkflowAccess
{
    /** @return list<array{id:string,name:string}> */
    public function signatureMembers(): array;
}
