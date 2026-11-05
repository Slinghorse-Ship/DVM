<?php declare(strict_types=1);
if(!isset($nativeAccess))return;
if(isset(\Kulturhus\Native\Language\DonationCollectionMessages::ENGLISH[$notice]))$notice=\Kulturhus\Native\Language\DonationCollectionMessages::text($notice,(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages());
if(!($view==='settings'&&input($_GET,'tab')==='donation-forms')&&!in_array($_POST['action']??null,['donation-preview','donation-save','donation-issue','donation-cancel','donation-compare','donation-adopt','donation-search'],true)&&!isset(\Kulturhus\Native\Language\DonationFormMessages::ENGLISH[$notice]))return;
$donationFormMessages=(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages();
if(($view==='settings'&&input($_GET,'tab')==='donation-forms')||in_array($_POST['action']??null,['donation-preview','donation-save','donation-issue','donation-cancel','donation-compare','donation-adopt','donation-search'],true))$error=\Kulturhus\Native\Language\DonationFormMessages::text($error,$donationFormMessages);
if(isset(\Kulturhus\Native\Language\DonationFormMessages::ENGLISH[$notice]))$notice=\Kulturhus\Native\Language\DonationFormMessages::text($notice,$donationFormMessages);
