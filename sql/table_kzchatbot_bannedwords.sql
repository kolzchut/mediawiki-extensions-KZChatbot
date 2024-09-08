CREATE TABLE IF NOT EXISTS /*_*/kzchatbot_bannedwords (
	kzcbb_id int NOT NULL PRIMARY KEY AUTO_INCREMENT,
	kzcbb_pattern varchar(255) binary NOT NULL,
	kzcbb_description BLOB
) /*$wgDBTableOptions*/;
