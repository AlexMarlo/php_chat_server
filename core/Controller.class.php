<?php

class Controller
{
  function _view($view)
  {
    $view = str_replace('action', '', $view);

    $contrloller_view = str_replace('Controller', '', get_class($this));
    $contrloller_view = preg_replace("/(^[A-Z])/e", "strtolower('\\1')", $contrloller_view);
    $contrloller_view = preg_replace("/([A-Z])/e", "'_'.strtolower('\\1')", $contrloller_view);

    $view = preg_replace("/(^[A-Z])/e", "strtolower('\\1')", $view);
    $view = preg_replace("/([A-Z])/e", "'_'.strtolower('\\1')", $view);

    try
    {
      include(VIEW_PATH . $contrloller_view . '/' . $view . '.ptml');
    }
    catch (Exception $e)
    {
      echo 'Caught exception: ',  $e->getMessage(), "\n";
    }

    exit;
  }

  function hasPostVal($key)
  {
    return isset($_POST[$key]) && !is_null($_POST[$key]) && $_POST[$key] != '';
  }
}
