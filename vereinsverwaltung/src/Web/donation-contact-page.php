<?php declare(strict_types=1);
if(!isset($nativeAccess))return;
$donorAction=in_array($_POST['action']??null,['donation-contact','donation-contact-review','donation-contact-adopt','donation-prepare'],true);
$donorAction=$donorAction||(($_POST['action']??null)==='contact-choice-browse'&&($contactChoiceState['action']??null)==='donation-contact');
$donorNotice=isset(\Kulturhus\Native\Language\DonationContactMessages::ENGLISH[$notice]);
if(!$donorAction&&!$donorNotice)return;
$donorContactMessages=(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages();
if($donorAction)$error=\Kulturhus\Native\Language\DonationContactMessages::text($error,$donorContactMessages);
if($donorNotice)$notice=\Kulturhus\Native\Language\DonationContactMessages::text($notice,$donorContactMessages);
