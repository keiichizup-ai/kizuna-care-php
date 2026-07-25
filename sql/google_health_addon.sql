CREATE TABLE IF NOT EXISTS health_connections (
  id INT AUTO_INCREMENT PRIMARY KEY,
  person_id INT NOT NULL,
  provider VARCHAR(50) NOT NULL DEFAULT 'google_health',
  google_health_user_id VARCHAR(255) NULL,
  fitbit_legacy_user_id VARCHAR(255) NULL,
  access_token_enc TEXT NULL,
  refresh_token_enc TEXT NULL,
  token_expires_at DATETIME NULL,
  scopes TEXT NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'active',
  last_synced_at DATETIME NULL,
  last_error TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_person_provider (person_id, provider),
  INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS health_daily_summaries (
  id INT AUTO_INCREMENT PRIMARY KEY,
  person_id INT NOT NULL,
  summary_date DATE NOT NULL,
  steps INT NULL,
  sleep_minutes INT NULL,
  resting_heart_rate INT NULL,
  avg_heart_rate INT NULL,
  min_heart_rate INT NULL,
  max_heart_rate INT NULL,
  hrv_value FLOAT NULL,
  spo2_avg FLOAT NULL,
  respiratory_rate FLOAT NULL,
  distance_meters INT NULL,
  raw_json JSON NULL,
  source VARCHAR(50) NOT NULL DEFAULT 'google_health',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_person_date (person_id, summary_date),
  INDEX idx_person_date (person_id, summary_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS health_exercise_sessions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  person_id INT NOT NULL,
  external_id VARCHAR(255) NOT NULL,
  exercise_type VARCHAR(100) NULL,
  display_name VARCHAR(255) NULL,
  started_at DATETIME NULL,
  ended_at DATETIME NULL,
  duration_seconds INT NULL,
  steps INT NULL,
  distance_meters INT NULL,
  avg_heart_rate INT NULL,
  has_gps TINYINT(1) NOT NULL DEFAULT 0,
  raw_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_external (external_id),
  INDEX idx_person_started (person_id, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS health_gps_route_points (
  id INT AUTO_INCREMENT PRIMARY KEY,
  exercise_session_id INT NOT NULL,
  latitude DECIMAL(10,7) NOT NULL,
  longitude DECIMAL(10,7) NOT NULL,
  altitude_meters FLOAT NULL,
  recorded_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_session_time (exercise_session_id, recorded_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS health_ai_summaries (
  id INT AUTO_INCREMENT PRIMARY KEY,
  person_id INT NOT NULL,
  summary_date DATE NOT NULL,
  summary_text TEXT NOT NULL,
  raw_prompt TEXT NULL,
  model VARCHAR(100) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_person_date (person_id, summary_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
