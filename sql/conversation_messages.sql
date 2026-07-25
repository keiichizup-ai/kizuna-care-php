CREATE TABLE IF NOT EXISTS conversation_messages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  person_id INT NOT NULL,
  role VARCHAR(30) NOT NULL,
  content TEXT NOT NULL,
  source VARCHAR(50) DEFAULT 'realtime',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_person_created (person_id, created_at)
);
