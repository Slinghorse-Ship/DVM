<?php declare(strict_types=1);
$invoiceDraftMessages=$managerLanguageMessages??(isset($nativeAccess)?(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages():\Kulturhus\Native\Language\InvoiceDraftMessages::messages('de'));
$invoiceDraftText=static fn(string$source):string=>\Kulturhus\Native\Language\InvoiceEntryMessages::text(\Kulturhus\Native\Language\InvoiceDraftMessages::text(\Kulturhus\Native\Language\InvoiceInboxSourceMessages::text($source,$invoiceDraftMessages),$invoiceDraftMessages),$invoiceDraftMessages);

$invoiceEntryMessages=$invoiceDraftMessages;
$invoiceEntryText=static fn(string$source,array$values=[]):string=>\Kulturhus\Native\Language\InvoiceEntryMessages::text($source,$invoiceEntryMessages,$values);
