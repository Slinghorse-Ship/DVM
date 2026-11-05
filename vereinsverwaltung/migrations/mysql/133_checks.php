<?php declare(strict_types=1);
return ['tax_filing_commands'=>[
 'kh133_tax_filing_operation'=>'CAST(operation AS BINARY) IN (\'profile\',\'create\',\'save\',\'validate\',\'evidence\')',
 'kh133_tax_filing_year'=>'tax_year BETWEEN 2000 AND 9999',
 'kh133_tax_filing_version'=>'filing_version>=0',
 'kh133_tax_filing_profile_version'=>'profile_version>=0',
 'kh133_tax_filing_key'=>'REGEXP_LIKE(request_key,\'^[a-f0-9]{32}$\',\'c\')',
 'kh133_tax_filing_input_hash'=>'REGEXP_LIKE(input_sha256,\'^[a-f0-9]{64}$\',\'c\')',
 'kh133_tax_filing_original_hash'=>'REGEXP_LIKE(original_sha256,\'^[a-f0-9]{64}$\',\'c\')',
 'kh133_tax_filing_input_json'=>'JSON_VALID(input_json)',
 'kh133_tax_filing_original_json'=>'JSON_VALID(original_json)',
 'kh133_tax_filing_before_pdf'=>"before_pdf IS NULL OR (LENGTH(before_pdf)<=8388608 AND LEFT(before_pdf,5)=_binary'%PDF-')",
 'kh133_tax_filing_pdf'=>'original_pdf IS NULL OR (LENGTH(original_pdf)<=8388608 AND LEFT(original_pdf,5)=_binary\'%PDF-\')',
]];
