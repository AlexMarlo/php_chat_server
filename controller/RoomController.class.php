<?php
require_once('model/UserToRoom.class.php');
require_once('model/User.class.php');
require_once('model/Room.class.php');
require_once('model/Message.class.php');

class RoomController extends Controller
{
  function actionDisplay()
  {
    $this->test = 'room controller';
    $this->_view(__FUNCTION__);
  }

  function actionList()
  {
    $this->rooms = array();
    $room_ids = array();
    $criteria = null;

    if($this->hasPostVal('user_id'))
    {
      $this->user_id = $_POST['user_id'];
      if($this->hasPostVal('user_rooms'))
      {
        if($user_to_room = UserToRoom :: find("UserToRoom", "room_id", "`user_id` = {$this->user_id}"))
        {
          foreach($user_to_room as $room)
            $room_ids[] = $room['room_id'];

          $criteria = "`id` in (" . implode(",", $room_ids) . ")";
        }
        else
          $criteria = "1 = 0";
      }
      elseif($this->hasPostVal('not_user_rooms'))
      {
        $user_room_ids = array();
        $user_rooms = UserToRoom :: find("UserToRoom", "room_id", "`user_id` = {$this->user_id}");

        if($user_rooms)
        {
          foreach($user_rooms as $room)
            $user_room_ids[] = $room['room_id'];
        }

        $not_user_rooms = UserToRoom :: find("UserToRoom", "room_id", "`user_id` != {$this->user_id}");
        if($not_user_rooms)
        {
          foreach($not_user_rooms as $room)
          {
            if(!in_array($room['room_id'], $user_room_ids) && !in_array($room['room_id'], $room_ids))
              $room_ids[] = $room['room_id'];
          }

          $criteria = "`id` in (" . implode(",", $room_ids) . ")";
        }
        else
          $criteria = "1 = 0";
      }
    }

    if($rooms = Room :: find("Room", "*", $criteria))
      $this->rooms = $rooms;

    if($this->hasPostVal('with_messages'))
    {
      foreach($rooms as $key => $room)
      {
        $this->rooms[$key]['messages'] = array();
        $sql = "
            SELECT
              m.id as id,
              u.name as user_name,
              u.id as user_id,
              m.ctime as ctime,
              m.utime as utime,
              m.content as content
              
            FROM
              message as m, user as u
            WHERE
              u.id = m.user_id
              AND
              m.room_id = {$room['id']}
            ORDER BY m.id ASC
          ;";
        if($messages = Message :: select($sql))
          $this->rooms[$key]['messages'] = $messages;
      }
    }

    $this->_view(__FUNCTION__);
  }

  function actionSendMessage()
  {
    if($this->hasPostVal('user_id') && $this->hasPostVal('room_id') && $this->hasPostVal('content')  && $this->hasPostVal('ctime') )
    {
      $this->user_id = $_POST['user_id'];
      $this->room_id = $_POST['room_id'];
      $this->content = $_POST['content'];
      $this->ctime = $_POST['ctime'];
    }
    else
    {
      $this->message = 'Bad value for: user_id/room_id/content.';
      $this->_view('error');
    }

    if(!User :: findOne("User", "*", "`id` = {$this->user_id}"))
    {
      $this->message = 'User not found.';
      $this->_view('error');
    }

    if(!Room :: findOne("Room", "*", "`id` = {$this->room_id}"))
    {
      $this->message = 'Room not found.';
      $this->_view('error');
    }

    if(!$user_to_room = UserToRoom :: findOne("UserToRoom", "*", "`user_id` = {$this->user_id} AND `room_id` = {$this->room_id}"))
    {
      $this->message = "No premision for user[id=$user_id] to send message to this room[id={$this->room_id}].";
      $this->_view('error');
    }

    if(!Room :: findOne("Room", "*", "`id` = {$this->room_id}"))
    {
      $this->message = 'Room not found.';
      $this->_view('error');
    }

    $fields = array(
        'user_id' => $this->user_id,
        'room_id' => $this->room_id,
        'content' => $this->content,
        'ctime' => $this->ctime,
        'utime' => $this->ctime,
      );

    if(!$this->message_id = Message :: insert("Message", $fields))
    {
      $this->message = 'Error while send message.';
      $this->_view('error');
    }

    $this->_view(__FUNCTION__);
  }

  function actionGetMessages()
  {
    $this->messages = array();

    if($this->hasPostVal('room_id'))
      $this->room_id = $_POST['room_id'];
    else
    {
      $this->message = 'Bad value for: room_id.';
      $this->_view('error');
    }

    if($this->hasPostVal('last_message_id'))
      $this->last_message_id = $_POST['last_message_id'];
    else
      $this->last_message_id = 0;

    if(!Room :: findOne("Room", "*", "`id` = {$this->room_id}"))
    {
      $this->message = 'Room not found.';
      $this->_view('error');
    }

    $sql = "
        SELECT
          m.id as id,
          u.name as user_name,
          u.id as user_id,
          m.ctime as ctime,
          m.utime as utime,
          m.content as content
          
        FROM
          message as m, user as u
        WHERE
            u.id = m.user_id
          AND
            m.room_id = {$this->room_id}
          AND
            m.id > {$this->last_message_id}
        ORDER BY m.id ASC
      ;";

    if($messages = Message :: select($sql))
      $this->messages = $messages;

    $this->_view(__FUNCTION__);
  }

  function actionAddUsers()
  {
    if($this->hasPostVal('room_id') && $this->hasPostVal('user_ids'))
    {
      $this->room_id = $_POST['room_id'];
      $this->user_ids = $_POST['user_ids'];
    }
    else
    {
      $this->message = 'Bad value for: room_id/user_ids.';
      $this->_view('error');
    }

    if(!Room :: findOne("Room", "*", "`id` = {$this->room_id}"))
    {
      $this->message = 'Room not found.';
      $this->_view('error');
    }

    foreach($this->user_ids as $user_id)
    {
      if(!$user = User :: findOne("User", "*", "`id` = $user_id"))
      {
        $this->message = 'User not found.';
        $this->_view('error');
      }
    }

    $this->count = 0;
    foreach($this->user_ids as $user_id)
    {
      if(!$user_to_room = UserToRoom :: findOne("UserToRoom", "*", "`user_id` = $user_id AND `room_id` = {$this->room_id}"))
      {
        $fields = array(
          'user_id' => $user_id,
          'room_id' => $this->room_id,
        );

        if($this->id = UserToRoom :: insert("UserToRoom", $fields))
          $this->count++;
        else
        {
          $this->message = 'Error while adding users to room.';
          $this->_view('error');
        }
      }
    }

    $this->_view(__FUNCTION__);
  }

  function actionCreate()
  {
    if($this->hasPostVal('author_id') && $this->hasPostVal('user_ids') && $this->hasPostVal('title'))
    {
      $this->title = $_POST['title'];
      $this->author_id = $_POST['author_id'];
      $this->user_ids = $_POST['user_ids'];
    }
    else
    {
      $this->message = 'Bad value for: title/author_id/user_ids.';
      $this->_view('error');
    }

    $this->user_ids[] = $this->author_id;
    foreach($this->user_ids as $user_id)
    {
      if(!$user = User :: findOne("User", "*", "`id` = $user_id"))
      {
        $this->message = "User[id=$user_id] not found.";
        $this->_view('error');
      }
    }

    $fields = array(
      'author_id' => "{$this->author_id}",
      'title' => "{$this->title}",
    );

    if(!$this->room_id = Room :: insert("Room", $fields))
    {
      $this->message = 'Error while creating room.';
      $this->_view('error');
    }

/*
    if(!$user_to_room = UserToRoom :: findOne("UserToRoom", "*", "`room_id` = {$this->room_id} AND `user_id` in(" . implode() . ")"))
    {
      $this->message = 'Error while adding users to room.';
      $this->_view('error');
    }
*/

    foreach($this->user_ids as $user_id)
    {
      if(!$user_to_room = UserToRoom :: findOne("UserToRoom", "*", "`user_id` = $user_id AND `room_id` = {$this->room_id}"))
      {
        $fields = array(
          'user_id' => "$user_id",
          'room_id' => "{$this->room_id}",
        );

        if($this->id = UserToRoom :: insert("UserToRoom", $fields))
          $this->count++;
        else
        {
          $this->message = "Error while adding user[id={$this->id}] to room.";
          $this->_view('error');
        }
      }
    }

    $this->_view(__FUNCTION__);
  }
}
