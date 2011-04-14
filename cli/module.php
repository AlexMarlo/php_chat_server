<?php

function task_parse_module_argument($args)
{
  $module = $args[0];
  $project_dir = taskman_prop('PROJECT_DIR');

  if(!$module || '' == $module)
  {
    taskman_sysmsg("No modules found in " . $module . PHP_EOL);
    exit(1);
  }

  taskman_propset('MODULE', $module);
}

/**
 * @desc install module
 * @param module_name
 * @deps parse_module_argument
 */
function task_install()
{
  chdir(taskman_prop('PROJECT_DIR'));
  require_once('setup.php');

  if(is_dir('lib/bitcms/' . taskman_prop('MODULE')))
  {
    taskman_sysmsg(PHP_EOL . 'Module `' . taskman_prop('MODULE') . '` already installed' . PHP_EOL);
    exit(1);
  }

  taskman_sysmsg(PHP_EOL . 'Install module `' . taskman_prop('MODULE') . '`' . PHP_EOL);

  _exportLibraryExternal();
  _exportSkel();

  _installDependentModules();

  _applyDatabaseMigration();
  _applyExtraSettings();
}

function _exportLibraryExternal()
{
  $module_name = taskman_prop('MODULE');
  $module_src = lmbToolkit :: instance()->getConf('modules')->get('bitcms_src') . '/' . $module_name;

  if(!is_dir($module_src))
  {
    taskman_sysmsg("No module with name `{$module_name}`" . PHP_EOL);
    exit(1);
  }

  passthru("ln -s {$module_src} ./lib/bitcms/$module_name");
}

function _exportSkel()
{
  $module_name = taskman_prop('MODULE');

  echo "Export module skel..." . PHP_EOL;

  passthru("cp -R ./lib/bitcms/{$module_name}/_install/skel ./_skel");
  passthru("find ./_skel -type d -name '.svn' -exec rm -rf {} \;");
  passthru("cp -R ./_skel/* .");
  passthru("rm -rf ./_skel");

  passthru('svn add --force .');
  passthru('svn commit -m "Installer: export skel for `' . $module_name . '` module"');
}

function _applyDatabaseMigration()
{
  $dsn = lmbToolkit :: instance()->getDefaultDbDSN();

  $migration_sql = taskman_prop('PROJECT_DIR') . '/lib/bitcms/' . taskman_prop('MODULE') . '/_install/init/migration.' . $dsn->getDriver();
  if(!file_exists($migration_sql))
    return;

  passthru("cp {$migration_sql} ./init/migrate/" . time() . "_" . taskman_prop('MODULE') . "_migration.sql");

  require_once('cli/' . $dsn->getDriver() . '.inc.php');
  passthru('php cli/migrate.php');

  passthru('svn add --force .');
  passthru('svn commit -m "Installer: apply database mirgation for `' . taskman_prop('MODULE') . '` module`"');
  passthru('svn update --ignore-externals');
}

function _applyExtraSettings()
{
  $extra_settings = taskman_prop('PROJECT_DIR') . '/cli/extra_settings/' . taskman_prop('MODULE') . '.php';
  if(!file_exists($extra_settings))
    return;

  passthru("php {$extra_settings}");

  passthru('svn add --force .');
  passthru('svn commit -m "Installer: apply extra settings for `' . taskman_prop('MODULE') . '` module`"');
}

function _installDependentModules()
{
  $conf_file = taskman_prop('PROJECT_DIR') . '/lib/bitcms/' . taskman_prop('MODULE') . '/_install/dependent.inc.php';
  if(!file_exists($conf_file))
    return;

  include($conf_file);
  foreach($dependent_modules as $module)
    passthru('php cli/module.php install ' . $module);
}

$project_dir = realpath(dirname(__FILE__) . '/../');

require_once($project_dir . '/setup.php');
lmb_require($project_dir . '/cli/sql_log.inc.php');
lmb_require('limb/taskman/taskman.inc.php');

taskman_propset('PROJECT_DIR', $project_dir);
taskman_run();

