<?php declare(strict_types=1);
namespace Kulturhus\Verein\Web;
use PDO;use DomainException;use Throwable;
use Kulturhus\Verein\{AssetBooks,AssetPartialDisposalRecords,BalancePeriod,Finance,WorkspaceAccess};

/** Read the exact current original; financial plans, methods and commands remain in their services. */
final class AssetBookSource
{
    public static function prepare(PDO $db,string $actor,array $query,array $post,string $method,string $defaultYear):array
    {
        $state=AssetBookSelection::read($query,$post,$method,$defaultYear);$state['request_context']=AssetBookSelection::context($actor,$query,$post,$method,$defaultYear);
        try{
            if(!(new Finance($db,$actor))->canRead())return self::failed($state,'blocked','Für diese Ansicht ist aktueller Finanzzugriff erforderlich.',403);
            if(!(new WorkspaceAccess($db,$actor))->depreciationEnabled())return self::failed($state,'blocked','Abschreibungen sind für diesen Verein in den Steuereinstellungen nicht aktiviert.',403);
            if(!in_array($state['state'],['requested','picker'],true))return self::failed($state,$state['state'],match($state['state']){
                'invalid-year'=>'Das ursprünglich angeforderte Jahr vollständig prüfen. Es wird kein anderes Jahr berechnet.',
                'invalid-item'=>'Die vollständige Gegenstandskennung prüfen.',
                'invalid-review'=>'Die vollständige ursprüngliche Prüfkennung prüfen.',
                'conflict'=>'Die Angaben im Link und im fehlgeschlagenen Vorgang gehören nicht zu derselben ursprünglichen Auswahl.',
                'invalid-action'=>'Die übermittelte Anfrage gehört nicht zu dieser Anlagenansicht.',
                default=>'Mehrteilige oder nicht als Text übermittelte Angaben werden nicht auf einzelne Werte reduziert.'
            },422);
            if($state['review']!==''){
                $record=(new AssetPartialDisposalRecords($db,$actor))->get($state['review']);
                $state['open_review']=['view'=>'finance','tab'=>'book-values','item'=>$record['item_id'],'year'=>(string)$record['year'],'partial_review'=>$state['review']];
                if(!AssetBookSelection::sameReview($state,$record)||($state['source']==='post'&&$state['action']!=='asset-partial-book'))return self::failed($state,'review-conflict','Diese Angaben gehören nicht zum ursprünglichen gespeicherten Prüfstand. Öffnen Sie ihn ausdrücklich erneut.',422);
            }
            $books=new AssetBooks($db,$actor);$plan=$state['state']==='picker'?null:$books->get($state['item']);
            if($plan&&$plan['data']!==null&&!is_array($plan['data']))return self::failed($state,'unavailable','Der ursprüngliche Anlagenstand konnte gerade nicht geladen werden. Bitte erneut prüfen.',503);
            if($plan&&in_array($plan['data']['method']??null,['cost','partnership_hgb','own_intangible_hgb','substance_original'],true)){
                try{BalancePeriod::calendar($state['year']);}
                catch(DomainException){return self::failed($state,'invalid-year','Die Bewertungsmethode erlaubt diesen Jahresstand nicht. Prüfen Sie das ganze ursprüngliche Jahr.',422);}
            }
            $state['plan']=$plan;$state['items']=$books->items();$state['state']=$plan?'ready':'picker';return $state;
        }catch(DomainException $failure){
            $permission=in_array($failure->getMessage(),['Finanzberechtigung erforderlich.','Zugang nicht aktiv.','Abschreibungen sind für diesen Verein in den Steuereinstellungen nicht aktiviert.'],true);
            return self::failed($state,$permission?'blocked':'unavailable',$permission?'Für diese Ansicht ist aktueller Finanzzugriff erforderlich.':($state['review']!==''?'Der ursprünglich angeforderte Prüfstand ist derzeit nicht verfügbar.':'Der ursprünglich angeforderte Gegenstand ist derzeit nicht verfügbar.'),$permission?403:($failure->getCode()===503?503:404));
        }catch(Throwable){return self::failed($state,'unavailable','Der ursprüngliche Anlagenstand konnte gerade nicht geladen werden. Bitte erneut prüfen.',503);}
    }
    private static function failed(array $state,string $kind,string $message,int $status):array{return array_replace($state,['state'=>$kind,'message'=>$message,'original_status'=>$status,'plan'=>null,'items'=>[]]);}
}
