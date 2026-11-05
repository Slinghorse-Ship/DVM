<?php declare(strict_types=1);
require dirname(__DIR__,2).'/vendor/autoload.php';
require __DIR__.'/native-document-interface.php';
require __DIR__.'/support/invoice-reminder-native-contracts.php';
$reminderFixture=ownInvoiceReminderNativeContracts($db,$check);
if(getenv('DVM_INVOICE_REMINDER_INTERFACE_BROWSER')==='1')require __DIR__.'/support/invoice-reminder-native-browser-fixture.php';
echo json_encode(['suite'=>'native-invoice-reminder-interface','checks'=>$n,'external_actions'=>0],JSON_THROW_ON_ERROR)."\n";
