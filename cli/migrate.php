<?php
require_once(dirname(__FILE__) . '/../setup.php');
require_once(lmb_env_get('PROJECT_DIR') . '/cli/mysql.inc.php');

$dsn = lmbToolkit :: instance()->getDefaultDbDSN();

$host = $dsn->getHost();
$user = $dsn->getUser();
$password = $dsn->getPassword();
$database = $dsn->getDatabase();
$since = null; //do we need this stuff?
$dry_run = false;

foreach($argv as $arg)
{
  if($arg == '--dry-run')
    $dry_run = true;
}

if($dry_run)
{
  echo "===== Migrating production DB(dry-run) =====\n";
  $sql_schema = lmb_env_get('PROJECT_DIR') . '/init/schema.mysql';
  $sql_data = lmb_env_get('PROJECT_DIR') . '/init/data.mysql';
  $tmp_db = mysql_create_tmp_db($host, $user, $password);
  mysql_load($host, $user, $password, $tmp_db, $sql_schema);
  mysql_load($host, $user, $password, $tmp_db, $sql_data);
  try
  {
    mysql_migrate($host, $user, $password, $tmp_db, $since);
  }
  catch(Exception $e)
  {
    echo "\nWARNING: migration error:\n" . $e->getMessage();
    echo "\nPlease correct the migration\n";
    mysql_db_drop($host, $user, $password, $tmp_db);
    exit();
  }
  mysql_db_drop($host, $user, $password, $tmp_db);
  echo "Everything seems to be OK\n";
}
else
{
  echo "===== Migrating production DB =====\n";
  mysql_migrate($host, $user, $password, $database, $since);
}

//sql_log_show();
?>
