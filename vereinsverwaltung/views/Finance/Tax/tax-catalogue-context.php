<?php declare(strict_types=1);
use Kulturhus\Native\Language\TaxCatalogueMessages;
use Kulturhus\Verein\Web\{OriginalInput,TaxCatalogueForm};
$taxCatalogueText=static fn(string $source,array $values=[]):string=>TaxCatalogueMessages::text($source,$managerLanguageMessages??[],$values);
if(!$canFinance){echo'<div class="content"><p>'.e($taxCatalogueText('Finanzberechtigung erforderlich.')).'</p></div>';return false;}
if($_POST!==[]&&!$finance->canWrite()){$taxCatalogueFailure=new DomainException('Keine ausreichende Finanzberechtigung.',403);require __DIR__.'/tax-catalogue-error.php';return false;}
try{
 $taxCatalogueQuery=TaxCatalogueForm::query($_GET);
 $taxCataloguePosted=$_POST!==[];
 $taxCatalogueCommand=$taxCataloguePosted?TaxCatalogueForm::command($_GET,$_POST):null;
}catch(DomainException $taxCatalogueFailure){require __DIR__.'/tax-catalogue-error.php';return false;}
?>
<link rel="stylesheet" href="tax-catalogues.css">
<?php if($taxCatalogueCommand!==null):
$taxCatalogueBlocked=in_array(http_response_code(),[403,404,409,500,503],true);
$taxCatalogueRead=['view'=>'settings','tab'=>'tax-accounts'];if(preg_match('/\A[0-79][0-9]{4}\z/D',$taxCatalogueCommand['code']))$taxCatalogueRead+=['q'=>$taxCatalogueCommand['code'],'active'=>''];
?>
<section class="content" data-tax-catalogue-original><h1><?=e($taxCatalogueText('Änderung korrigieren'))?></h1><p role="status"><?=e($taxCatalogueText('Die ursprünglichen Angaben bleiben erhalten. Vor einer weiteren Änderung den gespeicherten Stand prüfen.'))?></p><a class="btn btn-outline" target="_blank" rel="noopener" href="<?=e(url($taxCatalogueRead))?>"><?=e($taxCatalogueText('Gespeicherten Kontenplan in neuem Fenster prüfen'))?></a>
<fieldset <?=$taxCatalogueBlocked?'disabled':''?>><form method="post" action="<?=e(url($taxCatalogueQuery))?>" class="card" data-tax-catalogue-correction>
<?=csrf().hidden('action','save-tax-account').hidden('version',$_POST['version'])?>
<?php if($taxCatalogueCommand['create_only']):?><?=hidden('create_only','1')?><label><?=e($taxCatalogueText('Kontonummer'))?><input class="form-control" name="code" pattern="[0-79][0-9]{4}" maxlength="5" required value="<?=e($taxCatalogueCommand['code'])?>"></label>
<?php else:?><?=hidden('code',$taxCatalogueCommand['code'])?><p><?=e($taxCatalogueCommand['code'])?></p><?php endif?>
<label><?=e($taxCatalogueText('Bezeichnung'))?><input class="form-control" name="name" maxlength="200" required value="<?=e($taxCatalogueCommand['name'])?>"></label><label class="check"><input class="check" type="checkbox" name="active" value="1" <?=$taxCatalogueCommand['active']?'checked':''?>> <?=e($taxCatalogueText('Für neue Kontierungen verwenden'))?></label><button class="btn btn-solid theme-primary"><?=e($taxCatalogueText($taxCatalogueCommand['create_only']?'Steuerkonto anlegen':'Speichern'))?></button></form></fieldset></section>
<?php return false;endif;return true;
