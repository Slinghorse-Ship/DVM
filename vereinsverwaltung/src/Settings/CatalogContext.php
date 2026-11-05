<?php declare(strict_types=1);
namespace Kulturhus\Verein\Settings;
use PDO;
use DomainException;
use Kulturhus\Native\Shared\Db;
use Kulturhus\Verein\{ContributionRates,TaxCatalog};

/** Only the selected account and club-owned tariff bindings enter a current review. */
final class CatalogContext
{
    public static function read(PDO $db,string $club,string $id,string $kind,array $data):array
    {
        if(!$db->inTransaction())throw new DomainException('Tarifprüfung benötigt einen aktuellen Leseschritt.',503);
        $links=Db::run($db,'SELECT membership_type,catalog_id FROM membership_contribution_rates WHERE club_id=? ORDER BY membership_type FOR SHARE NOWAIT',[$club])->fetchAll();
        $types=[];foreach($links as$link)if($link['catalog_id']===$id)$types[]=$link['membership_type'];
        $account=null;
        if($data['tax_account']!==''){
            $account=TaxCatalog::accounts()[$data['tax_account']]??null;
            $override=Db::run($db,'SELECT * FROM tax_account_settings WHERE club_id=? AND year=? AND code=? FOR SHARE NOWAIT',[$club,TaxCatalog::YEAR,$data['tax_account']])->fetch();
            if($override)$account=array_replace($account??[],$override);
            if($account&&($account['blocked']??false))$account['active']=0;
        }
        $duplicate=(bool)Db::run($db,"SELECT 1 FROM settings_catalog WHERE club_id=? AND kind=? AND id<>? AND CAST(JSON_UNQUOTE(JSON_EXTRACT(payload,'$.name')) AS BINARY)=CAST(? AS BINARY) FOR SHARE NOWAIT",[$club,$kind,$id,$data['name']])->fetchColumn();
        return['linked_membership_types'=>$types,'tax_account'=>$account,'name_in_use'=>$duplicate];
    }
    public static function assert(array $context,array $data):void
    {
        if($context['name_in_use'])throw new DomainException('Bezeichnung bereits vorhanden.',422);
        if($data['tax_account']!==''&&(!$context['tax_account']||!$context['tax_account']['active']))throw new DomainException('Aktives Steuerkonto wählen.',422);
        if($context['linked_membership_types'])try{ContributionRates::validate($data,$data['active']);}catch(DomainException $failure){throw new DomainException($failure->getMessage(),422,$failure);}
    }
}
