<?php

class TestController extends Controller
{
  function actionDisplay()
  {
    $this->post = $_POST;
    $this->_view(__FUNCTION__);
  }
}
