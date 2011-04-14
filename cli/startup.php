<?php

include(dirname(__FILE__) . '/load.php');
include(dirname(__FILE__) . '/migrate.php');

$all_tabels = mysql_get_tables($host, $user, $password, $database);
$protected_tabels = array(
  'admin_navigation_item',
  'admin_user',
  'schema_info',
);

mysql_truncate_tables($host, $user, $password, $database, array_diff($all_tabels, $protected_tabels));
?>
