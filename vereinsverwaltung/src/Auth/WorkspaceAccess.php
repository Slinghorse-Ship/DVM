<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use PDO;
use DomainException;
require_once dirname(__DIR__, 1).'/Communication/EditorialModuleWorkspace.php';

/** Shared visibility policy for navigation and direct workspace routes. Services recheck writes. */
final class WorkspaceAccess
{
    public function __construct(private PDO $db,private string $actorId){}
    public function allows(array $route):bool
    {
        try{
            $a=Permissions::actor($this->db,$this->actorId);$view=$route['view']??'dashboard';$tab=$route['tab']??'';
            if($view==='documents'&&$tab==='inspections'){
                return \Kulturhus\Verein\DocumentWorkflow\InspectionStore::installed($this->db)&&(new ManagerDocumentWorkflows($this->db,$this->actorId))->inspections->allows();
            }
            if(($view==='documents'&&$tab==='workflows')||($view==='portal'&&$tab==='signatures')){
                require_once dirname(__DIR__, 1).'/Documents/ManagerDocumentWorkflows.php';
                return ManagerDocumentWorkflows::installed($this->db)&&(new ManagerDocumentWorkflows($this->db,$this->actorId))->allows($view==='portal');
            }
            if($view==='settings'&&$tab==='account')return $a['role']!=='editor';
            if($view==='settings'&&$tab==='api')return true;
            if($view==='settings'&&$tab==='system')return getenv('KH_NATIVE_MANAGER')==='1'&&\Kulturhus\Native\System\Navigation::manager($this->db,$this->actorId);
            if($view==='settings'&&$tab==='languages')return $this->editorial('website','pages');
            // The front controller redirects the unqualified portal route to the own profile.
            if($view==='portal'&&$tab==='')$tab='own';
            if($view==='portal'&&$tab==='vouchers')return (new EventVouchers($this->db,$this->actorId))->canRead();
            if($view==='settings'&&$tab==='member-cards')return Database::tableExists($this->db,'member_card_wallet_accounts')&&$a['role']!=='editor'&&!empty($a['is_superuser']);
            if($view==='portal'&&$tab==='card')return Database::tableExists($this->db,'member_card_heads')&&$a['role']!=='editor'&&(!empty($a['contact_id'])||in_array($a['members_access'],['read','write'],true));
            if($view==='settings'&&$tab==='sepa')return BoardAccess::superuser($this->db,$this->actorId);
            if($view==='settings'&&$tab==='extensions')return BoardAccess::superuser($this->db,$this->actorId);
            if($view==='settings'&&$tab==='cache')return WebsiteTechnicalAccess::allowed($this->db,$this->actorId);
            if($view==='finance'&&$tab==='book-values'&&!$this->depreciationEnabled())return false;
            if($view==='documents'&&$tab==='activity-reports'){$kind=array_key_exists('report_kind',$route)?$route['report_kind']:'tax';if(!in_array($kind,['tax','assembly'],true))return (new ActivityReportReview($this->db,$this->actorId,null,'tax'))->canRead()||(new ActivityReportReview($this->db,$this->actorId,null,'assembly'))->canRead();return (new ActivityReportReview($this->db,$this->actorId,null,$kind))->canRead();}
            if($tab==='changes'&&in_array($view,['members','portal'],true))return $view==='members'?(new MemberChangeRequests($this->db,$this->actorId))->canReview():(new MemberChangeRequests($this->db,$this->actorId))->canSubmit();
            if($view==='portal'&&$tab==='bank'){
                if($a['role']==='editor')return false;
                if(!empty($a['is_superuser']))return true;
                if((new InvoiceApprovals($this->db,$this->actorId))->canApprove())return true;
                $today=(new \DateTimeImmutable('now',new \DateTimeZone('Europe/Berlin')))->format('Y-m-d');$q=$this->db->prepare("SELECT 1 FROM contacts c JOIN memberships m ON m.club_id=c.club_id AND m.contact_id=c.id WHERE c.club_id=? AND c.id=? AND c.kind='person' AND c.archived_at IS NULL AND m.status IN ('aktiv','passiv','ehemalig') AND m.starts_on<=? AND (m.ends_on IS NULL OR m.ends_on>=?)");$q->execute([$a['club_id'],$a['contact_id'],$today,$today]);return (bool)$q->fetchColumn();
            }
            if($view==='settings'&&$tab===''&&($route['section']??'')==='mail')return BoardAccess::superuser($this->db,$this->actorId);
            $members=in_array($a['members_access'],['read','write'],true);$finance=in_array($a['finance_access'],['read','write'],true);
            return match($view){
                'dashboard','help','appearance'=>true,
                'handbook'=>false, // Retained for a later rewrite; currently outdated.
                'gema'=>BoardAccess::allowed($this->db,$this->actorId),
                'members'=>$tab==='archive'?$a['members_access']==='write':($members||($a['role']==='member'&&$a['contact_id']&&($route['id']??'')===$a['contact_id'])),
                'contacts-extra','members-extra'=>$members,
                'finance','donations'=>$finance,
                'inventory'=>$tab==='assets'?$finance:($tab==='loans'?(new Inventory($this->db,$this->actorId))->canReadLoans():(new Inventory($this->db,$this->actorId))->canRead()),
                'material'=>(new Inventory($this->db,$this->actorId))->canRead(),
                'calendar'=>(in_array($tab,['gema','history'],true)||isset($route['meeting']))?BoardAccess::allowed($this->db,$this->actorId):(new Registry($this->db,$this->actorId))->canReadCalendar(),
                'minutes'=>(new Minutes($this->db,$this->actorId))->canRead(),
                'free','chat'=>$a['role']!=='editor',
                'documents'=>$tab==='checks'?BoardAccess::allowed($this->db,$this->actorId):(new Documents($this->db,$this->actorId))->canRead(),
                'messages'=>$tab==='inbox'?BoardAccess::allowed($this->db,$this->actorId):(BoardAccess::allowed($this->db,$this->actorId)||(new Documents($this->db,$this->actorId))->canScope('orga',true)),
                'portal'=>in_array($tab,['application','admissions'],true)?BoardAccess::allowed($this->db,$this->actorId):($a['role']!=='editor'&&in_array($tab,['own','safety','documents'],true)),
                'website','social','newsletter','press'=>$this->editorial($view,$tab),
                'settings'=>$tab==='account'||(in_array($tab,['donation-forms','numbers','rates','services','booking-keys','tax-accounts'],true)||($tab===''&&in_array($route['section']??'',['bank','tax','finance_preferences'],true))?$finance:(new Settings($this->db,$this->actorId))->canManage()),
                'audit'=>$a['members_access']==='write',
                default=>false,
            };
        }catch(DomainException){return false;}
    }
    public function depreciationEnabled():bool
    {
        try{
            $a=Permissions::actor($this->db,$this->actorId);
            $q=$this->db->prepare("SELECT payload FROM club_settings WHERE club_id=? AND section='tax'");
            $q->execute([$a['club_id']]);
            $payload=$q->fetchColumn();
            if(!is_string($payload)||$payload==='')return false;
            $data=json_decode($payload,true,512,JSON_THROW_ON_ERROR);
            return is_array($data)&&(($data['accounting_basis']??'')==='balance'||in_array($data['depreciation_enabled']??false,[true,1,'1'],true));
        }catch(\Throwable){return false;}
    }
    private function editorial(string $module,string $tab):bool
    {
        if($module==='website'&&$tab==='integrations'&&getenv('KH_NATIVE_MANAGER')==='1')return WebsiteTechnicalAccess::allowed($this->db,$this->actorId);
        $route=EditorialModules::route(['view'=>$module,'tab'=>$tab]);$module=$route['view'];$site=new EditorialModuleWorkspace($this->db,$this->actorId,$module);
        return $site->canRead()&&($tab===''||in_array($tab,EditorialModules::tabs($module),true))&&($tab===''||in_array($tab,EditorialModules::EXTRA,true)||isset($site->areas()[$tab]));
    }
    private function website(string $tab):bool{$site=new WebsiteWorkspace($this->db,$this->actorId);return $site->canRead()&&($tab===''||isset($site->areas()[$tab]));}
}
