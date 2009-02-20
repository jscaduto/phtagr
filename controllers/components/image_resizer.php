<?php
/*
 * phtagr.
 * 
 * Multi-user image gallery.
 * 
 * Copyright (C) 2006-2009 Sebastian Felis, sebastian@phtagr.org
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
if (!App::import('Vendor', "phpthumb", true, array(), "phpthumb.class.php")) {
  debug("Please install phpthumb properly");
}

class ImageResizerComponent extends Object {

  var $controller = null;
  var $components = array('Logger');

  function startup(&$controller) {
    $this->controller =& $controller;
  }

  /** Resize an image 
    @param src Source image filename
    @param dst Destination image filename
    @param options Options
      - height Maximum height of the resized image. Default is 220.
      - quality Quality of the resized image. Default is 85
      - rotation Rotation in degree. Default i s0
      - square Square the image. Default is false. If set, only width is considered.
      - clearMetaData Clear all meta data. Default is true */
  function resize($src, $dst, $options = array()) {
    $options = am(array(
      'size' => 220,
      'quality' => 85,
      'rotation' => 0,
      'square' => false,
      'clearMetaData' => true
      ), $options);

    if (!is_readable($src)) {
      $this->Logger->err("Could not read source $src");
      return false;
    }
    if (!is_writeable(dirname($dst))) {
      $this->Logger->err("Could not write to path ".dirname($dst));
      return false;
    }
    if (!isset($options['width']) || !isset($options['height'])) {
      $size = getimagesize($src);
      $options['width'] = $size[0];
      $options['height'] = $size[1];
    }

    $phpThumb = new phpThumb();

    $phpThumb->src = $src;
    $phpThumb->w = $options['size'];
    $phpThumb->h = $options['size'];
    $phpThumb->q = $options['quality'];
    $phpThumb->ra = $options['rotation']; 

    if ($options['square'] && $options['height'] > 0) {
      $width = $options['width'];
      $height = $options['height'];
      if ($width < $height) {
        $ratio = ($width / $height);
        $size = $options['size'] / $ratio;
        $phpThumb->sx = 0;
        $phpThumb->sy = intval(($size - $options['size']) / 2);
      } else {
        $ratio = ($height / $width);
        $size = $options['size'] / $ratio;
        $phpThumb->sx = intval(($size - $options['size']) / 2);
        $phpThumb->sy = 0;
      }

      if ($phpThumb->ra == 90 || $phpThumb->ra == 270) {
        $tmp = $phpThumb->sx;
        $phpThumb->sx = $phpThumb->sy;
        $phpThumb->sy = $tmp;
      }

      $phpThumb->sw = $options['width'];
      $phpThumb->sh = $options['height'];

      $phpThumb->w = $size;
      $phpThumb->h = $size;

      //$this->Logger->debug(sprintf("square: %dx%d %dx%d", 
      //  $phpThumb->sx, $phpThumb->sy, 
      //  $phpThumb->sw, $phpThumb->sh), LOG_DEBUG);
    }
    $phpThumb->config_imagemagick_path = $this->controller->getOption('bin.convert', 'convert');
    $phpThumb->config_prefer_imagemagick = true;
    $phpThumb->config_imagemagick_use_thumbnail = false;
    $phpThumb->config_output_format = 'jpg';
    $phpThumb->config_error_die_on_error = true;
    $phpThumb->config_document_root = '';
    $phpThumb->config_temp_directory = APP . 'tmp';
    $phpThumb->config_allow_src_above_docroot = true;

    $phpThumb->config_cache_directory = dirname($dst);
    $phpThumb->config_cache_disable_warning = false;
    $phpThumb->cache_filename = $dst;
    
    //Thanks to Kim Biesbjerg for his fix about cached thumbnails being regenerated
    if(!is_file($phpThumb->cache_filename)) { 
      // Check if image is already cached.
      $t1 = getMicrotime();
      $result = $phpThumb->GenerateThumbnail();
      $t2 = getMicrotime();
      if ($result) {
        $this->Logger->debug("Render {$options['size']}x{$options['size']} image in ".round($t2-$t1, 4)."ms to '{$phpThumb->cache_filename}'");
        $phpThumb->RenderToFile($phpThumb->cache_filename);
      } else {
        $this->Logger->err("Could not generate thumbnail: ".$phpThumb->error);
        $this->Logger->err($phpThumb->debugmessages);
        die('Failed: '.$phpThumb->error);
      }
    } 
    //$this->Logger->debug($phpThumb->debugmessages);
    
    if ($options['clearMetaData']) {
      $this->clearMetaData($dst);
    }
    return true;
  }

  /** Clear image metadata from a file
    @param filename Filename to file to clean */
  function clearMetaData($filename) {
    if (!file_exists($filename)) {
      $this->Logger->err("Filename '$filename' does not exists");
      return;
    }
    if (!is_writeable($filename)) {
      $this->Logger->err("Filename '$filename' is not writeable");
      return;
    }

    $bin = $this->controller->getOption('bin.exiftool', 'exiftool');
    $command = $bin.' -all= '.escapeshellarg($filename);
    $output = array();
    $result = -1;
    $t1 = getMicrotime();
    exec($command, &$output, &$result);
    $t2 = getMicrotime();
    $this->Logger->trace("$bin call needed ".round($t2-$t1, 4)."ms");

    $this->Logger->debug("Cleaned meta data of '$filename'");
  }

}

?>