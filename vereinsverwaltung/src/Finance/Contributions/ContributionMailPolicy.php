<?php declare(strict_types=1);
namespace Kulturhus\Verein\Finance\Contributions;
use PDO;
use DomainException;
use Kulturhus\Verein\{Permissions,DocumentCatalog};
use Kulturhus\Native\Shared\Db;

/** Annual mail automation requires an explicit, current financial grant. */
final class ContributionMailPolicy
{
    private const SECTION='contribution_delivery';
    public function __construct(private PDO $db,private string $actor){}
    public function state():array
    {
        $a=Permissions::finance($this->db,$this->actor);$row=DocumentCatalog::query($this->db,'SELECT payload,version FROM club_settings WHERE club_id=? AND section=?',[$a['club_id'],self::SECTION])->fetch();$data=$row?json_decode($row['payload'],true,16,JSON_THROW_ON_ERROR):[];
        return ['version'=>(int)($row['version']??0),'automatic'=>($data['mode']??'manual')==='automatic','approved_by'=>$data['approved_by']??null,'can_write'=>$a['finance_access']==='write'];
    }
    public function automaticAllowed():bool
    {
        $a=Permissions::finance($this->db,$this->actor);$state=$this->state();if($a['finance_access']!=='write'||!$state['automatic']||!is_string($state['approved_by']))return false;
        try{$author=Permissions::finance($this->db,$state['approved_by'],true);return $author['club_id']===$a['club_id'];}catch(DomainException){return false;}
    }
    public function save(bool $automatic,int $version):void
    {
        Db::atomic($this->db,function()use($automatic,$version):void{$a=Permissions::finance($this->db,$this->actor,true);if(!Db::run($this->db,'SELECT id FROM clubs WHERE id=? FOR UPDATE NOWAIT',[$a['club_id']])->fetchColumn())throw new DomainException('Verein nicht verfügbar.',403);$state=$this->state();if($state['version']!==$version)throw new DomainException('Versandeinstellung wurde geändert. Bitte neu laden.',409);if($state['automatic']===$automatic&&$state['version']>0)return;$data=['mode'=>$automatic?'automatic':'manual','approved_by'=>$a['id'],'approved_at'=>gmdate(DATE_ATOM)];
        DocumentCatalog::query($this->db,'INSERT INTO club_settings VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE payload=VALUES(payload),version=VALUES(version),updated_at=VALUES(updated_at)',[$a['club_id'],self::SECTION,json_encode($data,JSON_THROW_ON_ERROR),$version+1,gmdate('Y-m-d\TH:i:s\Z')]);
        DocumentCatalog::query($this->db,'INSERT INTO audit_events(club_id,actor_id,action,object_id,object_version,happened_at) VALUES(?,?,?,?,?,?)',[$a['club_id'],$a['id'],'contribution.mail.policy',self::SECTION,$version+1,gmdate(DATE_ATOM)]);});
    }
}
