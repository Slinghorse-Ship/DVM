<?php declare(strict_types=1);
require __DIR__.'/ebilanz-period-club.php';
require_once __DIR__.'/../../src/Finance/Balance/BalancePeriodCombinedValues.php';
function periodCombinedFixture(string $kind='book'):array
{return \Kulturhus\Verein\BalancePeriodCombinedValues::fromPartial(periodClubFixture($kind));}
if(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__)echo json_encode(periodCombinedFixture($argv[1]??'book'),JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
