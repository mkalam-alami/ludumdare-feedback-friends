<?php

// TODO Exclude this file from web access with .htaccess

require_once('includes/init.php');

$db = db_connect();
scraping_run($db);
mysqli_close($db, 1); //DEBUG

?>