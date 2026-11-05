<?php declare(strict_types=1);
// Render the actual annual asset page with stored synthetic plans and real roles.
require __DIR__.'/fixtures/asset-partial-review-setup.php';
require __DIR__.'/fixtures/partial-ui.php';
use Kulturhus\Verein\{AssetTypes,SettingsSchema};
use Kulturhus\Verein\Tests\MySqlFixture;
set_error_handler(static function($s,$m,$f,$l){throw new ErrorException($m,0,$s,$f,$l);});
$n=0;
function check(bool $ok,string $label):void{global $n;if(!$ok)throw new RuntimeException($label);++$n;echo "PASS $label\n";}
extract(Kulturhus\Verein\Tests\partialReviewSetup());
$save=static function(string $code,array $extra=[])use($books):void{
    $books->save('partial',(int)$books->get('partial')['version'],array_replace([
        'basis'=>'120','residual'=>'0','months'=>'24','acquired_on'=>'2025-01-15',
        'sphere'=>'4','account'=>$code,'reason'=>'Synthetic reviewed asset type'], $extra));
};
foreach(AssetTypes::codes('movable') as $code){
    $save($code);$before=MySqlFixture::snapshot($db);
    foreach(['admin','writer'] as $who){
        $html=partialRender($db,$who,2025);
        check(str_contains($html,'name="disposal_kind"')&&str_contains($html,'value="sale"'),"$code $who can record complete disposal");
        check(str_contains($html,'value="asset-partial-prepare"')&&str_contains($html,'Teilabgang berechnen'),"$code $who can prepare partial disposal");
    }
    $html=partialRender($db,'reader',2025);
    check(!str_contains($html,'name="disposal_kind"')&&!str_contains($html,'value="asset-partial-prepare"')&&!str_contains($html,'value="asset-book-save"'),"$code reader has no write controls");
    check(MySqlFixture::snapshot($db)===$before,"$code rendering changes no records");
}
$save('01350');$html=partialRender($db,'admin',2025);
check(str_contains($html,'software_rights_ended')&&!str_contains($html,'value="sale"')&&!str_contains($html,'value="asset-partial-prepare"'),'software keeps its separate license-only workflow');
$db->exec("INSERT INTO inventory_locations(club_id,id,name) VALUES('a','asset-ui-store','Synthetic store'); INSERT INTO inventory_stock(club_id,item_id,location_id,shelf) VALUES('a','partial','asset-ui-store',1)");
$save('06700',['method'=>'gwg_immediate','months'=>'1','asset_reviewed'=>'1','gwg_net'=>'120','gwg_vat'=>'0','gwg_deductible'=>'0','gwg_independent'=>'1','gwg_no_pool'=>'1']);
$html=partialRender($db,'admin',2025);
check(str_contains($html,'name="disposal_kind"')&&!str_contains($html,'value="asset-partial-prepare"'),'GWG allows complete but not partial disposal');
$save('05200');$euer=array_replace(SettingsSchema::defaults('tax'),['accounting_basis'=>'euer','depreciation_enabled'=>true]);
$db->prepare("UPDATE club_settings SET payload=? WHERE club_id='a' AND section='tax'")->execute([json_encode($euer)]);
$before=MySqlFixture::snapshot($db);$html=partialRender($db,'admin',2025);
check(!str_contains($html,'value="asset-partial-prepare"'),'EÜR never enables the balance-only partial posting workflow');
$blocked=false;try{partialRender($db,'other',2025);}catch(DomainException $e){$blocked=true;}
check($blocked,'other club cannot see an asset');
check(MySqlFixture::snapshot($db)===$before,'EÜR rendering and denied access change no records');
echo "$n actual asset-page checks passed; synthetic MySQL only\n";
