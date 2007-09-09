<?php
/*
 * phtagr.
 * 
 * Multi-user image gallery.
 * 
 * Copyright (C) 2006,2007 Sebastian Felis, sebastian@phtagr.org
 * 
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; version 2 of the 
 * License.
 * 
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 * 
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
 */

include_once("$phtagr_lib/Image.php");

/** @class ImageSync Handels the synchronisation between database and the image
  @todo Improve the lazysync with a better modification granularity. If only
the ACL changes, an image will be exported and only the IPTC writen to an JPEG
file.
 * */ 
class ImageSync extends Image
{

function ImageSync($id=-1)
{
  $this->Image($id);
}

/** Import an image by a filename to the database. If an image with the same
 * filename exists, the function update() is called.
  @param filename Filename of the image
  @param is_upload True if the image was uploaded. False if the image is local. Default
  is false.
  @return Returns 0 on success, -1 for failure. On update the return value is
  1. If the file already exists and has no changes, the return value is 2.
  @see update() */
function import($filename, $is_upload=false)
{
  global $db, $log, $user;
  
  $log->trace("Import file: $filename (upload: $is_upload)");
  if (!file_exists($filename))
    return ERR_FS_NOT_EXISTS;

  // If no file handler exists, return with an error
  $handler=$this->get_file_handler($filename);
  if ($handler==null)
    return -1;

  // slashify path
  $path=dirname($filename);
  $path.=($path[strlen($path)-1]!='/')?'/':'';
  $file=basename($filename);

  $spath=mysql_escape_string($path);
  $sfile=mysql_escape_string($file);

  $sql="SELECT id,user_id,flag".
       " FROM $db->images".
       " WHERE path='$spath' AND file='$sfile'";
  $row=$db->query_row($sql);

  // image not found in the database. Import it
  if (empty($row['id']))
  {
    $userid=$user->get_id();
    $groupid=$user->get_groupid();

    $gacl=$user->get_gacl();
    $macl=$user->get_macl();
    $pacl=$user->get_pacl();
    
    $flag=IMAGE_FLAG_IMPORTED;
    $flag|=($is_upload ? IMAGE_FLAG_UPLOADED : 0);
    // insert basics
    $sql="INSERT INTO $db->images (".
         "   user_id, group_id, created,".
         "   path, file, flag,".
         "   gacl, macl, pacl,".
         "   clicks, lastview, ranking".
         " ) VALUES (".
         "   $userid, $groupid, NOW(),".
         "   '$spath', '$sfile', $flag,".
         "   $gacl, $macl, $pacl,".
         "   0, NOW(), 0.0".
         " )";
    $new_id=$db->query_insert($sql);
    if ($new_id<=0)
    {
      $log->err("Import failed of file '$filename': $sql");
      return -1;
    }

    $this->init_by_id($new_id);

    $handler->import($this);
    $this->set_modified($handler->get_filetime(), true);
    $log->trace("data: ".print_r($this->_data, true));
    $log->trace("Changes: ".print_r($this->_changes, true));
    if (!$this->commit())
    {
      $log->err("Could not commit database changes");
      return -1;
    }
    $log->debug("Successful import of '$filename' (ID $new_id)", $new_id);
    return 0;
  }
  else
  {
    // Another user owns this file already
    if ($row['user_id']!=$user->get_id())
    {
      $log->warn("Import failed of '$filename'. File owned by ".$row['user_id'], $row['id']);
      return -1;
    }

    $flag=$row['flag'];
    if (($flag & IMAGE_FLAG_MASK)==IMAGE_FLAG_UPLOADED)
    {
      $flag|=IMAGE_FLAG_IMPORTED;
      // image was uploaded, but not insert
      // update basics
      $sql="UPDATE $db->images ".
           " SET".
           "  flag=$flag".
           ", groupid=".$user->get_groupid().
           ", gacl=".$user->get_gacl().
           ", macl=".$user->get_macl().
           ", pacl=".$user->get_pacl().
           ", clicks=0, lastview=NOW(), ranking=0.0".
           " WHERE id=".$row['id'];  
      $log->trace("sql=$sql");
      if (!$db->query_update($sql))
      {
        $log->err("Update failed of '$filename': $sql", $row['id']);
        return -1;
      }

      $this->init_by_id($row['id']);

      $handler->import($this);
      $this->set_modified($handler->get_filetime(), true);
      if (!$this->commit())
      {
        $log->err("Could not commit database changes");
        return -1;
      }
      $log->debug("Successful import of '$filename' (uploaded)", $row['id']);
      return 0;
    }
    elseif (($flag & IMAGE_FLAG_IMPORTED)==IMAGE_FLAG_IMPORTED)
    {
      // Re-import file
      $this->init_by_id($row['id']);
      if (!$this->_import())
        return 2;
      $this->commit();
      $log->debug("Successful update of '$filename'", $row['id']);
      return 1;
    }
  }
  
  // Should never reach this
  $log->fatal("Generic error importing '$filename'");
  return -1;
}

/** Add an file by a filename to the database but don't import it.  
  @param filename Filename of the image
  @param is_upload True if the image was uploaded. False if the image is local. Default
  is false.
  @return Returns the new image id on success, -1 for failure. */
function add_file($filename, $is_upload=false)
{
  global $db, $log, $user;
  
  $log->trace("Add file: $filename (upload: $is_upload)");
  if (!file_exists($filename))
  {
    $log->err("Could not add file. File '$filename' does not exists");
    return -1;
  }

  // slashify path
  $path=dirname($filename);
  $path.=($path[strlen($path)]!='/')?'/':'';
  $file=basename($filename);

  @clearstatcache();
  $size=filesize($filename);

  $spath=mysql_escape_string($path);
  $sfile=mysql_escape_string($file);

  $sql="SELECT id, bytes".
       " FROM $db->images".
       " WHERE path='$spath' AND file='$sfile'";
  $row=$db->query_row($sql);

  if (empty($row['id']))
  {
    $flag=($is_upload)?IMAGE_FLAG_UPLOADED:0;
    // new entry
    $sql="INSERT INTO $db->images".
         " (user_id, path, file, bytes, created, flag)".
         " VALUES (".$user->get_id().", '$spath', '$sfile', $size, NOW(), $flag)";
    $id=$db->query_insert($sql);
    if ($id<0)
    {
      $log->err("Could not insert file '$filename' to database: $sql");
      return -1;
    }
    $log->debug("Add file '$filename' to database");
    return $id;
  }
  elseif ($row['bytes']!=$size && $row['id']>0)
  {
    // update size
    $sql="UPDATE $db->images".
         " SET bytes=$size".
         " WHERE id=".$row['id'];
    if ($db->query_update($sql)!=1)
    {
      $log->err("Update failed of filesize of '$filename': $sql");
      return -1;
    }
    $log->debug("Update filesize of '$filename' from ".$row['bytes']." to $size");
  }
  return $row['id'];
}

/** Import the image data if the file modification time is after the
 * changed time of the image data set or modification of time
  @param force If true, force the update procedure. Default is false.
  @return True if the image was updated. False otherwise */
function _import($force=false)
{
  global $user, $log;

  $file=$this->get_file_handler();
  if ($file==null)
    return false;

  $modified=$this->get_modified(true);
  $time_file=$file->get_filetime();

  // Skip if not forced or if file has the same time
  if (!$force && $time_file<=$modified)
  {
    return false;
  }
  
  // Clear the file stat chache to get updated stats  
  @clearstatcache();
  $log->warn("Importing file ".$this->get_filename(), $this->get_id(), $user->get_id());
  $file->import($this);

  $this->set_modified($file->get_filetime(), true);

  return true;
}

function export()
{
  $this->_export();
}

/** @param force Force the export of the file
  @return Returns true if data where written to the file, false otherwise */
function _export($force=false)
{
  global $user, $log;

  $file=$this->get_file_handler();
  if ($file==null)
    return false;

  @clearstatcache();
  $modified=$this->get_modified(true);
  $time_file=$file->get_filetime();

  $changed=$this->is_modified() || $this->is_meta_modified();
  // Data did not changed, skip export
  if (!$force && $time_file>=$modified && !$changed)
  {
    $log->trace("File '".$this->get_filename()."' has no changes. Skip export!", $this->get_id());
    return false;
  }
  
  $this->commit();

  $old_time=$file->get_filetime();
  if (!$file->export($this))
  {
    $this->set_modified(time());
    $this->commit();
    return false;
  }

  $log->warn("Exporting file ".$this->get_filename(), $this->get_id());
  @clearstatcache();
  $new_time=$file->get_filetime();
  if ($new_time>$old_time)
    $this->set_modified($file->get_filetime(), true);

  return true;
}

/** Synchronize the single image and call import and export */
function synchronize()
{
  $this->_import();
  $this->_export();
}

/** Synchronize files between the database and the filesystem. If a file not
 * exists delete its data. If a file is newer since the last update, update its
 * data. 
  @param userid Userid which must match current user. If userid -1 and user is
  admin, all files are synchronized.
  @param since Starting timestamp of image modification in UNIX format. If this
value is set, only files are considered, which modified after this timestamp excluding this time. Default is -1 (disabled).
  @return Array of count files, updated files, and deleted files. On error, the
  first array value is the global error code */
function sync_files($userid=-1, $since=-1)
{
  global $db, $user, $log;

  $sql="SELECT id".
       " FROM $db->images".
       " WHERE 1";
  if ($userid>0)
  {
    if ($userid!=$user->get_id() && !$user->is_member())
    {
      $log->err("User is not authorized to sync files");
      return array(-1, 0, 0);
    }
    $sql.=" AND user_id=$userid";
  } else {
    if (!$user->is_admin())
    {
      $log->err("User is not authorized to sync files of all users");
      return array(-1, 0, 0);
    }
  }

  if (intval($since)>0)
  {
    $time=$db->date_unix_to_mysql($since);
    $sql.=" AND modified>'$time'";
  }

  $result=$db->query($sql);
  if (!$result)
  {
    $log->err("Could not query: $sql");
    return array(-1, 0, 0);
  }
    
  @clearstatcache();
  $count=0;
  $updated=0;
  $deleted=0;

  while ($row=mysql_fetch_row($result))
  {
    $count++;
    
    $img=new ImageSync($row[0]);
    if (!file_exists($img->get_filename()))
    {
      $img->delete();
      $deleted++;
    }
    else 
    {
      if ($img->_import())
      {
        $img->commit();
        $updated++;
      }
      if ($img->_export())
      {
        $img->commit();
        $updated++;
      }
    }
    unset($img);
  }
  return array($count, $updated, $deleted);
}

/** Deletes the file if it was uploaded */
function delete()
{
  global $user, $log;

  if ($user->get_id()!=$this->get_userid() && !$user->is_admin())
    return;

  $previewer=$this->get_preview_handler();
  if ($previewer!=null)
    $previewer->delete_previews();

  if ($this->is_upload())
  {
    $log->info("Delete file '".$this->get_filename()."' from the filesystem", $this->get_id());
    @unlink($this->get_filename());
  }

  parent::delete();
}

/** Delets all uploaded files 
  @param userid User id
  @param id Optional image id */
function delete_from_user($userid, $id=0)
{
  global $user;
  global $db;

  if (!is_numeric($userid) || $userid<1)
    return ERR_PARAM;
  if ($userid!=$user->get_id() && !$user->is_admin())
    return ERR_NOT_PERMITTED;

  $previewer=new PreviewerBase();
  $previewer->delete_from_user($userid, $id);

  $userid=$user->get_id();
  $sql="SELECT path,file".
       " FROM $db->images".
       " WHERE userid=$userid AND flag & ".IMAGE_FLAG_UPLOADED;
  if ($id>0) $sql.=" AND id=$id";

  $result=$db->query($sql);

  if (!$result) {
    $log->err('Could not run sql query '.$sql);
    return;
  }

  while ($row=mysql_fetch_row($result)) {
    @unlink($row[0].$row[1]);
  }
  return parent::delete_from_user($userid, $id);
}

/** Deletes a file or directory. This function calls delete().
  @param file Filename or directory to delete
  @see delete
  @note Access rights are not checked */
function delete_file($file)
{
  global $db, $log, $user;

  if (!file_exists($file))
  {
    $log->err("Could not delete file. File '$file' does not exists");
    return false;
  }

  $sql="SELECT id".
       " FROM $db->images".
       " WHERE 1";
  // user permissions
  if (!$user->is_member())
  {
    $log->err("Deletion of '$file' denied");
    return false;
  }
  if (!$user->is_admin())
    $sql.=" AND userid=".$user->get_id();

  if (is_dir($file))
  {
    // slashify path
    $path=$file;
    $path.=($path[strlen($path)-1]!='/')?'/':'';
    $spath=mysql_escape_string($path); 

    $sql.=" AND path like '$spath%' AND file='$sfile'"; 
  }
  else
  {
    // slashify path
    $path=dirname($file);
    $path.=($path[strlen($path)-1]!='/')?'/':'';
    $f=basename($file);
   
    $spath=mysql_escape_string($path); 
    $sf=mysql_escape_string($f); 

    $sql.=" AND path='$spath' AND file='$sf'"; 
  }

  $col=$db->query_column($sql);
  if ($col==null)
  {
    $log->trace("Could not find file for deletion: $sql");
    $log->err("Could not delete file. File '$filename' is not handled by phtagr");
    return false;
  }

  foreach ($col as $id)
  {
    $image=new ImageSync($id);
    $image->delete();
  }
  return true;
}

function move_file($src, $dst)
{
  global $db, $log, $user;

  if ($src==$dst)
    return true;

  if (!$user->is_member())
  {
    $log->err("Move of '$src' to '$dst' denied");
    return false;
  }
  if (!$user->is_admin())
    $sql_where=" AND userid=".$user->get_id();  

  if (!rename($src, $dst))
  {
    $log->err("Could not rename '$src' to '$dst'");
    return false;
  }

  $sql_set=array();
  
  if (is_dir($src))
  {
    $src_path=$src;
    $src_path.=($src_path[strlen($src_path)-1]!='/')?'/':'';
    $ssrc_path=mysql_escape_string($src_path);

    $dst_path=$dst;
    $dst_path.=($dst_path[strlen($dst_path)-1]!='/')?'/':'';
    $sdst_path=mysql_escape_string($dst_path);
    
    array_push($sql_set, "path=REPLACE(path, '$ssrc_path', '$sdst_path')");
    $sql_where.=" AND path LIKE '$ssrc_path%'";
  }
  else
  {
    $src_path=dirname($src);
    $src_path.=($src_path[strlen($src_path)-1]!='/')?'/':'';
    $ssrc_path=mysql_escape_string($src_path);
    $ssrc_file=mysql_escape_string(basename($src));

    $dst_path=dirname($dst);
    $dst_path.=($dst_path[strlen($dst_path)-1]!='/')?'/':'';
    $sdst_path=mysql_escape_string($dst_path);
    $sdst_file=mysql_escape_string(basename($dst));
    
    if ($ssrc_path!=$sdst_path)
      array_push($sql_set, "path=REPLACE(path, '$ssrc_path', '$sdst_path')");
    if ($ssrc_file!=$sdst_file)
      array_push($sql_set, "file=REPLACE(file, '$ssrc_file', '$sdst_file')");

    $sql_where.=" AND path='$ssrc_path' AND file='$ssrc_file'";
  }

  $sql="UPDATE $db->images";
  $sql.=" SET ".implode(", ", $sql_set);
  $sql.=" WHERE 1".$sql_where;

  $num=$db->query_update($sql);
  if ($num<0)
  {
    $log->err("Could not run update query: ".$sql);
    return false;
  }
  else
    $log->debug("Moved $num file(s) from '$src' to '$dst'");

  return true;
}

/** Handle the caption input 
  @param prefix Prefix of forumlar input names
  @param merge True if data should merge. False if data will overwrite the
  current data */
function _handle_request_caption($prefix='', $merge)
{
  if (!isset($_REQUEST[$prefix.'caption']) || 
    $_REQUEST[$prefix.'caption']=='') {
    if ($merge)
      $this->set_caption(null);
    return;
  }
  $this->set_caption($_REQUEST[$prefix.'caption']);
}

/** Checks the values of the date inpute and sets the date of the image
  @param year Valid range is 1000 < year < 3000
  @param month Valid range is 1 <= month <= 12
  @param day Valid range is 1 <= day <= 31
  @param hour Valid range is 0 <= hour < 23
  @param min Valid range is 0 <= min < 60
  @param sec Valid range is 0 <= sec < 60 */
function _check_date($year, $month, $day, $hour, $min, $sec)
{
  $year=$year<1000?1000:($year>3000?3000:$year);
  $month=$month<1?1:($month>12?12:$month);
  $day=$day<1?1:($day>31?31:$day);

  $hour=$hour<0?0:($hour>23?23:$hour);
  $min=$min<0?0:($min>59?59:$min);
  $sec=$sec<0?0:($sec>59?59:$sec);

  $date=sprintf("%04d-%02d-%02d", $year, $month, $day);
  $time=sprintf("%02d-%02d-%02d", $hour, $min, $sec);

  $this->set_date($date.' '.$time);
}

/** Handle the date input 
  @param prefix Prefix of forumlar input names
  @param merge True if data should merge. False if data will overwrite the
  current data 
  @todo Check date input for the database */
function _handle_request_date($prefix='', $merge)
{
  if (!isset($_REQUEST[$prefix.'date']) || 
    $_REQUEST[$prefix.'date']=='') {
    return true;
  }

  $date=$_REQUEST[$prefix.'date'];
  if ($date=='-') {
    return true;
  }

  // Check format of YYYY-MM-DD hh:mm:ss
  if (!preg_match('/^[0-9]{4}(-[0-9]{2}(-[0-9]{2}( [0-9]{2}(:[0-9]{2}(:[0-9]{2})?)?)?)?)?$/', $date))
    return false;

  $this->_check_date(
    intval(substr($date, 0, 4)), 
    intval(substr($date, 5, 2)), 
    intval(substr($date, 8, 2)),
    intval(substr($date, 11, 2)), 
    intval(substr($date, 14, 2)), 
    intval(substr($date, 17, 2)));
  return true;
}

/** Handle the tag input 
  @param prefix Prefix of forumlar input names
  @param merge True if data should merge. False if data will overwrite the
  current data */
function _handle_request_tags($prefix='', $merge)
{
  global $conf;
  $sep=$conf->get('meta.separator', ';');
  $sep=($sep==" ")?"\s":$sep;
  $tags=preg_split("/[$sep]+/", $_REQUEST[$prefix.'tags']);
  if (count($tags)==0)
    return false;

  // distinguish between add and remove operation.
  $add_tags=array();
  $del_tags=array();
  foreach ($tags as $tag)
  {
    $tag=trim($tag);
    if ($tag=='-' || $tag=='')
      continue;

    if ($tag{0}=='-')
      array_push($del_tags, substr($tag, 1));
    else
      array_push($add_tags, $tag);
  }
  if (count($del_tags))
    $this->del_tags($del_tags);

  if (!$merge)
  {
    $db_tags=$this->get_tags();
    $del_tags=array_diff($db_tags, $add_tags);
    if (count($del_tags))
      $this->del_tags($del_tags);
  }
  $this->add_tags($add_tags);
}

/** Handle the set input 
  @param prefix Prefix of forumlar input names
  @param merge True if data should merge. False if data will overwrite the
  current data */
function _handle_request_categories($prefix='', $merge)
{
  global $conf;
  $sep=$conf->get('meta.separator', ';');
  $sep=($sep==" ")?"\s":$sep;
  $cats=preg_split("/[$sep]+/", $_REQUEST[$prefix.'categories']);
  if (count($cats)==0)
    return false;

  // distinguish between add and remove operation.
  $add_cats=array();
  $del_cats=array();
  foreach ($cats as $cat)
  {
    $cat=trim($cat);
    if ($cat=='-' || $cat=='')
      continue;

    if ($cat{0}=='-')
      array_push($del_cats, substr($cat, 1));
    else
      array_push($add_cats, $cat);
  }
  if (count($del_cats))
    $this->del_categories($del_cats);
  if (!$merge)
  {
    $db_sets=$this->get_categories();
    $del_cats=array_diff($db_sets, $add_cats);
    if (count($del_cats))
      $this->del_categories($del_cats);
  }
  $this->add_categories($add_cats);
}

/** Handle the location input 
  @param prefix Prefix of forumlar input names
  @param merge True if data should merge. False if data will overwrite the
  current data */
function _handle_request_location($prefix='', $merge)
{
  $loc=array(LOCATION_CITY => $_REQUEST[$prefix.'city'],
    LOCATION_SUBLOCATION => $_REQUEST[$prefix.'sublocation'],
    LOCATION_STATE => $_REQUEST[$prefix.'state'],
    LOCATION_COUNTRY => $_REQUEST[$prefix.'country']);

  for ($i=LOCATION_CITY ; $i<= LOCATION_COUNTRY ; $i++) {
    if ($loc[$i]!='') {
      $this->set_location($loc[$i], $i);
    } else if (!$merge) {
      $this->del_location(null, $i);
    }
  }
}

function handle_request()
{
  global $conf, $user, $log;
  $log->trace("Handle image request for changes", $this->get_id());
  $this->_import(false);

  if (!isset($_REQUEST['edit']))
    return false;

  $edit=$_REQUEST['edit'];
  if ($edit=='multi')
  {
    $prefix='edit_';
    if ($this->can_write_tag(&$user))
      $this->_handle_request_tags($prefix, true);
    if ($this->can_write_meta(&$user))
    {
      $this->_handle_request_date($prefix, true);
      $this->_handle_request_categories($prefix, true);
      $this->_handle_request_location($prefix, true);
    }
    if ($this->can_write_caption(&$user))
      $this->_handle_request_caption($prefix, true);
  } else if ($edit=='js_tag') {
    $prefix='js_';
    if ($this->can_write_tag(&$user))
      $this->_handle_request_tags($prefix, false);
  } else if ($edit=='js_meta') {
    $prefix='js_';
    if ($this->can_write_meta(&$user))
    {
      $this->_handle_request_date($prefix, false);
      $this->_handle_request_tags($prefix, false);
      $this->_handle_request_categories($prefix, false);
      $this->_handle_request_location($prefix, false);
    }
  } else if ($edit=='js_caption') {
    $prefix='js_';
    if ($this->can_write_caption(&$user))
      $this->_handle_request_caption($prefix, false);
  }

  // Commit changes to update the values
  $changed=$this->is_modified() || $this->is_meta_modified();  
  if (!$changed)
  {
    return;
  }

  if ($this->is_modified())
    $log->debug("Data changed", $this->get_id());
  if ($this->is_meta_modified())
    $log->debug("Metadata changed", $this->get_id());

  // Dont export on lazy sync but update modified
  if ($conf->query($this->get_userid(), 'image.lazysync', 0)==1)
  {
    $this->set_modified(time(), true);
    $this->commit();
  }
  else
  {
    $this->_export(false);
    $this->commit();
  }
  $previewer=$this->get_preview_handler();
  if ($previewer!=null)
    $previewer->touch_previews();
}

}
?>