ALTER TABLE `lh_abstract_rest_api_key` ADD `scopes` varchar(2000) NOT NULL DEFAULT '', ADD `expires_at` varchar(20) NOT NULL DEFAULT '', ADD `last_used` bigint(20) NOT NULL DEFAULT 0;
