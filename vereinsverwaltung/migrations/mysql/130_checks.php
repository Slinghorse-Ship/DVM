<?php declare(strict_types=1);
return ['finance_reimbursement_actions'=>[
 'kh130_reimbursement_action_kind'=>"CAST(action AS BINARY) IN ('create','approve','associate')",
 'kh130_reimbursement_action_keys'=>"REGEXP_LIKE(id,'^[a-f0-9]{32}$','c') AND REGEXP_LIKE(request_key,'^[a-f0-9]{32}$','c')",
 'kh130_reimbursement_action_originals'=>"JSON_VALID(request_json) AND JSON_TYPE(request_json)='OBJECT' AND JSON_VALID(review_json) AND JSON_TYPE(review_json)='OBJECT' AND JSON_VALID(result_json) AND JSON_TYPE(result_json)='OBJECT' AND CAST(request_sha256 AS BINARY)=CAST(SHA2(request_json,256) AS BINARY) AND CAST(review_sha256 AS BINARY)=CAST(SHA2(review_json,256) AS BINARY) AND CAST(result_sha256 AS BINARY)=CAST(SHA2(result_json,256) AS BINARY)",
]];
