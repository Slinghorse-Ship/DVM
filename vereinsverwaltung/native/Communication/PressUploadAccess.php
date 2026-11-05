<?php declare(strict_types=1);
namespace Kulturhus\Native\Communication;
use PDO;
use Kulturhus\Native\Shared\Access;

/** Internal new-photo upload adapter; never a general website or media browser. */
final class PressUploadAccess extends Access
{
    public function __construct(PDO $db,private Access $source){parent::__construct($db,$source->user()['id']);}
    public function user():array{return $this->source->require('press','edit');}
    public function require(string $module,string $capability):array
    {
        if($module!=='website'||!in_array($capability,['read','edit'],true))throw new \DomainException('Neue Pressefotos nur im Presseformular hinzufügen.');
        return $this->source->require('press','edit');
    }
}
