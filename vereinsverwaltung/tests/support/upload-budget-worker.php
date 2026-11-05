<?php declare(strict_types=1);
require dirname(__DIR__,2).'/src/Infrastructure/Media/LiveUploads.php';
try{\Kulturhus\Verein\LiveUploads::bytes($argv[1],10485760);throw new RuntimeException('Own oversize source unexpectedly accepted.');}
catch(DomainException){echo json_encode(['rejected'=>true,'peak_memory_bytes'=>memory_get_peak_usage(true)],JSON_THROW_ON_ERROR).PHP_EOL;}
