-- Bővítsd a users.role ENUM-ot (ha jelenleg csak 'superadmin','admin','user','civil' van).
-- Így az adminban megjelenhet: civiluser, govuser, communityuser is.

ALTER TABLE users
  MODIFY COLUMN role ENUM('superadmin','admin','user','civil','civiluser','communityuser','govuser') NOT NULL DEFAULT 'user';
