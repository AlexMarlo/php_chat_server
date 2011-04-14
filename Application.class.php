<?php
require_once(CORE_PATH . "/Controller.class.php");
require_once(CORE_PATH . "/Model.class.php");

class Application
{
  protected $_controller;
  protected $_action;
  protected $_default_controller = 'main';
  protected $_default_action = 'display';

  function run()
  {
    if(isset($_REQUEST['controller']))
      $this->_controller = $_REQUEST['controller'];
    else
      $this->_controller = $this->_default_controller;

    if(isset($_REQUEST['action']))
      $this->_action = $_REQUEST['action'];
    else
      $this->_action = $this->_default_action;

    $this->_controller = ucfirst(preg_replace("/_([a-z])/e", "strtoupper('\\1')", $this->_controller));
    $this->_controller = $this->_controller . 'Controller';
    $this->_action = 'action' . ucfirst(preg_replace("/_([a-z])/e", "strtoupper('\\1')", $this->_action));

    require_once(CONTROLLER_PATH . "/{$this->_controller}.class.php");

    $controller = new $this->_controller;
    $action = $this->_action;

    $controller->$action();
  }
}
