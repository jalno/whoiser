CREATE TABLE `whoiser_domains` (
	`id` int(11) NOT NULL AUTO_INCREMENT,
	`name` varchar(127) NOT NULL,
	`tld` varchar(63) NOT NULL,
	`domain` varchar(255) NOT NULL,
	`is_registered` tinyint(1) NOT NULL,
	`statuses` varchar(255) DEFAULT NULL,
	`registrar` varchar(255) DEFAULT NULL,
	`nservers` varchar(255) DEFAULT NULL,
	`create_at` int(11) DEFAULT NULL,
	`change_at` int(11) DEFAULT NULL,
	`expire_at` int(11) DEFAULT NULL,
	`whois_at` int(11) NOT NULL,
	PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
