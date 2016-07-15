<html>
<head>
 <!-- <meta http-equiv="refresh" content="1">-->
</head>
<body>

<?php

// TODO Password-protect

require_once('includes/init.php');

set_time_limit(LDFF_SCRAPING_TIMEOUT + 30);

$db = db_connect();

echo '<pre>';
print_r(scraping_run($db));
//print_r(http_fetch_uids($db));

mysqli_close($db);

?>

</body>
</html>