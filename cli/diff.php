<?php
require_once(dirname(__FILE__) . '/../setup.php');

$dsn = lmbToolkit :: instance()->getDefaultDbDSN();

$host = $dsn->getHost();
$user = $dsn->getUser();
$password = $dsn->getPassword();
$database = $dsn->getDatabase();
$schema = lmb_env_get('PROJECT_DIR') . '/init/schema.mysql';
$data = lmb_env_get('PROJECT_DIR') . '/init/data.mysql';

if(preg_match('~INSERT\s+INTO\s+\D*schema_info\D+\((\d+)\)\;~i', file_get_contents($data), $m))
  $since = $m[1];
else
  $since = -1;

//collecting all not applied migrations
$migrations = array();
foreach(glob(lmb_env_get('PROJECT_DIR') . '/init/migrate/*.sql') as $migration)
{
  list($version,) = explode('_', basename($migration));

  if($since < intval($version))
    $migrations[] = $migration;
}
asort($migrations);

$working_db = array(
  'hostname' => $host,
  'username' => $user,
  'password' => $password,
  'database' => $database
);

$conn = new cConnection($host, $user, $password);
$conn->open();
$tmp_db = $conn->createTemporaryDatabase();

$repos_db = $working_db;
$repos_db['database'] = $tmp_db;

$conn->importSql($tmp_db, $schema);

foreach($migrations as $migration)
  $conn->importSql($tmp_db, $migration);

echo generateScript($repos_db, $working_db);

$conn->dropDatabase($tmp_db);
$conn->close();

//////////////////// DONT TOUCH BELOW ////////////////////

function sameConnection(&$con1, &$con2) {
  return $con1->_con == $con2->_con;
}

class cConnection {

  var $_hostname = NULL;
  var $_username = NULL;
  var $_password = NULL;
  var $_database = NULL;
  var $_con = NULL;

  var $_laststatement = "";
  var $_lasterrno = 0;
  var $_lasterror = "";
  var $_lasterrorpos = "";

  function cConnection($hostname = NULL, $username = NULL, $password = NULL) {
    $this->_hostname = isset($hostname) ? $hostname : NULL;
    $this->_username = isset($username) ? $username : NULL;
    $this->_password = isset($password) ? $password : NULL;
    $this->_con = NULL;
  }

  function connectionEqual(&$connection) {
    return $this->_con = $connection->_con;
  }

  function close() {
    if ( isset($this->_con) ) {
      mysql_close($this->_con);
    }
  }

  function open($persistent = FALSE) {
    static $connectionfunctions = array(1 => "mysql_pconnect", 0 => "mysql_connect");

    $this->_con = @$connectionfunctions[$persistent ? 1 : 0]($this->_hostname, $this->_username, $this->_password, true);
    return isset($this->_con) && trim((string)$this->_con) != "";
  }

  function query($stat, $file=NULL, $line=NULL) {
    $result=new cQuery($stat, $this, isset($file)?$file:NULL, isset($line)?$line:NULL);
    if ( !isset($result) ) {
      return NULL;
    } else if ( $result->_res ) {
      return $result;
    } else {
      $result->destroy();
      return NULL;
    }
  }

  function splitSqlFile($sql) {
    $result = array();
    $sql = trim($sql);
    $sql_len = strlen($sql);
    $char         = '';
    $string_start = '';
    $is_string = FALSE;
    $time0        = time();
    $server_version = $this->serverVersionInteger();

    for ( $i = 0; $i < $sql_len; ++$i) {
      if ( $sql[$i] == ';' ) { // End Of Statement ...
        $result[] = substr($sql, 0, $i);
        $sql = ltrim(substr($sql, min($i + 1, $sql_len)));
        $sql_len = strlen($sql);
        if ( $sql_len == 0 ) {
          return $result;
        }
        $i = -1;
      } else if ( $sql[$i] == '#' || ($sql[$i] == ' ' && $i > 1 && $sql[$i-2] . $sql[$i-1] == '--') ) {
        // starting position of the comment depends on the comment type
        $start_of_comment = ($sql[$i] == '#' ? $i : $i-2);
        // search for new line i.e. \n or \r (Mac style)
        $end_of_comment = (strpos($sql, "\012", $i+2) !== FALSE ? strpos($sql, "\012", $i+2) : strpos($sql, "\015", $i+2) ) + 1;
        if (!$end_of_comment) {
          // no eol found after '#', add the parsed part to the returned
          // array if required and exit
          if ($start_of_comment > 0) {
            $result[]    = trim(substr($sql, 0, $start_of_comment));
          }
          return $result;
        } else {
          $sql          = substr($sql, 0, $start_of_comment) . ltrim(substr($sql, $end_of_comment));
          $sql_len      = strlen($sql);
          $i--;
        } // end if...else
      } else if ( $server_version < 32270 && ($sql[$i] == '!' && $i > 1  && $sql[$i-2] . $sql[$i-1] == '/*') ) {
        $sql[$i] = ' ';
      } else if ( in_array($sql[$i], array("\"", "'", "`")) ) { // Skipping String ...
        $string_start = $sql[$i];
        while (TRUE) {
          if ( !($i = strpos($sql, $string_start, $i+1)) ) {
            $result[] = $sql;
            return $result;
          } else if ( $string_start == '`' || $sql[$i-1] != '\\') {
            $string_start = '';
            $is_string = FALSE;
            break;
          } else {
            $j = 2;
            $escaped_backslash = FALSE;
            while ($i-$j > 0 && $sql[$i-$j] == '\\') {
              $escaped_backslash = !$escaped_backslash;
              $j++;
            }
            if ($escaped_backslash) {
              $string_start  = '';
              $is_string     = FALSE;
              break;
            } else {
              $i++;
            }
          }
        }
      }

      // Sending Keep-Alive Header ...
      $time1 = time();
      if ($time1 >= $time0 + 30) {
        $time0 = $time1;
        header('X-mysqldiff-keep-alive: Pong');
      }
    }

    // add any rest to the returned array
    if ( !empty($sql) && preg_match('@[^[:space:]]+@', $sql) ) {
      $result[] = $sql;
    }

    return $result;
  }


  function importSql($database, $file) {
    `mysql -h{$this->_hostname} -u{$this->_username} -p{$this->_password} {$database} < $file`;
  }

  function listDatabases($numericindex = FALSE) {
    $result = array();
    if ( $res = mysql_list_dbs($this->_con) ) {
      while ( $row = mysql_fetch_object($res) ) {
        if ( $numericindex ) {
          $result[] = $row->Database;
        } else $result[$row->Database] = $row->Database;
      }
      mysql_free_result($res);
    }
    return $result;
  }

  function selectDatabase($name) {
    if ( !mysql_select_db($this->_database = $name, $this->_con) ) {
      $this->_database = NULL;
    }
    return isset($this->_database);
  }

  function canCreateTemporaryDatabase() {

  }

  function createTemporaryDatabase($name = NULL) {
    static $idx = 0;
    $tempname = isset($name) ? $name : "temp_mysqldiff_".time()."_".$idx;
    $idx++;
    mysql_query("CREATE DATABASE $tempname", $this->_con);
    echo mysql_error($this->_con);
    if ( mysql_errno($this->_con) == 0 ) {
      return $tempname;
    } else return "";
  }

  function dropDatabase($name) {
    mysql_query("DROP DATABASE $name", $this->_con);
    return mysql_errno($this->_con) == 0;
  }

  function fetchTablelist($db = NULL, $numericindex = FALSE, $extendeddisplay = FALSE) {
    $result = array();

    if ( $extendeddisplay ) {
      if ( $res = $this->query($stat = "SHOW TABLE STATUS FROM `$db`") ) {
        while ( $row = $res->next() ) {
          $result[$row->Name] = $row->Name." ($row->Type)";
        }
        $res->destroy();
      }
    } else {
      if ( !isset($db) && isset($this->_database) ) $db = $this->_database;
      if ( isset($db) && $res = mysql_list_tables($db, $this->_con) ) {
        while ( $row = mysql_fetch_row($res) ) {
          if ( $numericindex ) {
            $result[] = $row[0];
          } else $result[$row[0]] = $row[0];
        }
        mysql_free_result($res);
      }
    }
    return $result;
  }

  function fetchFields($table, $db) {
    $result=NULL;
    if ( $res = $this->query("SHOW FULL FIELDS FROM `$table` FROM `$db`") ) {
      while ( $row = $res->next() ) {
        $result[$row->Field] = array(
          "database" => $db,
          "name" => $row->Field,
          "type" => $row->Type,
          "null" => ( isset($row->Null) && $row->Null == "YES" ? 1 : 0 ),
          "default" => ( isset($row->Default) ? $row->Default : NULL ),
          "extra"=> ( isset($row->Extra) ? $row->Extra : NULL ),
        );
        if ( isset($row->Comment) ) $result[$row->Field]["comment"] = $row->Comment;
        if ( isset($row->Collation) && $row->Collation != "NULL" ) $result[$row->Field]["collate"] = $row->Collation;
      }
      $res->destroy();
    }
    return isset($result) ? $result : NULL;
  }

  function fetchIndexes($table, $db = NULL) {
    $result=NULL;
    if ( !isset($db) && isset($this->_database) ) $db = $this->database;

    if ( $res = $this->query("SHOW INDEX FROM `$table` FROM `$db`") ) {
      while ( $row = $res->next() ) {
        $result[$row->Key_name]["database"] = $db;
        $result[$row->Key_name]["name"] = $row->Key_name;
        $result[$row->Key_name]["unique"] = $row->Non_unique == 0 ? 1 : 0;
        $result[$row->Key_name]["fields"][$row->Column_name]["name"]=$row->Column_name;
        $result[$row->Key_name]["type"] = isset($row->Index_type) ? $row->Index_type : "BTREE";
        if ( isset($row->Sub_part) && $row->Sub_part != "" ) $result[$row->Key_name]["fields"][$row->Column_name]["sub"]=$row->Sub_part;
      }
      $res->destroy();
    }
    return isset($result) ? $result : NULL;
  }

  function fetchTables($db = NULL) {
    $result = array();
    if ( !isset($db) && isset($this->_database) ) $db = $this->_database;
    if ( isset($db) ) {

      if ( $res = $this->query("SHOW TABLE STATUS FROM `$db`") ) {
        while ( $row = $res->nextarray() ) {
          $indexes = $this->fetchIndexes($row[0], $db);
          $fields = $this->fetchFields($row[0], $db);
          $constraints = array();
          if ( $row["Engine"] == "InnoDB" ) {

            $cparts = explode("; ", $row["Comment"]);
            $comment = preg_match("/^InnoDB free:/i", $c = trim($cparts[0])) ? "" : $c;

            if ( $tabres = $this->query("SHOW CREATE TABLE `$db`.`" . $row["Name"] . "`") ) {
              $obj = $tabres->nextarray();
              if ( preg_match_all("/(CONSTRAINT `([0-9_]+)` )?(FOREIGN KEY) \(([^)]+)\) REFERENCES `(([A-Z0-9_$]+)(\.([A-Z0-9_$]+))?)` \(([^)]+)\)( ON (DELETE|UPDATE)( (CASCADE|SET NULL|NO ACTION|RESTRICT)))?/i", $obj["Create Table"], $matches, PREG_SET_ORDER) ) {
                foreach ( $matches AS $match ) {
                  $constraints[$match[4]] = array(
                      "name" => $match[4],
                      "id" => $match[2],
                      "engine" => $match[3],
                      "targetdb" => isset($match[8]) && trim($match[8]) != "" ? $match[6] : $db,
                      "targettable" => isset($match[8]) && trim($match[8]) != "" ? $match[8] : $match[6],
                      "targetcols" => $match[9],
                      "params" => isset($match[10]) ? $match[10] : NULL,
                    );
                }
              }
              $tabres->destroy();
            }
          } else $comment=trim($row["Comment"]);
          $result[$row["Name"]] = array(
            "database" => $db,
            "name" => $row["Name"],
            "engine" => $row["Engine"],
            "options" => $row["Create_options"],
            "auto_incr" => isset($row["Auto_increment"]) ? $row["Auto_increment"] : NULL,
            "comment"=>$comment,
            "fields"=>$fields,
            "idx"=>$indexes,
            "constraints"=>$constraints
          );
          if ( isset($row["Collation"]) ) {
            $result[$row["Name"]]["collate"] = $row["Collation"];
          } else $result[$row["Name"]]["collate"] = "";
        }
        $res->destroy();
      }
    }
    //print_r($result);
    return count($result) ? $result : NULL;
  }

  function serverVersion() {
    if ( preg_match("/^((\d+)\.(\d+)\.(\d+))/", mysql_get_server_info($this->_con), $matches) ) {
      return array("version"=>$matches[1], "major"=>(int)$matches[2], "minor"=>(int)$matches[3], "revision"=>(int)$matches[4]);
    } else return NULL;
  }

  function serverVersionCompare($version) {
    $info=$this->serverVersion();
    if ( preg_match("/^(\d+)(\.(\d+)(\.(\d+))?)?$/", $version, $matches) ) {
      $server=sprintf("%03d%04d%05d", $info["major"], $info["minor"], $info["revision"]);
      $version=sprintf("%03d%04d%05d", $matches[1], isset($matches[3])?$matches[3]:0, isset($matches[5])?$matches[5]:0);
      if ( $server > $version ) return 1;
      if ( $server < $version ) return -1;
      return 0;
    } else return FALSE;
  }

  function serverVersionString() {
    $version = $this->serverVersion();
    return $version["version"];
  }

  function serverVersionInteger() {
    if ( preg_match("/^((\d+)\.(\d+)\.(\d+))/", mysql_get_server_info($this->_con), $matches) ) {
      return (int)$matches[2] * 10000 + (int)$matches[3] * 100 + (int)$matches[4];
    } else return 0;
  }

  function error() {
    return "[$this->_lasterrno] $this->_lasterror<br />$this->_laststatement";
  }

  function __error($stat="", $file=NULL, $line=NULL) {
    GLOBAL $database_show_errors;

    $this->_laststatement=$stat;
    $this->_lasterrno=mysql_errno($this->_con);
    $this->_lasterror=mysql_error($this->_con);
    $this->_lasterrorpos=( isset($file) && $file!="" && isset($line) && $line!="" ? $file.":".$line : NULL );
  }

  function escapestring($str) {
    if ( version_compare(phpversion(), "4.3.0", ">=") ) {
      return mysql_real_escape_string($str, $this->_con);
    } else return mysql_escape_string($str);
  }

}

class cQuery {

  var $_res;
  var $_parent;
  var $_rowarray = array();
  var $_row = NULL;

  function cQuery($stat, &$parent, $file=NULL, $line=NULL) {
    GLOBAL $database_profile_mode;

    $this->_parent = &$parent;
    if ( $this->_res = mysql_query($stat, $this->_parent->_con) ) {
      $this->currow = 0;
      $numrows = @mysql_num_rows($this->_res);
      $this->_parent->__error($stat);
    } else {
      $this->_parent->__error($stat, isset($file)?$file:NULL, isset($line)?$line:NULL);
      $numrows=0;
    }
  }

  function destroy() {
    if ( isset($this->_res) && $this->_res!="" ) mysql_free_result($this->_res);
  }

  function created() { return isset($this->_res); }

  function count() { return mysql_num_rows($this->_res); }

  function nextarray($type=MYSQL_BOTH) {
    if ( $this->_res ) $this->_rowarray = mysql_fetch_array($this->_res, $type);
    else $this->_rowarray=NULL;
    return isset($this->_rowarray) ? $this->_rowarray : NULL;
  }

  function next() {
    if ( $this->_res ) $this->_row = mysql_fetch_object($this->_res);
    else $this->_row=NULL;
    return isset($this->_row) ? $this->_row : NULL;
  }

}

class cCommandBuilder {

  var $_highlight = FALSE;
  var $_html = FALSE;
  var $_con = NULL;
  var $_renamed = array();
  var $_options = array('engine' => true, 'charset' => true, 'backticks' => true, 'comment' => false);

  var $_translates = array(
    "signs"=>array("translate" => "<span class=\"signs\">\\1</span>", "items"=>array("/([\.,\(\)-])/im"),),
    "num"=>array("translate"=>"\\1<span class=\"num\">\\2</span>\\3", "items"=>array("/(\b)(\d+)(\b)/im"),),
  );
  var $_reservedwords = array(
    "ADD", "ACTION", "ALL", "ALTER", "ANALYZE", "AND", "AS", "ASC", "ASENSITIVE", "AUTO_INCREMENT",
    "BDB", "BEFORE", "BERKELEYDB", "BETWEEN", "BIGINT", "BINARY", "BIT", "BLOB", "BOTH", "BTREE", "BY",
    "CALL", "CASCADE", "CASE", "CHANGE", "CHAR", "CHARACTER", "CHECK", "COLLATE", "COLUMN", "COLUMNS", "CONNECTION", "CONSTRAINT", "CREATE", "CROSS", "CURRENT_DATE", "CURRENT_TIME", "CURRENT_TIMESTAMP", "CURSOR",
    "DATE", "DATABASE", "DATABASES", "DAY_HOUR", "DAY_MINUTE", "DAY_SECOND", "DEC", "DECIMAL", "DECLARE", "DEFAULT", "DELAYED", "DELETE", "DESC", "DESCRIBE", "DISTINCT", "DISTINCTROW", "DIV", "DOUBLE", "DROP",
    "ENUM", "ELSE", "ELSEIF", "ENCLOSED", "ERRORS", "ESCAPED", "EXISTS", "EXPLAIN",
    "FALSE", "FIELDS", "FLOAT", "FOR", "FORCE", "FOREIGN", "FROM", "FULLTEXT",
    "GRANT", "GROUP",
    "HASH", "HAVING", "HIGH_PRIORITY", "HOUR_MINUTE", "HOUR_SECOND",
    "IF", "IGNORE", "IN", "INDEX", "INFILE", "INNER", "INNODB", "INOUT", "INSENSITIVE", "INSERT", "INT", "INTEGER", "INTERVAL", "INTO", "IS", "ITERATE",
    "JOIN",
    "KEY", "KEYS", "KILL",
    "LEADING", "LEAVE", "LEFT", "LIKE", "LIMIT", "LINES", "LOAD", "LOCALTIME", "LOCALTIMESTAMP", "LOCK", "LONG", "LONGBLOB", "LONGTEXT", "LOOP", "LOW_PRIORITY",
    "MASTER_SERVER_ID", "MATCH", "MEDIUMBLOB", "MEDIUMINT", "MEDIUMTEXT", "MIDDLEINT", "MINUTE_SECOND", "MOD", "MRG_MYISAM",
    "NATURAL", "NO", "NOT", "NULL", "NUMERIC",
    "ON", "OPTIMIZE", "OPTION", "OPTIONALLY", "OR", "ORDER", "OUT", "OUTER", "OUTFILE",
    "PRECISION", "PRIMARY", "PRIVILEGES", "PROCEDURE", "PURGE",
    "READ", "REAL", "REFERENCES", "REGEXP", "RENAME", "REPEAT", "REPLACE", "REQUIRE", "RESTRICT", "RETURN", "RETURNS", "REVOKE", "RIGHT", "RLIKE", "RTREE",
    "SELECT", "SENSITIVE", "SEPARATOR", "SET", "SHOW", "SMALLINT", "SOME", "SONAME", "SPATIAL", "SPECIFIC", "SQL_BIG_RESULT", "SQL_CALC_FOUND_ROWS", "SQL_SMALL_RESULT", "SSL", "STARTING", "STRAIGHT_JOIN", "STRIPED",
    "TABLE", "TABLES", "TERMINATED", "TEXT", "THEN", "TIME", "TIMESTAMP", "TINYBLOB", "TINYINT", "TINYTEXT", "TO", "TRAILING", "TRUE", "TYPES",
    "UNION", "UNIQUE", "UNLOCK", "UNSIGNED", "UNTIL", "UPDATE", "USAGE", "USE", "USER_RESOURCES", "USING",
    "VALUES", "VARBINARY", "VARCHAR", "VARCHARACTER", "VARYING",
    "WARNINGS", "WHEN", "WHERE", "WHILE", "WITH", "WRITE",
    "XOR",
    "YEAR_MONTH",
    "ZEROFILL",
  );
  var $_resources = array(
    "fieldformat_changed_single" => "",
    "fieldformat_changed_multiple" => "",
    "fieldformat_changeinfo" => "",
    "fieldformat_modification_needed" => "",
  );

  function cCommandBuilder(&$con, $highlight = FALSE, $html = FALSE) {
    $this->_highlight = $highlight;
    $this->_html = $html;
    $this->_con = &$con;
  }

  /*
    "Public" Methods ...
  */
  function addOption($option, $value) {
    $this->_options[$option] = $value;
  }

  function addOptions($options) {
    if ( isset($options) && is_array($options) ) {
      foreach ( $options as $option => $value ) {
        $this->addOption($option, $value);
      }
    }
  }

  function addRenamed(&$renamed) {
    $this->_renamed = &$renamed;
  }

  function addResource($id, $text) {
    $this->_resources[$id] = $text;
  }

  function alterTableContraints($source, $target) {

    $altering = $result = "";
    $altered = 0;

    // Doing handling of foreign key constraints ...
    if ( $this->getOption("cfk_back") && isset($source["constraints"]) ) foreach ( $source["constraints"] AS $vk=>$vf ) {
      if ( !isset($target["constraints"][$vk]) ) {
        if ( $this->getOption("short") ) {
          $altering.=($altering==""?"":$this->_translate(",")."\n")."    ".$this->_constraintString($vf, $target["database"], 1, $this->_con->serverVersionString());
        } else $result .= $this->_highlightString("ALTER TABLE")." ".$this->_objectName($target["name"])." ".$this->_constraintString($vf, $target["database"], 1, $this->_con->serverVersionString()).$this->_translate(";")."\n";
        $altered++;
      }
    }
    if ( $this->getOption("cfk_back") && isset($target["constraints"]) ) foreach ( $target["constraints"] AS $vk=>$vf ) {
      if ( !isset($source["constraints"][$vk]) ) {
        if ( $this->getOption("short") ) {
          $altering.=($altering==""?"":$this->_translate(",")."\n")."    ".$this->_constraintString($vf, $target["database"], 0);
        } else $result .= $this->_highlightString("ALTER TABLE")." ".$this->_objectName($target["name"])." ".$this->_constraintString($vf, $target["database"], 0).$this->_translate(";")."\n";
        $altered++;
      }
    }

    if ( $altering != "" ) {
      $result .= $this->_highlightString("ALTER TABLE")." ".$this->_objectName($target["name"])."\n$altering;\n";
    }
    return $result;
  }

  function alterTable($source, $target) {
    $altering = $result = "";
    $altered=0;
    $alteredfields=NULL;

    $lastfield=NULL;
    // Checking attributes ...
    $added_fields = array();
    foreach ( $target["fields"] AS $vk=>$vf ) {
      if ( !isset($source["fields"][$vk]) ) {
        if ( isset($this->_renamed[$target["name"]]) && in_array($vk, $this->_renamed[$target["name"]]) ) {
          if ( $this->getOption("short") ) {
            $altering.=($altering==""?"":",\n")."    ".( $this->_html ? "<a class=\"script\" href=\"[save]?sc=removerenamed&amp;table=".urlencode($target["name"])."&amp;field=".urlencode(array_search($vk, $this->_renamed[$target["name"]])).( ini_get("session.use_cookies") ? "" : ( (boolean)ini_get("session.use_trans_sid") ? "" : "&amp;".session_name()."=".session_id() ) )."\">" : "" ).$this->_highlightString("CHANGE").( $this->_html ? "</a>" : "" )." ".$this->_objectName(array_search($vk, $this->_renamed[$target["name"]]))." ".$this->_fieldString($target["fields"][$vk]);
          } else $result .= $this->_highlightString("ALTER TABLE")." ".$this->_objectName($target["name"])." ".( $this->_html ? "<a class=\"script\" href=\"[save]?sc=removerenamed&amp;table=".urlencode($target["name"])."&amp;field=".urlencode(array_search($vk, $this->_renamed[$target["name"]])).( ini_get("session.use_cookies") ? "" : ( (boolean)ini_get("session.use_trans_sid") ? "" : "&amp;".session_name()."=".session_id() ) )."\">" : "" ).$this->_highlightString("CHANGE").( $this->_html ? "</a>" : "" )." ".$this->_objectName($vk)." ".$this->_fieldString($target["fields"][$vk]).";\n";
        } else {
          $added_fields[] = $vk;
          if ( $this->getOption("short") ) {
            $altering.=($altering==""?"":$this->_translate(",")."\n")."    ".$this->_highlightString("ADD")." ".$this->_fieldString($target["fields"][$vk]).( isset($lastfield) ? " ".$this->_highlightString("AFTER")." $lastfield" : " ".$this->_highlightString("FIRST") );
          } else $result .= $this->_highlightString("ALTER TABLE")." ".$this->_objectName($target["name"])." ".$this->_highlightString("ADD")." ".$this->_fieldString($target["fields"][$vk]).( isset($lastfield) ? " ".$this->_highlightString("AFTER")." $lastfield" : " ".$this->_highlightString("FIRST") ).$this->_translate(";")."\n";
        }
        $altered++;
      }
      $lastfield=$target["fields"][$vk]["name"];
    }

    foreach ( $source["fields"] AS $vk=>$vf ) {
      if ( isset($target["fields"][$vk]) ) {
        if ( $vf["type"]==$target["fields"][$vk]["type"] && $vf["null"]==$target["fields"][$vk]["null"] && $vf["extra"]==$target["fields"][$vk]["extra"] && $vf["default"]!=$target["fields"][$vk]["default"] ) {
          if ( $this->getOption("short") ) {
            $altering.=($altering==""?"":",\n")."    ".$this->_highlightString("ALTER")." ".$this->_objectName($target["fields"][$vk]["name"])." ".( isset($target["fields"][$vk]["default"]) ? $this->_highlightString("SET DEFAULT")." ".( is_numeric($target["fields"][$vk]["default"]) ? $target["fields"][$vk]["default"] : "'".$target["fields"][$vk]["default"]."'" ) : $this->_highlightString("DROP DEFAULT") );
            $alterfields[]=array( "name"=>$target["name"].".".$target["fields"][$vk]["name"], "from"=>$this->_fieldString($source["fields"][$vk], FALSE), "to"=>$this->_fieldString($target["fields"][$vk], FALSE) );
          } else {
            $result .= $this->_highlightString("ALTER TABLE")." ".$this->_objectName($target["name"])." ".$this->_highlightString("ALTER")." ".$this->_objectName($target["fields"][$vk]["name"])." ".( isset($target["fields"][$vk]["default"]) ? $this->_highlightString("SET DEFAULT")." ".( is_numeric($target["fields"][$vk]["default"]) ? $target["fields"][$vk]["default"] : "'".$target["fields"][$vk]["default"]."'" ) : " ".$this->_highlightString("DROP DEFAULT") );
            $result .= "#\n#  Fieldformat of '".$target["name"].".$vk' changed from '".$this->_fieldString($source["fields"][$vk], FALSE)." to ".$this->_fieldString($target["fields"][$vk], FALSE).". Possibly data modifications needed!\n#\n\n";
          }
        } else if ( $vf["type"] != $target["fields"][$vk]["type"] || $vf["null"] != $target["fields"][$vk]["null"] || $vf["default"] != $target["fields"][$vk]["default"] || $vf["extra"] != $target["fields"][$vk]["extra"] || ( $this->_con->serverVersionCompare("4.1.0") >= 0 && ( $vf["comment"] != $target["fields"][$vk]["comment"] || (isset($vf["collation"])?$vf["collation"]:NULL) != (isset($target["fields"][$vk]["collation"])?$target["fields"][$vk]["collation"]:NULL)) ) ) {
          if ( $this->getOption("short") ) {
            $altering.=($altering==""?"":",\n")."    ".$this->_highlightString("MODIFY")." ".$this->_fieldString($target["fields"][$vk]);
            $alteredfields[]=array( "name"=>$target["name"].".".$target["fields"][$vk]["name"], "from"=>$this->_fieldString($source["fields"][$vk], FALSE), "to"=>$this->_fieldString($target["fields"][$vk], FALSE) );
          } else {
            $result .= $this->_highlightString("ALTER TABLE")." ".$this->_objectName($target["name"])." ".$this->_highlightString("MODIFY")." ".$this->_fieldString($target["fields"][$vk]).";\n";
            $result .= "#\n#  Fieldformat of '".$target["name"].".$vk' changed from '".$this->_fieldString($source["fields"][$vk], FALSE)." to ".$this->_fieldString($target["fields"][$vk], FALSE).". Possibly data modifications needed!\n#\n\n";
          }
          $altered++;
        }
      } else {
        if ( !isset($this->_renamed[$target["name"]][$vk]) ) {
          $addedfieldnames = "";
          foreach ( $added_fields AS $addfld ) {
            $addedfieldnames .= ( $addedfieldnames=="" ? "" : "&amp;" )."fields[]=".urlencode($addfld);
          }
          if ( $this->getOption("short") ) {
            $altering.=($altering==""?"":$this->_translate(",")."\n")."    ".( $add=(isset($added_fields) && count($added_fields)) && $this->_html ? "<a class=\"script\" href=\"[script]?sc=field&amp;table=".urlencode($target["name"])."&amp;field=".urlencode($vk)."&amp;$addedfieldnames".( ini_get("session.use_cookies") ? "" : ( (boolean)ini_get("session.use_trans_sid") ? "" : "&amp;".session_name()."=".session_id() ) )."\">" : "" ).$this->_highlightString("DROP").( $add && $this->_html ? "</a>" : "" )." ".$this->_objectName($vk);
          } else $result .= $this->_highlightString("ALTER TABLE")." ".$this->_objectName($target["name"])." ".( $add=(isset($added_fields) && count($added_fields)) && $this->_html ? "<a class=\"script\" href=\"[script]?sc=field&amp;table=".urlencode($target["name"])."&amp;field=".urlencode($vk)."&amp;$addedfieldnames".( ini_get("session.use_cookies") ? "" : ( (boolean)ini_get("session.use_trans_sid") ? "" : "&amp;".session_name()."=".session_id() ) )."\">" : "" ).$this->_highlightString("DROP").( $add && $this->_html ? "</a>" : "" )." ".$this->_objectName($vk).$this->_translate(";")."\n";
        }
        $altered++;
      }
    }

    // Checking keys ...
    if ( isset($source["idx"]) ) foreach ( $source["idx"] AS $vk=>$vf ) {
      if ( isset($target["idx"][$vk] ) ) {
        if ( $this->_fieldsdiff($vf["fields"], $target["idx"][$vk]["fields"]) ) {
          if ( $this->getOption("short") ) {
            $altering.=($altering==""?"":$this->_translate(",")."\n")."    ".$this->_highlightString("DROP")." ".( $vf["unique"] && $vk=="PRIMARY" ? $this->_highlightString("PRIMARY KEY") : $this->_highlightString("INDEX")." ".$this->_objectName($vk) ).$this->_translate(",")."\n    ".$this->_highlightString("ADD")." ".$this->_indexString($target["idx"][$vk]);
          } else $result .= $this->_highlightString("ALTER TABLE")." ".$this->_objectName($target["name"])." ".$this->_highlightString("DROP")." ".( $vf["unique"] && $vk=="PRIMARY" ? $this->_highlightString("PRIMARY KEY") : $this->_highlightString("INDEX")." $vk" ).$this->_translate(";\n").$this->_highlightString("ALTER TABLE")." ".$target["name"]." ".$this->_highlightString("ADD")." ".$this->_indexString($target["idx"][$vk]).$this->_translate(";")."\n";
        }
      } else {
        if ( $this->getOption("comment") ) {
          $altering.=($altering==""?"":$this->_translate(",")."\n")."    ".$this->_highlightString("DROP")." ".( $vf["unique"] && $vk=="PRIMARY" ? $this->_highlightString("PRIMARY KEY") : $this->_highlightString("INDEX")." ".$this->_objectName($vk) );
        } else $result .= $this->_highlightString("ALTER TABLE")." ".$this->_objectName($target["name"])." ".$this->_highlightString("DROP")." ".( $vf["unique"] && $vk=="PRIMARY" ? $this->_highlightString("PRIMARY KEY") : $this->_highlightString("INDEX")." $vk" ).$this->_translate(";")."\n";
        $altered++;
      }
    }
    if ( isset($target["idx"]) ) foreach ( $target["idx"] AS $vk=>$vf ) {
      if ( !isset($source["idx"][$vk]) ) {
        if ( $this->getOption("short") ) {
          $altering.=($altering==""?"":$this->_translate(",")."\n")."    ".$this->_highlightString("ADD")." ".$this->_indexString($vf);
        } else $result .= $this->_highlightString("ALTER TABLE")." ".$this->_objectName($target["name"])." ".$this->_highlightString("ADD")." ".$this->_indexString($vf).$this->_translate(";")."\n";
        $altered++;
      }
    }


    // Doing handling of foreign key constraints ...
    if ( !$this->getOption("cfk_back") && isset($source["constraints"]) ) foreach ( $source["constraints"] AS $vk=>$vf ) {
      if ( !isset($target["constraints"][$vk]) ) {
        if ( $this->getOption("short") ) {
          $altering.=($altering==""?"":$this->_translate(",")."\n")."    ".$this->_constraintString($vf, $target["database"], 1, $this->_con->serverVersionString());
        } else $result .= $this->_highlightString("ALTER TABLE")." ".$this->_objectName($target["name"])." ".$this->_constraintString($vf, $target["database"], 1, $this->_con->serverVersionString()).$this->_translate(";")."\n";
        $altered++;
      }
    }
    if ( !$this->getOption("cfk_back") && isset($target["constraints"]) ) foreach ( $target["constraints"] AS $vk=>$vf ) {
      if ( !isset($source["constraints"][$vk]) ) {
        if ( $this->getOption("short") ) {
          $altering.=($altering==""?"":$this->_translate(",")."\n")."    ".$this->_constraintString($vf, $target["database"], 0);
        } else $result .= $this->_highlightString("ALTER TABLE")." ".$this->_objectName($target["name"])." ".$this->_constraintString($vf, $target["database"], 0).$this->_translate(";")."\n";
        $altered++;
      }
    }

    // Charset ...
    if ( $this->getOption("charset") ) {
      if ( $source["collate"]!=$target["collate"] ) {
        $charsetinfo = explode("_", $target["collate"]);

        $charset = $this->_highlightString("DEFAULT CHARSET").$this->_translate("=").$this->_highlightString($charsetinfo[0], "const")." ".$this->_highlightString("COLLATE").$this->_translate("=").$this->_highlightString($target["collate"], "const");

        if ( $this->getOption("short") ) {
          $altering.=($altering==""?"":$this->_translate(", ")).$charset;
        } else $result .= $this->_highlightString("ALTER TABLE")." ".$this->_objectName($target["name"])." ".$charset.$this->_translate(";")."\n";
      }
    }

    // table options ...
    $tableoptions = "";
    if ( $this->getOption("engine") ) {
      if ( $source["engine"]!=$target["engine"] ) {
        if ( $this->getOption("short") ) {
          $tableoptions .= ($tableoptions == "" ? ( $altering == "" ? "    " : "" ) : $this->_translate(" ")).$this->_highlightString("ENGINE").$this->_highlightstring("=", "signs").$this->_highlightstring($target["engine"], "const");
        } else $result .= $this->_highlightString("ALTER TABLE")." ".$this->_objectName($target["name"])." ".$this->_highlightString("ENGINE").$this->_highlightstring("=", "signs").$this->_highlightstring($target["engine"], "const").$this->_translate(";")."\n";
        $altered++;
      }
    }

    if ( $this->getOption("options") ) {
      if ( $source["options"]!=$target["options"] ) {
        if ( $this->getOption("short") ) {
          $tableoptions .= ($tableoptions == "" ? ( $altering == "" ? "    " : "" ) : $this->_translate(" ")).$target["options"];
        } else $result .= $this->_highlightString("ALTER TABLE")." ".$this->_objectName($target["name"])." ".$this->_highlightString($target["options"], "values").$this->_translate(";")."\n";
        $altered++;
      }
    }
    if ( $this->getOption("auto_incr") ) {
      if ( $source["auto_incr"] != $target["auto_incr"] ) {
        if ( $this->getOption("short") ) {
          $tableoptions .= ($tableoptions == "" ? ( $altering == "" ? "    " : "" ) : $this->_translate(" ")).$this->_highlightString("AUTO_INCREMENT").$this->_highlightstring("=", "signs").$this->_con->escapestring($target["auto_incr"]);
        } else $result .= $this->_highlightString("ALTER TABLE")." ".$this->_objectName($target["name"])." ".$this->_highlightString("AUTO_INCREMENT").$this->_highlightstring("=", "signs").$this->_con->escapestring($target["auto_incr"]).$this->_highlightstring(";", "signs")."\n";
        $altered++;
      }
    }
    if ( $this->getOption("comment") ) {
      if ( $source["comment"]!=$target["comment"] ) {
        if ( $this->getOption("short") ) {
          $tableoptions .= ($tableoptions == "" ? ( $altering == "" ? "    " : "" ) : $this->_translate(" ")).$this->_highlightString("COMMENT").$this->_highlightstring("=", "signs")."'".$this->_con->escapestring($target["comment"]).$this->_translate("'");
        } else $result .= $this->_highlightString("ALTER TABLE")." ".$this->_objectName($target["name"])." ".$this->_highlightString("COMMENT").$this->_highlightstring("=", "signs")."'".$this->_con->escapestring($target["comment"]).$this->_translate("';")."\n";
        $altered++;
      }
    }
    if ( $tableoptions != "" ) {
      if ( $this->getOption("short") ) {
        $altering .= ( $altering=="" ? "":$this->_translate(",\n    ")).$tableoptions;
      }
    }

    // the end ...
    if ( $altering != "" ) {
      $result .= $this->_highlightString("ALTER TABLE")." ".$this->_objectName($target["name"])."\n$altering;\n";
      if ( isset($alteredfields) && $this->getOption("changes") ) {
        $result .= "#\n";
        $result .= "#  ".$this->_resources["fieldformat_changed".( count($alteredfields)==1 ? "_single" : "_multiple" )]."\n";
        foreach ( $alteredfields AS $val ) {
          $result .= "#    ".sprintf($this->_resources["fieldformat_changeinfo"], $val["name"], $val["from"], $val["to"])."\n";
        }
        $result .= "#  ".$this->_resources["fieldformat_modification_needed"]."\n";
        $result .= "#";
      }
    }
    return $result;
  }

  function createTable($table) {
    $item = "";
    $item .= $this->_highlightString("CREATE TABLE")." ".$this->_objectName($table["name"])." ".$this->_translate("(")."\n";
    $idx = 1; $max = count($table["fields"]);
    foreach ( $table["fields"] AS $vf ) {
      $item .= "    ".$this->_fieldString($vf).( $idx<$max || count($table["idx"]) ? $this->_translate(",") : "" )."\n";
      $idx++;
    }
    $idx=1; $max = count($table["idx"]);
    if ( isset($table["idx"]) ) foreach ( $table["idx"] AS $vx ) {
      $item .= "    ".$this->_indexString($vx).( $idx < $max || ( isset($table["constraints"]) && count($table["constraints"]) ) ? "," : "" )."\n";
      $idx++;
    }

    // Doing handling of foreign key constraints ...
    if ( isset($table["constraints"]) ) {
      $idx = 1; $max = count($table["constraints"]);
      foreach ( $table["constraints"] AS $vk=>$vf ) {
        $item .= "    ".$this->_constraintString($vf, $table["database"], 2).( $idx < $max ? "," : "" )."\n";
        $idx++;
      }
    }

    $item.=$this->_translate(")");
    if ( $this->getOption("engine") ) {
      if ( isset($table["engine"]) && $table["engine"] != "" ) {
        $item .= " ".$this->_highlightString("ENGINE").$this->_highlightstring("=", "signs").$this->_highlightstring($table["engine"], "const");
      }
    }
    if ( $this->getOption("options") ) {
      if ( isset($table["options"]) && $table["options"] != "" ) {
        $item .= " ".$table["options"];
      }
    }
    if ( $this->getOption("charset") ) {
      if ( isset($table["collate"]) && $table["collate"] != "" ) {
        $charsetinfo = explode("_", $table["collate"]);

        $charset = $this->_highlightString("DEFAULT CHARSET").$this->_translate("=").$this->_highlightString($charsetinfo[0], "const")." ".$this->_highlightString("COLLATE").$this->_translate("=").$this->_highlightString($table["collate"], "const");

        $item .= " ".$charset;
      }
    }
    if ( $this->getOption("comment") ) {
      if ( isset($table["comment"]) && $table["comment"] != "" ) {
        $item .= " ".$this->_highlightString("COMMENT").$this->_highlightstring("=", "signs")."'".$this->_con->escapestring($table["comment"]).$this->_translate("'");
      }
    }
    $item .= $this->_translate(";");
    return $item;
  }

  function dropTable($table) {
    return $this->_highlightString("DROP TABLE")." ".$this->_objectName($table["name"]).$this->_translate(";");
  }

  function getOption($option) {
    if ( isset($this->_options[$option]) ) {
      return $this->_options[$option];
    } else return NULL;
  }

  function insertRecord($table, $data) {
    return $this->_insertreplaceRecord($table, $data, "INSERT");
  }

  function replaceRecord($table, $data) {
    return $this->_insertreplaceRecord($table, $data, "REPLACE");
  }

  function setMySqlVariable($variable, $value) {
    return $this->_highlightString("SET ", "dml").$this->_highlightString($variable, "obj").$this->_highlightString(" = ", "signs").( is_numeric($value) ? $this->_highlightString($value, "num") : $this->_highlightString("'$value'", CMDBH_VALUE) ).$this->_highlightString(";", "signs");
  }

  /*
    "Private" methods ...
  */
  function _alternateNullDefault($type) {
    if ( strtolower(substr($type, 0, 4))=="int(" || strtolower(substr($type, 0, 8))=="bigint(" || strtolower(substr($type, 0, 8))=="smallint(" || strtolower(substr($type, 0, 8))=="tinyint(" || strtolower(substr($type, 0, 10))=="mediumint(" ) {
      $result="0";
    } else if ( strtolower(substr($type, 0, 8))=="datetime" ) {
      $result="0000-00-00 00:00:00";
    } else if ( strtolower(substr($type, 0, 4))=="date" ) {
      $result="0000-00-00";
    } else if ( strtolower(substr($type, 0, 4))=="time" ) {
      $result="00:00:00";
    } else {
      $result="''";
    }
    return $result;
  }

  function _constraintString($idx, $targetdb, $what = 0, $serverversion = NULL) {
    if ( $what == 0 ) {
      $result = $this->_highlightString("ADD CONSTRAINT")." ".$this->_highlightString($idx["type"]) . $this->_translate(" (") . $idx["name"] . $this->_translate(") ") . $this->_highlightString("REFERENCES") . " " . $this->_objectName( ( $targetdb != $idx["targetdb"] ? $idx["targetdb"] . "." : "" ) . $idx["targettable"]) . $this->_translate(" (") . $idx["targetcols"] . $this->_translate(")").( isset($idx["params"]) && trim($idx["params"]) != "" ? $this->_highlightString($idx["params"]) : "" );
    } else if ( $what == 1 && isset($serverversion) && $this->_con->serverVersionCompare("4.0.13") >= 0 ) {
      $result = $this->_highlightString("DROP ".$idx["type"])." ".$idx["id"];
    } else if ( $what == 2 ) {
      $result = $this->_highlightString("CONSTRAINT")." ".$this->_highlightString($idx["type"]) . $this->_translate(" (") . $idx["name"] . $this->_translate(") ") . $this->_highlightString("REFERENCES") . " " . $this->_objectName( ( $targetdb != $idx["targetdb"] ? $idx["targetdb"] . "." : "" ) . $idx["targettable"]) . $this->_translate(" (") . $idx["targetcols"] . $this->_translate(")").( isset($idx["params"]) && trim($idx["params"]) != "" ? $this->_highlightString($idx["params"]) : "" );
    } else $result = "";
    return $result;
  }

  function _fieldsDiff($f1, $f2) {
    if ( count($f1) != count($f2) ) return TRUE;
    foreach ($f1 AS $key=>$value) {
      if ( !isset($f2[$key]) || $value["name"]!=$f2[$key]["name"] ) return TRUE;
    }
    return FALSE;
  }

  function _fieldString($field, $withname=TRUE) {
    $result = "";
    if ( $withname ) $result .= $this->_objectName($field["name"])." ";
    $result .= $this->_typeString($field["type"]);
    $result .= " ".$this->_highlightString(( $field["null"] ? "" : "NOT " )."NULL", "const");

    if(!isset($field["extra"]) || strstr($field["extra"], "auto_increment") === false)
    {
      $result .= " ".$this->_highlightString("DEFAULT", "ddl");
      if ( isset($field["default"]) ) {
        $result .= " ".$this->_highlightstring("'".$field["default"]."'", "values");
      } else {
        $result .= " ".($field["null"] ? $this->_highlightString("NULL", "const") : $this->_highlightstring($this->_alternateNullDefault($field["type"]), "values"));
      }
    }

    if ( isset($field["comment"]) && $this->_con->serverVersionCompare("4.1.0") >= 0 ) {
      $result .= " ".$this->_highlightString("COMMENT")." ".$this->_highlightString("'".$field["comment"]."'", "values");
    }
    if ( $this->getOption("charset") && isset($field["collate"]) && $this->_con->serverVersionCompare("4.1.0") >= 0 ) {
      $result .= " ".$this->_highlightString("COLLATE")." ".$this->_highlightString($field["collate"], "const");
    }
    if ( isset($field["extra"]) && $field["extra"]!="" ) {
      $result .= " ".$field["extra"];
    }

    return $result;
  }

  function _highlightString($what, $kind = "ddl") {
    if ( $this->_highlight ) {
      return "<span class=\"$kind\">$what</span>";
    } else return $what;
  }

  function _indexNull($idx, $table="b") {
    $fields="";
    if ( isset($idx["fields"]) && is_array($idx["fields"]) ) foreach ( $idx["fields"] AS $key=>$value ) {
      $fields.=( $fields=="" ? "" : " AND " )."$table.$key IS NULL";
    }
    return $fields;
  }

  function _indexOn($idx, $tableA="a", $tableB="b") {
    $fields="";
    if ( isset($idx["fields"]) && is_array($idx["fields"]) ) foreach ( $idx["fields"] AS $key=>$value ) {
      $fields.=( $fields=="" ? "" : " AND " )."$tableA.$key=$tableB.$key";
    }
    return $fields;
  }

  function _indexString($idx) {
    $result = ( $idx["type"] == "FULLTEXT" ? $this->_highlightString("FULLTEXT INDEX") . ( isset($idx["name"]) ? " ".$this->_objectName($idx["name"]) : "" ) : ( $idx["unique"] ? ( $idx["name"]=="PRIMARY" ? $this->_highlightString("PRIMARY KEY") : $this->_highlightString("UNIQUE")." ".$this->_objectName($idx["name"]) ) : $this->_highlightString("INDEX")." ".$this->_objectName($idx["name"]) ) )." (";
    $i = 1; $im = count($idx["fields"]);
    foreach ( $idx["fields"] AS $vf ) {
      $result .= $this->_objectName($vf["name"]).( isset($vf["sub"]) ? "(".$vf["sub"].")" : "" ).( $i<$im ? ", " : "" );
      $i++;
    }
    $result .= ")";
    return $result;
  }

  function _insertreplaceRecord($table, $data, $what = "INSERT") {
    $values = $fields = "";
    foreach ( $data AS $fieldname => $fieldvalue ) {
      $fields .= ($fields==""?"":",").$this->_objectName($fieldname);
      $values .= ($values==""?"":",")."'".$this->_con->escapestring($fieldvalue)."'";
    }
    return $this->_highlightString("$what INTO", "dml")." ".$this->_objectName($table)." ".$this->_translate("(").$fields.$this->_translate(") ").$this->_highlightString("VALUES", "dml").$this->_translate(" (").$values.$this->_translate(");");
  }

  function _objectName($name) {
    return $this->_highlightString($this->getOption("backticks") || preg_match("/[^a-z0-9_$]/i", $name) || in_array(strtoupper($name), $this->_reservedwords) ? "`".$name."`" : $name, "obj");
  }

  function _translate($item) {
    if ( $this->_highlight ) foreach ( $this->_translates AS $types ) {
      foreach ( $types["items"] AS $items ) {
        $item=preg_replace($items, $types["translate"], $item);
      }
    }
    return str_replace("  ", "&nbsp;&nbsp;", $item);
  }

  function _typeString($type) {
    if ( $this->_highlight ) $type = preg_replace(array("/([(])(\\d+)([)])/", "/([(])(([']\\w+['])([,]\\s*(([']\\w+['])))*)([)])/"), array("<span class=\"signs\">$1</span><span class=\"num\">$2</span><span class=\"signs\">$3</span>", "<span class=\"signs\">$1</span><span class=\"values\">$2</span><span class=\"signs\">$7</span>"), $type);
    return $this->_highlightString($type, "type");
  }
}

function generateScript($cfg_source, $cfg_target) {
  $result = '';
  $syntax = false;
  $html = false;

  $sourcehost = $cfg_source["hostname"];
  $targethost = $cfg_target["hostname"];

  // Flags for temporary databases ...
  $s_temp = $t_temp = FALSE;

  $scon = new cConnection($sourcehost, $cfg_source["username"], $cfg_source["password"]);
  if ( $scon->open() ) {

    $s_db = $cfg_source["database"];
    if ( isset($s_db) && !empty($s_db) && $scon->selectDatabase($s_db) ) {

      $tcon = new cConnection($targethost,  $cfg_target["username"], $cfg_target["password"]);
      if ( $tcon->open() ) {
          $t_db = $cfg_target["database"];

        if ( isset($t_db) && !empty($t_db) && $tcon->selectDatabase($t_db) ) {

          $builder = new cCommandBuilder($tcon, $syntax, $html);

          $s_tab = $scon->fetchTables($s_db);
          $t_tab = $tcon->fetchTables($t_db);

          if ( is_array($t_tab) ) foreach ( $t_tab AS $key=>$value ) {
            if ( !isset($s_tab[$key]) ) {
              $item = $builder->createTable($t_tab[$key]);
              $result .= $item == "" ? "" : $item."\n\n";
            }
          }
          if ( is_array($s_tab) ) foreach ( $s_tab AS $key=>$value ) {
            if ( isset($t_tab[$key]) ) {
              $item = $builder->alterTable($s_tab[$key], $t_tab[$key]);
              $result .= $item == "" ? "" : $item."\n\n";
            } else {
              $item = $builder->dropTable($s_tab[$key]);
              $result .= $item == "" ? "" : $item."\n\n";
            }
          }

          if ( is_array($s_tab) ) foreach ( $s_tab AS $key=>$value ) {
            if ( isset($t_tab[$key]) ) {
              $item = $builder->alterTableContraints($s_tab[$key], $t_tab[$key]);
              $result .= $item == "" ? "" : $item."\n\n";
            }
          }

        } else $result .= $tcon->error()."\n";

        $tcon->close();
      } else $result .= $tcon->error()."\n";
    } else $result .= $scon->error()."\n";

    $scon->close();
  } else $result .= $scon->error()."\n";

  if($result)
  {
    return "\n".$builder->setMySqlVariable("FOREIGN_KEY_CHECKS", 0)."\n\n" .
           $result .
           $builder->setMySqlVariable("FOREIGN_KEY_CHECKS", 1)."\n";
  }
}
?>
