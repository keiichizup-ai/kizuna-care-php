CREATE DATABASE IF NOT EXISTS kizuna_care_db
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE kizuna_care_db;

CREATE TABLE IF NOT EXISTS conversation_people (
  id INT AUTO_INCREMENT PRIMARY KEY,
  display_name VARCHAR(64) NOT NULL,
  memo TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS conversation_messages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  person_id INT NOT NULL,
  role ENUM('user', 'assistant') NOT NULL,
  content TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_person_created_at (person_id, created_at),
  CONSTRAINT fk_messages_person
    FOREIGN KEY (person_id)
    REFERENCES conversation_people(id)
    ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS conversation_summaries (
  id INT AUTO_INCREMENT PRIMARY KEY,
  person_id INT NOT NULL,
  period_type ENUM('day', 'week', 'month') NOT NULL,
  period_start DATE NOT NULL,
  period_end DATE NOT NULL,
  summary TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_summary (person_id, period_type, period_start, period_end),
  CONSTRAINT fk_summaries_person
    FOREIGN KEY (person_id)
    REFERENCES conversation_people(id)
    ON DELETE CASCADE
);

INSERT INTO conversation_people (id, display_name, memo)
VALUES (1, 'お母さん', '初期デモ用の会話者')
ON DUPLICATE KEY UPDATE display_name = display_name;

