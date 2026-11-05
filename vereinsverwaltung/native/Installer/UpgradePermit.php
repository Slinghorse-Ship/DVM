<?php declare(strict_types=1);
namespace Kulturhus\Native\Installer;

/** Uploader's private upgrade key; never a fresh-install or general schema permission. */
final class UpgradePermit
{
    private function __construct(private string $root,private string $package,private string $proof,private string $tokenHash) {}

    public static function authenticate(string $root,string $package,#[\SensitiveParameter] string $token): self
    {
        $root=realpath($root)?:''; $package=realpath($package)?:'';
        if ($root==='' || $package!==$root.'/private/upgrade-package' || !preg_match('/\A[a-f0-9]{64}\z/D',$token)) throw new \DomainException('Privaten Upgradeschlüssel und Paketpfad prüfen.');
        UpgradeFiles::privateFile($package.'/private/upgrade-token');
        UpgradeFiles::privateFile($root.'/installation.json');
        $hash=trim((string)file_get_contents($package.'/private/upgrade-token'));
        if (!hash_equals($hash,hash('sha256',$token))) throw new \DomainException('Upgradeschlüssel ungültig.');
        UpgradeFiles::proof($package.'/private/app-files.json');
        return new self($root,$package,hash_file('sha256',$package.'/private/app-files.json'),$hash);
    }

    public function assertActive(string $root): void
    {
        UpgradeFiles::privateFile($this->package.'/private/upgrade-token');
        if (realpath($root)!==$this->root || !hash_equals($this->tokenHash,trim((string)file_get_contents($this->package.'/private/upgrade-token'))) || !hash_equals($this->proof,hash_file('sha256',$this->package.'/private/app-files.json'))) throw new \DomainException('Upgrade gehört zu einer anderen Paketfassung oder Installation.');
    }

    public function assertMaintenance(string $root,\PDO $db): void
    {
        $this->assertActive($root);
        $state=Upgrade::readState($root);
        $environment=\PackageStore::json($root.'/private/environment.json');
        Upgrade::connection($db,$environment);
        $hold=$root.'/private/upgrade-hold'; UpgradeFiles::privateFile($hold);
        if (!hash_equals($state['intent'],trim((string)file_get_contents($hold))) || $state['package']!==$this->proof || !in_array($state['phase'],['schema','rollback-db','rollback-finish'],true)) throw new \DomainException('Keine eigene Upgrade-Datenbankwartung freigegeben.');
        $query=$db->query('SELECT intent FROM native_package_operation WHERE id=1');
        if (!hash_equals($state['intent'],(string)$query->fetchColumn())) throw new \DomainException('Upgrade-Datenbank gehört zu einem anderen Vorgang.');
    }
}
