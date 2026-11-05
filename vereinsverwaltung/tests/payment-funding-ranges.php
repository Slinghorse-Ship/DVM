<?php declare(strict_types=1);
require_once dirname(__DIR__).'/src/Finance/Accounting/PaymentFundingRanges.php';
use Kulturhus\Verein\PaymentFundingRanges as R;
$checks=0;$check=static function(bool$ok,string$label)use(&$checks):void{if(!$ok)throw new RuntimeException($label);++$checks;echo "PASS $label\n";};$deny=static function(callable$run,string$label)use($check):void{try{$run();}catch(DomainException){$check(true,$label);return;}throw new RuntimeException('Accepted: '.$label);};

$first=R::take([[0,100]],40,100,true);$check($first===['taken'=>[[60,100]],'remaining'=>[[0,60]]],'actual forty-cent return retains exactly the original first sixty cents');
$replacement=R::fill($first['remaining'],40,100);$check($replacement===[[60,100]],'actual forty-cent recollection fills precisely the returned original cents');
$second=R::take($first['remaining'],20,100,true);$occupied=R::restore($second['remaining'],$replacement,100);$check($second['taken']===[[40,60]]&&$occupied===[[0,40],[60,100]],'another return removes its original twenty cents without moving the later payment');
$deny(fn()=>R::restore($occupied,$first['taken'],100),'original return cannot be revoked over the already assigned actual replacement');
$check(R::fill($occupied,20,100)===[[40,60]],'next actual twenty-cent recollection fills the remaining original hole');
$otherHole=[[0,40],[60,80]];$deny(fn()=>R::restore($otherHole,[[60,80]],100),'free total capacity elsewhere never permits restoring overlapping original cents');
$check(R::restore($otherHole,[[40,60]],100)===[[0,80]],'revocation restores its own still-vacant exact cents');

$fragmented=R::take([[0,10],[20,30],[40,50]],25,50);$check($fragmented['taken']===[[0,10],[20,30],[40,45]]&&$fragmented['remaining']===[[45,50]],'a new allocation records every actual separate vacant cent interval');
$refund=R::take($fragmented['taken'],12,50,true);$check($refund['taken']===[[23,30],[40,45]]&&$refund['remaining']===[[0,10],[20,23]],'partial return of fragmented funding retains exact original cent identities');
$check(R::cents($refund['taken'],50)===12&&R::cents($refund['remaining'],50)===13,'fragmented funding and actual returned amount reconcile to the complete original twenty-five');
$deny(fn()=>R::subtract([[0,10],[20,30]],[[5,25]],30),'return cannot silently cross cents funded by another original payment');
$deny(fn()=>R::subtract([[10,20]],[[0,5]],20),'return cannot consume a preceding unrelated payment');
$deny(fn()=>R::subtract([[0,10]],[[20,30]],30),'return cannot consume a later unrelated payment');
$check(R::normalize([[20,30],[0,10],[10,20]],30)===[[0,30]],'canonical interval order merges only directly adjacent funding');
$deny(fn()=>R::normalize([[0,20],[10,30]],30),'overlapping original funding is rejected rather than rounded or replaced');
$deny(fn()=>R::take([[0,10]],11,20),'actual return cannot exceed its exact source capacity');
$deny(fn()=>R::fill([[0,10]],11,20),'actual new allocation cannot exceed remaining receipt capacity');
$deny(fn()=>R::take([[0,10]],0,20),'zero money never creates a returned or new funding interval');
foreach([[[0,1.0]],[[0,'1']],[[0,0]],[[-1,1]],[[0,31]]]as$invalid)$deny(fn()=>R::normalize($invalid,30),'invalid cent intervals do not become valid money implicitly');
$huge=R::take([[0,R::MAX_CENTS]],33333333333,R::MAX_CENTS,true);$check($huge['taken']===[[66666666667,100000000000]]&&R::cents($huge['remaining'],R::MAX_CENTS)===66666666667,'maximum supported original amounts retain exact cents without floats or overflow');
$occupied=[];for($i=0;$i<40;$i++)$occupied[]=[$i*4,$i*4+2];$filled=R::fill($occupied,50,160);$check(count($filled)===25&&R::cents($filled,160)===50&&R::cents(R::restore($occupied,$filled,160),160)===130,'many original partial sources preserve all separate holes and exact new allocation capacity');
echo json_encode(['suite'=>'payment-funding-ranges','checks'=>$checks,'external_actions'=>0],JSON_THROW_ON_ERROR)."\n";
