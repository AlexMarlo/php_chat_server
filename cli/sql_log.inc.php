<?php

function sql_log($sql, $message = '')
{
  $log = PHP_EOL . PHP_EOL . "--- Start `{$message}` loading ---" . PHP_EOL;
  $log .= "--- SQL executed at: " . date('H:i:s d-m-Y') . " --- " . PHP_EOL . $sql . PHP_EOL;
  $log .= "--- End `{$message}` loading ---" . PHP_EOL;
  
  file_put_contents((dirname(__FILE__) . '/../var/sql.log'), $log, FILE_APPEND);
  
  if(!isset($GLOBALS['sql'])) $GLOBALS['sql'] = '';
  $GLOBALS['sql'] .= PHP_EOL . PHP_EOL . $log . PHP_EOL;
}

function sql_log_file($file, $message = '')
{
  sql_log(file_get_contents($file), $message); 
}

function sql_log_show()
{
  if(isset($GLOBALS['sql'])) 
    echo $GLOBALS['sql'];
}
