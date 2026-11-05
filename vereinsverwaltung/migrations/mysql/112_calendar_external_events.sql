ALTER TABLE appointments
    DROP CHECK kh86_ck_5308a485e5cf94a32a2a63a8,
    ADD CONSTRAINT kh112_activity_kind CHECK (
        CAST(activity_kind AS BINARY) IN ('event','external','assembly','board','other')
    );
