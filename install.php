<?php

require_once('includes/init.php');

function read_version($db) {
	$current_version = read_setting($db, 'current_version', 0);
	echo 'Current version is '.$current_version.'<br />';
	return $current_version;
}

function write_version($db, $version) {
	write_setting($db, 'current_version', $version);
	echo 'Upgraded version to '.$version.'!<br />';
	return $version;
}

$db = db_connect();
$current_version = read_version($db);

if ($current_version < LDFF_VERSION) {

	$target_version = 1;
	if ($current_version < $target_version) {
		mysqli_query($db, "CREATE TABLE `entry` (
			`uid` INT NOT NULL ,
			`author` VARCHAR(255) NOT NULL ,
			`title` VARCHAR(255) NOT NULL ,
			`platforms` VARCHAR(255) NOT NULL ,
			`picture` VARCHAR(255) ,
			`comments_given` INT NOT NULL ,
			`comments_received` INT NOT NULL , 
			`coolness` INT NOT NULL , 
			`timestamp` DATE NOT NULL , 
			PRIMARY KEY (`uid`),
			INDEX `comments_given_index` (`comments_given`),
			INDEX `comments_received_index` (`comments_received`), 
			INDEX `coolness` (`coolness`), 
			FULLTEXT INDEX `index_platforms` (`platforms`) , 
			FULLTEXT INDEX `index_full` (`author`, `title`, `platforms`)
			) ENGINE = InnoDB") or die("Failed to create entry table");
		mysqli_query($db, "CREATE TABLE `comment` (
			`uid_author` INT NOT NULL ,
			`uid_entry` INT NOT NULL ,
			`order` INT NOT NULL , 
			`comment` VARCHAR(8192) NOT NULL , 
			`score` INT NOT NULL , 
			INDEX `uid_author_index` (`uid_author`) , 
			INDEX `uid_entry_index` (`uid_entry`)
			) ENGINE = InnoDB") or die("Failed to create comment table");
		mysqli_query($db, "CREATE TABLE `setting` (
			`id` VARCHAR(255) NOT NULL , 
			`value` VARCHAR(1024) NOT NULL , 
			PRIMARY KEY (`id`)
			) ENGINE = InnoDB") or die("Failed to create setting table");
		
		$current_version = write_version($db, $target_version);
	}
}
else {
	echo 'Nothing to upgrade.';
}

mysqli_close($db);

?>