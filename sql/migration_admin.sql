-- SakuKu admin module migration.
-- Run once against the existing database. Does not delete existing data.

ALTER TABLE users
  ADD COLUMN role ENUM('admin', 'user') NOT NULL DEFAULT 'user' AFTER password_hash,
  ADD COLUMN status ENUM('active', 'inactive') NOT NULL DEFAULT 'active' AFTER role;

-- Initial system administrator. Password: admin123. Security answer: SakuKu.
-- Change both credentials immediately after first login on any deployed instance.
INSERT INTO users (name, email, password_hash, role, status, security_question, security_answer_hash)
SELECT
  'Administrator SakuKu',
  'admin@sakuku.id',
  '$2y$12$fe2W4QQQqUrBKIcB2KYNp.FccvvWCWi1.kNKuRUNnbTBoj0sFfoDu',
  'admin',
  'active',
  'Apa nama aplikasi ini?',
  '$2y$12$Y9bJop5AcoPgn4srkWWbAe31cGF3jJXUYJltSe2YyojaEVI3HfsRi'
WHERE NOT EXISTS (SELECT 1 FROM users WHERE email = 'admin@sakuku.id');
