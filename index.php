<?php

try
{
  require_once(dirname(__FILE__) . '/setup.php');

  require_once(CHAT_DIR . 'Application.class.php');
  $app = new Application();
  $app->run();
}
catch (Exception $e)
{
    echo 'Caught exception: ',  $e->getMessage(), "\n";
}
