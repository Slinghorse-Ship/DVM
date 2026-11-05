<?php declare(strict_types=1);
namespace Kulturhus\Native\Demo;

use Kulturhus\Native\Shared\{Db,Identity};
use Kulturhus\Native\Integration\ManagerBridge;
use Kulturhus\Native\Cms\Pages;
use Kulturhus\Native\Communication\{Workspace,Images,ImageHistory};
use Kulturhus\Verein\{Registry,Finance,Settings,SettingsSchema};

/** Explicit fictional first-install data, created through the ordinary services. */
final class Samples
{
    private \Closure $persist;
    public function __construct(private \PDO $db,private array &$journal,callable $persist){$this->persist=\Closure::fromCallable($persist);}

    private function once(string $key,callable $create,?callable $find=null):mixed
    {
        if(array_key_exists($key,$this->journal['samples']))return $this->journal['samples'][$key];
        $result=$find?$find():null;if($result===null)$result=$create();
        $this->journal['samples'][$key]=$result;($this->persist)($key);return $result;
    }

    private function unique(string $sql,array $parameters):?string
    {
        $rows=Db::run($this->db,$sql,$parameters)->fetchAll(\PDO::FETCH_COLUMN);
        if(count($rows)>1)throw new \DomainException('Begonnene Beispieldaten sind nicht eindeutig.');return $rows[0]??null;
    }

    private function memberMandate(string $contact):bool
    {
        $roles=json_decode((string)file_get_contents(Runtime::root().'/roles.json'),true,32,JSON_THROW_ON_ERROR);$record=$roles['member'];
        $auth=\Kulturhus\Native\Auth\Runtime::service($this->db);$login=$auth->passwordLogin($record['email'],$record['password'],'demo-install-mandate-'.Runtime::instance());
        if(($login['status']??null)!=='authenticated')throw new \DomainException('Eigenes fiktives Mitgliedskonto nicht bestätigt.');
        $token=$login['session_token'];$actor='demo-member';$identity=function()use($auth,$token,$actor):void{\Kulturhus\Native\Integration\MemberBankAuthentication::identity($this->db,$actor,$auth,$token);};
        $security=function()use($identity,$auth,$token):array{$identity();return $auth->reauthenticationState($token);};
        $session=[];$sessionHash=hash('sha256',Runtime::instance().':install-mandate');
        $step=new \Kulturhus\Verein\MemberBankStepUp($this->db,$actor,$session,$sessionHash,static fn(string $password):bool=>$auth->reauthenticatePassword($token,$password),$security,static fn(string $email,string $code):bool=>false,static function(string $phase):void{},hash_hkdf('sha256',\Kulturhus\Verein\ContactArchive::serverKey(),32,'member-bank-stepup-v1'));
        $vault=new \Kulturhus\Verein\MemberBankVault($this->db,$actor,Runtime::root().'/member-bank-keys/v1.key',1,$identity);
        try{
            $summary=$vault->summary($contact);
            if((int)$summary['version']===0){
                $data=['holder'=>'Erika Beispiel','iban'=>'DE89370400440532013000','bic'=>'COBADEFFXXX'];$binding=['contact'=>$contact,'action'=>'save','version'=>0,'draft_hash'=>\Kulturhus\Verein\MemberBankVault::payloadHash('save',$data)];
                $grant=$step->begin($binding,$record['password']);if($grant['requires_code'])throw new \DomainException('Fiktives Mitglied benötigt keine reale zweite Bestätigung.');$summary=$vault->save($contact,0,$data,$grant['token'],$sessionHash);
            }
            if($summary['status']==='mandated')return true;
            if($summary['status']!=='saved'||(int)$summary['version']!==1)throw new \DomainException('Bestehende Mitgliedsbankdaten bleiben unverändert.');
            $data=$vault->mandateTerms($contact)['payload'];$binding=['contact'=>$contact,'action'=>'mandate','version'=>1,'draft_hash'=>\Kulturhus\Verein\MemberBankVault::payloadHash('mandate',$data)];$grant=$step->begin($binding,$record['password']);
            if($grant['requires_code'])throw new \DomainException('Fiktives Mitglied benötigt keine reale zweite Bestätigung.');$vault->mandate($contact,1,$data,$grant['token'],$sessionHash);return true;
        }finally{$auth->logout($token);}
    }

    public function run(array $configuration):void
    {
        $club=Runtime::club();$owner='demo-install-owner';$bridge=new ManagerBridge($this->db,$owner,'native',static function():void{if(PHP_SAPI!=='cli')throw new \DomainException('Private Demo-Einrichtung erforderlich.');});$access=$bridge->access();
        $settings=new Settings($this->db,$owner);
        $this->once('club-settings',function()use($settings){$data=SettingsSchema::defaults('club');$data=array_replace($data,['name'=>'Übungsverein / Sample Club','street'=>'Beispielweg 1','postcode'=>'12345','city'=>'Musterstadt','website'=>Runtime::origin().'/demo']);$settings->save('club',$data,0);return true;},fn()=>Db::run($this->db,"SELECT 1 FROM club_settings WHERE club_id=? AND section='club'",[$club])->fetchColumn()?true:null);
        $this->once('identity',fn()=>(new Identity($this->db,$access))->save(['name'=>'Übungsverein / Sample Club','origin'=>Runtime::origin(),'address'=>"Beispielweg 1\n12345 Musterstadt",'logo_id'=>'','club_version'=>\Kulturhus\Native\Integration\ManagerClub::profile($this->db,$access->club())['version']],0)['version'],fn()=>Db::run($this->db,'SELECT version FROM native_club_settings WHERE club_id=?',[$access->club()])->fetchColumn()?:null);
        $registry=new Registry($this->db,$owner);$members=[];
        foreach(['Erika Beispiel','Alex Muster','Sam Demo'] as $index=>$name){
            $email='demo-member-'.$index.'@example.invalid';
            $members[]=$contact=$this->once('contact-'.$index,fn()=>$registry->saveContact(null,0,'person',$name,$email,"Beispielweg ".($index+2)."\n12345 Musterstadt",['notes'=>'Ausschließlich fiktive Beispieldaten / Fictional sample data']),fn()=>$this->unique('SELECT id FROM contacts WHERE club_id=? AND email=?',[$club,$email]));
            $number='DEMO-00'.($index+1);$this->once('membership-'.$index,fn()=>$registry->addMembership($contact,$number,'aktiv','2026-01-01',false),fn()=>$this->unique('SELECT id FROM memberships WHERE club_id=? AND member_number=?',[$club,$number]));
        }
        Db::run($this->db,'UPDATE actors SET contact_id=? WHERE club_id=? AND id=? AND contact_id IS NULL',[$members[0],$club,'demo-member']);
        $finance=new Finance($this->db,$owner);$day=$this->journal['sample_day'];$key=static fn(string $step)=>substr(hash('sha256',Runtime::instance().':'.$step),0,32);
        $bank=$this->once('bank',fn()=>$finance->createAccount('Fiktives Vereinskonto','offline','1000',$day),fn()=>$this->unique('SELECT id FROM finance_accounts WHERE club_id=? AND name=?',[$club,'Fiktives Vereinskonto']));
        $cash=$this->once('cash',fn()=>$finance->createAccount('Fiktive Barkasse','cash','100',$day),fn()=>$this->unique('SELECT id FROM finance_accounts WHERE club_id=? AND name=?',[$club,'Fiktive Barkasse']));
        $this->once('mock-bank-settings',function()use($settings,$bank){$settings->save('bank',array_replace(SettingsSchema::defaults('bank'),['account_id'=>$bank,'holder'=>'Fiktiver Uebungsverein','creditor'=>'DE98ZZZ09999999999','iban'=>'DE02120300000000202051','bic'=>'DEMOXXXXXXX']),0);return true;},fn()=>Db::run($this->db,"SELECT 1 FROM club_settings WHERE club_id=? AND section='bank'",[$club])->fetchColumn()?true:null);
        $this->once('mock-bank-write',function()use($finance,$bank){$state=$finance->accountSettings($bank);$finance->saveAccountSettings($bank,(int)$state['version'],true,'',true,true);return true;},fn()=>(bool)$finance->accountSettings($bank)['fints_write']?true:null);
        $this->once('treasurer-office',function(){(new \Kulturhus\Verein\AdmissionVotes($this->db,'demo-install-owner'))->grant('demo-treasurer',0,'Kassenwart',true,true);return true;},fn()=>Db::run($this->db,"SELECT 1 FROM admission_board b JOIN admission_board_checks c ON c.club_id=b.club_id AND c.actor_id=b.actor_id AND c.board_version=b.version AND c.office=b.office WHERE b.club_id=? AND b.actor_id='demo-treasurer' AND b.office='Kassenwart' AND b.enabled=1",[$club])->fetchColumn()?true:null);
        $this->once('sepa-settings',function(){(new \Kulturhus\Verein\MemberBankSettings($this->db,'demo-install-owner'))->save(0,['sepa_enabled'=>'1','creditor_id'=>'DE98ZZZ09999999999','creditor_name'=>'Fiktiver Uebungsverein','creditor_address'=>'Beispielweg 1, 12345 Musterstadt']);return true;},fn()=>Db::run($this->db,'SELECT 1 FROM member_bank_config WHERE club_id=?',[$club])->fetchColumn()?true:null);
        $this->once('sample-mandate',fn()=>$this->memberMandate($members[0]));
        $entry=$this->once('contribution',fn()=>$finance->createEntry(['kind'=>'income','category'=>'general','description'=>'Fiktiver Mitgliedsbeitrag / Sample membership fee','amount'=>'240','document_date'=>$day,'due_date'=>$day],$key('contribution')));
        $this->once('open-debit-contribution',fn()=>$finance->createEntry(['kind'=>'contribution','category'=>'general','description'=>'Offener fiktiver Beitrag / Unpaid sample contribution','counterparty'=>'Erika Beispiel','amount'=>'30','document_date'=>$day,'due_date'=>$day,'notes'=>json_encode(['payer'=>$members[0]],JSON_THROW_ON_ERROR)],$key('open-debit-contribution')));
        $expense=$this->once('open-receipt',fn()=>$finance->createEntry(['kind'=>'expense','category'=>'general','description'=>'Offener Beispielbeleg / Unpaid sample receipt','amount'=>'120','document_date'=>$day,'due_date'=>$day],$key('open-receipt')));
        $suppliers=new \Kulturhus\Verein\InvoiceSupplierContacts($this->db,$owner);
        $supplier=$this->once('sample-supplier',fn()=>$suppliers->create(['name'=>'Fiktive Demo-Lieferfirma','email'=>'demo-supplier@example.invalid','address'=>'Beispielweg 9, Musterstadt','iban'=>'DE12500105170648489890','bic'=>'PBNKDEFFXXX'],$key('sample-supplier'))['id']);
        $this->once('sample-supplier-link',function()use($suppliers,$registry,$expense,$supplier){$suppliers->link($expense,$supplier,(int)$registry->contact($supplier)['version']);return true;},fn()=>$suppliers->forEntry($expense)?true:null);
        $this->once('sample-invoice-document',function()use($expense,$key){
            $image=imagecreatetruecolor(800,1100);$white=imagecolorallocate($image,255,255,255);$black=imagecolorallocate($image,0,0,0);imagefill($image,0,0,$white);
            foreach(['FIKTIVE DEMO-RECHNUNG / FICTIONAL DEMO INVOICE','Keine echte Forderung. / No real payment due.','Fiktive Demo-Lieferfirma','Beispielweg 9, 12345 Musterstadt','Leistung: Fiktiver Vereinsbedarf / Sample club supplies','Gesamtbetrag / Total: 120,00 EUR'] as $index=>$line)imagestring($image,4,40,70+$index*60,$line,$black);
            ob_start();imagepng($image);$bytes=ob_get_clean();imagedestroy($image);
            return (new \Kulturhus\Verein\Documents($this->db,'demo-install-owner'))->upload('Fiktive-Demo-Rechnung.png',$bytes,['title'=>'Fiktive Demo-Rechnung / Fictional demo invoice','kind'=>'invoice','scope'=>'finance','links'=>['entry:'.$expense]],$key('sample-invoice-document'));
        });
        $this->once('sample-invoice-approval',function()use($expense,$key){$approval=new \Kulturhus\Verein\InvoiceApprovals($this->db,'demo-treasurer');$state=$approval->state($expense);if($state['approved']??false)return true;$approval->approve($expense,$state['hash'],$state['decision_id'],$key('sample-invoice-approval'),true);return true;});
        $transaction=fn(string $name,string $direction,string $amount,string $description)=>$this->once($name,fn()=>$finance->createTransaction(['account'=>$bank,'booked_on'=>$day,'direction'=>$direction,'amount'=>$amount,'counterparty'=>'Fiktive Beispielperson','description'=>$description],$key($name)));
        $paid=$transaction('contribution-payment','income','240','Fiktiver Mitgliedsbeitrag / Sample membership payment');
        $this->once('allocation',function()use($finance,$paid,$entry,$key){$finance->allocate($paid,1,$entry,1,'240',$key('allocation'));return true;},fn()=>Db::run($this->db,'SELECT 1 FROM finance_allocations WHERE club_id=? AND transaction_id=? AND entry_id=?',[$club,$paid,$entry])->fetchColumn()?true:null);
        $transaction('unallocated-income','income','90','Unzugeordneter Beispieleingang / Unallocated sample income');
        $transaction('unallocated-expense','expense','45','Unzugeordneter Beispielausgang / Unallocated sample expense');
        $withdrawal=$transaction('cash-withdrawal','expense','50','Fiktive Bargeldabhebung / Sample cash withdrawal');
        $this->once('cash-movement',fn()=>$finance->recordCashMovement($withdrawal,1,$cash,$day,'50','Fiktiver Zugang zur Barkasse / Sample cash deposit',$key('cash-movement')));
        $pages=new Pages($this->db,$access);
        foreach([
            ['slug'=>'start','title'=>'Willkommen im Übungsverein / Welcome to the sample club','body'=>'Diese Website und alle Daten sind fiktiv. / This website and all data are fictional.','nav_order'=>1],
            ['slug'=>'ueber-uns','title'=>'Über uns / About us','body'=>'Gemeinsam ausprobieren, ohne echte Vereinsdaten. / Try the manager without real club data.','nav_order'=>2],
            ['slug'=>'demo-veranstaltung','template'=>'event','title'=>'Fiktiver Vereinsabend / Sample club evening','body'=>'Fiktive Veranstaltung zum Ausprobieren. / A fictional event for trying the manager.','event_start'=>$day.'T18:00','event_end_local'=>$day.'T20:00','event_location'=>'Fiktives Vereinshaus','event_address'=>'Beispielweg 1, Musterstadt'],
        ] as $data){
            $slug=$data['slug'];$id=$this->once('page-'.$slug,fn()=>$pages->save($data)['id'],fn()=>$this->unique('SELECT id FROM khcms_pages WHERE club_id=? AND slug=?',[$access->club(),$slug]));
            $this->once('publish-'.$slug,function()use($pages,$id){$page=$pages->get($id);if($page['published_revision']===null)$pages->publish($id,$page['version']);return true;});
        }
        foreach([
            ['key'=>'post','module'=>'social','kind'=>'post','title'=>'Geplanter Facebook-Beitrag / Planned Facebook post','channels'=>['facebook']],
            ['key'=>'story','module'=>'social','kind'=>'story','title'=>'Geplante Instagram-Story / Planned Instagram story','channels'=>['instagram'],'story'=>[['title'=>'Ein Blick in den Übungsverein','text'=>'Fiktive Story / Fictional story','media_ref'=>'']]],
            ['key'=>'newsletter','module'=>'newsletter','kind'=>'edition','title'=>'Geplanter Newsletter / Planned newsletter'],
            ['key'=>'reel','module'=>'social','kind'=>'reel','title'=>'Reel-Entwurf / Reel draft'],
        ] as $data){
            $workspace=new Workspace($this->db,$access,$data['module'],Runtime::root().'/media');$table=\Kulturhus\Native\Communication\Schema::table($data['module'],'items');$name=$data['key'];unset($data['key']);
            $id=$this->once('communication-'.$name,fn()=>$workspace->save($data+['body'=>'Ausschließlich fiktive Beispieldaten / Fictional sample data'])['id'],fn()=>$this->unique("SELECT id FROM $table WHERE club_id=? AND JSON_UNQUOTE(JSON_EXTRACT(payload,'$.title'))=?",[$access->club(),$data['title']]));
            if($name==='reel')continue;
            $this->once('schedule-'.$name,function()use($workspace,$id,$data,$access,$day){
                $item=$workspace->get($id);if($item['status']==='scheduled')return true;
                if($item['status']==='draft'){
                    if($data['module']==='social'){
                        if(!(new ImageHistory($this->db,$access))->newest($id,$item['version'],Images::fingerprint($item),$item['kind'],$item['kind']==='story'?count($item['story']):1))(new Images($this->db,$access,Runtime::root().'/media'))->render($id);
                    }
                    $item=$workspace->approve($id,$item['version']);
                }
                $at=(new \DateTimeImmutable($day.' 12:00:00',new \DateTimeZone('Europe/Berlin')))->format(DATE_ATOM);
                $workspace->schedule($id,$at,$item['version']);return true;
            });
        }
    }
}
