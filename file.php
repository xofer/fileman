<?php
/**
 * Fileman 0.3 BETA
 * copyright (c) 2009-2010 Christopher Clarke: http://fuscata.com/contact
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
 * Fileman uses portions of the TimThumb script created by Tim McDaniels and
 * Darren Hoyt with tweaks by Ben Gillbanks
 * http://code.google.com/p/timthumb/

    system requirements:
      PHP 5.2.0 or later
      Apache 2.0 or later (not tested on others)
      GD Library for image processing: http://php.net/manual/en/book.image.php

    Fileman has been tested on Windows/Apache, although there may be some
    issues with absolute paths.

    usage example: <img src="/file.php?src=whatever.jpg&amp;w=100&amp;q=60" />

    query string options:
      w - width in pixels, up to the width of the original
      h - height in pixels, up to the height of the original
      zc - set to 1 to 'zoom crop' rather than resize
      s - set to 1 to stream, 0 to 'Save file as...' See attachmentTypes option below.
      q - quality for JPEG and PNG files, only used if the image is resized
    To resize while preserving the aspect ratio, set either height OR width, not both.

    SECURITY OVERVIEW
    Anyone can view any file on your system that is within the includePath
    if its MIME type is valid. Use includePath, allowedTypes and disallowedTypes
    to protect your system files, PHP scripts and any other files that you
    don't want everyone in the world to see. DO NOT allow files with a text*
    MIME type! Allowing application*, multipart* and/or message* is probably a
    BAD IDEA as well. Relative paths in the src variable are not allowed, so
    for example, http://example.com/file.php?src=../../../etc/passwd SHOULD
    not work, but DO NOT ALLOW TEXT FILES to be sure. Depending on your server
    configuration, filesystem links/shortcuts MIGHT be followed; keep that
    in mind.

*/

    include 'fileman.php';
    $fm = new fileman();

    /*
    magicLocation: The PECL fileinfo package is the preferred method for
    determining a file's MIME type. This method requires the PECL package
    and the magic database.
    To install on Ubuntu:
    $ sudo sudo apt-get install php-pear php5-dev libmagic1 libmagic-dev
    $ sudo pecl channel-update pear.php.net
    $ sudo pecl install fileinfo
    If not using magic, set magicLocation to an empty string and install
    the mime_type PEAR package:
    To install on Ubuntu:
    $ sudo pear install system_command mime_type
    You may also download the PEAR package directly and put it somewhere
    in the include_path.
    If both of the above methods fail, fileman will attempt to use the
    *nix 'file' command to determine the type. If that fails, fileman will
    set the type based on the extension for JPEG, PNG and GIF files. Other
    file types will result in a 404 error.
    DEFAULT: '/usr/share/file/magic'
    */
    $fm->magicLocation = '/usr/share/file/magic';

    /*
    includePath: A comma-separated list of locations to check for files.
    Locations can be relative to the calling (i.e. *this*) script (no
    beginning slash e.g. 'images/', '../media/') absolute (e.g. '/var/www/images/')
    or an http(s)/ftp URL (e.g. 'http://example.com/images/').
    To use remote locations, your PHP installation must 'allow_url_fopen'.
    See: http://php.net/manual/en/filesystem.configuration.php#ini.allow-url-fopen
    SECURITY: Take care when setting this option. See SECURITY OVERVIEW above.
    EXAMPLE: $fm->includePath = '.,images/,http://example.com/images/,ftp://user@example.com/music/';
    DEFAULT: '.'
    */
    $fm->includePath = '.';

    /*
    cacheDir: set to a relative (to fileman.php) or absolute path to cache
    files that are resized or loaded from a remote source. You can set it to
    an empty string to disable the cache, but this is NOT RECOMMENDED. No
    cleanup is performed; you're on your own for that. The cacheDir must be
    writeable by the web server user. If you want to restrict direct access
    to it (e.g. via a web browser), move it out of the site's document root
    or use your web server's configuration to deny access.
    DEFAULT: 'cache/'
    */
    $fm->cacheDir = 'cache/';

    /*
    allowedTypes: set this to MIME types or parts of MIME types that you want to
    allow. disallowedTypes takes precedence over allowedTypes. Set this to an
    empty string to allow all types not specified in disallowedTypes (NOT
    RECOMMENDED!).
    See: http://www.iana.org/assignments/media-types/
    SECURITY: Take care when setting this option. See SECURITY OVERVIEW above.
    DEFAULT: 'image,audio,video'
    */
    $fm->allowedTypes = 'image,audio,video';

    /*
    disallowedTypes: set this to MIME types or parts of MIME types that you
    want to deny. disallowedTypes takes precedence over allowedTypes.
    See: http://www.iana.org/assignments/media-types/
    SECURITY: Take care when setting this option. See SECURITY OVERVIEW above.
    DEFAULT: ''
    */
    $fm->disallowedTypes = '';

    /*
    attachmentTypes: set this to MIME types or parts of MIME types that you
    DO NOT want to stream (send directly) to the client by default. Most
    browsers will display a 'Save as...' dialog if the content is sent
    as an attachment rather than streamed, and will attempt to open the file
    in an appropriate plugin or application if it is streamed. The query string
    variable 's' can always override the behavior defined here, allowing you
    to stream some files and not others. (Set s=1 to stream, s=0 to 'Save as...')
    DEFAULT: 'audio,video'
    */
    $fm->attachmentTypes = 'audio,video';

    /*
    bufferSize: file buffer size. Only change if you're having problems and know
    what you're doing.
    DEFAULT: 4096
    */
    $fm->bufferSize = 4096;

    /*
    debug: set to TRUE to display debugging output instead of the file.
    DEFAULT: FALSE
    */
    $fm->debug = FALSE;


    // send file to client:
    $fm->send();

// EOF
