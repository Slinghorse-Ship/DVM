<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use PDO;
use DomainException;
/** Shared local dispatch. Certificates and transmission never enter this service. */
final class BalanceFilingActions
{
    public const ACTIONS=['tax-filing-balance-create', 'tax-filing-balance-generate', 'tax-filing-balance-save', 'tax-filing-balance-partnership', 'tax-filing-balance-equalization', 'tax-filing-balance-financial-reserve', 'tax-filing-balance-financial-reserve-withdraw', 'tax-filing-balance-basis-6b', 'tax-filing-balance-basis-7g', 'tax-filing-balance-special-7b', 'tax-filing-balance-special-7g', 'tax-filing-balance-pension-offsets', 'tax-filing-balance-pool-original', 'tax-filing-balance-own-intangible', 'tax-filing-balance-approve', 'tax-filing-balance-revision', 'tax-filing-balance-capital', 'tax-filing-balance-adjust', 'tax-filing-balance-carry', 'tax-filing-balance-asset', 'tax-filing-validate'];
    private static function field(array $values,string $key,string $default=''):string
    {
        if(!array_key_exists($key,$values))return $default;
        if(!is_string($values[$key]))throw new DomainException('E-Bilanz-Angaben vollständig prüfen.',422);
        return $values[$key];
    }
    public static function values(string $action,array $values):void
    {
        $main=['profile','mapping','whole_mapping','values_reviewed','reviewed'];
        $allowed=match($action){
            'tax-filing-balance-create'=>['balance_scope','environment'],
            'tax-filing-balance-save','tax-filing-balance-generate','tax-filing-validate','tax-filing-balance-approve'=>$main,
            'tax-filing-balance-partnership'=>['holding_id','partnership_review'],
            'tax-filing-balance-equalization'=>['equalization'],
            'tax-filing-balance-financial-reserve'=>['financial_reserve'],
            'tax-filing-balance-financial-reserve-withdraw'=>['withdrawal_reason'],
            'tax-filing-balance-basis-6b'=>['basis_6b'],
            'tax-filing-balance-basis-7g'=>['basis_7g'],
            'tax-filing-balance-special-7b'=>['special_7b'],
            'tax-filing-balance-special-7g'=>['special_7g'],
            'tax-filing-balance-pension-offsets'=>['pension_review','pension_scope'],
            'tax-filing-balance-pool-original'=>['pool_review'],
            'tax-filing-balance-own-intangible'=>['item_id','own_review'],
            'tax-filing-balance-revision'=>['revision_status','revision_reason','revision_legal_basis','revision_source','revision_confirmed'],
            'tax-filing-balance-capital'=>['capital_movements'],
            'tax-filing-balance-adjust'=>['adjust_period','adjust_reason','adjust_debit','adjust_credit','adjust_amount'],
            'tax-filing-balance-carry'=>['prior_filing','carry_confirmed'],
            'tax-filing-balance-asset'=>['asset_item','tax_years','tax_depreciation_account','tax_plan_reason','tax_plan_confirmed','remove_plan','remove_confirmed'],
            default=>throw new DomainException('Lokalen E-Bilanz-Schritt vollständig prüfen.',422),
        };
        if(array_diff(array_keys($values),$allowed))throw new DomainException('E-Bilanz-Angaben vollständig prüfen.',422);
        $inspect=static function(array $items,int $depth=0)use(&$inspect):void{
            if($depth>16)throw new DomainException('E-Bilanz-Angaben vollständig prüfen.',422);
            foreach($items as $value){if(is_array($value))$inspect($value,$depth+1);elseif(!is_string($value))throw new DomainException('E-Bilanz-Angaben vollständig prüfen.',422);}
        };$inspect($values);
    }
    public static function apply(PDO $db,string $actor,string $action,array $post,?BalanceFilingRuntime $runtime=null):array
    {
        $service=new BalanceTaxFiling($db,$actor,$runtime);$filings=new TaxFilings($db,$actor,$runtime);
        $fid=self::field($post,'filing');$version=(int)self::field($post,'version');$notice='E-Bilanz-Angaben gespeichert.';$answer=null;
        switch($action){
case 'tax-filing-balance-create':

    $balanceService=$service;
    if(self::field($post,'period_id')!==''){
        $fid=$balanceService->createPeriod(self::field($post,'period_id'),self::field($post,'balance_scope'),self::field($post,'environment','test'));

    }else $fid=$balanceService->create((int)self::field($post,'tax_year'),self::field($post,'balance_scope','separate_business_ledger'),self::field($post,'environment','test'));
    $notice='Lokaler E-Bilanz-Entwurf angelegt';break;
case 'tax-filing-balance-generate':

    $service->generate($fid,$version);
    $notice='XML lokal vorbereitet. Amtliche Prüfung und Übermittlung sind noch offen.';break;
case 'tax-filing-balance-save':

    $profile=$post['profile']??[];$mapping=$post['mapping']??[];
    if(!is_array($profile)||!is_array($mapping))throw new DomainException('E-Bilanz-Angaben prüfen.');
    foreach(['scope_confirmed','asset_values_unchanged','asset_tax_values_reviewed'] as $key)$profile[$key]=self::field($profile,$key)==='1';
    $privileged=self::field($profile,'tax_privileged');if(!in_array($privileged,['yes','no'],true))throw new DomainException('Steuerbegünstigung auswählen.');$profile['tax_privileged']=$privileged==='yes';
    // Existing adjustment entries are retained verbatim; this simple form cannot
    // erase a professionally reviewed reconciliation by omitting hidden fields.
    $pending=$filings->get($fid);$review=$pending['form_data']['balance_preparation']['review']??[];
    unset($review['year'],$review['journal_sha256'],$review['period']);$review['complete']=self::field($post,'values_reviewed')==='1';
    $wholeMapping=$post['whole_mapping']??null;if($wholeMapping!==null&&!is_array($wholeMapping))throw new DomainException('Kontenzuordnung des Gesamtvereins prüfen.');
    $service->save($fid,$version,$profile,$mapping,$review,$wholeMapping);break;
case 'tax-filing-balance-partnership':

    $holdingReview=$post['partnership_review']??null;if(!is_array($holdingReview))throw new DomainException('Steuerlichen Originalnachweis der Beteiligung vollständig eingeben.');
    $service->partnershipReview($fid,$version,self::field($post,'holding_id'),$holdingReview);
    $notice='Steuerlichen Beteiligungsnachweis mit den Originaljournalen verbunden. Den gesamten Entwurf erneut prüfen.';break;
case 'tax-filing-balance-equalization':
$source=$post['equalization']??null;if(!is_array($source))throw new DomainException('Originale Nachweise des steuerlichen Ausgleichspostens fehlen.');$service->taxEqualization($fid,$version,$source);$notice='Belegte Ergebnisumbuchung gespeichert. Gesamten steuerlichen Entwurf erneut prüfen.';break;
case 'tax-filing-balance-financial-reserve':
$source=$post['financial_reserve']??null;if(!is_array($source))throw new DomainException('Originale Rücklagenkette vollständig eingeben.');
    $service->financialReserveIntake($fid,$version,$source);$notice='Quellenentwurf gespeichert. Die steuerliche Behandlung und XML-Zuordnung benötigen eine gesonderte Freigabe.';break;
case 'tax-filing-balance-financial-reserve-withdraw':

    $service->financialReserveIntake($fid,$version,null,self::field($post,'withdrawal_reason'));$notice='Quellenentwurf mit Begründung zurückgenommen. Gesamten Steuerentwurf erneut prüfen.';break;
case 'tax-filing-balance-basis-6b':
$source=$post['basis_6b']??null;if(!is_array($source))throw new DomainException('Originale §6b-Nachweise fehlen.');$service->basisReduction6b($fid,$version,$source);$notice='Originale §6b-Wahl gespeichert. Tatsächliche Steuerbuchungen und gesamten Entwurf erneut prüfen.';break;
case 'tax-filing-balance-basis-7g':

      $basis7g=$post['basis_7g']??null;if(!is_array($basis7g))throw new DomainException('Ursprüngliche IAB-Angaben vollständig eingeben.');
      $service->basisReduction7g($fid,$version,$basis7g);
      $notice='IAB und Anschaffungskostenkürzung mit dem Originalabschluss verbunden. Tatsächliche Steuerwertänderungen erfassen und erneut prüfen.';break;
case 'tax-filing-balance-special-7b':

      $special7b=$post['special_7b']??null;if(!is_array($special7b))throw new DomainException('Neubau-Originalnachweise vollständig eingeben.');
      $service->specialTax7b($fid,$version,$special7b);
      $notice='Neubauwahl mit dem Originalabschluss verbunden. Reguläre steuerliche Gebäude-AfA und Sonderabschreibung gesondert überleiten und erneut prüfen.';break;
  case 'tax-filing-balance-special-7g':

      $special7g=$post['special_7g']??null;if(!is_array($special7g))throw new DomainException('Ursprüngliche Steuerwahl vollständig eingeben.');
      $service->specialTax7g($fid,$version,$special7g);
      $notice='Steuerwahl mit dem Originalabschluss verbunden. Tatsächliche Wertänderungen erfassen und die Steuerwerte vollständig erneut prüfen.';break;
case 'tax-filing-balance-pension-offsets':

      $pensionReview=$post['pension_review']??null;if(!is_array($pensionReview))throw new DomainException('Originalen Pensionsnachweis vollständig eingeben.');
      $service->pensionOffsets($fid,$version,$pensionReview,self::field($post,'pension_scope','tax'));
      $notice=self::field($post,'pension_scope','tax')==='whole'?'Pensionsnachweise mit dem ursprünglichen Gesamtabschluss verbunden. Jede handelsrechtliche Vereinbarung einzeln prüfen; steuerliche Werte werden dadurch nicht bestätigt.':'Pensionsnachweise mit dem ursprünglichen Abschluss verbunden. Steuerliche Bruttowerte gesondert erfassen und den Entwurf erneut prüfen.';break;
case 'tax-filing-balance-pool-original':

    $poolReview=$post['pool_review']??null;if(!is_array($poolReview))throw new DomainException('Ursprüngliche Sammelpostenwahl vollständig prüfen.');
    $service->poolOriginalReview($fid,$version,$poolReview);
    $notice='Sammelpostenwahl mit ursprünglichen Einzelregistern und Journalen verbunden. Den gesamten Entwurf erneut prüfen.';break;
case 'tax-filing-balance-own-intangible':

    $ownReview=$post['own_review']??null;if(!is_array($ownReview))throw new DomainException('Steuerlichen Originalnachweis des Entwicklungsprojekts vollständig eingeben.');
    if(($ownReview['tax_links']??null)==='')$ownReview['tax_links']=[];
    $service->ownIntangibleReview($fid,$version,self::field($post,'item_id'),$ownReview);
    $notice='Steuerlichen Entwicklungsnachweis mit den Originaljournalen verbunden. Den gesamten Entwurf erneut prüfen.';break;
case 'tax-filing-balance-approve':

    $service->approve($fid,$version,self::field($post,'reviewed')==='1');
    $notice='Dieser E-Bilanz-Entwurf ist für seine Abgabeumgebung freigegeben.';break;
case 'tax-filing-balance-revision':

    $service->revision($fid,$version,
        ['status'=>self::field($post,'revision_status'),'reason'=>self::field($post,'revision_reason'),'legal_basis'=>self::field($post,'revision_legal_basis'),
            'source_id'=>self::field($post,'revision_source'),'confirmed'=>self::field($post,'revision_confirmed')==='1']);
    $notice='Abgabeart gespeichert. Entwurf erneut prüfen und freigeben.';break;
case 'tax-filing-balance-capital':

    $decisions=$post['capital_movements']??[];if(!is_array($decisions))throw new DomainException('Kapitalbewegungen prüfen.');
    $service->capitalMovements($fid,$version,$decisions);
    $notice='Kapitalbewegungen gespeichert. Zahlen erneut prüfen.';break;
case 'tax-filing-balance-adjust':

    $service->appendAdjustment($fid,$version,self::field($post,'adjust_period'),self::field($post,'adjust_reason'),self::field($post,'adjust_debit'),self::field($post,'adjust_credit'),\Kulturhus\Verein\Finance::cents(self::field($post,'adjust_amount')));
    $notice='Steuerliche Wertänderung erfasst. Werte erneut prüfen.';break;
case 'tax-filing-balance-carry':

    $service->carryForward($fid,$version,self::field($post,'prior_filing'),self::field($post,'carry_confirmed')==='1');
    $notice='Steuerliche Anfangswerte aus dem Vorjahr übernommen. Werte erneut prüfen.';break;
case 'tax-filing-balance-asset':

    $plan=null;
    if(self::field($post,'remove_plan')==='1'){
        if(self::field($post,'remove_confirmed')!=='1')throw new DomainException('Entfernen des abweichenden Steuerplans bestätigen.');
    }else{
        $years=self::field($post,'tax_years');if(!preg_match('/^[1-9][0-9]{0,2}$/D',$years)||(int)$years>100)throw new DomainException('Steuerliche Nutzungsdauer in ganzen Jahren angeben.');
        $plan=['months'=>(int)$years*12,'depreciation_account'=>self::field($post,'tax_depreciation_account'),'reason'=>self::field($post,'tax_plan_reason'),'confirmed'=>self::field($post,'tax_plan_confirmed')==='1'];
    }
    $service->assetTaxPlan($fid,$version,self::field($post,'asset_item'),$plan);
    $notice=$plan===null?'Abweichenden Steuerplan entfernt. Anlagenwerte erneut prüfen.':'Steuerliche Abschreibung berechnet. Anlagenwerte erneut prüfen.';break;
case 'tax-filing-validate':
    $answer=$service->validate($fid,$version);$notice=$answer['valid']?'Amtliche Prüfung bestanden.':'Die amtliche Prüfung meldet offene Angaben.';break;
default:throw new DomainException('Lokalen E-Bilanz-Schritt vollständig prüfen.',422);
        }
        return ['filing'=>$filings->get($fid),'notice'=>$notice,'answer'=>$answer];
    }
}
