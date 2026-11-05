ALTER TABLE balance_period_sources
 DROP CHECK balance_period_source_type,
 ADD CONSTRAINT balance_period_source_type CHECK(source_type IN ('invoice','transaction','depreciation'));
