<?php
//
//  Copyright (c) 2009 Facebook
//
//  Licensed under the Apache License, Version 2.0 (the "License");
//  you may not use this file except in compliance with the License.
//  You may obtain a copy of the License at
//
//      http://www.apache.org/licenses/LICENSE-2.0
//
//  Unless required by applicable law or agreed to in writing, software
//  distributed under the License is distributed on an "AS IS" BASIS,
//  WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
//  See the License for the specific language governing permissions and
//  limitations under the License.
//

//
// This file defines the interface iXHProfRuns and also provides a default
// implementation of the interface (class XHProfRuns).
//

/**
 * iXHProfRuns interface for getting/saving a XHProf run.
 *
 * Clients can either use the default implementation,
 * namely XHProfRuns_Default, of this interface or define
 * their own implementation.
 *
 * @author Kannan
 */
interface iXHProfRuns {

  /**
   * Returns XHProf data given a run id ($run) of a given
   * type ($type).
   *
   * Also, a brief description of the run is returned via the
   * $run_desc out parameter.
   */
  public function get_run($run_id, $type, &$run_desc);

  /**
   * Save XHProf data for a profiler run of specified type
   * ($type).
   *
   * The caller may optionally pass in run_id (which they
   * promise to be unique). If a run_id is not passed in,
   * the implementation of this method must generated a
   * unique run id for this saved XHProf run.
   *
   * Returns the run id for the saved XHProf run.
   *
   */
  public function save_run($xhprof_data, $type, $run_id = null);
}


/**
 * XHProfRuns_Default is the default implementation of the
 * iXHProfRuns interface for saving/fetching XHProf runs.
 *
 * This modified version of the file uses a MySQL backend to store
 * the data, it also stores additional information outside the run
 * itself (beyond simply the run id) to make comparisons and run
 * location easier
 * 
 * Configuration steps:
 *  1 - Set the database credentials in the class properties
 *  2 - Create the database, create table syntax provided below
 *  3 - Set the prefix for this server or application
 *  4 - Configure the urlSimilator method (bottom of this file)
 *  5 - Ensure you're using the callgraph_utils.php and xprof_runs.php 
 * files from this repo, as they've been updated to deal with get_run() returning
 * an array.
 *
 * @author Kannan
 * @author Paul Reinheimer (http://blog.preinheimer.com)
 */
class XHProfRuns_Default implements iXHProfRuns {

  private $dir = '';
  public $prefix = 't11_';
  public $run_details = null;
  private $dbName = 'xhprof';
  private $dbhost = 'localhost';
  private $dbuser = 'xhprof';
  private $dbpass = '6549qHC6R8Yi';
  protected $linkID;

  public function __construct($dir = null) 
  {
    $this->db();
  }

  protected function db()
  {
    $linkid = mysql_connect($this->dbhost, $this->dbuser, $this->dbpass);
    if ($linkid === FALSE)
    {
      xhprof_error("Could not connect to db");
      $run_desc = "could not connect to db";
      return null;
    }
    mysql_select_db($this->dbName, $linkid);
    $this->linkID = $linkid; 
  }
  /**
  * When setting the `id` column, consdier the length of the prefix you're specifying in $prefix
  * 
  *
CREATE TABLE `details` (
  `id` char(17) NOT NULL,
  `url` varchar(255) default NULL,
  `c_url` varchar(255) default NULL,
  `timestamp` timestamp NOT NULL default CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP,
  `server name` varchar(64) default NULL,
  `perfdata` text,
  `type` tinyint(4) default NULL,
  `cookie` text,
  `post` text,
  `get` text,
  `pmu` int(11) default NULL,
  `wt` int(11) default NULL,
  `cpu` int(11) default NULL,
  PRIMARY KEY  (`id`),
  KEY `url` (`url`),
  KEY `c_url` (`c_url`),
  KEY `cpu` (`cpu`),
  KEY `wt` (`wt`),
  KEY `pmu` (`pmu`),
  KEY `timestamp` (`timestamp`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8 
  
*/

    
  private function gen_run_id($type) 
  {
    return uniqid($this->prefix);
  }
  
  
  public function getRuns($stats)
  {
      $query = "SELECT * FROM `details` ";
      $skippers = array("limit", "order by", "group by", "where");
      $hasWhere = false;
      
      foreach($stats AS $column => $value)
      {
          
          if (in_array($column, $skippers))
          {
              continue;
          }
          if ($hasWhere === false)
          {
              $query .= " WHERE ";
              $hasWhere = true;
          }
          if (strlen($value) == 0)
          {
              $query .= $column;
          }
          $query .= " `$column` = '$value' ";
      }
      
      if (isset($stats['where']))
      {
          if ($hasWhere === false)
          {
              $query .= " WHERE ";
              $hasWhere = true;
          }
          $query .= $stats['where'];
      }
      
      if (isset($stats['order by']))
      {
          $query .= " ORDER BY `{$stats['order by']}` DESC";
      }
      
      if (isset($stats['group by']))
      {
          $query .= " GROUP BY `{$stats['group by']}` ";
      }
      
      if (isset($stats['limit']))
      {
          $query .= " LIMIT {$stats['limit']} ";
      }

      $resultSet = mysql_query($query);
      return $resultSet;
  }
  
  
  /**
  * Retreives a run from the database, 
  * 
  * @param string $run_id unique identifier for the run being requested
  * @param mixed $type
  * @param mixed $run_desc
  * @return mixed
  */
  public function get_run($run_id, $type, &$run_desc) 
  {

    $query = "SELECT * FROM `details` WHERE `id` = '$run_id'";
    $resultSet = mysql_query($query, $this->linkID);
    $data = mysql_fetch_assoc($resultSet);
    
    //The Performance data is compressed lightly to avoid max row length
    $contents = unserialize(gzuncompress($data['perfdata']));
    
    //This data isnt' needed for display purposes, there's no point in keeping it in this array
    unset($data['perfdata']);

    if (is_null($this->run_details))
    {
        $this->run_details = $data;
    }else
    {
        $this->run_details[0] = $this->run_details; 
        $this->run_details[1] = $data;
    }
    
    $run_desc = "XHProf Run (Namespace=$type)";
    $this->getRunComparativeData($data['url'], $data['c_url']);
    
    return array($contents, $data);
  }
  
  /**
  * Get stats (pmu, ct, wt) on a url or c_url
  * 
  * @param array $url
  */
  public function getUrlStats($data)
  {

      $limit = $data['limit'];
      if (isset($data['c_url']))
      {
          $url = mysql_real_escape_string($data['c_url']);
          $query = "SELECT `id`, UNIX_TIMESTAMP(`timestamp`) as `timestamp`, `pmu`, `wt`, `cpu` FROM `details` WHERE `c_url` = '$url' LIMIT $limit";
      }else
      {
          $url = mysql_real_escape_string($data['url']);
          $query = "SELECT `id`, UNIX_TIMESTAMP(`timestamp`) as `timestamp`, `pmu`, `wt`, `cpu` FROM `details` WHERE `url` = '$url' LIMIT $limit";
      }
      $rs = mysql_unbuffered_query($query, $this->linkID);
      return $rs;
  }
  
  public function getRunComparativeData($url, $c_url)
  {
      //Runs same URL
      //  count, avg/min/max for wt, cpu, pmu
      $query = "SELECT count(`id`), avg(`wt`), min(`wt`), max(`wt`),  avg(`cpu`), min(`cpu`), max(`cpu`), avg(`pmu`), min(`pmu`), max(`pmu`) FROM `details` WHERE `url` = '$url'";
      $rs = mysql_query($query, $this->linkID);
      $row = mysql_fetch_assoc($rs);
      $row['url'] = $url;
      global $comparative;
      $comparative['url'] = $row;
      unset($row);
      
      //Runs same c_url
      //  count, avg/min/max for wt, cpu, pmu
      $query = "SELECT count(`id`), avg(`wt`), min(`wt`), max(`wt`),  avg(`cpu`), min(`cpu`), max(`cpu`), avg(`pmu`), min(`pmu`), max(`pmu`) FROM `details` WHERE `c_url` = '$c_url'";
      $rs = mysql_query($query, $this->linkID);
      $row = mysql_fetch_assoc($rs);
      $row['url'] = $c_url;
      $comparative['c_url'] = $row;
      unset($row);
      return $comparative;
  }

    public function save_run($xhprof_data, $type, $run_id = null, $xhprof_details = null) 
    {
        global $_xhprof;

        if ($run_id === null) {
          $run_id = $this->gen_run_id($type);
        }
        
        $get = mysql_real_escape_string(serialize($_GET), $this->linkID);
        $cookie = mysql_real_escape_string(serialize($_COOKIE), $this->linkID);
        
        //This code has not been tested
        if ($_xhprof['savepost'])
        {
            $post = mysql_real_escape_string(serialize($_POST), $this->linkID);    
        }else
        {
            $post = mysql_real_escape_string(serialize(array("Skipped" => "Post data omitted by rule")));
        }
        
        
        $pmu = $xhprof_data['main()']['pmu'];
        $wt = $xhprof_data['main()']['wt'];
        $cpu = $xhprof_data['main()']['cpu'];
        
        //The MyISAM table type has a maxmimum row length of 65,535bytes, without compression XHProf data can exceed that. 
        $xhprof_data = mysql_real_escape_string(gzcompress(serialize($xhprof_data), 2));
        
        $url = mysql_real_escape_string($_SERVER['REQUEST_URI']);
        $c_url = mysql_real_escape_string($this->urlSimilartor($_SERVER['REQUEST_URI']));
        $serverName = mysql_real_escape_string($_SERVER['SERVER_NAME']);
        $type = isset($xhprof_details['type']) ? $xhprof_details['type'] : 0;
        $timestamp = mysql_real_escape_string($_SERVER['REQUEST_TIME']);

        
        
        $query = "INSERT INTO `details` (`id`, `url`, `c_url`, `timestamp`, `server name`, `perfdata`, `type`, `cookie`, `post`, `get`, `pmu`, `wt`, `cpu`) VALUES('$run_id', '$url', '$c_url', FROM_UNIXTIME('$timestamp'), '$serverName', '$xhprof_data', '$type', '$cookie', '$post', '$get', '$pmu', '$wt', '$cpu')";
        
        mysql_query($query, $this->linkID);
        if (mysql_affected_rows($this->linkID) == 1)
        {
            return $run_id;
        }else
        {
            global $_xhprof;
            if ($_xhprof['display'] === true)
            {
                echo "Failed to insert: $query <br>\n";
                var_dump(mysql_error($this->linkID));
                var_dump(mysql_errno($this->linkID));
            }
            return -1;
        }
  }
  
  
  /**
  * The goal of this function is to accept the URL for a resource, and return a "simplified" version
  * thereof. Similar URLs should become identical. Consider:
  * http://example.org/stories.php?id=23
  * http://example.org/stories.php?id=24
  * Under most setups these two URLs, while unique, will have an identical execution path, thus it's
  * worthwhile to consider them as identical. The script will store both the original URL and the
  * Simplified URL for display and comparison purposes. 
  * 
  * @param string $url The URL to be simplified
  * @return string The simplified URL 
  */
  protected function urlSimilartor($url)
  {
      //This is an example 
      $url = preg_replace("!\d{4}!", "", $url);
      return $url;
  }
}
