CREATE TABLE IF NOT EXISTS /*_*/kzchatbot_users (
	kzcbu_uuid VARBINARY(32) NOT NULL PRIMARY KEY,
	kzcbu_ip_address VARBINARY(16) NOT NULL,
	kzcbu_is_shown INTEGER UNSIGNED NOT NULL,
	kzcbu_cookie_expiry BINARY(14) NOT NULL,
	kzcbu_last_active BINARY(14) NOT NULL,
	kzcbu_questions_last_active_day INTEGER,
	kzcbu_ranking_eligible_answer_id VARBINARY(32)
) /*$wgDBTableOptions*/;

CREATE INDEX /*i*/kzcbu_cookie_expiry ON /*_*/kzchatbot_users (kzcbu_cookie_expiry);
CREATE INDEX /*i*/kzcbu_last_active ON /*_*/kzchatbot_users (kzcbu_last_active);
