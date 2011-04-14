<?php
require_once('model/User.class.php');
require_once('model/Room.class.php');

class UserController extends Controller
{
  function actionDisplay()
  {
    $this->test = 'user controller';
    $this->_view(__FUNCTION__);
  }

  function actionList()
  {
    $this->users = array();

    if($this->hasPostVal('id'))
      $this->id = $_POST['id'];
    else
    {
      $this->message = 'Bad value for: id.';
      $this->_view('error');
    }

    if($users = User :: find("User", "*", "`id` != " . $this->id))
    {
      $this->users = $users;
    }

    $this->_view(__FUNCTION__);
  }

  function actionCreate()
  {
    $this->user = array();
    if(isset($_POST['name']) && isset($_POST['login']) && isset($_POST['pass']) && isset($_POST['retry_pass']))
    {
      $this->name = $_POST['name'];
      $this->login = $_POST['login'];
      $this->pass = $_POST['pass'];
      $this->retry_pass = $_POST['retry_pass'];
    }
    else
    {
      $this->message = 'Bad value for: name, login, pass, retry_pass.';
      $this->_view('error');
    }

    if($this->pass != $this->retry_pass)
    {
      $this->message = 'pass not match with retry_pass.';
      $this->_view('error');
    }

    $fields = array(
        'name' => "{$this->name}",
        'login' => "{$this->login}",
        'password' => "{$this->pass}",
      );

    if(!$this->user_id = User :: insert("User", $fields))
    {
      $this->user = $fields;
      $this->user['id'] = $this->user_id;
    }
    else
    {
      $this->message = 'Error while registring user.';
      $this->_view('error');
    }

    $this->_view(__FUNCTION__);
  }

  function actionInfo()
  {
    if($this->hasPostVal('id') && $this->hasPostVal('uid'))
    {
      $this->uid = $_POST['uid'];
      $this->id = $_POST['id'];
    }
    else
    {
      $this->message = 'Bad value for: uid/id.';
      $this->_view('error');
    }

    if(!$this->user = User :: findOne("User", "*", "`id` = " . $this->id))
    {
      $this->message = 'User not found.';
      $this->_view('error');
    }

    $this->_view(__FUNCTION__);
  }
}
