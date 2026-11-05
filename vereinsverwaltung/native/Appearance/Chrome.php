<?php declare(strict_types=1);
namespace Kulturhus\Native\Appearance;

/** Fixed authenticated administration chrome; no public page or business original uses it. */
final class Chrome
{
    public static function attributes(\PDO $db,\Kulturhus\Native\Shared\Access $access):string
    {return Values::attributes((new Preferences($db,$access))->state()['data']);}
    public static function assets():string
    {
        $version=substr(hash_file('sha256',dirname(__DIR__,2).'/public/admin-appearance.css'),0,16);
        return '<link rel="stylesheet" href="/assets/admin-appearance.css?v='.$version.'"><script defer src="/assets/admin-appearance.js"></script>';
    }
}
