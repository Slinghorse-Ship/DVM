<?php declare(strict_types=1);
return ['balance_filing_commands'=>[
 'kh134_balance_filing_operation'=>'CAST(operation AS BINARY) IN (\'create\',\'generate\',\'save\',\'partnership\',\'equalization\',\'financial-reserve\',\'financial-reserve-withdraw\',\'basis-6b\',\'basis-7g\',\'special-7b\',\'special-7g\',\'pension-offsets\',\'pool-original\',\'own-intangible\',\'approve\',\'revision\',\'capital\',\'adjust\',\'carry\',\'asset\',\'validate\')',
 'kh134_balance_filing_year'=>'tax_year BETWEEN 2000 AND 9999',
 'kh134_balance_filing_version'=>'filing_version>=1',
 'kh134_balance_filing_key'=>'REGEXP_LIKE(request_key,\'^[a-f0-9]{32}$\',\'c\')',
 'kh134_balance_filing_input_hash'=>'REGEXP_LIKE(input_sha256,\'^[a-f0-9]{64}$\',\'c\')',
 'kh134_balance_filing_original_hash'=>'REGEXP_LIKE(original_sha256,\'^[a-f0-9]{64}$\',\'c\')',
 'kh134_balance_filing_input_json'=>'JSON_VALID(input_json)',
 'kh134_balance_filing_original_json'=>'JSON_VALID(original_json)',
]];
