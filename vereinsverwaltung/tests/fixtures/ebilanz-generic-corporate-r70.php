<?php declare(strict_types=1);
// True original cost journal and dated whole/SGEB area allocation, entirely synthetic.
if(!in_array($argv[1]??'',['08000','08090','08200'],true))throw new DomainException('Synthetic original corporate holding only.');
unset($argv[5]);
if(str_starts_with($argv[3]??'','period'))$argv[6]='shared';
require __DIR__.'/ebilanz-cost-combined.php';
