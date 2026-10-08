CREATE TABLE IF NOT EXISTS users (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  email VARCHAR(254) NOT NULL,
  password_hash VARCHAR(255) NULL,
  role ENUM('participant', 'admin') NOT NULL DEFAULT 'participant',
  name VARCHAR(80) NOT NULL,
  identity_number VARCHAR(40) NOT NULL DEFAULT '',
  school VARCHAR(100) NOT NULL DEFAULT '',
  division_id BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY users_email_unique (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE users
  MODIFY password_hash VARCHAR(255) NULL;

INSERT INTO users (email, password_hash, name) VALUES
  ('shahibulfikri04@gmail.com', '$2y$10$m2OFax.D6QEqdPgbDjM3OOvru64w0HDcsnwhVPaZyOoKd/9qmwV4O', 'Shahibul Fikri'),
  ('nasywaalya906@gmail.com', '$2y$10$Hs2NWWuRfUyB4rE91V9AluamhLmuc08drWGfoeVzcuNgH7o1r1ONO', 'Nasywa Alya'),
  ('ayippennn@gmail.com', '$2y$10$2.Linl48bsmwbSbQ95ku.eV2L6ccQJFariVkx12YA2ttYrnUZuVEq', 'Ayip Pennn'),
  ('nazwa123@gmail.com', '$2y$10$0JeO2AQKf8d5bnbhA7NaQOqFP/Ze/5RoExfel1/7p4PPkattpXy9q', 'Nazwa')
ON DUPLICATE KEY UPDATE
  password_hash = VALUES(password_hash),
  name = VALUES(name);

INSERT INTO users (email, password_hash, role, name) VALUES
  ('admin@kesbangpol.bandung.go.id', '$2y$10$PkTO8mXaJ8wos/zxt4Q5tOqzhEJjMTxhCHJprb3AMg5j8uOOqXw5C', 'admin', 'Admin Kesbangpol')
ON DUPLICATE KEY UPDATE
  password_hash = VALUES(password_hash),
  role = 'admin',
  name = VALUES(name);

CREATE TABLE IF NOT EXISTS attendances (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  work_date DATE NOT NULL,
  check_in DATETIME NULL,
  check_out DATETIME NULL,
  attendance_status VARCHAR(16) NOT NULL DEFAULT 'present',
  attendance_note VARCHAR(255) NOT NULL DEFAULT '',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY attendance_user_date_unique (user_id, work_date),
  KEY attendance_work_date_index (work_date),
  CONSTRAINT attendance_user_fk
    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT attendance_checkout_after_checkin
    CHECK (check_out IS NULL OR check_out >= check_in)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS attendance_reminder_log (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  work_date DATE NOT NULL,
  reminder_type VARCHAR(32) NOT NULL,
  sent_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY attendance_reminder_unique (user_id, work_date, reminder_type),
  CONSTRAINT attendance_reminder_user_fk
    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS divisions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(80) NOT NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY divisions_name_unique (name),
  CONSTRAINT divisions_creator_fk
    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS divisions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(80) NOT NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY divisions_name_unique (name),
  CONSTRAINT divisions_creator_fk
    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS attendance_reminder_log (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  work_date DATE NOT NULL,
  reminder_type VARCHAR(32) NOT NULL,
  sent_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY attendance_reminder_unique (user_id, work_date, reminder_type),
  CONSTRAINT attendance_reminder_user_fk
    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS announcements (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  title VARCHAR(120) NOT NULL,
  body TEXT NOT NULL,
  division_id BIGINT UNSIGNED NOT NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY announcements_division_created_index (division_id, created_at),
  CONSTRAINT announcements_division_fk
    FOREIGN KEY (division_id) REFERENCES divisions (id) ON DELETE CASCADE,
  CONSTRAINT announcements_creator_fk
    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS activity_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  work_date DATE NOT NULL,
  activity TEXT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY activity_log_user_date_unique (user_id, work_date),
  KEY activity_log_work_date_index (work_date),
  CONSTRAINT activity_log_user_fk
    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE users
  ADD COLUMN role ENUM('participant', 'admin') NOT NULL DEFAULT 'participant' AFTER password_hash;

INSERT INTO users (email, password_hash, role, name) VALUES
  ('admin@kesbangpol.bandung.go.id', '$2y$10$PkTO8mXaJ8wos/zxt4Q5tOqzhEJjMTxhCHJprb3AMg5j8uOOqXw5C', 'admin', 'Admin Kesbangpol')
ON DUPLICATE KEY UPDATE
  password_hash = VALUES(password_hash),
  role = 'admin',
  name = VALUES(name);

CREATE TABLE IF NOT EXISTS announcements (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  title VARCHAR(120) NOT NULL,
  body TEXT NOT NULL,
  division_id BIGINT UNSIGNED NOT NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY announcements_division_created_index (division_id, created_at),
  CONSTRAINT announcements_division_fk
    FOREIGN KEY (division_id) REFERENCES divisions (id) ON DELETE CASCADE,
  CONSTRAINT announcements_creator_fk
    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS activity_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  work_date DATE NOT NULL,
  activity TEXT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY activity_log_user_date_unique (user_id, work_date),
  KEY activity_log_work_date_index (work_date),
  CONSTRAINT activity_log_user_fk
    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
