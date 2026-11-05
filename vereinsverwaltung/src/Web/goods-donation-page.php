<?php declare(strict_types=1);
if($view!=='donations'||input($_GET,'tab')!=='goods'||!$canFinance)return;
$goodsUiMessages=isset($nativeAccess)?(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages():\Kulturhus\Native\Language\GoodsDonationMessages::messages('de');
$goodsPanelCatalogue=\Kulturhus\Verein\Web\GoodsDonationState::catalogue($finance,$_GET);
if(!$goodsPanelCatalogue['available']&&http_response_code()<400)http_response_code(422);
$error=\Kulturhus\Native\Language\GoodsDonationMessages::text($error,$goodsUiMessages);
if(isset(\Kulturhus\Native\Language\GoodsDonationMessages::ENGLISH[$notice]))$notice=\Kulturhus\Native\Language\GoodsDonationMessages::text($notice,$goodsUiMessages);
