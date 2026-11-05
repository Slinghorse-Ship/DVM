<?php declare(strict_types=1);
namespace Kulturhus\Native\Cms;

use PDO;
use Kulturhus\Native\Shared\{Access, Db};

/** Explicit website appearance changes never rewrite content or approval originals. */
final class WebsiteDesign
{
    use ClubPublicationLock;
    public function __construct(private PDO $db, private Access $access) {}

    /** Deployment configuration decides; query parameters and forwarded hosts never do. */
    public static function kulturhusAllowed():bool
    {
        $mode=getenv('VEREIN_MODE');
        if($mode!=='production')return in_array($mode,['synthetic','development','test'],true)||getenv('KH_NATIVE_CI')==='1';
        $origin=(string)getenv('VEREIN_PUBLIC_URL');$host=parse_url($origin,PHP_URL_HOST);
        if(!is_string($host)||!in_array(strtolower($host),['kulturhus.de','www.kulturhus.de'],true)||parse_url($origin,PHP_URL_SCHEME)!=='https')return false;
        if(isset($_SERVER['HTTP_HOST'])&&(!is_string($_SERVER['HTTP_HOST'])||!in_array(strtolower($_SERVER['HTTP_HOST']),['kulturhus.de','www.kulturhus.de'],true)))return false;
        return true;
    }

    public static function templates(): array
    {
        $templates = [
            'klar'=>['name'=>'Klar','description'=>'Viel Weißraum, ruhige Typografie und großzügige Karten. Für einen übersichtlichen Vereinsauftritt.','class'=>'design-klar'],
            'magazin'=>['name'=>'Magazin','description'=>'Große Schlagzeilen, redaktionelle Linien und ein markanter zweispaltiger Einstieg. Für Kultur und Geschichten.','class'=>'design-magazin'],
            'aktiv'=>['name'=>'Aktiv','description'=>'Kräftiges Blau, klare Kontraste und ein dynamischer Einstieg. Für Sport, Teams und Veranstaltungen.','class'=>'design-aktiv'],
            'natur'=>['name'=>'Natur','description'=>'Sanfte Grüntöne, organische Formen und ein zentrierter Einstieg. Für Gemeinschaft und Engagement.','class'=>'design-natur'],
            'kulturhus'=>['name'=>'Kulturhus','description'=>'Das einmalig übernommene Kulturhus-Design mit Plakattypografie, warmen Farben und Vereinsmotiv.','class'=>'theme-kultur'],
        ];
        if(!self::kulturhusAllowed())unset($templates['kulturhus']);return $templates;
    }

    public static function template(mixed $key): array
    {
        if (!is_string($key) || !isset(self::templates()[$key])) throw new \DomainException('Eine verfügbare Website-Vorlage auswählen.');
        return self::templates()[$key];
    }

    public static function install(PDO $db): void
    {
        foreach ([
            'CREATE TABLE IF NOT EXISTS khcms_website_design (club_id VARCHAR(64) PRIMARY KEY, version INT UNSIGNED NOT NULL, template_key VARCHAR(32) NOT NULL, actor_id VARCHAR(64) NOT NULL, updated_at DATETIME(6) NOT NULL)',
            'CREATE TABLE IF NOT EXISTS khcms_website_design_history (club_id VARCHAR(64) NOT NULL, version INT UNSIGNED NOT NULL, template_key VARCHAR(32) NOT NULL, actor_id VARCHAR(64) NOT NULL, created_at DATETIME(6) NOT NULL, PRIMARY KEY(club_id,version))',
        ] as $sql) $db->exec($sql.' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }

    public static function forClub(PDO $db, string $club): array
    {
        $row=Db::run($db,'SELECT version,template_key,actor_id,updated_at FROM khcms_website_design WHERE club_id=?',[$club])->fetch();
        // Preserve the existing native website until its club explicitly chooses a design.
        $state=$row ? ['version'=>(int)$row['version'],'template'=>$row['template_key'],'actor_id'=>$row['actor_id'],'updated_at'=>$row['updated_at']] : ['version'=>0,'template'=>'kulturhus','actor_id'=>null,'updated_at'=>null];
        if($state['template']==='kulturhus'&&!self::kulturhusAllowed())$state['template']='klar';return $state;
    }

    public function get(): array
    {
        $this->access->require('website','read');
        return self::forClub($this->db,$this->access->club());
    }

    public function useTemplate(mixed $key, int $version): array
    {
        self::template($key);
        $this->access->require('website','manage');
        $this->access->require('website','publish');
        if ($version<0 || $version>=4294967295) throw new \DomainException('Versionsstand der Website-Vorlage prüfen.');
        return Db::atomic($this->db,function()use($key,$version):array {
            $actor=$this->access->require('website','manage');$this->access->require('website','publish');$this->lockClub();
            $club=$actor['club_id'];$old=self::forClub($this->db,$club);
            if($old['version']!==$version) throw new Conflict('Die Website-Vorlage wurde inzwischen geändert. Bitte neu laden und die Auswahl prüfen.');
            if($old['template']===$key) return $old;
            Db::run($this->db,'INSERT INTO khcms_website_design VALUES(?,?,?,?,UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE version=VALUES(version),template_key=VALUES(template_key),actor_id=VALUES(actor_id),updated_at=VALUES(updated_at)',[$club,$version+1,$key,$actor['id']]);
            Db::run($this->db,'INSERT INTO khcms_website_design_history VALUES(?,?,?,?,UTC_TIMESTAMP(6))',[$club,$version+1,$key,$actor['id']]);
            Db::run($this->db,'UPDATE khcms_state SET generation=generation+1 WHERE club_id=?',[$club]);
            return self::forClub($this->db,$club);
        });
    }

    public function history(): array
    {
        $this->access->require('website','manage');
        return Db::run($this->db,'SELECT version,template_key,created_at FROM khcms_website_design_history WHERE club_id=? ORDER BY version DESC LIMIT 20',[$this->access->club()])->fetchAll();
    }
}
