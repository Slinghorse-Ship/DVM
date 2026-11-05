<?php declare(strict_types=1);
return ['tax_auto_requests'=>[
 'kh132_tax_auto_operation'=>"CAST(operation AS BINARY) IN ('draft','filing')",
 'kh132_tax_auto_year'=>'tax_year BETWEEN 2000 AND 9999',
 'kh132_tax_auto_case_version'=>'case_version>=0',
 'kh132_tax_auto_profile_version'=>'profile_version>=0',
 'kh132_tax_auto_key'=>"REGEXP_LIKE(request_key,'^[a-f0-9]{32}$','c')",
 'kh132_tax_auto_input_hash'=>"REGEXP_LIKE(input_sha256,'^[a-f0-9]{64}$','c')",
 'kh132_tax_auto_original_hash'=>"REGEXP_LIKE(original_sha256,'^[a-f0-9]{64}$','c')",
 'kh132_tax_auto_input_json'=>'JSON_VALID(input_json)',
 'kh132_tax_auto_original_json'=>'JSON_VALID(original_json)',
]];
