<?php
require_once(dirname(__FILE__) . '/../setup.php');
require_once(dirname(__FILE__) . '/mysql.inc.php');

$host = DB_HOST;
$user = DB_USER;
$password = DB_PASS;
$database = DB_NAME;
$charset = DB_CHARSET;

$sql_schema = CHAT_DIR . '/init/schema.mysql';
$sql_data = CHAT_DIR . '/init/data.mysql';

mysql_dump_schema($host, $user, $password, $database, $charset, $sql_schema);
mysql_dump_data($host, $user, $password, $database, $charset, $sql_data);

?>
