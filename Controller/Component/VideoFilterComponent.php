<?php
/**
 * PHP versions 5
 *
 * phTagr : Tag, Browse, and Share Your Photos.
 * Copyright 2006-2012, Sebastian Felis (sebastian@phtagr.org)
 *
 * Licensed under The GPL-2.0 License
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     Copyright 2006-2012, Sebastian Felis (sebastian@phtagr.org)
 * @link          http://www.phtagr.org phTagr
 * @package       Phtagr
 * @since         phTagr 2.2b3
 * @license       GPL-2.0 (http://www.opensource.org/licenses/GPL-2.0)
 */

App::uses('BaseFilter', 'Component');

class VideoFilterComponent extends BaseFilterComponent {

  var $controller = null;
  var $components = array('VideoPreview', 'FileManager', 'Command');

  public function initialize(Controller $controller) {
    $this->controller = $controller;
  }

  public function getName() {
    return "Video";
  }

  public function _getVideoExtensions() {
    if ($this->controller->getOption('bin.exiftool') || $this->controller->getOption('bin.ffmpeg')) {
      return array('avi', 'mov', 'mpeg', 'mpg', 'mts', 'mp4', 'flv', 'ogg');
    } else {
      return array('flv');
    }
  }

  public function getExtensions() {
    return am($this->_getVideoExtensions(), array('thm' => array('priority' => 5)));
  }

  /**
   * Finds the video thumb of a video
   *
   * @param video File model data of the video
   * @param insertIfMissing If true, adds the thumb file to the database. Default is true
   * @return Filename of the thumb file. False if no thumb file was found
   */
  public function _findVideo($thumb) {
    $thumbFilename = $this->controller->MyFile->getFilename($thumb);
    $path = dirname($thumbFilename);
    $folder = new Folder($path);
    $pattern = basename($thumbFilename);
    $pattern = substr($pattern, 0, strrpos($pattern, '.')+1).'('.implode($this->_getVideoExtensions(), '|').')';
    $found = $folder->find($pattern);
    if (!count($found)) {
      return false;
    }
    foreach ($found as $file) {
      if (is_readable(Folder::addPathElement($path, $file))) {
        $videoFilename = Folder::addPathElement($path, $file);
        $video = $this->controller->MyFile->findByFilename($videoFilename);
        if ($video) {
          return $video;
        }
      }
    }
    return false;
  }

  public function _readThumb($file, &$media) {
    $filename = $this->controller->MyFile->getFilename($file);
    if (!$media) {
      $video = $this->_findVideo($file);
      if (!$video) {
        $this->FilterManager->addError($filename, "VideoNotFound");
        Logger::err("Could not find video for video thumb $filename");
        return false;
      }
      $media = $this->controller->Media->findById($video['File']['media_id']);
      if (!$media) {
        $this->FilterManager->addError($filename, "MediaNotFound");
        Logger::err("Could not find media for video file. Maybe import it first");
        return false;
      }
    }
    $ImageFilter = $this->FilterManager->getFilter('Image');
    Logger::debug("Read video thumbnail by ImageFilter: $filename");
    foreach (array('name', 'width', 'height', 'flag', 'duration') as $column) {
      if (isset($media['Media'][$column])) {
        $tmp[$column] = $media['Media'][$column];
      }
    }
    $ImageFilter->read($file, $media, array('noSave' => true));
    // accept different name except filename
    if ($media['Media']['name'] != basename($filename)) {
      unset($tmp['name']);
    }
    // restore overwritten values
    $media['Media'] = am($media['Media'], $tmp);
    if (!$this->controller->Media->save($media)) {
      $this->FilterManager->addError($filename, "MediaSaveError");
      Logger::err("Could not save media");
      return false;
    }
    $this->controller->MyFile->setMedia($file, $media['Media']['id']);
    $this->controller->MyFile->updateReaded($file);
    Logger::verbose("Updated media from thumb file");
    return $this->controller->Media->findById($media['Media']['id']);
  }

  /**
   * Read the video data from the file
   *
   * @param image Media model data
   * @return True, false on error
   */
  public function read(&$file, &$media = null, $options = array()) {
    $filename = $this->controller->MyFile->getFilename($file);

    if ($this->controller->MyFile->isType($file, FILE_TYPE_VIDEOTHUMB)) {
      return $this->_readThumb($file, $media);
    } elseif (!$this->controller->MyFile->isType($file, FILE_TYPE_VIDEO)) {
      $this->FilterManager->addError($filename, "FileNotSupported");
      Logger::err("File type is not supported: ".$this->controller->MyFile->getFilename($file));
      return false;
    }

    $isNew = false;
    if (!$media) {
      $media = $this->controller->Media->create(array(
            'type' => MEDIA_TYPE_VIDEO,
            'date' => date('Y-m-d H:i:s', time()),
            'name' => basename($filename),
            'orientation' => 1
            ), true);
      if ($this->controller->getUserId() != $file['File']['user_id']) {
        $user = $this->controller->Media->User->findById($file['File']['user_id']);
      } else {
        $user = $this->controller->getUser();
      }
      $media = $this->controller->Media->addDefaultAcl($media, $user);

      $isNew = true;
    }

    $media = $this->_readVideoFormat($media, $filename);
    if (!$media || !$this->controller->Media->save($media)) {
      $this->FilterManager->addError($filename, "MediaSaveError");
      Logger::err("Could not save media");
      return false;
    }

    $mediaId = $media['Media']['id'];
    if ($isNew || !$this->controller->MyFile->hasMedia($file)) {
      $mediaId = $isNew ? $this->controller->Media->getLastInsertID() : $data['id'];
      if (!$this->controller->MyFile->setMedia($file, $mediaId)) {
        Logger::err("File was not saved: " . $filename);
        $this->FilterManager->addError($filename, "FileSaveError");
        $this->controller->Media->delete($mediaId);
        return false;
      }
      $media = $this->controller->Media->findById($mediaId);
    }

    $this->controller->MyFile->updateReaded($file);
    $this->controller->MyFile->setFlag($file, FILE_FLAG_DEPENDENT);

    return $this->controller->Media->findById($mediaId);
  }

  public function _readVideoFormat(&$media, $filename) {
    if ($this->controller->getOption('bin.exiftool')) {
      $result = $this->_readExiftool($filename);
    }
    if (!$result && $this->controller->getOption('bin.ffmpeg')) {
      $result = $this->_readFfmpeg($filename);
    }
    if (!$result) {
      $result = $this->_readGetId3($filename);
    }
    if (!$result || !isset($result['width']) || !isset($result['height']) || !isset($result['duration'])) {
      $this->FilterManager->addError($filename, "UnknownVideoFormatError");
      Logger::err("Could extract video format");
      return false;
    }
    foreach ($result as $name => $value) {
      $media['Media'][$name] = $value;
    }
    return $media;
  }

  public function _readExiftool($filename) {
    $bin = $this->controller->getOption('bin.exiftool', 'exiftool');
    $this->Command->redirectError = true;
    $result = $this->Command->run($bin, array('-n', '-S', $filename));
    $output = $this->Command->output;

    if ($result != 0) {
      Logger::err("Command '$bin' returned unexcpected $result");
      return false;
    } elseif (!count($output)) {
      Logger::err("Command returned no output!");
      return false;
    }

    $data = array();
    foreach ($output as $line) {
      if (preg_match('/^(\w+): (.*)$/', $line, $m)) {
        $data[$m[1]] = $m[2];
      } else {
        Logger::warn('Could not parse line: '.$line);
      }
    }

    $result = array();
    if (!isset($data['ImageWidth']) || !isset($data['ImageWidth']) || !isset($data['Duration']) ) {
      Logger::warn("Could not extract width, height, or durration from '$filename'");
      Logger::warn($result);
      return false;
    }
    $result['height'] = intval($data['ImageHeight']);
    $result['width'] = intval($data['ImageWidth']);
    $result['duration'] = ceil(intval($data['Duration']));

    if (isset($data['DateTimeOriginal'])) {
      $result['date'] = $data['DateTimeOriginal'];
    } else if (isset($data['FileModifyDate'])) {
      $result['date'] = $data['FileModifyDate'];
    }
    if (isset($data['Orientation'])) {
      $result['orientation'] = $data['Orientation'];
    }
    if (isset($data['Model'])) {
      $result['model'] = $data['Model'];
    }
    if (isset($data['GPSLatitude']) && isset($data['GPSLongitude'])) {
      $result['latitude'] = $data['GPSLatitude'];
      $result['longitude'] = $data['GPSLongitude'];
    }
    Logger::trace("Extracted " . count($result) . " fields via exiftool");
    Logger::trace($result);
    return $result;
  }

  public function _readFfmpeg($filename) {
    $bin = $this->controller->getOption('bin.ffmpeg', 'ffmpeg');
    $this->Command->redirectError = true;
    $result = $this->Command->run($bin, array('-i' => $filename, '-t', 0.0));
    $output = $this->Command->output;

    if ($result != 1) {
      Logger::err("Command '$bin' returned unexcpected $result");
      return false;
    } elseif (!count($output)) {
      Logger::err("Command returned no output!");
      return false;
    }
    Logger::trace($output);

    $result = array();
    foreach ($output as $line) {
      $words = preg_split("/[\s,]+/", trim($line));
      if (count($words) >= 2 && $words[0] == "Duration:") {
        $times = preg_split("/:/", $words[1]);
        $time = $times[0] * 3600 + $times[1] * 60 + intval($times[2]);
        $result['duration'] = $time;
        Logger::trace("Extract duration of '$filename': $time");
      } elseif (count($words) >= 6 && $words[2] == "Video:") {
        $words = preg_split("/,+/", trim($line));
        $size = preg_split("/\s+/", trim($words[2]));
        list($width, $height) = split("x", trim($size[0]));
        $result['width'] = $width;
        $result['height'] = $height;
        Logger::trace("Extract video size of '$filename': $width x $height");
      }
    }
    if (count($result) != 3) {
      Logger::warn("Could not extract width, height, or durration from '$filename'");
      Logger::warn($result);
      return false;
    }
    return $result;
  }

  public function _readGetId3($filename) {
    App::import('vendor', 'getid3/getid3');
    $getId3 = new getId3();
    // disable not required modules
    $getId3->option_tag_id3v1 = false;
    $getId3->option_tag_id3v2 = false;
    $getId3->option_tag_lyrics3 = false;
    $getId3->option_tag_apetag = false;

    $data = $getId3->analyze($filename);
    if (isset($data['error'])) {
      Logger::err("GetId3 analyzing error: {$data['error'][0]}");
      Logger::debug($data);
      return false;
    }

    $result = array();
    $result['duration'] = $data['meta']['onMetaData']['duration'];
    $result['width'] = $data['meta']['onMetaData']['width'];
    $result['height'] = $data['meta']['onMetaData']['height'];

    return $result;
  }

  // Check for video thumb
  public function _hasThumb($media) {
    $thumb = $this->controller->Media->getFile($media, FILE_TYPE_VIDEOTHUMB);
    if ($thumb) {
      return true;
    } else {
      return false;
    }
  }

  public function _createThumb($media) {
    $video = $this->controller->Media->getFile($media, FILE_TYPE_VIDEO);
    if (!$video) {
      Logger::err("Media {$media['Media']['id']} has no video");
      return false;
    }
    if (!is_writable(dirname($this->controller->MyFile->getFilename($video)))) {
      Logger::warn("Cannot create video thumb. Directory of video is not writeable");
    }
    $thumb = $this->VideoPreview->create($video);
    if (!$thumb) {
      return false;
    }
    return $this->FileManager->add($thumb);
  }

  public function write(&$file, &$media, $options = array()) {
    if (!$this->_hasThumb($media)) {
      $id = $this->_createThumb($media);
      if ($id) {
        $file = $this->controller->MyFile->findById($id);
        $this->controller->MyFile->setMedia($file, $media['Media']['id']);
        $media = $this->controller->Media->findById($media['Media']['id']);
        $this->write($file, $media);
      }
    }
    if ($this->controller->MyFile->isType($file, FILE_TYPE_VIDEOTHUMB)) {
      $imageFilter = $this->FilterManager->getFilter('Image');
      if (!$imageFilter) {
        Logger::err("Could not get filter Image");
        return false;
      }
      $filename = $this->controller->MyFile->getFilename($file);
      Logger::debug("Write video thumbnail by ImageFilter: $filename");
      return $imageFilter->write($file, $media);
    }
    return true;
  }

}
