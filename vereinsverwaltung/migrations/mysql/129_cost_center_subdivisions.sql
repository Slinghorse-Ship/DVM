-- Allow the exact KOST1 main areas and their supported subdivisions.
-- One atomic ALTER; safe to repeat after DDL succeeded before its version marker.
ALTER TABLE accounting_positions
 DROP CHECK `kh86_ck_7dac600d5d80e0bbec6b47e1`,
 ADD CONSTRAINT `kh86_ck_7dac600d5d80e0bbec6b47e1` CHECK (CAST(`sphere` AS BINARY) IN ('', '1', '2', '3', '4', '9', '11', '12', '13', '14', '15', '16', '17', '18', '19', '21', '22', '23', '24', '25', '26', '27', '28', '29', '31', '32', '33', '34', '35', '36', '37', '38', '39', '41', '42', '43', '44', '45', '46', '47', '48', '49'));
