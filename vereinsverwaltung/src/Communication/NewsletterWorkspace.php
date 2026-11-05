<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use PDO;
require_once __DIR__.'/EditorialModuleWorkspace.php';
final class NewsletterWorkspace extends EditorialModuleWorkspace
{public function __construct(PDO $db,string $actor){parent::__construct($db,$actor,'newsletter');}}
