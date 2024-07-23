CREATE TABLE IF NOT EXISTS /*_*/kzchatbot_settings (
	kzcbs_name VARBINARY(32) PRIMARY KEY,
	kzcbs_value BLOB NOT NULL
) /*$wgDBTableOptions*/;
