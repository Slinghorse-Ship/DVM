<?php declare(strict_types=1);
require __DIR__.'/balance-period-disposal-ledger.php';
require_once __DIR__.'/../src/Finance/Balance/BalancePeriodAreas.php';
require __DIR__.'/fixtures/period-area-asset-chain.php';
$base=$n;$n=0;
verifyAssetAreaChain($db,'disposal-admin',$l,[$pid,$p2],'1');
check(!\Kulturhus\Verein\Tests\MySqlFixture::foreignKeyErrors($db),'disposal activity chain remains tenant bound');
echo "$n dated disposal activity checks passed plus $base original disposal checks; synthetic MySQL only\n";
