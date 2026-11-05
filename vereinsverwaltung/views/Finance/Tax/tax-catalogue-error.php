<?php declare(strict_types=1);
if(http_response_code()<400)http_response_code(in_array($taxCatalogueFailure->getCode(),[403,404,409,422,503],true)?$taxCatalogueFailure->getCode():422);
?>
<link rel="stylesheet" href="tax-catalogues.css">
<section class="content" data-tax-catalogue-rejected><h1><?=e($taxCatalogueText('Katalogauswahl prüfen'))?></h1><p role="alert"><?=e($taxCatalogueText($taxCatalogueFailure->getMessage()))?></p><a class="btn btn-outline" target="_blank" rel="noopener" href="<?=e(url(['view'=>'settings','tab'=>$taxCatalogueTab]))?>"><?=e($taxCatalogueText('Gespeicherten Kontenplan in neuem Fenster prüfen'))?></a></section>
