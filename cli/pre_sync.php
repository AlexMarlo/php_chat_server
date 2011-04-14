<?php
require_once(dirname(__FILE__) . '/../settings/external_libs.conf.php');

echo "Pre syncing..." . PHP_EOL;
echo "Sync limb project..." . PHP_EOL;

$lib_path = dirname(__FILE__) . '/../lib';
$var_projects = dirname(__FILE__) . '/../..';

$path_array = explode(DIRECTORY_SEPARATOR ,realpath(dirname(__FILE__). '/../'));
$project_name = array_pop($path_array);

$exterals = "$var_projects/externals";
$project_exterals = "$exterals/$project_name";

if(!file_exists($exterals))
  mkdir($exterals);

if(!file_exists($project_exterals))
  mkdir($project_exterals);

if(!file_exists("$project_exterals/$limb_tag"))
{
  mkdir("$project_exterals/$limb_tag");
  chdir("$project_exterals/$limb_tag");

  passthru("git init .");
  passthru("git fetch $limb_src tag $limb_tag");
  passthru("git branch master $limb_tag");
  passthru("git checkout master");
}

  chdir("$lib_path/limb");
  passthru("rm -rf *");
  passthru("ln -s $project_exterals/$limb_tag/* .");

echo "Sync bitcms project..." . PHP_EOL;

if(!file_exists("$project_exterals/$bitcms_tag"))
{
  chdir("$project_exterals");
  passthru("svn export --force $bitcms_src");
}

  chdir("$lib_path/bitcms");
  passthru("rm -rf *");
  passthru("ln -s $project_exterals/$bitcms_tag/* .");

echo "Sync shared static..." . PHP_EOL;
chdir(dirname(__FILE__) . '/..');

$remove_dirs = array($all_shared = dirname(__FILE__) . "/../www/shared");
$remove_dirs[] = dirname(__FILE__) . "/../_docs";

foreach($remove_dirs as $dir)
  passthru("rm -rf " . $dir);

mkdir($all_shared);

foreach(glob(dirname(__FILE__) . "/../lib/limb/*/shared") as $pkg_shared)
{
  echo "Moving $pkg_shared..\n";

  $pkg = basename(dirname($pkg_shared));
  rename($pkg_shared, "$all_shared/$pkg");
}

foreach(glob(dirname(__FILE__) . "/../lib/bitcms/*/shared") as $pkg_shared)
{
  echo "Moving $pkg_shared..\n";

  $pkg = basename(dirname($pkg_shared));
  rename($pkg_shared, "$all_shared/$pkg");
}

echo "done" . PHP_EOL;
