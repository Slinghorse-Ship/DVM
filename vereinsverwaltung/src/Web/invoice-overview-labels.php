<?php declare(strict_types=1);
$invoiceOverviewMessages=$managerLanguageMessages??(isset($nativeAccess)?(new \Kulturhus\Native\Language\Catalog($db,$nativeAccess))->messages():\Kulturhus\Native\Language\InvoiceOverviewMessages::messages('de'));
$invoiceOverviewText=static fn(string $source,array $values=[]):string=>\Kulturhus\Native\Language\InvoiceOverviewMessages::text($source,$invoiceOverviewMessages,$values);
