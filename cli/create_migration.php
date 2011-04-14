<?php
require_once(dirname(__FILE__) . '/../setup.php');
require_once(dirname(__FILE__) . '/mysql.inc.php');

$since = isset($argv[1]) ? $argv[1] : 0;

if(!isset($argv[1]))
{
  echo "Specify migration name\n";
  exit(1);
}
$name = $argv[1];

$dir = dirname(__FILE__);

$dsn = lmbToolkit :: instance()->getDefaultDbDSN();

$host = $dsn->getHost();
$user = $dsn->getUser();
$password = $dsn->getPassword();
$database = $dsn->getDatabase();
$diff_cmd = "php $dir/diff.php";

if($diff = `$diff_cmd`)
{
  $last = get_last_migration_file();
  if(file_get_contents($last) == $diff)
  {
    echo "The last migration file '$last' is identical to the new migration, skipped\n";
    exit();
  }

  $stamp = time();
  $file = lmb_env_get('PROJECT_DIR') . "/init/migrate/{$stamp}_{$name}.sql";

  echo "Writing new migration to file '$file'...";
  file_put_contents($file, $diff);
  echo "done! (" . strlen($diff). " bytes)\n";

  if(!mysql_test_migration($host, $user, $password))
    echo "\nWARNING: migration has errors, please correct them before committing! Try dry-running it with mysql_migrate.php --dry-run\n";

  echo "Updating version info...";
  mysql_exec($host, $user, $password, $database, "UPDATE schema_info SET version = $stamp;");
  echo "done!\n";
}
else
  echo "There haven't been any changes according to the latest dump\n";

?>
