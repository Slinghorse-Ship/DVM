-- Extend only command kinds; all first requests, claims, and payment evidence remain unchanged.
ALTER TABLE finance_reimbursement_actions
 DROP CHECK kh130_reimbursement_action_kind,
 ADD CONSTRAINT kh130_reimbursement_action_kind CHECK(CAST(action AS BINARY) IN ('create','approve','associate','correct','cancel','reopen'));
