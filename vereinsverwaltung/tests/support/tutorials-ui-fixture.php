<?php declare(strict_types=1);
require __DIR__.'/member-profile-environment.php';
use Kulturhus\Verein\{Database,Registry,Documents,ClubChat,ChatPolls};
$db=\Kulturhus\Verein\Tests\MySqlFixture::existing($memberTestDatabase);Database::migrate($db);
$club='tutorial-ui-'.bin2hex(random_bytes(6));$actor=$club.'-board';
$db->prepare('INSERT INTO clubs VALUES(?,?)')->execute([$club,'Fiktiver Beispielverein']);
$db->prepare("INSERT INTO actors(id,club_id,role,is_superuser) VALUES(?,?,'member',1)")->execute([$actor,$club]);
$reg=new Registry($db,$actor);$company=$reg->saveContact(null,0,'organisation','Mustertechnik GmbH','buero@example.invalid',"Beispielstraße 12\n12345 Musterort");
$xml=<<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2" xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2" xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2">
<cbc:ID>FIKTIV-2026-001</cbc:ID><cbc:IssueDate>2026-09-09</cbc:IssueDate><cbc:DueDate>2026-09-30</cbc:DueDate><cbc:InvoiceTypeCode>380</cbc:InvoiceTypeCode><cbc:DocumentCurrencyCode>EUR</cbc:DocumentCurrencyCode>
<cac:AccountingSupplierParty><cac:Party><cac:PartyName><cbc:Name>Mustertechnik GmbH</cbc:Name></cac:PartyName><cac:PostalAddress><cbc:StreetName>Beispielstraße 12</cbc:StreetName><cbc:CityName>Musterort</cbc:CityName><cbc:PostalZone>12345</cbc:PostalZone><cac:Country><cbc:IdentificationCode>DE</cbc:IdentificationCode></cac:Country></cac:PostalAddress><cac:Contact><cbc:ElectronicMail>buero@example.invalid</cbc:ElectronicMail></cac:Contact></cac:Party></cac:AccountingSupplierParty>
<cac:AccountingCustomerParty><cac:Party><cac:PartyName><cbc:Name>Fiktiver Beispielverein</cbc:Name></cac:PartyName></cac:Party></cac:AccountingCustomerParty>
<cac:TaxTotal><cbc:TaxAmount currencyID="EUR">19.00</cbc:TaxAmount><cac:TaxSubtotal><cbc:TaxableAmount currencyID="EUR">100.00</cbc:TaxableAmount><cbc:TaxAmount currencyID="EUR">19.00</cbc:TaxAmount><cac:TaxCategory><cbc:ID>S</cbc:ID><cbc:Percent>19</cbc:Percent><cac:TaxScheme><cbc:ID>VAT</cbc:ID></cac:TaxScheme></cac:TaxCategory></cac:TaxSubtotal></cac:TaxTotal>
<cac:LegalMonetaryTotal><cbc:LineExtensionAmount currencyID="EUR">100.00</cbc:LineExtensionAmount><cbc:TaxExclusiveAmount currencyID="EUR">100.00</cbc:TaxExclusiveAmount><cbc:TaxInclusiveAmount currencyID="EUR">119.00</cbc:TaxInclusiveAmount><cbc:PayableAmount currencyID="EUR">119.00</cbc:PayableAmount></cac:LegalMonetaryTotal>
<cac:InvoiceLine><cbc:ID>1</cbc:ID><cbc:InvoicedQuantity unitCode="C62">4</cbc:InvoicedQuantity><cbc:LineExtensionAmount currencyID="EUR">100.00</cbc:LineExtensionAmount><cac:Item><cbc:Name>Klappbank – erfundener Artikel</cbc:Name><cac:ClassifiedTaxCategory><cbc:ID>S</cbc:ID><cbc:Percent>19</cbc:Percent><cac:TaxScheme><cbc:ID>VAT</cbc:ID></cac:TaxScheme></cac:ClassifiedTaxCategory></cac:Item><cac:Price><cbc:PriceAmount currencyID="EUR">25.00</cbc:PriceAmount></cac:Price></cac:InvoiceLine>
</Invoice>
XML;
$document=(new Documents($db,$actor))->upload('fiktive-beispielrechnung.xml',$xml,['title'=>'Fiktive Rechnung · Mustertechnik','kind'=>'receipt','scope'=>'finance','document_date'=>'2026-09-09','links'=>[]],bin2hex(random_bytes(16)));
$peer=$club.'-peer';$person=$reg->saveContact(null,0,'person','Erika Beispiel','erika@example.invalid','');$db->prepare("INSERT INTO actors(id,club_id,role,contact_id) VALUES(?,?,'member',?)")->execute([$peer,$club,$person]);$chat=(new ClubChat($db,$actor))->create('Aufbau im Beispielsaal',[$peer]);$poll=(new ChatPolls($db,$actor))->create($chat,'Wann treffen wir uns zum Aufbau?',['Freitag um 16 Uhr','Samstag um 10 Uhr'],'',bin2hex(random_bytes(16)));(new ChatPolls($db,$peer))->vote($chat,$poll,2,0,bin2hex(random_bytes(16)));
session_name('KH_VEREIN_DEMO');session_save_path($memberTestSessions);session_id(bin2hex(random_bytes(20)));session_start();$_SESSION=['actor'=>$actor,'csrf'=>bin2hex(random_bytes(32))];$session=session_id();session_write_close();
$folder=$memberTestRoot.'/.local/tutorial-fixtures/'.$club;mkdir($folder,0700,true);$file=$folder.'/fixture.json';file_put_contents($file,json_encode(['base'=>$memberTestBase,'club'=>$club,'actor'=>$actor,'sessions'=>['admin'=>$session],'company'=>$company,'document'=>$document,'chat'=>$chat,'poll'=>$poll,'project_hash'=>hash('sha256',strtolower(str_replace('\\','/',$memberTestRoot)))],JSON_THROW_ON_ERROR));echo $file,"\n";
