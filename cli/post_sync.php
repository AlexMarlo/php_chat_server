<?php

$dir = dirname(__FILE__);

echo "Post syncing...\n";
//include('migrate.php');

`rm -rf $dir/../var/compiled`;
`rm -rf $dir/../var/locators`;
`rm -f $dir/../var/db_info*`;

echo "done.\n";

?>
