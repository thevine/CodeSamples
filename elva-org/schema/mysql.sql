##
 # Simple news db schema for Elva web-developer application
 # 
 # Author: Steve Neill <steve@steveneill.com>
 # Date: 10th February, 2016
 # 
 ##
 
CREATE TABLE `article` (
	`id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
	`date` BIGINT(20) UNSIGNED NOT NULL,
	`title_en` VARCHAR(100) NOT NULL DEFAULT '' COLLATE 'utf8_unicode_ci',
	`title_ge` VARCHAR(100) NOT NULL DEFAULT '' COLLATE 'utf8_unicode_ci',
	`text_en` TEXT NOT NULL COLLATE 'utf8_unicode_ci',
	`text_ge` TEXT NOT NULL COLLATE 'utf8_unicode_ci',
	PRIMARY KEY (`id`)
)
COLLATE='utf8_general_ci'
ENGINE=InnoDB;


CREATE TABLE `article_category` (
	`article_id` INT(11) NOT NULL DEFAULT '0',
	`category_id` INT(11) NOT NULL DEFAULT '0',
	PRIMARY KEY (`article_id`, `category_id`)
)
COLLATE='utf8_general_ci'
ENGINE=InnoDB;

