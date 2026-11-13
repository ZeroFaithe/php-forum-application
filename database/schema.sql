-- Nested Forum Lab: schema and fictional seed data
-- Target: MySQL 8 or MariaDB 10.4+ (InnoDB, utf8mb4).
-- Run:  mysql -u root < database/schema.sql
--
-- All timestamps are stored in UTC. The application sets the connection
-- time zone to +00:00, so CURRENT_TIMESTAMP is written as UTC.

CREATE DATABASE IF NOT EXISTS nested_forum
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE nested_forum;

SET time_zone = '+00:00';

DROP TABLE IF EXISTS comments;
DROP TABLE IF EXISTS discussions;
DROP TABLE IF EXISTS users;

CREATE TABLE users (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  username      VARCHAR(30)  NOT NULL,
  email         VARCHAR(254) NOT NULL,
  -- NULL means "cannot log in" (used by the fictional seed users).
  -- The application never authenticates a NULL hash.
  password_hash VARCHAR(255) NULL,
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_username (username),
  UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB;

CREATE TABLE discussions (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id    INT UNSIGNED NOT NULL,
  title      VARCHAR(200) NOT NULL,
  body       TEXT         NOT NULL,
  created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_discussions_user (user_id),
  CONSTRAINT fk_discussions_user
    FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB;

CREATE TABLE comments (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  discussion_id INT UNSIGNED NOT NULL,
  -- NULL = top-level comment (an edge from the discussion root).
  parent_id     INT UNSIGNED NULL,
  user_id       INT UNSIGNED NOT NULL,
  body          TEXT         NOT NULL,
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  -- Target for the composite self-reference below.
  UNIQUE KEY uq_comments_discussion_id (discussion_id, id),
  KEY ix_comments_parent (discussion_id, parent_id),
  KEY ix_comments_user (user_id),
  CONSTRAINT fk_comments_discussion
    FOREIGN KEY (discussion_id) REFERENCES discussions (id) ON DELETE CASCADE,
  CONSTRAINT fk_comments_user
    FOREIGN KEY (user_id) REFERENCES users (id),
  -- A reply's parent must be a comment in the SAME discussion.
  -- When parent_id is NULL the constraint is not checked (MATCH SIMPLE).
  -- This does not prevent cycles; the write handler prevents them by only
  -- linking to an already-existing parent and never updating parent_id.
  CONSTRAINT fk_comments_parent_same_discussion
    FOREIGN KEY (discussion_id, parent_id) REFERENCES comments (discussion_id, id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------
-- Fictional seed data. Seed users have NULL password hashes and cannot log in;
-- register a new account through the app to post.
-- ---------------------------------------------------------------------------

INSERT INTO users (id, username, email, password_hash, created_at) VALUES
  (1, 'Alice', 'alice@example.test', NULL, '2026-01-05 08:00:00'),
  (2, 'Ben',   'ben@example.test',   NULL, '2026-01-05 08:05:00'),
  (3, 'Cara',  'cara@example.test',  NULL, '2026-01-05 08:10:00'),
  (4, 'Dee',   'dee@example.test',   NULL, '2026-01-05 08:15:00'),
  (5, 'Eli',   'eli@example.test',   NULL, '2026-01-05 08:20:00');

INSERT INTO discussions (id, user_id, title, body, created_at) VALUES
  (1, 1, 'How do I learn PHP?', 'Share your learning tips.', '2026-01-06 09:00:00'),
  (2, 4, 'Favourite SQL resources?', 'Books, sites or videos that helped you.', '2026-01-07 10:00:00');

-- Discussion 1 matches the lab's reply tree. Comment 7 reaches displayed depth 4.
--   1 Alice (d0) -> 2 Ben (d1) -> 3 Cara (d2) -> 6 Ben (d3) -> 7 Eli (d4)
--   1 Alice (d0) -> 4 Dee (d1)
--   5 Eli (d0)
INSERT INTO comments (id, discussion_id, parent_id, user_id, body, created_at) VALUES
  (1, 1, NULL, 1, 'Start with the PHP manual.',    '2026-01-06 09:10:00'),
  (2, 1, 1,    2, 'Try the examples as you read.', '2026-01-06 09:20:00'),
  (3, 1, 2,    3, 'Build a small project too.',    '2026-01-06 09:30:00'),
  (4, 1, 1,    4, 'Which manual section first?',   '2026-01-06 09:40:00'),
  (5, 1, NULL, 5, 'Practice SQL as well.',         '2026-01-06 09:50:00'),
  (6, 1, 3,    2, 'Start with a forum.',           '2026-01-06 10:00:00'),
  (7, 1, 6,    5, 'Add replies next.',             '2026-01-06 10:10:00'),
  -- Discussion 2: used to test that a reply cannot use a parent from discussion 1.
  (8, 2, NULL, 3, 'SQLBolt is a good interactive start.', '2026-01-07 10:15:00');

ALTER TABLE users       AUTO_INCREMENT = 6;
ALTER TABLE discussions AUTO_INCREMENT = 3;
ALTER TABLE comments    AUTO_INCREMENT = 9;
