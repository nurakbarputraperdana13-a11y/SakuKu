-- SakuKu MVP schema
-- MySQL 8.x, utf8mb4. All money columns use BIGINT UNSIGNED (Rupiah, whole number).

CREATE DATABASE IF NOT EXISTS saku_ku
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE saku_ku;

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS expense_splits;
DROP TABLE IF EXISTS group_expenses;
DROP TABLE IF EXISTS group_members;
DROP TABLE IF EXISTS `groups`;
DROP TABLE IF EXISTS savings_goals;
DROP TABLE IF EXISTS budgets;
DROP TABLE IF EXISTS transactions;
DROP TABLE IF EXISTS categories;
DROP TABLE IF EXISTS security_answer_attempts;
DROP TABLE IF EXISTS users;

SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE users (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(100) NOT NULL,
  email VARCHAR(100) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('admin', 'user') NOT NULL DEFAULT 'user',
  status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
  security_question VARCHAR(255) NOT NULL,
  security_answer_hash VARCHAR(255) NOT NULL,
  session_version INT UNSIGNED NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE security_answer_attempts (
  email_hash CHAR(64) NOT NULL,
  ip_hash CHAR(64) NOT NULL,
  attempt_count SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  window_start TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (email_hash, ip_hash),
  KEY idx_security_attempts_window (window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE categories (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NULL DEFAULT NULL,
  name VARCHAR(50) NOT NULL,
  type ENUM('income', 'expense') NOT NULL,
  icon VARCHAR(50) NOT NULL DEFAULT 'circle',
  PRIMARY KEY (id),
  KEY idx_categories_user_type (user_id, type),
  CONSTRAINT fk_categories_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE transactions (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  category_id INT UNSIGNED NOT NULL,
  type ENUM('income', 'expense') NOT NULL,
  amount BIGINT UNSIGNED NOT NULL,
  transaction_date DATE NOT NULL,
  note VARCHAR(255) NULL DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_transactions_user_date (user_id, transaction_date),
  KEY idx_transactions_user_category (user_id, category_id),
  CONSTRAINT fk_transactions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_transactions_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE budgets (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  category_id INT UNSIGNED NOT NULL,
  amount_limit BIGINT UNSIGNED NOT NULL,
  month_year CHAR(7) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_budget_user_category_month (user_id, category_id, month_year),
  CONSTRAINT fk_budgets_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_budgets_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE savings_goals (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  title VARCHAR(100) NOT NULL,
  target_amount BIGINT UNSIGNED NOT NULL,
  current_amount BIGINT UNSIGNED NOT NULL DEFAULT 0,
  deadline DATE NULL DEFAULT NULL,
  status ENUM('active', 'completed') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_savings_user_status (user_id, status),
  CONSTRAINT fk_savings_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `groups` (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(100) NOT NULL,
  description VARCHAR(255) NULL DEFAULT NULL,
  invite_code VARCHAR(20) NOT NULL,
  created_by INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_groups_invite_code (invite_code),
  CONSTRAINT fk_groups_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE group_members (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  group_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  role ENUM('admin', 'member') NOT NULL DEFAULT 'member',
  joined_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_group_member (group_id, user_id),
  KEY idx_group_members_user (user_id),
  CONSTRAINT fk_group_members_group FOREIGN KEY (group_id) REFERENCES `groups`(id) ON DELETE CASCADE,
  CONSTRAINT fk_group_members_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE group_expenses (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  group_id INT UNSIGNED NOT NULL,
  paid_by_user_id INT UNSIGNED NOT NULL,
  title VARCHAR(100) NOT NULL,
  total_amount BIGINT UNSIGNED NOT NULL,
  expense_date DATE NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_group_expenses_group_date (group_id, expense_date),
  CONSTRAINT fk_group_expenses_group FOREIGN KEY (group_id) REFERENCES `groups`(id) ON DELETE CASCADE,
  CONSTRAINT fk_group_expenses_payer FOREIGN KEY (paid_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE expense_splits (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  group_expense_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  owed_amount BIGINT UNSIGNED NOT NULL,
  is_settled TINYINT(1) NOT NULL DEFAULT 0,
  settled_at TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_expense_split (group_expense_id, user_id),
  KEY idx_expense_splits_user_settled (user_id, is_settled),
  CONSTRAINT fk_expense_splits_expense FOREIGN KEY (group_expense_id) REFERENCES group_expenses(id) ON DELETE CASCADE,
  CONSTRAINT fk_expense_splits_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Default categories: user_id NULL means available to every user.
INSERT INTO categories (user_id, name, type, icon) VALUES
  (NULL, 'Makan & Minum',      'expense', 'utensils'),
  (NULL, 'Kos & Utilitas',     'expense', 'home'),
  (NULL, 'Transportasi',       'expense', 'bus'),
  (NULL, 'Akademik / Tugas',   'expense', 'book'),
  (NULL, 'Hiburan',            'expense', 'gamepad'),
  (NULL, 'Kesehatan',          'expense', 'heart'),
  (NULL, 'Lainnya',            'expense', 'dots'),
  (NULL, 'Kiriman Orang Tua',  'income',  'gift'),
  (NULL, 'Beasiswa',           'income',  'award'),
  (NULL, 'Gaji / Freelance',   'income',  'briefcase'),
  (NULL, 'Pemasukan Lainnya',  'income',  'dots');

-- Initial system administrator. Password: admin123. Security answer: SakuKu.
-- Change both credentials immediately after first login on any deployed instance.
INSERT INTO users (name, email, password_hash, role, status, security_question, security_answer_hash)
VALUES (
  'Administrator SakuKu',
  'admin@sakuku.id',
  '$2y$12$fe2W4QQQqUrBKIcB2KYNp.FccvvWCWi1.kNKuRUNnbTBoj0sFfoDu',
  'admin',
  'active',
  'Apa nama aplikasi ini?',
  '$2y$12$Y9bJop5AcoPgn4srkWWbAe31cGF3jJXUYJltSe2YyojaEVI3HfsRi'
);
