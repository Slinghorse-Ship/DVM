<?php declare(strict_types=1);
namespace Kulturhus\Native\Shared;

/** One deployment lock shared by requests/workers; recovery owns it exclusively. */
final class OperationGate
{
    private function __construct(private $handle) {}
    public static function shared(string $private): self { return self::acquire($private, LOCK_SH); }
    public static function exclusive(string $private): self { return self::acquire($private, LOCK_EX); }

    private static function acquire(string $private, int $mode): self
    {
        $root=realpath($private);
        if (!$root || !is_dir($root) || is_link($private) || (fileperms($root)&0077)) throw new \DomainException('Privates Betriebsverzeichnis prüfen.');
        $file=$root.'/operations.lock';
        if (is_link($file) || (file_exists($file) && (!is_file($file) || (fileperms($file)&0077)))) throw new \DomainException('Betriebssperre muss privat gespeichert sein.');
        $old=umask(0077);
        try { $handle=fopen($file,'c+'); } finally { umask($old); }
        if (!$handle) throw new \DomainException('Betriebssperre nicht verfügbar.');
        if (!flock($handle,$mode|LOCK_NB)) {
            fclose($handle);
            throw new \DomainException('Wartung oder ein laufender Zugriff verhindert diesen Vorgang. Bitte später erneut versuchen.');
        }
        if ($mode===LOCK_SH && (file_exists($root.'/upgrade-hold') || is_link($root.'/upgrade-hold') || file_exists($root.'/restore-hold') || is_link($root.'/restore-hold'))) {
            flock($handle,LOCK_UN); fclose($handle);
            throw new \DomainException('Begonnene Wartung zuerst fortsetzen oder zurücksetzen.');
        }
        return new self($handle);
    }
    public function close(): void
    {
        if (is_resource($this->handle)) { flock($this->handle,LOCK_UN); fclose($this->handle); }
        $this->handle=null;
    }
    public function __destruct() { $this->close(); }
}
