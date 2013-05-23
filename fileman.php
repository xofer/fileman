<?php
/**
 * Fileman 0.3 BETA
 * copyright (c) 2009-2012 Christopher Clarke: http://fuscata.com/contact
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 *
 * FileMan uses portions of the TimThumb script created by Tim McDaniels and
 * Darren Hoyt with tweaks by Ben Gillbanks
 * http://code.google.com/p/timthumb/
 */

class fileman {

    public $magicLocation = '/usr/share/file/magic';
    public $includePath = '.';
    public $debug = FALSE;
    public $cacheDir = 'cache/';
    public $allowedTypes = 'image,audio,video';
    public $attachmentTypes = 'audio,video';
    public $disallowedTypes = '';
    public $bufferSize = 4096;

    private $stream = NULL;
    private $file = FALSE;
    private $filename = FALSE;
    private $cacheFilename = '';
    private $isLocal = TRUE;
    private $foundInCache = FALSE;
    private $isCached = FALSE;
    private $isResizable = FALSE;
    private $src = '';
    private $height = 0;
    private $width = 0;
    private $zoomCrop = 0;
    private $quality = 75;
    private $mimeType = FALSE;
    private $buffer = NULL;
    private $headersSent = FALSE;
    private $gmdateMod = 0;

    const RESIZABLE_TYPES = 'image/jpeg,image/gif,image/png';

    public function send () {

        // set configuation values from request:
        if (isset($_REQUEST['src']) && $_REQUEST['src']) {
            $this->src = str_replace('\\', '/', urldecode($_REQUEST['src']));
            if (strpos($this->src, '../') !== FALSE) {
                trigger_error("'../' is not permitted in the src argument", E_USER_WARNING);
                $this->src = str_replace('../', '', $this->src);
            }
        }
        $this->filename = $this->src;
        if (isset($_REQUEST['h']) && is_numeric($_REQUEST['h'])) {
            $this->height = $_REQUEST['h'];
        }
        if (isset($_REQUEST['w']) && is_numeric($_REQUEST['w'])) {
            $this->width = $_REQUEST['w'];
        }
        if (isset($_REQUEST['q']) && is_numeric($_REQUEST['q']) && $_REQUEST['q'] > 0 && $_REQUEST['q'] < 100) {
            $this->quality = $_REQUEST['q'];
        }
        $this->zoomCrop = (isset($_REQUEST['zc']) && $_REQUEST['zc'] == 1) ? 1 : 0;
        if (isset($_REQUEST['s']) && ($_REQUEST['s'] == 1 || $_REQUEST['s'] == 0)) {
            $this->stream = $_REQUEST['s'];
        }

        // set cache directory:
        if (!$this->cacheDir) {
            $this->cacheDir = '';
        } else {
            if (!preg_match('#^(/|[[:alpha:]]:[/\\\\])#', $this->cacheDir)) {
                $this->cacheDir = rtrim(dirname(__FILE__), '\/') . '/' . $this->cacheDir;
            }
            if (!file_exists($this->cacheDir)) {
                if (!@mkdir($this->cacheDir)) {
                    $this->error('Could not create cache (' . $this->cacheDir . ') ', FALSE);
                    $this->cacheDir = FALSE;
                }
            }
            // set cachefilename:
            $this->cacheFilename = $this->cacheDir . md5($this->src . $this->width . $this->height . $this->zoomCrop . $this->quality) . $this->getExt();
        }

        // find file (sets $this->filename and $this->isCached):
        if (!$this->findFile()) {
            $this->error('File not found.');
        }

		// check for updates:
        if (isset($_SERVER['HTTP_IF_MODIFIED_SINCE'])) {
            $ifModifiedSince = preg_replace('/;.*$/', '', $_SERVER['HTTP_IF_MODIFIED_SINCE']);
            if ($ifModifiedSince == $this->gmdateMod && !$this->debug) {
                header('HTTP/1.1 304 Not Modified');
                exit;
            }
        }

        // set mime type:
        $this->setMimeType();

        // cache file if needed:
        if (!$this->cacheFile()) {
            $this->setModTime(TRUE);
            $this->isLocal = FALSE;
        }

        // resize file, if necessary (will send file if cache is disabled):
        if (!$this->foundInCache) {
            $this->resizeFile();
        }

        // send the file to the browser, if not already sent:
        $this->sendFile();

    }

    private function error ($message, $die=TRUE) {
        if ($this->debug) {
            echo $message;
            if ($die) {
                exit();
            }
        } elseif ($die) {
            header('HTTP/1.0 404 Not Found');
            exit();
        }
    }

    private function sendHeaders ($streaming=FALSE) {
        if ($streaming) {
            $disposition = 'inline';
        } elseif (is_null($this->stream)) {
            $disposition = $this->inList($this->mimeType, $this->attachmentTypes) ? 'attachment' : 'inline';
        } else {
            $disposition = $this->stream == 1 ? 'inline' : 'attachment';
        }
        if (!$this->debug && !$this->headersSent) {
            header('Content-Type: ' . $this->mimeType . '; charset=binary');
            if (!$streaming) {
                header('Last-Modified: ' . $this->gmdateMod);
                header('Content-Length: ' . filesize($this->filename));
            }
            header('Cache-Control: max-age=9999, must-revalidate');
            header('Expires: ' . $this->getGmt(time() + 9999));

            header('Content-Disposition: ' . $disposition  . '; filename="' . basename($this->src) . '"');

            $this->headersSent = TRUE;
        }
    }

    private function sendFile () {
        if ($this->debug) {
            die('SUCCESS! Turn off debug mode to display: ' . htmlentities($this->filename) . ' (' . htmlentities($this->mimeType) . ')');
        }

        // send headers:
        $this->sendHeaders();

        // send file:
        echo $this->buffer;
        while(!feof($this->file)) {
            echo fread($this->file, $this->bufferSize);
        }

        // cleanup:
        fclose($this->file);
        exit();
    }

    /**
     * Tries to locate the file in $this->includePath and sets $this->filename
     * and $this->isCached.
     * @return bool TRUE if the file is found.
     */
    private function findFile () {
// TODO: not caching untouched local files, should we still check?
        if ($this->cacheDir && @file_exists($this->cacheFilename)) {
            $this->filename = $this->cacheFilename;
            $this->setModTime();
            $this->isCached = TRUE;
            $this->foundInCache = TRUE;
            return TRUE;
        } else {
            $basedir = rtrim(dirname($_SERVER['SCRIPT_FILENAME']), '/ ') . '/';
            $paths = explode(',', $this->includePath);
            foreach ($paths as $path) {
                $path = rtrim($path, '/ ') . '/';
// TODO: windows?
                if (preg_match('#^(https?|ftp)://#', $path)) {
                    $arr = @get_headers($path . $this->filename);
                    if ($arr !== FALSE) {
                        $arr = explode(' ', $arr[0]);
                        if (isset($arr[1]) && $arr[1] == 200) {
                            $this->filename = $path . $this->filename;
                            $this->isLocal = FALSE;
                            return TRUE;
                        }
                    }
                } else {
                    if (substr($path, 0, 1) != '/') {
                        $path = $basedir . $path;
                    }
                    if (@file_exists($path . $this->filename)) {
                        $this->filename = $path . $this->filename;
                        $this->setModTime();
                        return TRUE;
                    }
                }
            }
        }
        return FALSE;
    }

    private function openFile () {
        if ($this->file) {
            fclose($this->file);
            $this->buffer = NULL;
        }
        $this->file = @fopen($this->filename, 'rb');
        if (!$this->file) {
            $this->error('Could not open file. Check permissions.');
        }
    }

    private function setMimeType () {
        if (!$this->mimeType) {
            // open file:
            $this->openFile();

            $os = strtolower(php_uname());
            if (class_exists('finfo', FALSE) && $this->magicLocation) {
                // use PECL fileinfo package:
                $this->buffer = fread($this->file, $this->bufferSize);
                $finfo = new finfo(FILEINFO_MIME, $this->magicLocation);
                $this->mimeType = $finfo->buffer($this->buffer);
            } elseif ($this->isLocal && (strpos($os, 'freebsd') !== FALSE || strpos($os, 'linux') !== FALSE)) {
                // use 'file' command:
                $this->mimeType = trim(@shell_exec('file -bi "' . $this->filename . '"'));
            } elseif ($this->isLocal && @(include 'MIME/Type.php')) {
                // use PEAR MIME package:
                $this->mimeType = MIME_Type::autoDetect($this->filename);
            } else {
                // determine by extension -- for timthumb compatibility; only works for image types
                $ext = $this->getExt();
                switch ($ext) {
                    case '.jpg':
                    case '.jpeg':
                        $this->mimeType = 'image/jpeg';
                    break;
                    case '.png':
                        $this->mimeType = 'image/png';
                    break;
                    case '.gif':
                        $this->mimeType = 'image/gif';
                    break;
                }
            }
            $this->isResizable = $this->inList($this->mimeType, self::RESIZABLE_TYPES);
        }
        $this->mimeType = str_replace('; charset=binary', '', $this->mimeType);
        $this->checkMimeType();
    }

    private function checkMimeType () {
        if ($this->mimeType) {
            $valid = TRUE;
            $mt = trim(strtolower($this->mimeType));
            if (($this->disallowedTypes && $this->inList($mt, $this->disallowedTypes))
            || ($this->allowedTypes && !$this->inList($mt, $this->allowedTypes))) {
                $valid = FALSE;
            }
        } else {
            $valid = FALSE;
        }
        if (!$valid) {
            $this->error('Invalid file type: ' . ($this->mimeType ? $this->mimeType : 'none'));
        }
    }

    private function inList ($string, $list, $separator=',') {
        $items = explode($separator, $list);
        foreach ($items as $item) {
            if (stripos($string, $item) !== FALSE) {
                return TRUE;
            }
        }
        return FALSE;
    }

    private function cacheFile () {
        if (!$this->cacheDir || $this->foundInCache || !$this->cacheFilename) {
            return TRUE;
        } else {
            if (@file_exists($this->cacheFilename)) {
                @unlink($this->cacheFilename);
            }

            if (!@copy($this->filename, $this->cacheFilename)) {
                $this->error('Error copying file to cache: ' . $this->filename . ' ', FALSE);
                $this->isCached = FALSE;
            } else {
                $this->filename = $this->cacheFilename;
                $this->setModTime();
                $this->isCached = TRUE;
            }
            return $this->isCached;
        }
    }

    private function resizeFile () {
        if ($this->isResizable && ($this->width || $this->height || $this->quality)) {
            // open the existing image
            $image = FALSE;
            switch ($this->mimeType) {
                case 'image/gif':
                    $image = imagecreatefromgif($this->filename);
                break;
                case 'image/jpeg':
                    @ini_set('gd.jpeg_ignore_warning', 1);
                    $image = imagecreatefromjpeg($this->filename);
                break;
                case 'image/png':
                    $image = imagecreatefrompng($this->filename);
                break;
            }
            if ($image === FALSE) {
                $this->error('Unable to open image: ' . $this->filename);
            }

            // Get original width and height
            $width = imagesx($image);
            $height = imagesy($image);

            // don't allow new width or height to be greater than the original
            if ($this->width > $width) {
                $this->width = $width;
            }
            if ($this->height > $height) {
                $this->height = $height;
            }

            // generate new w/h if not provided
            if($this->width && !$this->height) {
                $this->height = $height * ($this->width / $width);
            } elseif($this->height && !$this->width) {
                $this->width = $width * ($this->height / $height);
            } elseif(!$this->width && !$this->height) {
                $this->width = $width;
                $this->height = $height;
            }

            // create a new true color image
            $canvas = imagecreatetruecolor($this->width, $this->height);

            if ($this->zoomCrop) {
                $src_x = $src_y = 0;
                $src_w = $width;
                $src_h = $height;

                $cmp_x = $width  / $this->width;
                $cmp_y = $height / $this->height;

                // calculate x or y coordinate and width or height of source
                if ($cmp_x > $cmp_y) {
                    $src_w = round(($width / $cmp_x * $cmp_y));
                    //$src_x = round(($width - ($width / $cmp_x * $cmp_y)) / 2);
                } elseif ($cmp_y > $cmp_x) {
                    $src_h = round(($height / $cmp_y * $cmp_x));
                    //$src_y = round(($height - ($height / $cmp_y * $cmp_x)) / 2);
                }
                imagecopyresampled($canvas, $image, 0, 0, $src_x, $src_y, $this->width, $this->height, $src_w, $src_h);
            } else {
                // copy and resize part of an image with resampling
                imagecopyresampled($canvas, $image, 0, 0, 0, 0, $this->width, $this->height, $width, $height);
            }

            if ($this->cacheDir) {
                $this->setModTime(TRUE);
                $fn = $this->cacheFilename;
            } else {
                $this->sendHeaders(TRUE);
                $this->fileSent = TRUE;
                $fn = NULL; // causes image to be sent directly to browser on call to image*()
            }

            switch ($this->mimeType) {
                case 'image/gif':
                    imagegif($canvas, $fn);
                break;
                case 'image/jpeg':
                    imagejpeg($canvas, $fn, $this->quality);
                break;
                case 'image/png':
                    imagepng($canvas, $fn, ceil($this->quality / 10));
                break;
                default:
                    $this->error('Could not save resized image: invalid type.');
            }

            if ($this->cacheDir){
                $this->openFile();
            } else {
                exit();
            }

            // remove image from memory
            imagedestroy($canvas);
        }
    }

    private function getExt () {
        $pos = strrpos($this->src, '.');
        return $pos === FALSE ? '' : substr($this->src, $pos);
    }

    private function setModTime ($now=FALSE) {
        $t = $now ? time() : filemtime($this->filename);
        $this->gmdateMod = $this->getGmt($t);
    }

    private function getGmt ($time) {
        return gmdate('D, d M Y H:i:s', $time) . ' GMT';
    }
}

// EOF
