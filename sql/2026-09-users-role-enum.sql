-- Külön felhasználótípusok: user, civiluser, communityuser, govuser (nem azonosak!).
-- Kötelező futtatni, hogy a regisztráció és az admin szerepkör-változtatás működjön.
-- user = általános (bejelentést tehet), civiluser = civil esemény, communityuser = közület (profil + 1 buborék),
-- govuser = önkormányzat (saját dashboard, saját város).

ALTER TABLE users
  MODIFY COLUMN role ENUM('superadmin','admin','user','civil','civiluser','communityuser','govuser') NOT NULL DEFAULT 'user';
