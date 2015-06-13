<?php

/*
Source: https://github.com/splitbrain/php-archive
Modified to work with PHP 5.2 (removed namespaces, changed clearstatcache signature)
*/

if (!defined('ABSPATH'))
    die('Plugin file cannot be accessed directly.');

if (!class_exists('Splitbrain_Archive')) {
    abstract class Splitbrain_Archive
    {

        const COMPRESS_AUTO = -1;
        const COMPRESS_NONE = 0;
        const COMPRESS_GZIP = 1;
        const COMPRESS_BZIP = 2;

        /**
         * Set the compression level and type
         *
         * @param int $level Compression level (0 to 9)
         * @param int $type  Type of compression to use (use COMPRESS_* constants)
         * @return mixed
         */
        abstract public function setCompression($level = 9, $type = Splitbrain_Archive::COMPRESS_AUTO);

        /**
         * Open an existing archive file for reading
         *
         * @param string $file
         * @throws ArchiveIOException
         */
        abstract public function open($file);

        /**
         * Read the contents of an archive
         *
         * This function lists the files stored in the archive, and returns an indexed array of FileInfo objects
         *
         * The archive is closed afer reading the contents, because rewinding is not possible in bzip2 streams.
         * Reopen the file with open() again if you want to do additional operations
         *
         * @return FileInfo[]
         */
        abstract public function contents();

        /**
         * Extract an existing archive
         *
         * The $strip parameter allows you to strip a certain number of path components from the filenames
         * found in the archive file, similar to the --strip-components feature of GNU tar. This is triggered when
         * an integer is passed as $strip.
         * Alternatively a fixed string prefix may be passed in $strip. If the filename matches this prefix,
         * the prefix will be stripped. It is recommended to give prefixes with a trailing slash.
         *
         * By default this will extract all files found in the archive. You can restrict the output using the $include
         * and $exclude parameter. Both expect a full regular expression (including delimiters and modifiers). If
         * $include is set, only files that match this expression will be extracted. Files that match the $exclude
         * expression will never be extracted. Both parameters can be used in combination. Expressions are matched against
         * stripped filenames as described above.
         *
         * The archive is closed afterwards. Reopen the file with open() again if you want to do additional operations
         *
         * @param string     $outdir  the target directory for extracting
         * @param int|string $strip   either the number of path components or a fixed prefix to strip
         * @param string     $exclude a regular expression of files to exclude
         * @param string     $include a regular expression of files to include
         * @throws ArchiveIOException
         * @return array
         */
        abstract public function extract($outdir, $strip = '', $exclude = '', $include = '');

        /**
         * Create a new archive file
         *
         * If $file is empty, the archive file will be created in memory
         *
         * @param string $file
         */
        abstract public function create($file = '');

        /**
         * Add a file to the current archive using an existing file in the filesystem
         *
         * @param string          $file     path to the original file
         * @param string|FileInfo $fileinfo either the name to us in archive (string) or a FileInfo oject with all meta data, empty to take from original
         * @throws ArchiveIOException
         */
        abstract public function addFile($file, $fileinfo = '');

        /**
         * Add a file to the current archive using the given $data as content
         *
         * @param string|FileInfo $fileinfo either the name to us in archive (string) or a FileInfo oject with all meta data
         * @param string          $data     binary content of the file to add
         * @throws ArchiveIOException
         */
        abstract public function addData($fileinfo, $data);

        /**
         * Close the archive, close all file handles
         *
         * After a call to this function no more data can be added to the archive, for
         * read access no reading is allowed anymore
         */
        abstract public function close();

        /**
         * Returns the created in-memory archive data
         *
         * This implicitly calls close() on the Archive
         */
        abstract public function getArchive();

        /**
         * Save the created in-memory archive data
         *
         * Note: It is more memory effective to specify the filename in the create() function and
         * let the library work on the new file directly.
         *
         * @param string $file
         */
        abstract public function save($file);

    }

    class Splitbrain_ArchiveIOException extends Exception
    {
    }

    class Splitbrain_ArchiveIllegalCompressionException extends Exception
    {
    }

    /**
     * Class FileInfo
     *
     * stores meta data about a file in an Archive
     *
     * @author  Andreas Gohr <andi@splitbrain.org>
     * @package splitbrain\PHPArchive
     * @license MIT
     */
    class Splitbrain_FileInfo
    {

        protected $isdir = false;
        protected $path = '';
        protected $size = 0;
        protected $csize = 0;
        protected $mtime = 0;
        protected $mode = 0664;
        protected $owner = '';
        protected $group = '';
        protected $uid = 0;
        protected $gid = 0;
        protected $comment = '';

        /**
         * initialize dynamic defaults
         *
         * @param string $path The path of the file, can also be set later through setPath()
         */
        public function __construct($path = '')
        {
            $this->mtime = time();
            $this->setPath($path);
        }

        /**
         * Factory to build FileInfo from existing file or directory
         *
         * @param string $path path to a file on the local file system
         * @param string $as   optional path to use inside the archive
         * @throws FileInfoException
         * @return FileInfo
         */
        public static function fromPath($path, $as = '')
        {
            if (defined('__DIR__'))
                clearstatcache(false, $path);
            else
                clearstatcache();

            if (!file_exists($path)) {
                throw new Splitbrain_FileInfoException("$path does not exist");
            }

            $stat = stat($path);
            $file = new Splitbrain_FileInfo();

            $file->setPath($path);
            $file->setIsdir(is_dir($path));
            $file->setMode(fileperms($path));
            $file->setOwner(fileowner($path));
            $file->setGroup(filegroup($path));
            $file->setUid($stat['uid']);
            $file->setGid($stat['gid']);
            $file->setMtime($stat['mtime']);

            if ($as) {
                $file->setPath($as);
            }

            return $file;
        }

        /**
         * @return int
         */
        public function getSize()
        {
            return $this->size;
        }

        /**
         * @param int $size
         */
        public function setSize($size)
        {
            $this->size = $size;
        }

        /**
         * @return int
         */
        public function getCompressedSize()
        {
            return $this->csize;
        }

        /**
         * @param int $csize
         */
        public function setCompressedSize($csize)
        {
            $this->csize = $csize;
        }

        /**
         * @return int
         */
        public function getMtime()
        {
            return $this->mtime;
        }

        /**
         * @param int $mtime
         */
        public function setMtime($mtime)
        {
            $this->mtime = $mtime;
        }

        /**
         * @return int
         */
        public function getGid()
        {
            return $this->gid;
        }

        /**
         * @param int $gid
         */
        public function setGid($gid)
        {
            $this->gid = $gid;
        }

        /**
         * @return int
         */
        public function getUid()
        {
            return $this->uid;
        }

        /**
         * @param int $uid
         */
        public function setUid($uid)
        {
            $this->uid = $uid;
        }

        /**
         * @return string
         */
        public function getComment()
        {
            return $this->comment;
        }

        /**
         * @param string $comment
         */
        public function setComment($comment)
        {
            $this->comment = $comment;
        }

        /**
         * @return string
         */
        public function getGroup()
        {
            return $this->group;
        }

        /**
         * @param string $group
         */
        public function setGroup($group)
        {
            $this->group = $group;
        }

        /**
         * @return boolean
         */
        public function getIsdir()
        {
            return $this->isdir;
        }

        /**
         * @param boolean $isdir
         */
        public function setIsdir($isdir)
        {
            // default mode for directories
            if ($isdir && $this->mode === 0664) {
                $this->mode = 0775;
            }
            $this->isdir = $isdir;
        }

        /**
         * @return int
         */
        public function getMode()
        {
            return $this->mode;
        }

        /**
         * @param int $mode
         */
        public function setMode($mode)
        {
            $this->mode = $mode;
        }

        /**
         * @return string
         */
        public function getOwner()
        {
            return $this->owner;
        }

        /**
         * @param string $owner
         */
        public function setOwner($owner)
        {
            $this->owner = $owner;
        }

        /**
         * @return string
         */
        public function getPath()
        {
            return $this->path;
        }

        /**
         * @param string $path
         */
        public function setPath($path)
        {
            $this->path = $this->cleanPath($path);
        }

        /**
         * Cleans up a path and removes relative parts, also strips leading slashes
         *
         * @param string $path
         * @return string
         */
        protected function cleanPath($path)
        {
            $path    = str_replace('\\', '/', $path);
            $path    = explode('/', $path);
            $newpath = array();
            foreach ($path as $p) {
                if ($p === '' || $p === '.') {
                    continue;
                }
                if ($p === '..') {
                    array_pop($newpath);
                    continue;
                }
                array_push($newpath, $p);
            }
            return trim(implode('/', $newpath), '/');
        }

        /**
         * Strip given prefix or number of path segments from the filename
         *
         * The $strip parameter allows you to strip a certain number of path components from the filenames
         * found in the tar file, similar to the --strip-components feature of GNU tar. This is triggered when
         * an integer is passed as $strip.
         * Alternatively a fixed string prefix may be passed in $strip. If the filename matches this prefix,
         * the prefix will be stripped. It is recommended to give prefixes with a trailing slash.
         *
         * @param  int|string $strip
         * @return FileInfo
         */
        public function strip($strip)
        {
            $filename = $this->getPath();
            $striplen = strlen($strip);
            if (is_int($strip)) {
                // if $strip is an integer we strip this many path components
                $parts = explode('/', $filename);
                if (!$this->getIsdir()) {
                    $base = array_pop($parts); // keep filename itself
                } else {
                    $base = '';
                }
                $filename = join('/', array_slice($parts, $strip));
                if ($base) {
                    $filename .= "/$base";
                }
            } else {
                // if strip is a string, we strip a prefix here
                if (substr($filename, 0, $striplen) == $strip) {
                    $filename = substr($filename, $striplen);
                }
            }

            $this->setPath($filename);
        }

        /**
         * Does the file match the given include and exclude expressions?
         *
         * Exclude rules take precedence over include rules
         *
         * @param string $include Regular expression of files to include
         * @param string $exclude Regular expression of files to exclude
         * @return bool
         */
        public function match($include = '', $exclude = '')
        {
            $extract = true;
            if ($include && !preg_match($include, $this->getPath())) {
                $extract = false;
            }
            if ($exclude && preg_match($exclude, $this->getPath())) {
                $extract = false;
            }

            return $extract;
        }
    }

    class Splitbrain_FileInfoException extends Exception
    {
    }

    /**
     * Class Zip
     *
     * Creates or extracts Zip archives
     *
     * @author  Andreas Gohr <andi@splitbrain.org>
     * @package splitbrain\PHPArchive
     * @license MIT
     */
    class Splitbrain_Zip extends Splitbrain_Archive
    {

        protected $file = '';
        protected $fh;
        protected $memory = '';
        protected $closed = true;
        protected $writeaccess = false;
        protected $ctrl_dir;
        protected $complevel = 9;

        /**
         * Set the compression level.
         *
         * Compression Type is ignored for ZIP
         *
         * You can call this function before adding each file to set differen compression levels
         * for each file.
         *
         * @param int $level Compression level (0 to 9)
         * @param int $type  Type of compression to use ignored for ZIP
         * @return mixed
         */
        public function setCompression($level = 9, $type = Splitbrain_Archive::COMPRESS_AUTO)
        {
            $this->complevel = $level;
        }

        /**
         * Open an existing ZIP file for reading
         *
         * @param string $file
         * @throws ArchiveIOException
         */
        public function open($file)
        {
            $this->file = $file;
            $this->fh   = @fopen($this->file, 'rb');
            if (!$this->fh) {
                throw new Splitbrain_ArchiveIOException('Could not open file for reading: '.$this->file);
            }
            $this->closed = false;
        }

        /**
         * Read the contents of a ZIP archive
         *
         * This function lists the files stored in the archive, and returns an indexed array of FileInfo objects
         *
         * The archive is closed afer reading the contents, for API compatibility with TAR files
         * Reopen the file with open() again if you want to do additional operations
         *
         * @throws ArchiveIOException
         * @return FileInfo[]
         */
        public function contents()
        {
            if ($this->closed || !$this->file) {
                throw new Splitbrain_ArchiveIOException('Can not read from a closed archive');
            }

            $result = array();

            $centd = $this->readCentralDir();

            @rewind($this->fh);
            @fseek($this->fh, $centd['offset']);

            for ($i = 0; $i < $centd['entries']; $i++) {
                $result[] = $this->header2fileinfo($this->readCentralFileHeader());
            }

            $this->close();
            return $result;
        }

        /**
         * Extract an existing ZIP archive
         *
         * The $strip parameter allows you to strip a certain number of path components from the filenames
         * found in the tar file, similar to the --strip-components feature of GNU tar. This is triggered when
         * an integer is passed as $strip.
         * Alternatively a fixed string prefix may be passed in $strip. If the filename matches this prefix,
         * the prefix will be stripped. It is recommended to give prefixes with a trailing slash.
         *
         * By default this will extract all files found in the archive. You can restrict the output using the $include
         * and $exclude parameter. Both expect a full regular expression (including delimiters and modifiers). If
         * $include is set only files that match this expression will be extracted. Files that match the $exclude
         * expression will never be extracted. Both parameters can be used in combination. Expressions are matched against
         * stripped filenames as described above.
         *
         * @param string     $outdir  the target directory for extracting
         * @param int|string $strip   either the number of path components or a fixed prefix to strip
         * @param string     $exclude a regular expression of files to exclude
         * @param string     $include a regular expression of files to include
         * @throws ArchiveIOException
         * @return FileInfo[]
         */
        function extract($outdir, $strip = '', $exclude = '', $include = '')
        {
            if ($this->closed || !$this->file) {
                throw new Splitbrain_ArchiveIOException('Can not read from a closed archive');
            }

            $outdir = rtrim($outdir, '/');
            @mkdir($outdir, 0777, true);

            $extracted = array();

            $cdir      = $this->readCentralDir();
            $pos_entry = $cdir['offset']; // begin of the central file directory

            for ($i = 0; $i < $cdir['entries']; $i++) {
                // read file header
                @fseek($this->fh, $pos_entry);
                $header          = $this->readCentralFileHeader();
                $header['index'] = $i;
                $pos_entry       = ftell($this->fh); // position of the next file in central file directory
                fseek($this->fh, $header['offset']); // seek to beginning of file header
                $header   = $this->readFileHeader($header);
                $fileinfo = $this->header2fileinfo($header);

                // apply strip rules
                $fileinfo->strip($strip);

                // skip unwanted files
                if (!strlen($fileinfo->getPath()) || !$fileinfo->match($include, $exclude)) {
                    continue;
                }

                $extracted[] = $fileinfo;

                // create output directory
                $output    = $outdir.'/'.$fileinfo->getPath();
                $directory = ($header['folder']) ? $output : dirname($output);
                @mkdir($directory, 0777, true);

                // nothing more to do for directories
                if ($fileinfo->getIsdir()) {
                    continue;
                }

                // compressed files are written to temporary .gz file first
                if ($header['compression'] == 0) {
                    $extractto = $output;
                } else {
                    $extractto = $output.'.gz';
                }

                // open file for writing
                $fp = fopen($extractto, "wb");
                if (!$fp) {
                    throw new Splitbrain_ArchiveIOException('Could not open file for writing: '.$extractto);
                }

                // prepend compression header
                if ($header['compression'] != 0) {
                    $binary_data = pack(
                        'va1a1Va1a1',
                        0x8b1f,
                        chr($header['compression']),
                        chr(0x00),
                        time(),
                        chr(0x00),
                        chr(3)
                    );
                    fwrite($fp, $binary_data, 10);
                }

                // read the file and store it on disk
                $size = $header['compressed_size'];
                while ($size != 0) {
                    $read_size   = ($size < 2048 ? $size : 2048);
                    $buffer      = fread($this->fh, $read_size);
                    $binary_data = pack('a'.$read_size, $buffer);
                    fwrite($fp, $binary_data, $read_size);
                    $size -= $read_size;
                }

                // finalize compressed file
                if ($header['compression'] != 0) {
                    $binary_data = pack('VV', $header['crc'], $header['size']);
                    fwrite($fp, $binary_data, 8);
                }

                // close file
                fclose($fp);

                // unpack compressed file
                if ($header['compression'] != 0) {
                    $gzp = @gzopen($extractto, 'rb');
                    if (!$gzp) {
                        @unlink($extractto);
                        throw new Splitbrain_ArchiveIOException('Failed file extracting. gzip support missing?');
                    }
                    $fp = @fopen($output, 'wb');
                    if (!$fp) {
                        throw new Splitbrain_ArchiveIOException('Could not open file for writing: '.$extractto);
                    }

                    $size = $header['size'];
                    while ($size != 0) {
                        $read_size   = ($size < 2048 ? $size : 2048);
                        $buffer      = gzread($gzp, $read_size);
                        $binary_data = pack('a'.$read_size, $buffer);
                        @fwrite($fp, $binary_data, $read_size);
                        $size -= $read_size;
                    }
                    fclose($fp);
                    gzclose($gzp);
                }

                touch($output, $fileinfo->getMtime());
                //FIXME what about permissions?
            }

            $this->close();
            return $extracted;
        }

        /**
         * Create a new ZIP file
         *
         * If $file is empty, the zip file will be created in memory
         *
         * @param string $file
         * @throws ArchiveIOException
         */
        public function create($file = '')
        {
            $this->file   = $file;
            $this->memory = '';
            $this->fh     = 0;

            if ($this->file) {
                $this->fh = @fopen($this->file, 'wb');

                if (!$this->fh) {
                    throw new Splitbrain_ArchiveIOException('Could not open file for writing: '.$this->file);
                }
            }
            $this->writeaccess = true;
            $this->closed      = false;
            $this->ctrl_dir    = array();
        }

        /**
         * Add a file to the current ZIP archive using an existing file in the filesystem
         *
         * @param string          $file     path to the original file
         * @param string|FileInfo $fileinfo either the name to us in archive (string) or a FileInfo oject with all meta data, empty to take from original
         * @throws ArchiveIOException
         */

        /**
         * Add a file to the current archive using an existing file in the filesystem
         *
         * @param string          $file     path to the original file
         * @param string|FileInfo $fileinfo either the name to us in archive (string) or a FileInfo oject with all meta data, empty to take from original
         * @throws ArchiveIOException
         */
        public function addFile($file, $fileinfo = '')
        {
            if (is_string($fileinfo)) {
                $fileinfo = Splitbrain_FileInfo::fromPath($file, $fileinfo);
            }

            if ($this->closed) {
                throw new Splitbrain_ArchiveIOException('Archive has been closed, files can no longer be added');
            }

            $data = @file_get_contents($file);
            if ($data === false) {
                throw new Splitbrain_ArchiveIOException('Could not open file for reading: '.$file);
            }

            // FIXME could we stream writing compressed data? gzwrite on a fopen handle?
            $this->addData($fileinfo, $data);
        }

        /**
         * Add a file to the current TAR archive using the given $data as content
         *
         * @param string|FileInfo $fileinfo either the name to us in archive (string) or a FileInfo oject with all meta data
         * @param string          $data     binary content of the file to add
         * @throws ArchiveIOException
         */
        public function addData($fileinfo, $data)
        {
            if (is_string($fileinfo)) {
                $fileinfo = new Splitbrain_FileInfo($fileinfo);
            }

            if ($this->closed) {
                throw new Splitbrain_ArchiveIOException('Archive has been closed, files can no longer be added');
            }

            // prepare the various header infos
            $dtime    = dechex($this->makeDosTime($fileinfo->getMtime()));
            $hexdtime = pack(
                'H*',
                $dtime[6].$dtime[7].
                $dtime[4].$dtime[5].
                $dtime[2].$dtime[3].
                $dtime[0].$dtime[1]
            );
            $size     = strlen($data);
            $crc      = crc32($data);
            if ($this->complevel) {
                $fmagic = "\x50\x4b\x03\x04\x14\x00\x00\x00\x08\x00";
                $cmagic = "\x50\x4b\x01\x02\x00\x00\x14\x00\x00\x00\x08\x00";
                $data   = gzcompress($data, $this->complevel);
                $data   = substr($data, 2, -4); // strip compression headers
            } else {
                $fmagic = "\x50\x4b\x03\x04\x0a\x00\x00\x00\x00\x00";
                $cmagic = "\x50\x4b\x01\x02\x14\x00\x0a\x00\x00\x00\x00\x00";
            }
            $csize  = strlen($data);
            $offset = $this->dataOffset();
            $name   = $fileinfo->getPath();

            // write data
            $this->writebytes($fmagic);
            $this->writebytes($hexdtime);
            $this->writebytes(pack('V', $crc).pack('V', $csize).pack('V', $size)); //pre header
            $this->writebytes(pack('v', strlen($name)).pack('v', 0).$name.$data); //file data
            $this->writebytes(pack('V', $crc).pack('V', $csize).pack('V', $size)); //post header

            // add info to central file directory
            $cdrec = $cmagic;
            $cdrec .= $hexdtime.pack('V', $crc).pack('V', $csize).pack('V', $size);
            $cdrec .= pack('v', strlen($name)).pack('v', 0).pack('v', 0);
            $cdrec .= pack('v', 0).pack('v', 0).pack('V', 32);
            $cdrec .= pack('V', $offset);
            $cdrec .= $name;
            $this->ctrl_dir[] = $cdrec;
        }

        /**
         * Add the closing footer to the archive if in write mode, close all file handles
         *
         * After a call to this function no more data can be added to the archive, for
         * read access no reading is allowed anymore
         */
        public function close()
        {
            if ($this->closed) {
                return;
            } // we did this already

            // write footer
            if ($this->writeaccess) {
                $offset  = $this->dataOffset();
                $ctrldir = join('', $this->ctrl_dir);
                $this->writebytes($ctrldir);
                $this->writebytes("\x50\x4b\x05\x06\x00\x00\x00\x00"); // EOF CTRL DIR
                $this->writebytes(pack('v', count($this->ctrl_dir)).pack('v', count($this->ctrl_dir)));
                $this->writebytes(pack('V', strlen($ctrldir)).pack('V', strlen($offset))."\x00\x00");
                $this->ctrl_dir = array();
            }

            // close file handles
            if ($this->file) {
                fclose($this->fh);
                $this->file = '';
                $this->fh   = 0;
            }

            $this->writeaccess = false;
            $this->closed      = true;
        }

        /**
         * Returns the created in-memory archive data
         *
         * This implicitly calls close() on the Archive
         */
        public function getArchive()
        {
            $this->close();

            return $this->memory;
        }

        /**
         * Save the created in-memory archive data
         *
         * Note: It's more memory effective to specify the filename in the create() function and
         * let the library work on the new file directly.
         *
         * @param     $file
         * @throws ArchiveIOException
         */
        public function save($file)
        {
            if (!file_put_contents($file, $this->getArchive())) {
                throw new Splitbrain_ArchiveIOException('Could not write to file: '.$file);
            }
        }

        /**
         * Read the central directory
         *
         * This key-value list contains general information about the ZIP file
         *
         * @return array
         */
        protected function readCentralDir()
        {
            $size = filesize($this->file);
            if ($size < 277) {
                $maximum_size = $size;
            } else {
                $maximum_size = 277;
            }

            @fseek($this->fh, $size - $maximum_size);
            $pos   = ftell($this->fh);
            $bytes = 0x00000000;

            while ($pos < $size) {
                $byte  = @fread($this->fh, 1);
                $bytes = (($bytes << 8) & 0xFFFFFFFF) | ord($byte);
                if ($bytes == 0x504b0506) {
                    break;
                }
                $pos++;
            }

            $data = unpack(
                'vdisk/vdisk_start/vdisk_entries/ventries/Vsize/Voffset/vcomment_size',
                fread($this->fh, 18)
            );

            if ($data['comment_size'] != 0) {
                $centd['comment'] = fread($this->fh, $data['comment_size']);
            } else {
                $centd['comment'] = '';
            }
            $centd['entries']      = $data['entries'];
            $centd['disk_entries'] = $data['disk_entries'];
            $centd['offset']       = $data['offset'];
            $centd['disk_start']   = $data['disk_start'];
            $centd['size']         = $data['size'];
            $centd['disk']         = $data['disk'];
            return $centd;
        }

        /**
         * Read the next central file header
         *
         * Assumes the current file pointer is pointing at the right position
         *
         * @return array
         */
        protected function readCentralFileHeader()
        {
            $binary_data = fread($this->fh, 46);
            $header      = unpack(
                'vchkid/vid/vversion/vversion_extracted/vflag/vcompression/vmtime/vmdate/Vcrc/Vcompressed_size/Vsize/vfilename_len/vextra_len/vcomment_len/vdisk/vinternal/Vexternal/Voffset',
                $binary_data
            );

            if ($header['filename_len'] != 0) {
                $header['filename'] = fread($this->fh, $header['filename_len']);
            } else {
                $header['filename'] = '';
            }

            if ($header['extra_len'] != 0) {
                $header['extra'] = fread($this->fh, $header['extra_len']);
            } else {
                $header['extra'] = '';
            }

            if ($header['comment_len'] != 0) {
                $header['comment'] = fread($this->fh, $header['comment_len']);
            } else {
                $header['comment'] = '';
            }

            if ($header['mdate'] && $header['mtime']) {
                $hour            = ($header['mtime'] & 0xF800) >> 11;
                $minute          = ($header['mtime'] & 0x07E0) >> 5;
                $seconde         = ($header['mtime'] & 0x001F) * 2;
                $year            = (($header['mdate'] & 0xFE00) >> 9) + 1980;
                $month           = ($header['mdate'] & 0x01E0) >> 5;
                $day             = $header['mdate'] & 0x001F;
                $header['mtime'] = mktime($hour, $minute, $seconde, $month, $day, $year);
            } else {
                $header['mtime'] = time();
            }

            $header['stored_filename'] = $header['filename'];
            $header['status']          = 'ok';
            if (substr($header['filename'], -1) == '/') {
                $header['external'] = 0x41FF0010;
            }
            $header['folder'] = ($header['external'] == 0x41FF0010 || $header['external'] == 16) ? 1 : 0;

            return $header;
        }

        /**
         * Reads the local file header
         *
         * This header precedes each individual file inside the zip file. Assumes the current file pointer is pointing at
         * the right position already. Enhances this given central header with the data found at the local header.
         *
         * @param array $header the central file header read previously (see above)
         * @return array
         */
        function readFileHeader($header)
        {
            $binary_data = fread($this->fh, 30);
            $data        = unpack(
                'vchk/vid/vversion/vflag/vcompression/vmtime/vmdate/Vcrc/Vcompressed_size/Vsize/vfilename_len/vextra_len',
                $binary_data
            );

            $header['filename'] = fread($this->fh, $data['filename_len']);
            if ($data['extra_len'] != 0) {
                $header['extra'] = fread($this->fh, $data['extra_len']);
            } else {
                $header['extra'] = '';
            }

            $header['compression'] = $data['compression'];
            foreach (array(
                         'size',
                         'compressed_size',
                         'crc'
                     ) as $hd) { // On ODT files, these headers are 0. Keep the previous value.
                if ($data[$hd] != 0) {
                    $header[$hd] = $data[$hd];
                }
            }
            $header['flag']  = $data['flag'];
            $header['mdate'] = $data['mdate'];
            $header['mtime'] = $data['mtime'];

            if ($header['mdate'] && $header['mtime']) {
                $hour            = ($header['mtime'] & 0xF800) >> 11;
                $minute          = ($header['mtime'] & 0x07E0) >> 5;
                $seconde         = ($header['mtime'] & 0x001F) * 2;
                $year            = (($header['mdate'] & 0xFE00) >> 9) + 1980;
                $month           = ($header['mdate'] & 0x01E0) >> 5;
                $day             = $header['mdate'] & 0x001F;
                $header['mtime'] = mktime($hour, $minute, $seconde, $month, $day, $year);
            } else {
                $header['mtime'] = time();
            }

            $header['stored_filename'] = $header['filename'];
            $header['status']          = "ok";
            $header['folder']          = ($header['external'] == 0x41FF0010 || $header['external'] == 16) ? 1 : 0;
            return $header;
        }

        /**
         * Create fileinfo object from header data
         *
         * @param $header
         * @return FileInfo
         */
        protected function header2fileinfo($header)
        {
            $fileinfo = new Splitbrain_FileInfo();
            $fileinfo->setPath($header['filename']);
            $fileinfo->setSize($header['size']);
            $fileinfo->setCompressedSize($header['compressed_size']);
            $fileinfo->setMtime($header['mtime']);
            $fileinfo->setComment($header['comment']);
            $fileinfo->setIsdir($header['external'] == 0x41FF0010 || $header['external'] == 16);
            return $fileinfo;
        }

        /**
         * Write to the open filepointer or memory
         *
         * @param string $data
         * @throws ArchiveIOException
         * @return int number of bytes written
         */
        protected function writebytes($data)
        {
            if (!$this->file) {
                $this->memory .= $data;
                $written = strlen($data);
            } else {
                $written = @fwrite($this->fh, $data);
            }
            if ($written === false) {
                throw new Splitbrain_ArchiveIOException('Failed to write to archive stream');
            }
            return $written;
        }

        /**
         * Current data pointer position
         *
         * @fixme might need a -1
         * @return int
         */
        protected function dataOffset()
        {
            if ($this->file) {
                return ftell($this->fh);
            } else {
                return strlen($this->memory);
            }
        }

        /**
         * Create a DOS timestamp from a UNIX timestamp
         *
         * DOS timestamps start at 1980-01-01, earlier UNIX stamps will be set to this date
         *
         * @param $time
         * @return int
         */
        protected function makeDosTime($time)
        {
            $timearray = getdate($time);
            if ($timearray['year'] < 1980) {
                $timearray['year']    = 1980;
                $timearray['mon']     = 1;
                $timearray['mday']    = 1;
                $timearray['hours']   = 0;
                $timearray['minutes'] = 0;
                $timearray['seconds'] = 0;
            }
            return (($timearray['year'] - 1980) << 25) |
            ($timearray['mon'] << 21) |
            ($timearray['mday'] << 16) |
            ($timearray['hours'] << 11) |
            ($timearray['minutes'] << 5) |
            ($timearray['seconds'] >> 1);
        }

    }
}
