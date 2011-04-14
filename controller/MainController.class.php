<?php
require_once('model/User.class.php');

class MainController extends Controller
{
  function actionDisplay()
  {
    $this->test = 'main controller';
    $this->_view(__FUNCTION__);
  }

  function actionLogin()
  {
    if($this->hasPostVal('login') && $this->hasPostVal('pass'))
    {
      $this->login = $_POST['login'];
      $this->pass = $_POST['pass'];
    }
    else
    {
      $this->message = 'Bad value for: login/pass.';
      $this->_view('error');
    }
/*
      $this->login = $_GET['login'];
      $this->pass = $_GET['pass'];
*/

    if($user = User :: findOne("User", null, "login = '{$this->login}' AND password = '{$this->pass}'"))
    {
      $uid = $user['id'];
      $this->uid = time() . '-' . $uid;
    }
    else
    {
      $this->message = 'User not found.';
      $this->_view('error');
    }

    $this->_view(__FUNCTION__);
  }
}
