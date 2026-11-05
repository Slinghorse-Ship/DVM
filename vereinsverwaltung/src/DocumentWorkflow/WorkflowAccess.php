<?php
declare(strict_types=1);

namespace Kulturhus\Verein\DocumentWorkflow;

/** The host must resolve current membership and module permissions on every call. */
interface WorkflowAccess
{
    /** @return array{id:string,club_id:string,access:'read'|'write'|'none'} */
    public function actor(): array;

    /** Current, admitted actors of the actor's club only. @return list<array{id:string,name:string,access:string}> */
    public function participants(): array;
}
