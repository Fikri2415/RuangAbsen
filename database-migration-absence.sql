ALTER TABLE attendances
  MODIFY check_in DATETIME NULL,
  ADD COLUMN attendance_status VARCHAR(16) NOT NULL DEFAULT 'present' AFTER check_out,
  ADD COLUMN attendance_note VARCHAR(255) NOT NULL DEFAULT '' AFTER attendance_status;
