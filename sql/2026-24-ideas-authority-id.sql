-- M3 Ötletek – hatóság (város) szerinti szűrés a gov oldalon.
-- ideas.authority_id: melyik hatóság területére esik az ötlet (bbox alapján az idea_create állítja).

ALTER TABLE ideas ADD COLUMN authority_id INT NULL;
CREATE INDEX idx_ideas_authority ON ideas (authority_id);
