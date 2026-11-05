<?php declare(strict_types=1);
namespace Kulturhus\Verein\DocumentWorkflow;

final class WorkflowException extends \DomainException
{
    private bool $originalConflict=false;
    private bool $creationUnconfirmed=false;
    public static function conflict(string $message):self
    {
        $failure=new self($message);$failure->originalConflict=true;return $failure;
    }
    public static function unconfirmedCreation(string $message,\Throwable $previous):self
    {
        $failure=new self($message,0,$previous);$failure->creationUnconfirmed=true;return $failure;
    }
    public function isUnconfirmedCreation():bool{return $this->creationUnconfirmed;}
    public function isConflict():bool{return $this->originalConflict;}
}
