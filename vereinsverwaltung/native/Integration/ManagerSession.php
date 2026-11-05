<?php declare(strict_types=1);
namespace Kulturhus\Native\Integration;
/** Shared PHP session for native account routes and the existing manager controller. */
final class ManagerSession
{
    public static function start(): void
    {
        if(session_status()===PHP_SESSION_ACTIVE)return;
        $directory=getenv('KH_NATIVE_MANAGER_SESSION_ROOT');
        if(!$directory||!is_dir($directory)||is_link($directory))throw new \RuntimeException('Privater Manager-Sitzungsspeicher fehlt.');
        session_module_name('files');session_name(\Kulturhus\Native\Demo\Runtime::active()?'DVM_DEMO_'.substr(\Kulturhus\Native\Demo\Runtime::instance(),0,12):'KH_NATIVE_MANAGER');session_save_path($directory);
        ini_set('session.gc_maxlifetime',(string)\Kulturhus\Native\Auth\Sessions::MAX_SECONDS);
        session_start(['cookie_lifetime'=>\Kulturhus\Native\Auth\Sessions::MAX_SECONDS,'use_strict_mode'=>1,'use_only_cookies'=>1,'cookie_httponly'=>1,'cookie_secure'=>getenv('VEREIN_MODE')==='production','cookie_samesite'=>'Strict','cookie_path'=>\Kulturhus\Native\Demo\Runtime::active()?'/demo':'/']);
        $_SESSION['csrf']??=bin2hex(random_bytes(32));
    }
}
