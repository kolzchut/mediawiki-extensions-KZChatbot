CREATE TABLE IF NOT EXISTS /*_*/kzchatbot_text (
	kzcbt_slug VARBINARY(32) PRIMARY KEY,
	kzcbt_text BLOB NOT NULL,
	kzcbt_desc varbinary(255)
) /*$wgDBTableOptions*/;
