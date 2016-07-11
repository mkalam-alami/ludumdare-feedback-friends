<html>
<head>
  <!--<meta http-equiv="refresh" content="5">-->
</head>
<body>

<?php

// TODO Exclude this file from web access with .htaccess

require_once('includes/init.php');

$db = db_connect();

echo '<pre>';
print_r(scraping_run($db, 1));

mysqli_close($db);

?>

</body>
</html>