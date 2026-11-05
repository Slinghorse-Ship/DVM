<?php declare(strict_types=1);
use Kulturhus\Verein\EventVouchers;
require_once __DIR__.'/../../src/Events/EventVoucherViewState.php';
$voucherViewState=$voucherViewState??\Kulturhus\Verein\EventVoucherViewState::prepare(new EventVouchers($db,(string)$_SESSION['actor'],$websiteCalendar??null),$_GET);
if($voucherViewState['personal']){require __DIR__.'/personal-vouchers.php';return;}
require_once __DIR__.'/../../src/Events/EventVoucherManageState.php';
$voucherManageState=$voucherManageState??\Kulturhus\Verein\EventVoucherManageState::prepare(new EventVouchers($db,(string)$_SESSION['actor'],$websiteCalendar??null),(string)$_SESSION['actor'],$_GET,$_SESSION,$voucherActionDraft??null,$voucherActionError??'',$voucherActionStatus??0);
require __DIR__.'/manage-vouchers.php';return;
