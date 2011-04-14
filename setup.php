<?php

/*
set_include_path(
  dirname(__FILE__) . '/' . PATH_SEPARATOR .
  dirname(__FILE__) . '/lib/' . PATH_SEPARATOR
);
*/

if(file_exists(dirname(__FILE__) . '/setup.override.php'))
  require_once(dirname(__FILE__) . '/setup.override.php');

@define('CHAT_DIR', dirname(__FILE__) . '/');
@define('CONTROLLER_PATH', CHAT_DIR . '/controller/');
@define('CORE_PATH', CHAT_DIR . '/core/');
@define('VIEW_PATH', CHAT_DIR . '/view/');
@define('VAR_DIR', CHAT_DIR . '/var/');
@define('CHAT_HOST', isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'chat');

@define('DB_USER', 'fees0_7479356');
@define('DB_PASS', '5XiedJBVcN');
@define('DB_HOST', 'sql208.0fees.net:3306');
@define('DB_NAME', 'fees0_7479356_chat');
@define('DB_CHARSET', 'utf8');

/*
var_dump(DB_HOST . DB_USER . DB_PASS);
$connection = mysql_connect(DB_HOST, DB_USER, DB_PASS);

var_dump(mysql_select_db(DB_NAME, $connection));
exit;
*/
