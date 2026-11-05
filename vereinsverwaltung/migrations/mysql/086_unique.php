<?php declare(strict_types=1);
/** Review-only explicit whitelist. No DDL executes on include. Require every preflight count = 0.
 * INVISIBLE columns preserve INSERT table VALUES and SELECT * shape.
 * Bounds on widened live columns and existing stricter indexes require parent review.
 */
return array (
  'appointment_source_reference' => 
  array (
    'table' => 'appointments',
    'source_sql' => 'CREATE UNIQUE INDEX appointment_source_reference ON appointments(club_id,source_reference) WHERE source_reference!=\'\'',
    'predicate' => 'CAST(`source_reference` AS BINARY) <> \'\'',
    'parts' => 
    array (
      0 => 
      array (
        'name' => 'kh86_ui_e237c7263cf7_0',
        'expression' => 'CASE WHEN (CAST(`source_reference` AS BINARY) <> \'\') THEN CAST(`club_id` AS BINARY) ELSE NULL END',
        'max_bytes' => 512,
      ),
      1 => 
      array (
        'name' => 'kh86_ui_e237c7263cf7_1',
        'expression' => 'CASE WHEN (CAST(`source_reference` AS BINARY) <> \'\') THEN CAST(`source_reference` AS BINARY) ELSE NULL END',
        'max_bytes' => 1020,
      ),
    ),
    'index_name' => 'kh86_ui_e237c7263cf7_unique',
    'preflight' => 
    array (
      'oversize_rows' => 'SELECT COUNT(*) FROM `appointments` WHERE OCTET_LENGTH(CASE WHEN (CAST(`source_reference` AS BINARY) <> \'\') THEN CAST(`club_id` AS BINARY) ELSE NULL END) > 512 OR OCTET_LENGTH(CASE WHEN (CAST(`source_reference` AS BINARY) <> \'\') THEN CAST(`source_reference` AS BINARY) ELSE NULL END) > 1020',
      'duplicate_groups' => 'SELECT COUNT(*) FROM (SELECT 1 FROM `appointments` WHERE (CASE WHEN (CAST(`source_reference` AS BINARY) <> \'\') THEN CAST(`club_id` AS BINARY) ELSE NULL END) IS NOT NULL AND (CASE WHEN (CAST(`source_reference` AS BINARY) <> \'\') THEN CAST(`source_reference` AS BINARY) ELSE NULL END) IS NOT NULL GROUP BY CASE WHEN (CAST(`source_reference` AS BINARY) <> \'\') THEN CAST(`club_id` AS BINARY) ELSE NULL END,CASE WHEN (CAST(`source_reference` AS BINARY) <> \'\') THEN CAST(`source_reference` AS BINARY) ELSE NULL END HAVING COUNT(*) > 1) AS duplicate_keys',
    ),
    'add_sql' => 'ALTER TABLE `appointments` ADD CONSTRAINT `kh86_ui_e237c7263cf7_size` CHECK (NOT (OCTET_LENGTH(CASE WHEN (CAST(`source_reference` AS BINARY) <> \'\') THEN CAST(`club_id` AS BINARY) ELSE NULL END) > 512 OR OCTET_LENGTH(CASE WHEN (CAST(`source_reference` AS BINARY) <> \'\') THEN CAST(`source_reference` AS BINARY) ELSE NULL END) > 1020)), ADD COLUMN `kh86_ui_e237c7263cf7_0` VARBINARY(512) GENERATED ALWAYS AS (CASE WHEN (CAST(`source_reference` AS BINARY) <> \'\') THEN CAST(`club_id` AS BINARY) ELSE NULL END) VIRTUAL INVISIBLE, ADD COLUMN `kh86_ui_e237c7263cf7_1` VARBINARY(1020) GENERATED ALWAYS AS (CASE WHEN (CAST(`source_reference` AS BINARY) <> \'\') THEN CAST(`source_reference` AS BINARY) ELSE NULL END) VIRTUAL INVISIBLE, ADD UNIQUE INDEX `kh86_ui_e237c7263cf7_unique` (`kh86_ui_e237c7263cf7_0`,`kh86_ui_e237c7263cf7_1`)',
    'review_existing_unique' => NULL,
    'limits' => 'No hash, CAST length clamp or lossy prefix key. Binary keys preserve exact source equality, with explicit ASCII NOCASE including NUL-prefix/original-byte-length comparison. Enforced byte limits cover native85 bounded fields and reject, never truncate, larger future input; review if live columns have been widened beyond these limits.',
  ),
  'document_folder_siblings' => 
  array (
    'table' => 'document_folders',
    'source_sql' => 'CREATE UNIQUE INDEX document_folder_siblings ON document_folders(club_id,COALESCE(parent_id,\'\'),name COLLATE NOCASE)',
    'predicate' => '1=1',
    'parts' => 
    array (
      0 => 
      array (
        'name' => 'kh86_ui_0a4f3ead3637_0',
        'expression' => 'CASE WHEN (1=1) THEN CAST(`club_id` AS BINARY) ELSE NULL END',
        'max_bytes' => 512,
      ),
      1 => 
      array (
        'name' => 'kh86_ui_0a4f3ead3637_1',
        'expression' => 'CASE WHEN (1=1) THEN CAST(COALESCE(`parent_id`, \'\') AS BINARY) ELSE NULL END',
        'max_bytes' => 512,
      ),
      2 => 
      array (
        'name' => 'kh86_ui_0a4f3ead3637_2',
        'expression' => 'CASE WHEN (1=1) THEN CAST(CONCAT(CAST(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(SUBSTRING_INDEX(`name`, CHAR(0), 1), \'A\', \'a\'), \'B\', \'b\'), \'C\', \'c\'), \'D\', \'d\'), \'E\', \'e\'), \'F\', \'f\'), \'G\', \'g\'), \'H\', \'h\'), \'I\', \'i\'), \'J\', \'j\'), \'K\', \'k\'), \'L\', \'l\'), \'M\', \'m\'), \'N\', \'n\'), \'O\', \'o\'), \'P\', \'p\'), \'Q\', \'q\'), \'R\', \'r\'), \'S\', \'s\'), \'T\', \'t\'), \'U\', \'u\'), \'V\', \'v\'), \'W\', \'w\'), \'X\', \'x\'), \'Y\', \'y\'), \'Z\', \'z\') AS BINARY), 0x00, UNHEX(LPAD(HEX(OCTET_LENGTH(`name`)), 16, \'0\'))) AS BINARY) ELSE NULL END',
        'max_bytes' => 1029,
      ),
    ),
    'index_name' => 'kh86_ui_0a4f3ead3637_unique',
    'preflight' => 
    array (
      'oversize_rows' => 'SELECT COUNT(*) FROM `document_folders` WHERE OCTET_LENGTH(CASE WHEN (1=1) THEN CAST(`club_id` AS BINARY) ELSE NULL END) > 512 OR OCTET_LENGTH(CASE WHEN (1=1) THEN CAST(COALESCE(`parent_id`, \'\') AS BINARY) ELSE NULL END) > 512 OR OCTET_LENGTH(CASE WHEN (1=1) THEN CAST(CONCAT(CAST(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(SUBSTRING_INDEX(`name`, CHAR(0), 1), \'A\', \'a\'), \'B\', \'b\'), \'C\', \'c\'), \'D\', \'d\'), \'E\', \'e\'), \'F\', \'f\'), \'G\', \'g\'), \'H\', \'h\'), \'I\', \'i\'), \'J\', \'j\'), \'K\', \'k\'), \'L\', \'l\'), \'M\', \'m\'), \'N\', \'n\'), \'O\', \'o\'), \'P\', \'p\'), \'Q\', \'q\'), \'R\', \'r\'), \'S\', \'s\'), \'T\', \'t\'), \'U\', \'u\'), \'V\', \'v\'), \'W\', \'w\'), \'X\', \'x\'), \'Y\', \'y\'), \'Z\', \'z\') AS BINARY), 0x00, UNHEX(LPAD(HEX(OCTET_LENGTH(`name`)), 16, \'0\'))) AS BINARY) ELSE NULL END) > 1029',
      'duplicate_groups' => 'SELECT COUNT(*) FROM (SELECT 1 FROM `document_folders` WHERE (CASE WHEN (1=1) THEN CAST(`club_id` AS BINARY) ELSE NULL END) IS NOT NULL AND (CASE WHEN (1=1) THEN CAST(COALESCE(`parent_id`, \'\') AS BINARY) ELSE NULL END) IS NOT NULL AND (CASE WHEN (1=1) THEN CAST(CONCAT(CAST(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(SUBSTRING_INDEX(`name`, CHAR(0), 1), \'A\', \'a\'), \'B\', \'b\'), \'C\', \'c\'), \'D\', \'d\'), \'E\', \'e\'), \'F\', \'f\'), \'G\', \'g\'), \'H\', \'h\'), \'I\', \'i\'), \'J\', \'j\'), \'K\', \'k\'), \'L\', \'l\'), \'M\', \'m\'), \'N\', \'n\'), \'O\', \'o\'), \'P\', \'p\'), \'Q\', \'q\'), \'R\', \'r\'), \'S\', \'s\'), \'T\', \'t\'), \'U\', \'u\'), \'V\', \'v\'), \'W\', \'w\'), \'X\', \'x\'), \'Y\', \'y\'), \'Z\', \'z\') AS BINARY), 0x00, UNHEX(LPAD(HEX(OCTET_LENGTH(`name`)), 16, \'0\'))) AS BINARY) ELSE NULL END) IS NOT NULL GROUP BY CASE WHEN (1=1) THEN CAST(`club_id` AS BINARY) ELSE NULL END,CASE WHEN (1=1) THEN CAST(COALESCE(`parent_id`, \'\') AS BINARY) ELSE NULL END,CASE WHEN (1=1) THEN CAST(CONCAT(CAST(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(SUBSTRING_INDEX(`name`, CHAR(0), 1), \'A\', \'a\'), \'B\', \'b\'), \'C\', \'c\'), \'D\', \'d\'), \'E\', \'e\'), \'F\', \'f\'), \'G\', \'g\'), \'H\', \'h\'), \'I\', \'i\'), \'J\', \'j\'), \'K\', \'k\'), \'L\', \'l\'), \'M\', \'m\'), \'N\', \'n\'), \'O\', \'o\'), \'P\', \'p\'), \'Q\', \'q\'), \'R\', \'r\'), \'S\', \'s\'), \'T\', \'t\'), \'U\', \'u\'), \'V\', \'v\'), \'W\', \'w\'), \'X\', \'x\'), \'Y\', \'y\'), \'Z\', \'z\') AS BINARY), 0x00, UNHEX(LPAD(HEX(OCTET_LENGTH(`name`)), 16, \'0\'))) AS BINARY) ELSE NULL END HAVING COUNT(*) > 1) AS duplicate_keys',
    ),
    'add_sql' => 'ALTER TABLE `document_folders` ADD CONSTRAINT `kh86_ui_0a4f3ead3637_size` CHECK (NOT (OCTET_LENGTH(CASE WHEN (1=1) THEN CAST(`club_id` AS BINARY) ELSE NULL END) > 512 OR OCTET_LENGTH(CASE WHEN (1=1) THEN CAST(COALESCE(`parent_id`, \'\') AS BINARY) ELSE NULL END) > 512 OR OCTET_LENGTH(CASE WHEN (1=1) THEN CAST(CONCAT(CAST(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(SUBSTRING_INDEX(`name`, CHAR(0), 1), \'A\', \'a\'), \'B\', \'b\'), \'C\', \'c\'), \'D\', \'d\'), \'E\', \'e\'), \'F\', \'f\'), \'G\', \'g\'), \'H\', \'h\'), \'I\', \'i\'), \'J\', \'j\'), \'K\', \'k\'), \'L\', \'l\'), \'M\', \'m\'), \'N\', \'n\'), \'O\', \'o\'), \'P\', \'p\'), \'Q\', \'q\'), \'R\', \'r\'), \'S\', \'s\'), \'T\', \'t\'), \'U\', \'u\'), \'V\', \'v\'), \'W\', \'w\'), \'X\', \'x\'), \'Y\', \'y\'), \'Z\', \'z\') AS BINARY), 0x00, UNHEX(LPAD(HEX(OCTET_LENGTH(`name`)), 16, \'0\'))) AS BINARY) ELSE NULL END) > 1029)), ADD COLUMN `kh86_ui_0a4f3ead3637_0` VARBINARY(512) GENERATED ALWAYS AS (CASE WHEN (1=1) THEN CAST(`club_id` AS BINARY) ELSE NULL END) VIRTUAL INVISIBLE, ADD COLUMN `kh86_ui_0a4f3ead3637_1` VARBINARY(512) GENERATED ALWAYS AS (CASE WHEN (1=1) THEN CAST(COALESCE(`parent_id`, \'\') AS BINARY) ELSE NULL END) VIRTUAL INVISIBLE, ADD COLUMN `kh86_ui_0a4f3ead3637_2` VARBINARY(1029) GENERATED ALWAYS AS (CASE WHEN (1=1) THEN CAST(CONCAT(CAST(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(SUBSTRING_INDEX(`name`, CHAR(0), 1), \'A\', \'a\'), \'B\', \'b\'), \'C\', \'c\'), \'D\', \'d\'), \'E\', \'e\'), \'F\', \'f\'), \'G\', \'g\'), \'H\', \'h\'), \'I\', \'i\'), \'J\', \'j\'), \'K\', \'k\'), \'L\', \'l\'), \'M\', \'m\'), \'N\', \'n\'), \'O\', \'o\'), \'P\', \'p\'), \'Q\', \'q\'), \'R\', \'r\'), \'S\', \'s\'), \'T\', \'t\'), \'U\', \'u\'), \'V\', \'v\'), \'W\', \'w\'), \'X\', \'x\'), \'Y\', \'y\'), \'Z\', \'z\') AS BINARY), 0x00, UNHEX(LPAD(HEX(OCTET_LENGTH(`name`)), 16, \'0\'))) AS BINARY) ELSE NULL END) VIRTUAL INVISIBLE, ADD UNIQUE INDEX `kh86_ui_0a4f3ead3637_unique` (`kh86_ui_0a4f3ead3637_0`,`kh86_ui_0a4f3ead3637_1`,`kh86_ui_0a4f3ead3637_2`)',
    'review_existing_unique' => 'Native document_folder_siblings loses COALESCE(NULL,empty) and uses Unicode CI/PAD SPACE. New index closes NULL-parent hole; old index can still reject source-valid non-ASCII variants until separately reviewed replacement.',
    'limits' => 'No hash, CAST length clamp or lossy prefix key. Binary keys preserve exact source equality, with explicit ASCII NOCASE including NUL-prefix/original-byte-length comparison. Enforced byte limits cover native85 bounded fields and reject, never truncate, larger future input; review if live columns have been widened beyond these limits.',
  ),
  'inventory_cash_account' => 
  array (
    'table' => 'inventory_items',
    'source_sql' => 'CREATE UNIQUE INDEX inventory_cash_account ON inventory_items(club_id,cash_account) WHERE cash_account IS NOT NULL',
    'predicate' => '`cash_account` IS NOT NULL',
    'parts' => 
    array (
      0 => 
      array (
        'name' => 'kh86_ui_95bff4a9f20a_0',
        'expression' => 'CASE WHEN (`cash_account` IS NOT NULL) THEN CAST(`club_id` AS BINARY) ELSE NULL END',
        'max_bytes' => 512,
      ),
      1 => 
      array (
        'name' => 'kh86_ui_95bff4a9f20a_1',
        'expression' => 'CASE WHEN (`cash_account` IS NOT NULL) THEN CAST(`cash_account` AS BINARY) ELSE NULL END',
        'max_bytes' => 512,
      ),
    ),
    'index_name' => 'kh86_ui_95bff4a9f20a_unique',
    'preflight' => 
    array (
      'oversize_rows' => 'SELECT COUNT(*) FROM `inventory_items` WHERE OCTET_LENGTH(CASE WHEN (`cash_account` IS NOT NULL) THEN CAST(`club_id` AS BINARY) ELSE NULL END) > 512 OR OCTET_LENGTH(CASE WHEN (`cash_account` IS NOT NULL) THEN CAST(`cash_account` AS BINARY) ELSE NULL END) > 512',
      'duplicate_groups' => 'SELECT COUNT(*) FROM (SELECT 1 FROM `inventory_items` WHERE (CASE WHEN (`cash_account` IS NOT NULL) THEN CAST(`club_id` AS BINARY) ELSE NULL END) IS NOT NULL AND (CASE WHEN (`cash_account` IS NOT NULL) THEN CAST(`cash_account` AS BINARY) ELSE NULL END) IS NOT NULL GROUP BY CASE WHEN (`cash_account` IS NOT NULL) THEN CAST(`club_id` AS BINARY) ELSE NULL END,CASE WHEN (`cash_account` IS NOT NULL) THEN CAST(`cash_account` AS BINARY) ELSE NULL END HAVING COUNT(*) > 1) AS duplicate_keys',
    ),
    'add_sql' => 'ALTER TABLE `inventory_items` ADD CONSTRAINT `kh86_ui_95bff4a9f20a_size` CHECK (NOT (OCTET_LENGTH(CASE WHEN (`cash_account` IS NOT NULL) THEN CAST(`club_id` AS BINARY) ELSE NULL END) > 512 OR OCTET_LENGTH(CASE WHEN (`cash_account` IS NOT NULL) THEN CAST(`cash_account` AS BINARY) ELSE NULL END) > 512)), ADD COLUMN `kh86_ui_95bff4a9f20a_0` VARBINARY(512) GENERATED ALWAYS AS (CASE WHEN (`cash_account` IS NOT NULL) THEN CAST(`club_id` AS BINARY) ELSE NULL END) VIRTUAL INVISIBLE, ADD COLUMN `kh86_ui_95bff4a9f20a_1` VARBINARY(512) GENERATED ALWAYS AS (CASE WHEN (`cash_account` IS NOT NULL) THEN CAST(`cash_account` AS BINARY) ELSE NULL END) VIRTUAL INVISIBLE, ADD UNIQUE INDEX `kh86_ui_95bff4a9f20a_unique` (`kh86_ui_95bff4a9f20a_0`,`kh86_ui_95bff4a9f20a_1`)',
    'review_existing_unique' => NULL,
    'limits' => 'No hash, CAST length clamp or lossy prefix key. Binary keys preserve exact source equality, with explicit ASCII NOCASE including NUL-prefix/original-byte-length comparison. Enforced byte limits cover native85 bounded fields and reject, never truncate, larger future input; review if live columns have been widened beyond these limits.',
  ),
  'inventory_individual_serial' => 
  array (
    'table' => 'inventory_items',
    'source_sql' => 'CREATE UNIQUE INDEX inventory_individual_serial ON inventory_items(club_id,serial_number COLLATE NOCASE) WHERE tracking_mode=\'individual\' AND serial_number<>\'\'',
    'predicate' => 'CAST(`tracking_mode` AS BINARY) = \'individual\' AND CAST(`serial_number` AS BINARY) <> \'\'',
    'parts' => 
    array (
      0 => 
      array (
        'name' => 'kh86_ui_e3cdedbcde56_0',
        'expression' => 'CASE WHEN (CAST(`tracking_mode` AS BINARY) = \'individual\' AND CAST(`serial_number` AS BINARY) <> \'\') THEN CAST(`club_id` AS BINARY) ELSE NULL END',
        'max_bytes' => 512,
      ),
      1 => 
      array (
        'name' => 'kh86_ui_e3cdedbcde56_1',
        'expression' => 'CASE WHEN (CAST(`tracking_mode` AS BINARY) = \'individual\' AND CAST(`serial_number` AS BINARY) <> \'\') THEN CAST(CONCAT(CAST(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(SUBSTRING_INDEX(`serial_number`, CHAR(0), 1), \'A\', \'a\'), \'B\', \'b\'), \'C\', \'c\'), \'D\', \'d\'), \'E\', \'e\'), \'F\', \'f\'), \'G\', \'g\'), \'H\', \'h\'), \'I\', \'i\'), \'J\', \'j\'), \'K\', \'k\'), \'L\', \'l\'), \'M\', \'m\'), \'N\', \'n\'), \'O\', \'o\'), \'P\', \'p\'), \'Q\', \'q\'), \'R\', \'r\'), \'S\', \'s\'), \'T\', \'t\'), \'U\', \'u\'), \'V\', \'v\'), \'W\', \'w\'), \'X\', \'x\'), \'Y\', \'y\'), \'Z\', \'z\') AS BINARY), 0x00, UNHEX(LPAD(HEX(OCTET_LENGTH(`serial_number`)), 16, \'0\'))) AS BINARY) ELSE NULL END',
        'max_bytes' => 1029,
      ),
    ),
    'index_name' => 'kh86_ui_e3cdedbcde56_unique',
    'preflight' => 
    array (
      'oversize_rows' => 'SELECT COUNT(*) FROM `inventory_items` WHERE OCTET_LENGTH(CASE WHEN (CAST(`tracking_mode` AS BINARY) = \'individual\' AND CAST(`serial_number` AS BINARY) <> \'\') THEN CAST(`club_id` AS BINARY) ELSE NULL END) > 512 OR OCTET_LENGTH(CASE WHEN (CAST(`tracking_mode` AS BINARY) = \'individual\' AND CAST(`serial_number` AS BINARY) <> \'\') THEN CAST(CONCAT(CAST(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(SUBSTRING_INDEX(`serial_number`, CHAR(0), 1), \'A\', \'a\'), \'B\', \'b\'), \'C\', \'c\'), \'D\', \'d\'), \'E\', \'e\'), \'F\', \'f\'), \'G\', \'g\'), \'H\', \'h\'), \'I\', \'i\'), \'J\', \'j\'), \'K\', \'k\'), \'L\', \'l\'), \'M\', \'m\'), \'N\', \'n\'), \'O\', \'o\'), \'P\', \'p\'), \'Q\', \'q\'), \'R\', \'r\'), \'S\', \'s\'), \'T\', \'t\'), \'U\', \'u\'), \'V\', \'v\'), \'W\', \'w\'), \'X\', \'x\'), \'Y\', \'y\'), \'Z\', \'z\') AS BINARY), 0x00, UNHEX(LPAD(HEX(OCTET_LENGTH(`serial_number`)), 16, \'0\'))) AS BINARY) ELSE NULL END) > 1029',
      'duplicate_groups' => 'SELECT COUNT(*) FROM (SELECT 1 FROM `inventory_items` WHERE (CASE WHEN (CAST(`tracking_mode` AS BINARY) = \'individual\' AND CAST(`serial_number` AS BINARY) <> \'\') THEN CAST(`club_id` AS BINARY) ELSE NULL END) IS NOT NULL AND (CASE WHEN (CAST(`tracking_mode` AS BINARY) = \'individual\' AND CAST(`serial_number` AS BINARY) <> \'\') THEN CAST(CONCAT(CAST(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(SUBSTRING_INDEX(`serial_number`, CHAR(0), 1), \'A\', \'a\'), \'B\', \'b\'), \'C\', \'c\'), \'D\', \'d\'), \'E\', \'e\'), \'F\', \'f\'), \'G\', \'g\'), \'H\', \'h\'), \'I\', \'i\'), \'J\', \'j\'), \'K\', \'k\'), \'L\', \'l\'), \'M\', \'m\'), \'N\', \'n\'), \'O\', \'o\'), \'P\', \'p\'), \'Q\', \'q\'), \'R\', \'r\'), \'S\', \'s\'), \'T\', \'t\'), \'U\', \'u\'), \'V\', \'v\'), \'W\', \'w\'), \'X\', \'x\'), \'Y\', \'y\'), \'Z\', \'z\') AS BINARY), 0x00, UNHEX(LPAD(HEX(OCTET_LENGTH(`serial_number`)), 16, \'0\'))) AS BINARY) ELSE NULL END) IS NOT NULL GROUP BY CASE WHEN (CAST(`tracking_mode` AS BINARY) = \'individual\' AND CAST(`serial_number` AS BINARY) <> \'\') THEN CAST(`club_id` AS BINARY) ELSE NULL END,CASE WHEN (CAST(`tracking_mode` AS BINARY) = \'individual\' AND CAST(`serial_number` AS BINARY) <> \'\') THEN CAST(CONCAT(CAST(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(SUBSTRING_INDEX(`serial_number`, CHAR(0), 1), \'A\', \'a\'), \'B\', \'b\'), \'C\', \'c\'), \'D\', \'d\'), \'E\', \'e\'), \'F\', \'f\'), \'G\', \'g\'), \'H\', \'h\'), \'I\', \'i\'), \'J\', \'j\'), \'K\', \'k\'), \'L\', \'l\'), \'M\', \'m\'), \'N\', \'n\'), \'O\', \'o\'), \'P\', \'p\'), \'Q\', \'q\'), \'R\', \'r\'), \'S\', \'s\'), \'T\', \'t\'), \'U\', \'u\'), \'V\', \'v\'), \'W\', \'w\'), \'X\', \'x\'), \'Y\', \'y\'), \'Z\', \'z\') AS BINARY), 0x00, UNHEX(LPAD(HEX(OCTET_LENGTH(`serial_number`)), 16, \'0\'))) AS BINARY) ELSE NULL END HAVING COUNT(*) > 1) AS duplicate_keys',
    ),
    'add_sql' => 'ALTER TABLE `inventory_items` ADD CONSTRAINT `kh86_ui_e3cdedbcde56_size` CHECK (NOT (OCTET_LENGTH(CASE WHEN (CAST(`tracking_mode` AS BINARY) = \'individual\' AND CAST(`serial_number` AS BINARY) <> \'\') THEN CAST(`club_id` AS BINARY) ELSE NULL END) > 512 OR OCTET_LENGTH(CASE WHEN (CAST(`tracking_mode` AS BINARY) = \'individual\' AND CAST(`serial_number` AS BINARY) <> \'\') THEN CAST(CONCAT(CAST(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(SUBSTRING_INDEX(`serial_number`, CHAR(0), 1), \'A\', \'a\'), \'B\', \'b\'), \'C\', \'c\'), \'D\', \'d\'), \'E\', \'e\'), \'F\', \'f\'), \'G\', \'g\'), \'H\', \'h\'), \'I\', \'i\'), \'J\', \'j\'), \'K\', \'k\'), \'L\', \'l\'), \'M\', \'m\'), \'N\', \'n\'), \'O\', \'o\'), \'P\', \'p\'), \'Q\', \'q\'), \'R\', \'r\'), \'S\', \'s\'), \'T\', \'t\'), \'U\', \'u\'), \'V\', \'v\'), \'W\', \'w\'), \'X\', \'x\'), \'Y\', \'y\'), \'Z\', \'z\') AS BINARY), 0x00, UNHEX(LPAD(HEX(OCTET_LENGTH(`serial_number`)), 16, \'0\'))) AS BINARY) ELSE NULL END) > 1029)), ADD COLUMN `kh86_ui_e3cdedbcde56_0` VARBINARY(512) GENERATED ALWAYS AS (CASE WHEN (CAST(`tracking_mode` AS BINARY) = \'individual\' AND CAST(`serial_number` AS BINARY) <> \'\') THEN CAST(`club_id` AS BINARY) ELSE NULL END) VIRTUAL INVISIBLE, ADD COLUMN `kh86_ui_e3cdedbcde56_1` VARBINARY(1029) GENERATED ALWAYS AS (CASE WHEN (CAST(`tracking_mode` AS BINARY) = \'individual\' AND CAST(`serial_number` AS BINARY) <> \'\') THEN CAST(CONCAT(CAST(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(SUBSTRING_INDEX(`serial_number`, CHAR(0), 1), \'A\', \'a\'), \'B\', \'b\'), \'C\', \'c\'), \'D\', \'d\'), \'E\', \'e\'), \'F\', \'f\'), \'G\', \'g\'), \'H\', \'h\'), \'I\', \'i\'), \'J\', \'j\'), \'K\', \'k\'), \'L\', \'l\'), \'M\', \'m\'), \'N\', \'n\'), \'O\', \'o\'), \'P\', \'p\'), \'Q\', \'q\'), \'R\', \'r\'), \'S\', \'s\'), \'T\', \'t\'), \'U\', \'u\'), \'V\', \'v\'), \'W\', \'w\'), \'X\', \'x\'), \'Y\', \'y\'), \'Z\', \'z\') AS BINARY), 0x00, UNHEX(LPAD(HEX(OCTET_LENGTH(`serial_number`)), 16, \'0\'))) AS BINARY) ELSE NULL END) VIRTUAL INVISIBLE, ADD UNIQUE INDEX `kh86_ui_e3cdedbcde56_unique` (`kh86_ui_e3cdedbcde56_0`,`kh86_ui_e3cdedbcde56_1`)',
    'review_existing_unique' => NULL,
    'limits' => 'No hash, CAST length clamp or lossy prefix key. Binary keys preserve exact source equality, with explicit ASCII NOCASE including NUL-prefix/original-byte-length comparison. Enforced byte limits cover native85 bounded fields and reject, never truncate, larger future input; review if live columns have been widened beyond these limits.',
  ),
  'member_change_one_pending' => 
  array (
    'table' => 'member_change_requests',
    'source_sql' => 'CREATE UNIQUE INDEX member_change_one_pending ON member_change_requests(club_id,contact_id) WHERE state=\'pending\'',
    'predicate' => 'CAST(`state` AS BINARY) = \'pending\'',
    'parts' => 
    array (
      0 => 
      array (
        'name' => 'kh86_ui_e7f5963bfeb2_0',
        'expression' => 'CASE WHEN (CAST(`state` AS BINARY) = \'pending\') THEN CAST(`club_id` AS BINARY) ELSE NULL END',
        'max_bytes' => 512,
      ),
      1 => 
      array (
        'name' => 'kh86_ui_e7f5963bfeb2_1',
        'expression' => 'CASE WHEN (CAST(`state` AS BINARY) = \'pending\') THEN CAST(`contact_id` AS BINARY) ELSE NULL END',
        'max_bytes' => 512,
      ),
    ),
    'index_name' => 'kh86_ui_e7f5963bfeb2_unique',
    'preflight' => 
    array (
      'oversize_rows' => 'SELECT COUNT(*) FROM `member_change_requests` WHERE OCTET_LENGTH(CASE WHEN (CAST(`state` AS BINARY) = \'pending\') THEN CAST(`club_id` AS BINARY) ELSE NULL END) > 512 OR OCTET_LENGTH(CASE WHEN (CAST(`state` AS BINARY) = \'pending\') THEN CAST(`contact_id` AS BINARY) ELSE NULL END) > 512',
      'duplicate_groups' => 'SELECT COUNT(*) FROM (SELECT 1 FROM `member_change_requests` WHERE (CASE WHEN (CAST(`state` AS BINARY) = \'pending\') THEN CAST(`club_id` AS BINARY) ELSE NULL END) IS NOT NULL AND (CASE WHEN (CAST(`state` AS BINARY) = \'pending\') THEN CAST(`contact_id` AS BINARY) ELSE NULL END) IS NOT NULL GROUP BY CASE WHEN (CAST(`state` AS BINARY) = \'pending\') THEN CAST(`club_id` AS BINARY) ELSE NULL END,CASE WHEN (CAST(`state` AS BINARY) = \'pending\') THEN CAST(`contact_id` AS BINARY) ELSE NULL END HAVING COUNT(*) > 1) AS duplicate_keys',
    ),
    'add_sql' => 'ALTER TABLE `member_change_requests` ADD CONSTRAINT `kh86_ui_e7f5963bfeb2_size` CHECK (NOT (OCTET_LENGTH(CASE WHEN (CAST(`state` AS BINARY) = \'pending\') THEN CAST(`club_id` AS BINARY) ELSE NULL END) > 512 OR OCTET_LENGTH(CASE WHEN (CAST(`state` AS BINARY) = \'pending\') THEN CAST(`contact_id` AS BINARY) ELSE NULL END) > 512)), ADD COLUMN `kh86_ui_e7f5963bfeb2_0` VARBINARY(512) GENERATED ALWAYS AS (CASE WHEN (CAST(`state` AS BINARY) = \'pending\') THEN CAST(`club_id` AS BINARY) ELSE NULL END) VIRTUAL INVISIBLE, ADD COLUMN `kh86_ui_e7f5963bfeb2_1` VARBINARY(512) GENERATED ALWAYS AS (CASE WHEN (CAST(`state` AS BINARY) = \'pending\') THEN CAST(`contact_id` AS BINARY) ELSE NULL END) VIRTUAL INVISIBLE, ADD UNIQUE INDEX `kh86_ui_e7f5963bfeb2_unique` (`kh86_ui_e7f5963bfeb2_0`,`kh86_ui_e7f5963bfeb2_1`)',
    'review_existing_unique' => NULL,
    'limits' => 'No hash, CAST length clamp or lossy prefix key. Binary keys preserve exact source equality, with explicit ASCII NOCASE including NUL-prefix/original-byte-length comparison. Enforced byte limits cover native85 bounded fields and reject, never truncate, larger future input; review if live columns have been widened beyond these limits.',
  ),
  'sepa_active_entry' => 
  array (
    'table' => 'sepa_order_items',
    'source_sql' => 'CREATE UNIQUE INDEX sepa_active_entry ON sepa_order_items(club_id,entry_id) WHERE state=\'active\'',
    'predicate' => 'CAST(`state` AS BINARY) = \'active\'',
    'parts' => 
    array (
      0 => 
      array (
        'name' => 'kh86_ui_0c317315e7db_0',
        'expression' => 'CASE WHEN (CAST(`state` AS BINARY) = \'active\') THEN CAST(`club_id` AS BINARY) ELSE NULL END',
        'max_bytes' => 512,
      ),
      1 => 
      array (
        'name' => 'kh86_ui_0c317315e7db_1',
        'expression' => 'CASE WHEN (CAST(`state` AS BINARY) = \'active\') THEN CAST(`entry_id` AS BINARY) ELSE NULL END',
        'max_bytes' => 512,
      ),
    ),
    'index_name' => 'kh86_ui_0c317315e7db_unique',
    'preflight' => 
    array (
      'oversize_rows' => 'SELECT COUNT(*) FROM `sepa_order_items` WHERE OCTET_LENGTH(CASE WHEN (CAST(`state` AS BINARY) = \'active\') THEN CAST(`club_id` AS BINARY) ELSE NULL END) > 512 OR OCTET_LENGTH(CASE WHEN (CAST(`state` AS BINARY) = \'active\') THEN CAST(`entry_id` AS BINARY) ELSE NULL END) > 512',
      'duplicate_groups' => 'SELECT COUNT(*) FROM (SELECT 1 FROM `sepa_order_items` WHERE (CASE WHEN (CAST(`state` AS BINARY) = \'active\') THEN CAST(`club_id` AS BINARY) ELSE NULL END) IS NOT NULL AND (CASE WHEN (CAST(`state` AS BINARY) = \'active\') THEN CAST(`entry_id` AS BINARY) ELSE NULL END) IS NOT NULL GROUP BY CASE WHEN (CAST(`state` AS BINARY) = \'active\') THEN CAST(`club_id` AS BINARY) ELSE NULL END,CASE WHEN (CAST(`state` AS BINARY) = \'active\') THEN CAST(`entry_id` AS BINARY) ELSE NULL END HAVING COUNT(*) > 1) AS duplicate_keys',
    ),
    'add_sql' => 'ALTER TABLE `sepa_order_items` ADD CONSTRAINT `kh86_ui_0c317315e7db_size` CHECK (NOT (OCTET_LENGTH(CASE WHEN (CAST(`state` AS BINARY) = \'active\') THEN CAST(`club_id` AS BINARY) ELSE NULL END) > 512 OR OCTET_LENGTH(CASE WHEN (CAST(`state` AS BINARY) = \'active\') THEN CAST(`entry_id` AS BINARY) ELSE NULL END) > 512)), ADD COLUMN `kh86_ui_0c317315e7db_0` VARBINARY(512) GENERATED ALWAYS AS (CASE WHEN (CAST(`state` AS BINARY) = \'active\') THEN CAST(`club_id` AS BINARY) ELSE NULL END) VIRTUAL INVISIBLE, ADD COLUMN `kh86_ui_0c317315e7db_1` VARBINARY(512) GENERATED ALWAYS AS (CASE WHEN (CAST(`state` AS BINARY) = \'active\') THEN CAST(`entry_id` AS BINARY) ELSE NULL END) VIRTUAL INVISIBLE, ADD UNIQUE INDEX `kh86_ui_0c317315e7db_unique` (`kh86_ui_0c317315e7db_0`,`kh86_ui_0c317315e7db_1`)',
    'review_existing_unique' => NULL,
    'limits' => 'No hash, CAST length clamp or lossy prefix key. Binary keys preserve exact source equality, with explicit ASCII NOCASE including NUL-prefix/original-byte-length comparison. Enforced byte limits cover native85 bounded fields and reject, never truncate, larger future input; review if live columns have been widened beyond these limits.',
  ),
);
