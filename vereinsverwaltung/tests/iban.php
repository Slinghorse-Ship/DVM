<?php declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';
use Kulturhus\Verein\{Iban,InvoiceSupplierContacts,SettingsSchema,MemberBankVault};
$n=0;function ok($v,$m){global $n;if(!$v)throw new RuntimeException($m);++$n;}
function rejects($f,$m){try{$f();}catch(DomainException){ok(true,$m);return;}throw new RuntimeException($m);}
$valid='DE89370400440532013000';
ok(Iban::normalize('')==='','optional blank');
ok(Iban::normalize(" de89\u{00A0}3704\t0044\u{202F}0532 0130 00 ")===$valid,'Unicode spaces normalized');
ok(Iban::normalize('GB82 WEST 1234 5698 7654 32')==='GB82WEST12345698765432','foreign alphanumeric account');
foreach(['DE'=>18,'AT'=>16] as $country=>$length){
 $bban='A'.str_repeat('0',$length-1);$numeric=preg_replace_callback('/[A-Z]/',fn($m)=>(string)(ord($m[0])-55),$bban.$country.'00');$mod=0;foreach(str_split($numeric) as $digit)$mod=($mod*10+(int)$digit)%97;
 $wrongStructure=$country.str_pad((string)(98-$mod),2,'0',STR_PAD_LEFT).$bban;
 rejects(fn()=>Iban::normalize($wrongStructure),'valid checksum cannot hide wrong national structure');
}
foreach(['DE89370400440532013001','DE8937040044053201300','DE893704004405320130000','ZZ89370400440532013000','US89370400440532013000','DΕ89370400440532013000','DE89-370400440532013000',"DE89\u{200B}370400440532013000", "\xff"] as $bad)rejects(fn()=>Iban::normalize($bad),'bad IBAN rejected');
ok(InvoiceSupplierContacts::bankFields(['iban'=>'de89 3704 0044 0532 0130 00'])['iban']===$valid,'supplier uses shared validation');
rejects(fn()=>InvoiceSupplierContacts::bankFields(['iban'=>'DE893704004405320130000']),'supplier country length');
$bank=SettingsSchema::defaults('bank');$bank['iban']='de89 3704 0044 0532 0130 00';
ok(SettingsSchema::normalize('bank',$bank)['iban']===$valid,'account settings normalize');
$bank['iban']='US89370400440532013000';rejects(fn()=>SettingsSchema::normalize('bank',$bank),'account settings country');
ok(MemberBankVault::canonicalPayload('save',['holder'=>'Fictional Owner','bic'=>'','iban'=>'de89 3704 0044 0532 0130 00'])['iban']===$valid,'member canonical payload shared validation');
rejects(fn()=>MemberBankVault::canonicalPayload('save',['holder'=>'Fictional Owner','bic'=>'','iban'=>'DE893704004405320130000']),'member country length');
echo "$n IBAN checks passed\n";
