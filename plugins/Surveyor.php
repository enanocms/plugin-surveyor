<?php
/*
Plugin Name: Survey/Poll plugin
Plugin URI: http://enanocms.org/Survey_plugin
Description: Adds a customizable poll to your sidebar. You can have any number of options, and the poll is randomly selected from a list of enabled polls. <b>Important:</b> When first loaded, this plugin creates the following tables in your Enano database: enano_polls, enano_poll_options, enano_poll_results
Author: Dan Fuhry
Version: 0.3
Author URI: http://enanocms.org/

Changelog:
  9/27/06:
  Updated to be valid XHTML 1.1
  11/2/07:
  Made compatible with Loch Ness and later (oops!)
  11/29/08:
  One change a year! Moved to Mercurial and brought up to date with naming conventions.
*/

/*
 * Surveyor
 * Version 0.3
 * Copyright (C) 2006-2007 Dan Fuhry
 *
 * This program is Free Software; you can redistribute and/or modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2 of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied
 * warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for details.
 */

global $db, $session, $paths, $template, $plugins; // Common objects

// Uncomment this line once the plugin has been enabled for the first time and at least one page has been requested
define('ENANO_SURVEYOR_TABLES_CREATED', 'true');

  if(!defined('ENANO_SURVEYOR_TABLES_CREATED')) {
  $e = $db->sql_query('CREATE TABLE IF NOT EXISTS '.table_prefix.'polls(
                         poll_id mediumint(5) NOT NULL auto_increment,
                         poll_question text,
                         end_time datetime,
                         enabled tinyint(1),
                         PRIMARY KEY (poll_id)
                       );');
  if(!$e) $db->_die('Surveyor plugin: error creating table '.table_prefix.'polls.');
  
  $e = $db->sql_query('CREATE TABLE IF NOT EXISTS '.table_prefix.'poll_options(
                         item_id mediumint(5) NOT NULL auto_increment,
                         poll_id mediumint(5) NOT NULL,
                         option_value text,
                         PRIMARY KEY (item_id)
                       );');
  if(!$e) $db->_die('Surveyor plugin: error creating table '.table_prefix.'poll_options.');
  
  $e = $db->sql_query('CREATE TABLE IF NOT EXISTS '.table_prefix.'poll_results(
                         poll_id mediumint(5),
                         item_id mediumint(5),
                         user_id mediumint(8),
                         ip_addr varchar(10)
                       );');
  if(!$e) $db->_die('Surveyor plugin: error creating table '.table_prefix.'poll_results.');
  
}

class Surveyor_Plugin {
  var $header_added;
  function html($pid = false)
  {
    global $db, $session, $paths, $template, $plugins; // Common objects
    $s = '';
    if(is_int($pid)) $s = ' AND p.poll_id='.$pid;
    $ret = '';
    if(!is_int($pid)) $ret .= '<div id="mdgVotePlugin" style="padding: 5px;">';
    $ret .= '<form id="survey" action="'.makeUrlNS('Special', 'SubmitVote').'" method="post"><div>';
    $q = $db->sql_query('SELECT p.poll_id AS pid,o.item_id AS oid,p.poll_question AS q,o.option_value AS v FROM '.table_prefix.'polls p, '.table_prefix.'poll_options o WHERE p.poll_id=o.poll_id AND p.enabled=1'.$s.';');
    if(!$q) $db->_die('An error occurred whilst selecting the poll data.');
    $l = Array();
    while($row = $db->fetchrow())
    {
      if(!isset($l[$row['q']]))
      {
        $l[$row['q']] = Array();
        $l[$row['q']]['pid'] = $row['pid'];
      }
      $l[$row['q']][] = $row;
    }
    if(sizeof($l) < 1) return 'No polls created yet';
    $ques = array_rand($l);
    $poll_id = $l[$ques]['pid'];
    unset($l[$ques]['pid']);
    if(!$poll_id) die_semicritical('Surveyor plugin error', 'Invalid poll ID: '.$poll_id);
    $q = $db->sql_query('SELECT * FROM '.table_prefix.'poll_results WHERE poll_id='.$poll_id.' AND ( ip_addr=\''.mysql_real_escape_string(ip2hex($_SERVER['REMOTE_ADDR'])).'\' OR user_id='.$session->user_id.' );');
    if(!$q) $db->_die('Error obtaining vote result information');
    if($db->numrows() > 0)
    {
      if(!isset($_GET['results'])) $_GET['results'] = '';
      $_REQUEST['poll_id'] = $poll_id.'';
      $_GET['poll_id'] = $poll_id.'';
      return __enanoVoteAjaxhandler(false);
    }
    $ret .= '<input type="hidden" name="poll_id" value="'.$poll_id.'" />';
    $ret .= '<span style="font-weight: bold;">'.$ques.'</span><br />';
    foreach($l[$ques] as $o)
    {
      $ret .= '<label><input type="radio" name="item_id" value="'.addslashes($o['oid']).'" /> '.$o['v'].'</label><br />';
    }
    $ret .= '<br /><div style="text-align: center"><input type="button" value="Vote!" onclick="ajaxSubmitVote(); return false;" /> <input type="button" onclick="ajaxVoteResults(); return false;" value="View results" /></div>';
    $ret .= '</div></form>';
    if(!is_int($pid)) $ret .= '</div>';
    
    $template->add_header('
      <script type="text/javascript">
      //<![CDATA[
        function ajaxSubmitVote()
        {
          frm = document.forms.survey;
          radios = document.getElementsByTagName(\'input\');
          optlist = new Array();
          j = 0;
          for(i=0;i<radios.length;i++)
          {
            if(radios[i].name == \'item_id\')
            {
              optlist[j] = radios[i];
              j++;
            }
          }
          val = \'enanoNuLl\';
          for(i=0;i<optlist.length;i++)
          {
            if(optlist[i].checked) val = optlist[i].value;
          }
          if(val==\'enanoNuLl\') { alert(\'Please select an option.\'); return; }
          ajaxPost(\''.makeUrlNS('Special', 'SubmitVote', 'redirect=no').'\', \'poll_id=\'+frm.poll_id.value+unescape(\'%26\')+\'item_id=\'+val, function() {
              if(ajax.readyState==4)
              {
                ajaxVoteResults();
              }
            });
        }
        function ajaxVoteForm()
        {
          ajaxGet(\''.makeUrlNS('Special', 'SubmitVote', 'voteform\'+unescape(\'%26\')+\'poll_id='.$poll_id).'\', function() {
              if(ajax.readyState==4)
              {
                document.getElementById("mdgVotePlugin").innerHTML = ajax.responseText;
              }
            });
        }
        function ajaxVoteResults()
        {
          ajaxGet(\''.makeUrlNS('Special', 'SubmitVote', 'results\'+unescape(\'%26\')+\'poll_id='.$poll_id).'\', function() {
              if(ajax.readyState==4)
              {
                document.getElementById("mdgVotePlugin").innerHTML = ajax.responseText;
              }
            });
        }
        // ]]>
      </script>
      ');
    
    return $ret;
  }
}

$plugins->attachHook('base_classes_initted', '
  $paths->add_page(Array(
      \'name\'=>\'Submit a poll vote\',
      \'urlname\'=>\'SubmitVote\',
      \'namespace\'=>\'Special\',
      \'special\'=>0,\'visible\'=>0,\'comments_on\'=>0,\'protected\'=>1,\'delvotes\'=>0,\'delvote_ips\'=>\'\',
      ));
  $paths->addAdminNode(\'Plugin configuration\', \'Manage polls\', \'PollEditor\');
  ');

function __mdgPluginDoSurvey() {
  global $db, $session, $paths, $template, $plugins; // Common objects
  $s = new Surveyor_Plugin();
  $template->sidebar_widget('Poll', $s->html());
}
$plugins->attachHook('compile_template', '__mdgPluginDoSurvey();');

function page_Special_SubmitVote()
{
  echo __enanoVoteAjaxhandler();
}
function __enanoVoteAjaxhandler($allow_vote = true)
{
  global $db, $session, $paths, $template, $plugins; // Common objects
  $ret = '';
  if(!isset($_REQUEST['poll_id'])) { die_semicritical('Critical error in plugin', '$_REQUEST[\'poll_id\'] is not set'); $paths->main_page(); exit; }
  if(!preg_match('/^([0-9]+)$/', $_REQUEST['poll_id'])) die('Hacking attempt'); // Prevents SQL injection from the URL
  if(isset($_GET['results']))
  {
    $q = $db->sql_query('SELECT p.poll_id AS pid,o.item_id AS oid,p.poll_question AS q,o.option_value AS v FROM '.table_prefix.'polls p, '.table_prefix.'poll_options o WHERE p.poll_id=o.poll_id AND p.poll_id=\''.$_GET['poll_id'].'\';');
    $l = Array();
    while($row = $db->fetchrow())
    {
      if(!isset($l[$row['q']]))
      {
        $l[$row['q']] = Array();
        $l[$row['q']]['pid'] = $row['pid'];
      }
      $l[$row['q']][] = $row;
    }
    // The reason we use array_rand() here? Simple - we used a WHERE clause to select only one poll, and since poll_id is
    // a primary key, there is only one match in the polls table. Therefore, array_rand() effectively returns the first key in the array
    $ques = array_rand($l);
    $poll_id = $l[$ques]['pid'];
    unset($l[$ques]['pid']);
    $results = Array();
    foreach($l[$ques] as $o)
    {
      $q = $db->sql_query('SELECT * FROM '.table_prefix.'poll_results WHERE poll_id='.$_GET['poll_id'].' AND item_id='.$o['oid'].';');
      if(!$q) $db->_die('The poll result data could not be selected.');
      $results[$o['v']] = $db->numrows();
    }
    $k = array_keys($results);
    $total = 0;
    foreach($k as $key)
    {
      $total = $total + $results[$key];
    }
    if($total==0) $total = 1;
    // Figure out the percentage, round it, and send the images
    $ret .= '<table border="0" style="margin: 0; padding: 0; width: 100%;" cellspacing="0" cellpadding="0">';
    $ret .= '<tr><td colspan="2"><b>'.$ques.'</b></td></tr>';
    foreach($k as $key)
    {
      $this_width = round(100*($results[$key] / $total));
      if ( $this_width == 0 )
        $this_width = 4;
      $ret .= '<tr>
                 <td colspan="2">'.$key.'</td>
               </tr>
               <tr>
                 <td style="padding: 0px 4px 0px 4px;">
                   <img alt="Poll bar" src="'.scriptPath.'/plugins/surveyor/poll-bar-left.png"
                    width="2" height="12" style="margin: 2px 0px 2px 0px; padding: 0;" hspace="0" 
                    
                  /><img alt="Poll bar" src="'.scriptPath.'/plugins/surveyor/poll-bar-middle.png"
                    width="'.$this_width.'" height="12" style="margin: 2px 0px 2px 0px; padding: 0;" hspace="0"
                    
                  /><img alt="Poll bar" src="'.scriptPath.'/plugins/surveyor/poll-bar-right.png"
                    width="2" height="12" style="margin: 2px 0px 2px 0px; padding: 0;" hspace="0" />
                    
                  </td>
                  
                  <td>
                    ['.$results[$key].']
                  </td>
                </tr>';
    }
    if($allow_vote) $ret .= '<tr><td colspan="2" style="text-align: center"><input type="button" value="Cast your vote" onclick="ajaxVoteForm(); return false;" /></td></tr>';
    $ret .= '</table>';
  } elseif(isset($_GET['voteform'])) {
    $s = new Surveyor_Plugin();
    $pid = (int)$_GET['poll_id'];
    $ret .= $s->html($pid);
  } else {
    if(!isset($_POST['item_id']) || (isset($_POST['item_id']) && !preg_match('/^([0-9]+)$/', $_POST['item_id']))) die('Hacking attempt'); // Once again, ensure that only numbers are passed on the URL
    if(isset($_GET['redirect']) && $_GET['redirect'] == 'no')
    {
      header('Content-type: text/plain');
      $q = $db->sql_query('SELECT * FROM '.table_prefix.'poll_results WHERE poll_id='.$_POST['poll_id'].' AND ( ip_addr=\''.mysql_real_escape_string(ip2hex($_SERVER['REMOTE_ADDR'])).'\' OR user_id='.$session->user_id.' );');
      if(!$q) $db->_die('Error obtaining vote result information');
      if($db->numrows() > 0)
      {
        die('Looks like you already voted in this poll.');
      }
      $q = $db->sql_query('INSERT INTO '.table_prefix.'poll_results(poll_id,item_id,ip_addr,user_id) VALUES('.$_POST['poll_id'].', '.$_POST['item_id'].', \''.ip2hex($_SERVER['REMOTE_ADDR']).'\', '.$session->user_id.');');
      if(!$q) $db->_die('Your vote could not be inserted into the results table.');
      $ret .= 'Your vote has been cast.';
    } else {
      $paths->main_page();
    }
  }
  return $ret;
}

function page_Admin_PollEditor()
{
  global $db, $session, $paths, $template, $plugins; if(!$session->sid_super || $session->user_level < 2) { header('Location: '.makeUrl($paths->nslist['Special'].'Administration'.urlSeparator.'noheaders')); die('Hacking attempt'); }
  if(isset($_POST['newpoll_create']))
  {
    $date_string = $_POST['newpoll_year'].'-'.$_POST['newpoll_month'].'-'.$_POST['newpoll_day'].' '.$_POST['newpoll_hour'].':'.$_POST['newpoll_minute'].':00';
    if(isset($_POST['newpoll_never']))
      $date_string = '9999-01-01 00:00:00';
    if(!$db->sql_query('INSERT INTO '.table_prefix.'polls(poll_question,enabled,end_time) VALUES(\''.mysql_real_escape_string($_POST['newpoll_name']).'\', 1, \''.$date_string.'\');')) $db->_die('The poll information could not be inserted.');
    $q = $db->sql_query('SELECT poll_id FROM '.table_prefix.'polls WHERE poll_question=\''.mysql_real_escape_string($_POST['newpoll_name']).'\' AND end_time=\''.$date_string.'\';');
    if(!$q) $db->_die('The new poll ID could not be fetched.');
    $r = $db->fetchrow();
    if(!$db->sql_query('INSERT INTO '.table_prefix.'poll_options(poll_id,option_value) VALUES('.$r['poll_id'].', \'First option\')')) $db->_die('The default option data could not be inserted.');
  }
  
  echo '<form action="'.makeUrl($paths->nslist['Special'].'Administration', 'module='.$paths->cpage['module'], true).'" method="post">';
    ?>
    <h3>Create a new poll</h3>
    <p>Question: <input name="newpoll_name" type="text" /></p>
    <p>Ending time:
    <select name="newpoll_month">
      <option value="01">January</option>
      <option value="02">February</option>
      <option value="03">March</option>
      <option value="04">April</option>
      <option value="05">May</option>
      <option value="06">June</option>
      <option value="07">July</option>
      <option value="08">August</option>
      <option value="09">September</option>
      <option value="10">October</option>
      <option value="11">November</option>
      <option value="12">December</option>
    </select>
    <select name="newpoll_day">
    <?php
      // This would be too hard to write by hand, so let's use a simple for-loop to take care of it for us
      for($i=1;$i<=31;$i++)
      {
        if($i < 10) $t = '0'.$i;
        else $t = $i.'';
        echo '<option value="'.$t.'">'.$t.'</option>'."\n      "; 
      }
    ?>
    </select>,
    <select name="newpoll_year">
    <?php
      // What the heck? Let's do it again :-D
      for($i=2006;$i<=2026;$i++)
      {
        echo '<option value="'.$i.'">'.$i.'</option>'."\n      "; 
      }
    ?>
    </select>&nbsp;&nbsp;
    <select name="newpoll_hour">
    <?php
      for($i=0;$i<=23;$i++)
      {
        if($i < 10) $t = '0'.$i;
        else $t = $i.'';
        echo '<option value="'.$t.'">'.$t.'</option>'."\n      "; 
      }
    ?>
    </select>:<select name="newpoll_minute">
    <?php
      for($i=0;$i<=59;$i++)
      {
        if($i < 10) $t = '0'.$i;
        else $t = $i.'';
        echo '<option value="'.$t.'">'.$t.'</option>'."\n      "; 
      }
    ?>
    </select><br />&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<label><input type="checkbox" name="newpoll_never" />Never ends</label></p>
    
    <p><input type="submit" name="newpoll_create" value="Create poll" /></p>
    <?php
  echo '</form>';
  
  $q = $db->sql_query('SELECT p.poll_id AS pid,o.item_id AS oid,p.poll_question AS q,o.option_value AS v,p.end_time,p.enabled FROM '.table_prefix.'polls p, '.table_prefix.'poll_options o WHERE p.poll_id=o.poll_id;');
  if(!$q) $db->_die('The poll information could not be selected.');
  $l = Array();
  while($row = $db->fetchrow())
  {
    if(!isset($l[$row['q']]))
    {
      $l[$row['q']] = Array();
    }
    $l[$row['q']][] = $row;
  }
  $k = array_keys($l);
  foreach ( $k as $key )
  {
    $c = $l[$key][0];
    $poll_id = $c['pid'];
    $enabled = $c['enabled'];
    $ending_time = $c['end_time'];
    $year = substr($ending_time, 0, 4);
    $month = substr($ending_time, 5, 2);
    $day = substr($ending_time, 8, 2);
    $hour = substr($ending_time, 11, 2);
    $minute = substr($ending_time, 14, 2);
    if(isset($_POST['poll_'.$c['pid'].'_update']))
    {
      $date_string = $_POST['poll_'.$c['pid'].'_year'].'-'.$_POST['poll_'.$c['pid'].'_month'].'-'.$_POST['poll_'.$c['pid'].'_day'].' '.$_POST['poll_'.$c['pid'].'_hour'].':'.$_POST['poll_'.$c['pid'].'_minute'].':00';
      if(isset($_POST['poll_'.$c['pid'].'_never']))
        $date_string = '9999-01-01 00:00:00';
      $en = isset($_POST['poll_'.$c['pid'].'_enabled']) ? '1' : '0';
      $q = $db->sql_query('UPDATE '.table_prefix.'polls SET enabled='.$en.',end_time=\''.$date_string.'\' WHERE poll_id='.$c['pid'].';');
      if(!$q) $db->_die('The poll data could not be updated.');
      
      $q = $db->sql_query('SELECT p.poll_id AS pid,o.item_id AS oid,p.poll_question AS q,o.option_value AS v,p.end_time,p.enabled FROM '.table_prefix.'polls p, '.table_prefix.'poll_options o WHERE p.poll_id=o.poll_id;');
      if(!$q) $db->_die('The poll information could not be selected.');
      $l = Array();
      while($row = $db->fetchrow())
      {
        if(!isset($l[$row['q']]))
        {
          $l[$row['q']] = Array();
        }
        $l[$row['q']][] = $row;
      }
      $k = array_keys($l);
      
      echo '<h3>Information</h3><p>Poll updated successfully.</p>';
    }
    if(isset($_POST['poll_'.$c['pid'].'_delete']))
    {
      // Safe to use the poll ID here because it's the primary key
      if(!$db->sql_query('DELETE FROM '.table_prefix.'poll_results WHERE poll_id='.$c['pid'].';') ) $db->_die('The poll results could not be deleted.');
      if(!$db->sql_query('DELETE FROM '.table_prefix.'poll_options WHERE poll_id='.$c['pid'].';') ) $db->_die('The poll options could not be deleted.');
      if(!$db->sql_query('DELETE FROM '.table_prefix.'polls WHERE poll_id='.$c['pid'].';')        ) $db->_die('The poll could not be deleted.');
      unset($l[$key]);
      echo '<h3>Information</h3><p>Poll deleted.</p>';
    }
  }
  $k = array_keys($l); // Refresh the key list after any deletions that may have been done
  foreach ( $k as $key )
  {
    if(isset($_POST['create_'.$l[$key][0]['pid']]))
    {
      $str = mysql_real_escape_string($_POST['value_'.$l[$key][0]['pid']]);
      $q = $db->sql_query('INSERT INTO '.table_prefix.'poll_options(poll_id,option_value) VALUES('.$l[$key][0]['pid'].', \''.$str.'\');');
      if(!$q) $db->_die('The poll data could not be inserted.');
      $q = $db->sql_query('SELECT o.item_id AS oid,option_value AS v, p.poll_id AS pid FROM '.table_prefix.'polls p, '.table_prefix.'poll_options o WHERE p.poll_id=o.poll_id AND option_value=\''.$str.'\';');
      if(!$q) $db->_die('The poll data could not be selected.');
      $nr = $db->fetchrow();
      $l[$key][] = $nr; // Fetches the option ID, which is needed for updating and deleting the poll option
    }
    echo '<hr /><h3>Poll: '.$key.'</h3>';
    echo '<form action="'.makeUrl($paths->nslist['Special'].'Administration', 'module='.$paths->cpage['module'], true).'" method="post">';
    $poll_id = $l[$key][0]['pid'];
    $enabled = $l[$key][0]['enabled'];
    $ending_time = $l[$key][0]['end_time'];
    $year = substr($ending_time, 0, 4);
    $month = substr($ending_time, 5, 2);
    $day = substr($ending_time, 8, 2);
    $hour = substr($ending_time, 11, 2);
    $minute = substr($ending_time, 14, 2);
    ?>
    <p>Ending time:
    <select name="poll_<?php echo $poll_id; ?>_month">
      <option<?php if($month=='01') echo ' selected="selected"'; ?> value="01">January</option>
      <option<?php if($month=='02') echo ' selected="selected"'; ?> value="02">February</option>
      <option<?php if($month=='03') echo ' selected="selected"'; ?> value="03">March</option>
      <option<?php if($month=='04') echo ' selected="selected"'; ?> value="04">April</option>
      <option<?php if($month=='05') echo ' selected="selected"'; ?> value="05">May</option>
      <option<?php if($month=='06') echo ' selected="selected"'; ?> value="06">June</option>
      <option<?php if($month=='07') echo ' selected="selected"'; ?> value="07">July</option>
      <option<?php if($month=='08') echo ' selected="selected"'; ?> value="08">August</option>
      <option<?php if($month=='09') echo ' selected="selected"'; ?> value="09">September</option>
      <option<?php if($month=='10') echo ' selected="selected"'; ?> value="10">October</option>
      <option<?php if($month=='11') echo ' selected="selected"'; ?> value="11">November</option>
      <option<?php if($month=='12') echo ' selected="selected"'; ?> value="12">December</option>
    </select>
    <select name="poll_<?php echo $poll_id; ?>_day">
    <?php
      // This would be too hard to write by hand, so let's use a simple for-loop to take care of it for us
      for($i=1;$i<=31;$i++)
      {
        if($i < 10) $t = '0'.$i;
        else $t = $i.'';
        echo '<option';
        if($t == $day) echo ' selected="selected"';
        echo ' value="'.$t.'">'.$t.'</option>'."\n      "; 
      }
    ?>
    </select>,
    <select name="poll_<?php echo $poll_id; ?>_year">
    <?php
      // What the heck? Let's do it again :-D
      for($i=2006;$i<=2026;$i++)
      {
        echo '<option';
        if($i.'' == $year) echo ' selected="selected"';
        echo ' value="'.$i.'">'.$i.'</option>'."\n      "; 
      }
    ?>
    </select>&nbsp;&nbsp;
    <select name="poll_<?php echo $poll_id; ?>_hour">
    <?php
      for($i=0;$i<=23;$i++)
      {
        if($i < 10) $t = '0'.$i;
        else $t = $i.'';
        echo '<option';
        if($t == $hour) echo ' selected="selected"';
        echo ' value="'.$t.'">'.$t.'</option>'."\n      "; 
      }
    ?>
    </select>:<select name="poll_<?php echo $poll_id; ?>_minute">
    <?php
      for($i=0;$i<=59;$i++)
      {
        if($i < 10) $t = '0'.$i;
        else $t = $i.'';
        echo '<option';
        if($t == $minute) echo ' selected="selected"';
        echo ' value="'.$t.'">'.$t.'</option>'."\n      "; 
      }
    ?>
    </select><br />
    &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<label><input<?php if($year=='9999' && $month=='01' && $day=='01' && $hour=='00' && $minute=='00') echo ' checked="checked"'; ?> type="checkbox" name="poll_<?php echo $poll_id; ?>_never" />Never ends</label></p>
    <p><label><input<?php if($enabled) echo ' checked="checked"'; ?> type="checkbox" name="poll_<?php echo $poll_id; ?>_enabled" /> Poll is enabled</label></p>
    <p><input type="submit" name="poll_<?php echo $poll_id; ?>_update" value="Update this poll" />  <input type="submit" name="poll_<?php echo $poll_id; ?>_delete" value="Delete this poll" /></p></p>
    <table border="0" width="100%" cellspacing="1" cellpadding="4">
      <tr><th>Option value</th><th>Votes</th><th>Actions</th></tr>
      <?php
        foreach($l[$key] as $row)
        {
          if(isset($_POST['delete_'.$row['pid'].'_'.$row['oid']]) && sizeof($l[$key]) > 1)
          {
            $q = $db->sql_query('DELETE FROM '.table_prefix.'poll_options WHERE poll_id='.$row['pid'].' AND item_id='.$row['oid'].';');
            if(!$q) $db->_die('The poll data could not be deleted.');
            $q = $db->sql_query('DELETE FROM '.table_prefix.'poll_results WHERE poll_id='.$row['pid'].' AND item_id='.$row['oid'].';');
            if(!$q) $db->_die('The poll result data could not be deleted.');
            echo '<tr><td colspan="3" style="text-align: center"><b>Item deleted.</b></tr>';
          } else {
            if(isset($_POST['delete_'.$row['pid'].'_'.$row['oid']]) && sizeof($l[$key]) < 2)
              echo '<tr><td colspan="3" style="text-align: center"><b>You cannot delete the last option in a poll.<br />Instead, please use the "Update" button.</b></tr>';
            if(isset($_POST['update_'.$row['pid'].'_'.$row['oid']]))
            {
              $q = $db->sql_query('UPDATE '.table_prefix.'poll_options SET option_value=\''.mysql_real_escape_string($_POST['value_'.$row['pid'].'_'.$row['oid']]).'\' WHERE poll_id='.$row['pid'].' AND item_id='.$row['oid'].';');
              if(!$q) $db->_die('The poll data could not be updated.');
              $row['v'] = $_POST['value_'.$row['pid'].'_'.$row['oid']];
            }
            // Sorry guys, really, I hate to make a ton of queries here but there's really no other way to do this :'(
            $q = $db->sql_query('SELECT * FROM '.table_prefix.'poll_results WHERE poll_id='.$row['pid'].' AND item_id='.$row['oid'].';');
            if(!$q) $db->_die('The poll result data could not be selected.');
            echo '<tr><td><input name="value_'.$row['pid'].'_'.$row['oid'].'" value="'.htmlspecialchars($row['v']).'" /></td><td>'.$db->numrows().'</td><td style="text-align: center"><input name="update_'.$row['pid'].'_'.$row['oid'].'" type="submit" value="Update" />  <input name="delete_'.$row['pid'].'_'.$row['oid'].'" type="submit" value="Delete" /></td></tr>';
          }
          //$last_pid
        }
      ?>
      <tr><td colspan="2"><input name="value_<?php echo $l[$key][0]['pid']; ?>" type="text" /></td><td style="text-align: center;"><input type="submit" name="create_<?php echo $l[$key][0]['pid']; ?>" value="Create option" /></td>
    </table>
    <?php
    echo '</form>';
  }
}

?>