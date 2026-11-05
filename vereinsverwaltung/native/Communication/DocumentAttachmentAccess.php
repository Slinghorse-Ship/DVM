<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication;
use PDO;
use Kulturhus\Native\Shared\Access;
/** Internal adapter for already-scoped newsletter PDFs; no website publication or management rights. */
final class DocumentAttachmentAccess extends Access
{
    public function __construct(PDO $db,private Access $source){parent::__construct($db,$source->user()['id']);}
    public function user():array{return$this->source->user();}
    public function require(string $module,string $capability):array
    {
        if($module!=='website'||!in_array($capability,['read','edit'],true))throw new \DomainException('Nur eigene Newsletteranlagen verwenden.');
        return$this->source->require('newsletter',$capability);
    }
}
