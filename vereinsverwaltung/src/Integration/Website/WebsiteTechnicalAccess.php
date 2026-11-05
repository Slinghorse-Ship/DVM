<?php declare(strict_types=1);
namespace Kulturhus\Verein;
require_once dirname(__DIR__,2).'/Infrastructure/Runtime/ProductionRuntime.php';

/** Current DVM technical rights; no external CMS owner or permission boundary. */
final class WebsiteTechnicalAccess
{
    public static function allowed(\PDO $db,string $actor):bool
    {
        if(ProductionRuntime::enabled()&&!ProductionRuntime::nativeManager())return false;
        try{
            $current=Permissions::actor($db,$actor);
            return $current['role']!=='editor'&&!empty($current['is_superuser']);
        }catch(\DomainException){return false;}
    }
}
