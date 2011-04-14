<?php

class Model
{
  static function connect($hostname = null, $username = null, $password = null, $database = null)
  {
    if(is_null($password))
      $password = DB_PASS;

    if(is_null($username))
      $username = DB_USER;

    if(is_null($hostname))
      $hostname = DB_HOST;

    if(is_null($database))
      $database = DB_NAME;

    $connection = mysql_connect($hostname, $username, $password);
    mysql_select_db($database, $connection);

    return $connection;
  }

  static function close_connect($connection)
  {
    mysql_close($connection);
  }

  function query($query)
  {
    $connection = self :: connect();
    $result =  mysql_query($query, $connection);
    self :: close_connect($connection);
    return $result;
  }

  static function select($select_query)
  {
    $query_result = self :: query($select_query);

    if(!$query_result)
      return null;

    $result = array();

    if (mysql_num_rows($query_result) == 0)
      return $result;

    while ($row = mysql_fetch_assoc($query_result))
      $result[] = $row;

    mysql_free_result($query_result);

    if(count($result) < 1)
      $result = null;

    return $result;
  }

  static function find($class_name, $fields = '*', $criteria = null)
  {
    $object = new $class_name;

    $sql = "
      SELECT {$fields}
      FROM {$object->table}
    ";

    if(!is_null($criteria))
      $sql .= " WHERE {$criteria}";

    $sql .= ";";

    return self :: select($sql);
  }

  static function findOne($class_name, $fields = '*', $criteria = null)
  {
    $object = new $class_name;

    if(is_null($fields) || $fields == '')
      $fields = '*';

    $sql = "
      SELECT {$fields}
      FROM {$object->table}
    ";

    if(!is_null($criteria))
      $sql .= " WHERE {$criteria}";

    $sql .= " LIMIT 1;";

    $result = self :: select($sql);

    if(!is_null($result) && is_array($result) && count($result) == 1)
      return array_pop($result);
    else
      return null;
  }

  static function insert($class_name, $fields = null, $values = null)
  {
    if(is_null($values) && is_null($fields))
    {
      return null;
    }
    $connection = self :: connect();
    $object = new $class_name;

    if(is_array($fields))
    {
      foreach($fields as $key => $value)
        $fields[$key] = mysql_real_escape_string($value, $connection);
    }

    if(is_array($values))
    {
      foreach($values as $key => $value)
        $values[$key] = mysql_real_escape_string($value, $connection);
    }

    if(is_null($values) && is_array($fields))
    {
      $values = "'" . implode("', '", $fields) . "'";
      $fields = implode(', ', array_keys($fields));
    }

    if(is_array($values) && is_array($fields))
    {
      $values = "'" . implode("', '", $values) . "'";
      $fields = implode(', ', $fields);
    }

    if(substr_count($fields , "ctime") < 1)
    {
      $fields .= ", ctime";
      $values .= ", '" . time() . "'";
    }

    if(substr_count($fields , "utime") < 1)
    {
      $fields .= ", utime";
      $values .= ", '" . time() . "'";
    }

    $insert_query = "
      INSERT INTO {$object->table}
      ($fields)
      VALUES($values);
    ";

    $query_result =  mysql_query($insert_query, $connection);

    if(!$query_result || mysql_insert_id($connection) == 0)
      $id = null;
    else
      $id = mysql_insert_id($connection);

    self :: close_connect($connection);
    return $id;
  }
}
