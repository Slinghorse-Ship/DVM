<?php declare(strict_types=1);
require dirname(__DIR__,2).'/vendor/autoload.php';
require __DIR__.'/native-document-interface.php';
require __DIR__.'/support/donation-cover-native-contracts.php';
$coverFixture=ownDonationCoverNativeContracts($db,$check,$pdf);
if(getenv('DVM_DONATION_COVER_INTERFACE_BROWSER')==='1')require __DIR__.'/support/donation-cover-native-browser-fixture.php';
echo json_encode(['suite'=>'native-donation-cover-interface','checks'=>$n,'external_actions'=>0],JSON_THROW_ON_ERROR)."\n";
