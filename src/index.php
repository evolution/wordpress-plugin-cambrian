<?php

/*
Plugin Name: Cambrian
Plugin URI: https://github.com/evolution/wordpress-plugin-cambrian
Description: Backup and export for Wordpress Evolution
Version: 0.3.0
Author: Evan Kaufman
Author URI: https://github.com/EvanK
License: MIT
*/

if (!defined('ABSPATH'))
    die('Plugin file cannot be accessed directly.');

if (defined('WP_INSTALLING') && WP_INSTALLING)
    return;

if (!class_exists('cambrian')) {
    class cambrian {
        /**
         * How many files/directories to copy per batch
         */
        const FILES_PER_BATCH = 10;

        /**
         * How many database rows to dump per batch
         */
        const ROWS_PER_BATCH = 100;

        /**
         * How many files/directories to archive per batch
         */
        const ZIP_PER_BATCH = 10;

        /**
         * Logfile name
         */
        const LOGFILE_STUB = '-progress.log';

        /**
         * Statefile name
         */
        const STATEFILE_STUB = '-state.bin';

        /**
         * In-progress archive name
         */
        const ZIPTEMP_STUB = '.temp.zip';

        /**
         * Completed archive name
         */
        const ZIPCOMP_STUB = '.complete.zip';

        /**
         * User friendly name used to identify the plugin
         */
        const NAME = 'Cambrian Explosion';

        /**
         * Current version of the plugin
         */
        const VERSION = '0.3.0';

        /**
         * Whether to produce debugging output
         */
        const DEBUG = false;

        /**
         * Full path to wp-content directory, no trailing slash
         * @var string
         */
        protected $content_dir = WP_CONTENT_DIR;

        /**
         * Full path to wordpress document root
         * @var string
         */
        protected $base_dir;

        /**
         * Runtime values to save in the archive manifest
         * @var array
         */
        protected $manifest = array();

        /**
         * State saved to file between batches
         * @var array
         */
        protected $state;

        /**
         * ZipArchive error strings
         * @var array
         */
        protected $zip_errors = array(
            'ER_EXISTS' => 'File already exists',
            'ER_INCONS' => 'Zip archive inconsistent',
            'ER_INVAL'  => 'Invalid argument',
            'ER_MEMORY' => 'Malloc failure',
            'ER_NOENT'  => 'No such file',
            'ER_NOZIP'  => 'Not a zip archive',
            'ER_OPEN'   => 'Can\'t open file',
            'ER_READ'   => 'Read error',
            'ER_SEEK'   => 'Seek error',
        );

        /**
         * Construct a new instance of Cambrian
         * @access public
         */
        public function __construct() {
            // hook plugin activation
            register_activation_hook(__FILE__, array(&$this, 'activatePlugin'));

            // hook admin functions
            if (is_admin()) {
                add_action('admin_menu', array(&$this, 'adminMenu'));
                add_action('plugins_loaded', array(&$this, 'downloadBackup'));
            }

            // compute base dir + url
            $plugin_dir_path = plugin_dir_path(__FILE__);
            $plugin_dir_url = plugin_dir_url(__FILE__);

            $base_url = is_multisite() ? network_home_url() : home_url();

            if (stripos($plugin_dir_url, $base_url) !== false) {
                $plugin_dir_url = str_replace($base_url, '', $plugin_dir_url);
            }

            if (stripos($plugin_dir_path, $plugin_dir_url) !== false) {
                $this->base_dir = str_replace($plugin_dir_url, '', $plugin_dir_path);
            } elseif (defined('ABSPATH')) {
                $this->base_dir = rtrim(ABSPATH, DIRECTORY_SEPARATOR);
            } else {
                wp_die('Could not determine base path from ' . $plugin_dir_path);
            }
        }

        /**
         * Prevent network activation (must be activated for a single site at a time)
         * @access public
         */
        public function activatePlugin($network_wide) {
            if (!$network_wide)
                return;

            deactivate_plugins(plugin_basename(__FILE__), true, true);
            wp_die('This plugin (' . self::NAME . ') does not support network wide activation');
        }

        /**
         * Add menu item (under Tools)
         * @access public
         */
        public function adminMenu() {
            add_submenu_page(
                'tools.php',
                self::NAME,
                self::NAME,
                'import',
                __CLASS__ . '_opt_menu',
                array(&$this, 'showBackupPage')
            );
        }

        /**
         * Render backup page (under Tools)
         * @access public
         */
        public function showBackupPage() {
            echo '<div class="wrap">';
            echo '<h2>' . self::NAME . ' v ' . self::VERSION . '</h2>';

            if ($this->getNonce()) {
                if (isset($_GET['kickoff'])) {
                    $this->renderRefresh(
                        $this->initArchiving()
                    );
                } elseif (isset($_GET['holding'])) {
                    $this->renderRefresh(
                        false,
                        $this->resumeArchiving()
                    );
                }
            } else {
                echo '<hr>';
                echo '<p>When you click the button below, Wordpress will create a zip archive of your content directory (in <code>' . $this->content_dir . '</code>) and a SQL export of your database.</p>';
                echo '<p>You can then import said archive into an <a target="_blank" href="https://github.com/evolution/wordpress">Evolution Wordpress</a> site.</p>';
                echo '<form action="" method="get">';
                wp_nonce_field(__CLASS__, __CLASS__.'_nonce', true);
                echo '<input type="hidden" name="page" value="' . __CLASS__ . '_opt_menu">';
                echo '<input type="submit" name="kickoff" value="Create Archive" class="button button-primary">';
                echo '</form>';
            }

            echo '</div>';
        }

        /**
         * Send backup file (before headers are sent)
         * @access public
         */
        public function downloadBackup() {
            $nonce = $this->getNonce();

            if ($nonce && isset($_GET['download'])) {
                $zipfile = $this->tempPath() . self::ZIPCOMP_STUB;
                if (file_exists($zipfile)) {
                    $this->loadManifest();

                    $filename = array(
                        __CLASS__,
                        $nonce,
                        preg_replace('/(?:https?)?[^a-z0-9]+/i', '-', $this->manifest['home_url']),
                    );

                    header('Content-Description: File Transfer');
                    header('Content-Type: application/octet-stream');
                    header('Content-Disposition: attachment; filename=' . implode('-', $filename) . '.zip');
                    header('Expires: 0');
                    header('Cache-Control: no-cache');
                    header('Content-Length: ' . filesize($zipfile));
                    readfile($zipfile);
                    $this->doCleanup();
                    exit();
                } else {
                    wp_die('No matching cambrian archive found');
                }
            }
        }

        /**
         * Retrieve and verify nonce from query string
         * @access protected
         */
        protected function getNonce() {
            global $pagenow;
            if ($pagenow == 'tools.php' && isset($_GET['page']) && $_GET['page'] == __CLASS__ . '_opt_menu') {
                if (isset($_GET[__CLASS__ . '_nonce'])) {
                    $nonce = $_GET[__CLASS__ . '_nonce'];

                    if (wp_verify_nonce($nonce, __CLASS__)) {
                        return $nonce;
                    }
                }
            }
        }

        /**
         * Calculate working temp directory given our nonce
         * @access protected
         */
        protected function tempPath() {
            if ($nonce = $this->getNonce()) {
                return sys_get_temp_dir() . DIRECTORY_SEPARATOR . $nonce;
            }
        }

        /**
         * Render form refresh (within backup page)
         * @access protected
         */
        protected function renderRefresh($kickoff = false, $download = false) {
            $this->printLog();

            echo '<form id="' . __CLASS__ . '_holding" action="" method="get">';
            wp_nonce_field(__CLASS__, __CLASS__.'_nonce', false);
            echo '<input type="hidden" name="page" value="' . __CLASS__ . '_opt_menu">';

            $action = 'holding';
            if ($kickoff) {
                $action = 'kickoff';
            }
            if ($download) {
                $action = 'download';
            }

            echo '<input type="hidden" name="'.$action.'" value="1">';
            echo '</form>';
            echo '<script>';
            echo 'window.setTimeout(function () { document.getElementById("' . __CLASS__ . '_holding").submit(); }, 5000);';
            echo '</script>';
        }

        /**
         * Initate backup/export process
         * @access protected
         */
        protected function initArchiving() {
            global $wp_filesystem;
            global $wpdb;

            check_admin_referer(__CLASS__, __CLASS__.'_nonce');

            // request filesystem credentials, as necessary
            $url = wp_nonce_url('tools.php?page='.__CLASS__.'_opt_menu&kickoff=1', __CLASS__, __CLASS__.'_nonce');
            if (false === ($creds = request_filesystem_credentials($url, '', false, false))) {
                $this->writeLog(true, '<p><b>Failed first WP_Filesystem condition</b></p>');
                return true;
            }

            // error and re-prompt when bad filesystem credentials
            if (!WP_Filesystem($creds)) {
                request_filesystem_credentials($url, '', true, false);
                $this->writeLog(true, '<p><b>Failed second WP_Filesystem condition</b></p>');
                return true;
            }

            $base_offset = stripos($this->base_dir, $wp_filesystem->abspath());
            $chroot_base_dir = ($base_offset === 0) ? $this->base_dir : rtrim($wp_filesystem->abspath(), DIRECTORY_SEPARATOR);

            // todo: create temp directory after our nonce, if it doesnt yet exist
            $tmp_dir = $this->tempPath();

            // todo: remove directory + log if they exist
            $this->doCleanup();
            // sleep(5);

            $this->writeLog('<p>Creating working directory...');
            ob_start();
            if (mkdir($tmp_dir, 0777, true)) {
                $this->writeLog(true, 'Created <code>', $tmp_dir, '</code></p>') || $this->writeLog('Success!</p>');
            } else {
                $this->writeLog(ob_get_flush(), '</p>');
                return true;
            }
            ob_end_flush();

            $all_tables   = $wpdb->get_results('SHOW TABLES', ARRAY_N);
            $blog_tables  = $wpdb->tables('blog');
            $ms_tables    = $wpdb->tables('ms_global');

            // todo: ensure manifest is written to filesystem early & often, under $tmp/$nonce/manifest.json
            $this->appendManifest(array(
                'wpfs_abspath'      => $wp_filesystem->abspath(),
                'const_abspath'     => ABSPATH,
                'cambrian_basepath' => $this->base_dir,
                'chroot_base_dir'   => $chroot_base_dir,
                'tmp_dir'           => $tmp_dir,
                'is_multisite'      => is_multisite(),
                'network_home_url'  => network_home_url(),
                'home_url'          => home_url(),
                'network_site_url'  => network_site_url(),
                'site_url'          => site_url(),
                'get_plugins'       => get_plugins(),
                'prefix'            => $wpdb->prefix,
                'base_prefix'       => $wpdb->base_prefix,
                'blogid'            => $wpdb->blogid,
                'siteid'            => $wpdb->siteid,
                'db_version'        => $wpdb->db_version(),
                'get_blog_prefix'   => $wpdb->get_blog_prefix(),
                'show_tables'       => $all_tables,
                'tables[blog]'      => $blog_tables,
                'tables[ms_global]' => $ms_tables,
            ));

            // create and save a starting state file
            $this->newState(
                $this->recurse($this->content_dir),
                array_fill_keys(array_map('array_shift', $all_tables), 0)
            );

            return false;
        }

        /**
         * Resume backup/export process, after refresh
         * @access protected
         */
        protected function resumeArchiving() {
            // load manifest + statefile
            $this->loadManifest();
            $this->loadState();

            // start the clock
            set_time_limit(30);
            $start = microtime(true);

            switch ($this->state['step']) {
                case 'files':
                    $start_count = count($this->state['files']);

                    while (count($this->state['files']) && (microtime(true) - $start) < 20) {
                        $this->doFiles(array_splice($this->state['files'], 0, self::FILES_PER_BATCH));
                    }

                    // increment step as necessary, save state, and return
                    if (!count($this->state['files'])) {
                        $this->state['step'] = 'tables';
                    }
                    $this->saveState();

                    $end_count = $start_count - count($this->state['files']);
                    $this->writeLog('<p>Processed', $end_count, 'directories/files</p>');

                    return;
                case 'tables':
                    $start_count = count($this->state['tables']);

                    while (count($this->state['tables']) && (microtime(true) - $start) < 20) {
                        // get first key ($table) from array
                        reset($this->state['tables']);
                        $table = key($this->state['tables']);

                        $new_offset = $this->doSql($table, $this->state['tables'][$table]);
                        if ($new_offset === false) {
                            unset($this->state['tables'][$table]);
                        } else {
                            $this->state['tables'][$table] = $new_offset;
                        }
                    }

                    // increment step as necessary, save state, and return
                    if (!count($this->state['tables'])) {
                        $this->state['step'] = 'archive';
                    }
                    $this->saveState();

                    $end_count = $start_count - count($this->state['tables']);
                    $this->writeLog('<p>Processed', $end_count, 'database tables</p>');

                    return;
                case 'archive':
                    if ($this->state['archive'] === false) {
                        $this->state['archive'] = $this->recurse($this->tempPath());
                    }

                    $start_count = count($this->state['archive']);

                    while (count($this->state['archive']) && (microtime(true) - $start) < 20) {
                        $zipname = $this->doArchive(array_splice($this->state['archive'], 0, self::ZIP_PER_BATCH));
                    }

                    // increment step as necessary, save state, and return
                    if (!count($this->state['archive'])) {
                        $this->state['step'] = 'complete';

                        $zipcomplete = str_replace(self::ZIPTEMP_STUB, self::ZIPCOMP_STUB, $zipname);
                        ob_start();
                        if (false === rename($zipname, $zipcomplete)) {
                            // todo: log an issue renaming the completed zip file
                            $this->writeLog('<p>Failed to rename archive:', ob_get_flush(), '</p>');
                        }
                        ob_end_flush();
                    }
                    $this->saveState();

                    $end_count = $start_count - count($this->state['archive']);
                    $this->writeLog('<p>Archived', $end_count, 'directories/files</p>');

                    return;
                case 'complete':
                    return true;
            }
        }

        /**
         * Batched copy of files/directories
         * @access protected
         */
        protected function doFiles($files) {
            global $wp_filesystem;

            // request filesystem credentials, as necessary
            $url = wp_nonce_url('tools.php?page='.__CLASS__.'_opt_menu&kickoff=1', __CLASS__, __CLASS__.'_nonce');
            if (false === ($creds = request_filesystem_credentials($url, '', false, false))) {
                $this->writeLog(true, '<p><b>Failed third WP_Filesystem condition</b></p>');
                return true;
            }

            // error and re-prompt when bad filesystem credentials
            if (!WP_Filesystem($creds)) {
                request_filesystem_credentials($url, '', true, false);
                $this->writeLog(true, '<p><b>Failed fourth WP_Filesystem condition</b></p>');
                return true;
            }

            $copied_files = 0;
            $copied_dirs  = 0;

            $tmp_dir = $this->tempPath();

            foreach ($files as $file) {
                $destination = preg_replace(
                    '/^' . preg_quote($this->content_dir, '/') . '([\s\S]*)$/',
                    $tmp_dir . '$1',
                    $file
                );

                if (is_dir($file)) {
                    ob_start();
                    if(mkdir($destination, 0777, true)) {
                        $copied_files++;
                        $this->writeLog(true, 'Created subdir <code>', $file, '</code><br>');
                    } else {
                        $this->writeLog('Failed to replicate directory', $file, ': <code>', ob_get_flush(), '</code><br>');
                    }
                    ob_end_clean();
                } else {
                    $content = $wp_filesystem->get_contents($this->chrootify($file));
                    if (file_put_contents($destination, $content) !== false) {
                        $copied_dirs++;
                    } else {
                        $this->writeLog('Failed to copy file <code>', $file, '</code><br>');
                    }
                }
            }

            $this->writeLog(true, '<p>Copied', $copied_dirs, 'directories and', $copied_files, 'files</p>');
        }

        /**
         * Batched dump of SQL tables
         * @access protected
         */
        protected function doSql($tablename, $offset) {
            global $wpdb;

            $dumpname = $this->tempPath() . DIRECTORY_SEPARATOR . 'wordpress.sql';
            $rawname  = preg_replace('/^'.preg_quote($wpdb->base_prefix).'(?:\d+_)?/', '', $tablename);

            // skip multisite tables
            if (isset($this->manifest['tables[ms_global]'][$rawname])) {
                $this->writeLog(true, 'Skipping multisite table <code>', $tablename, '</code><br>');

                return false;
            }

            // skip blog tables that do not belong to the currently exporting site
            if (isset($this->manifest['tables[blog]'][$rawname]) && strpos($tablename, $wpdb->prefix) === FALSE) {
                $this->writeLog(true, 'Skipping extrasite table <code>', $tablename, '</code><br>');

                return false;
            }

            // if starting off this table, generate crreate statements
            if ($offset === 0) {
                $results = $wpdb->get_row("SHOW CREATE TABLE `{$tablename}`", ARRAY_A);
                if (isset($results['Create Table'])) {
                    $create = array(
                        "-- Table {$tablename}",
                        "DROP TABLE IF EXISTS `{$tablename}`;",
                        "{$results['Create Table']};\n\n",
                    );
                    file_put_contents($dumpname, implode("\n", $create), FILE_APPEND|LOCK_EX);
                } else {
                    $this->writeLog('Warning: Could not get CREATE statement for', $tablename, '<br>');
                }
            }

            $names     = null;
            $row_queue = array();

            foreach ($wpdb->get_results("SELECT * FROM `{$tablename}` LIMIT {$offset}, ".self::ROWS_PER_BATCH, ARRAY_A) as $row) {
                if (!isset($names)) {
                    $names = '`' . implode('`, `', array_keys($row)) . '`';
                }

                $row_queue[] = '(\'' . implode('\', \'', $wpdb->_escape(array_values($row))) . '\')';
            }

            if (!empty($row_queue)) {
                $insert = "INSERT INTO `{$tablename}`\n  ({$names})\nVALUES\n  " . implode(",\n  ", $row_queue) . ";\n\n";
                file_put_contents($dumpname, $insert, FILE_APPEND|LOCK_EX);

                $this->writeLog(true, '<p>Dumped', count($row_queue), 'rows from', $tablename, '</p>');

                return $offset + self::ROWS_PER_BATCH;
            }

            return false;
        }

        /**
         * Batched archiving of files/directories
         * @access protected
         */
        protected function doArchive($items) {
            $tmp_dir = $this->tempPath();
            $zipname = $tmp_dir . self::ZIPTEMP_STUB;

            if (class_exists('ZipArchive')) {
                $zip = new ZipArchive();
                if (file_exists($zipname)) {
                    $res = $zip->open($zipname);
                } else {
                    $res = $zip->open($zipname, ZipArchive::CREATE | ZipArchive::OVERWRITE);
                }

                if ($res !== true) {
                    foreach ($this->zip_errors as $const => $message) {
                        if (constant('ZipArchive::'.$const) === $res) {
                            $this->writeLog('<code>ZipArchive::open()</code> failure:', $this->zip_errors[$const], '<br>');
                            return;
                        }
                    }
                }
            } else {
                include_once(dirname(__FILE__) . DIRECTORY_SEPARATOR . '_inc' . DIRECTORY_SEPARATOR . 'class.Splitbrain_Zip.php');
                $zip = new Splitbrain_Zip();

                if (file_exists($zipname)) {
                    $zip->open($zipname);
                } else {
                    $zip->create($zipname);
                }
            }

            $plugin_pattern = $this->findInactivePlugins();
            $basepath = $tmp_dir . DIRECTORY_SEPARATOR;

            $archived_files = 0;
            $archived_dirs  = 0;

            foreach($items as $file){
                $localname = preg_replace('/^' . preg_quote($basepath, '/') . '/', '', $file);

                if (preg_match($plugin_pattern, $localname)) {
                    continue;
                }

                $this->writeLog(true, 'Zipping <code>', $file, '</code> as <code>', $localname, '</code><br>');

                if (is_dir($file)) {
                    if (class_exists('ZipArchive')) {
                        if (!$zip->addEmptyDir($localname)) {
                            $this->writeLog('<code>ZipArchive::addEmptyDir()</code> failure for', $file, '<br>');
                        } else {
                            $archived_dirs++;
                        }
                    }
                } else {
                    if (class_exists('ZipArchive')) {
                        if (!$zip->addFile($file, $localname)) {
                            $this->writeLog('<code>ZipArchive::addFile()</code> failure for', $file, '<br>');
                        } else {
                            $archived_files++;
                        }
                    } else {
                        try {
                            $zip->addFile($file, $localname);
                            $archived_files++;
                        } catch (Exception $e) {
                            $this->writeLog('<code>Splitbrain_Zip::addFile()</code> failure: <code>', $e, '</code><br>');
                        }
                    }
                }
            }

            $zip->close();

            $this->writeLog(true, '<p>Archived', $archived_dirs, 'directories and', $archived_files, 'files</p>');

            return $zipname;
        }

        /**
         * Clean and remove working files/directories
         * @access protected
         */
        protected function doCleanup() {
            $base = $this->tempPath();
            $dirs = array($base);

            if (is_dir($base)) {
                foreach ($this->recurse($base) as $file) {
                    if (is_dir($file)) {
                        $dirs[] = $file;
                    } else {
                        unlink($file);
                    }
                }

                foreach (array_reverse($dirs) as $path) {
                    rmdir($path);
                }
            }

            foreach (array(self::LOGFILE_STUB, self::STATEFILE_STUB, self::ZIPTEMP_STUB, self::ZIPCOMP_STUB) as $extra_file) {
                if (is_file($base.$extra_file)) {
                    unlink($base.$extra_file);
                }
            }
        }

        /**
         * Print batch log
         * @access protected
         */
        protected function printLog() {
            $filename = $this->tempPath() . self::LOGFILE_STUB;

            echo '<p>Archiving in progress! <b>Do not navigate away from this page</b></p><blockquote>';
            echo file_get_contents($filename);
            echo '</blockquote>';
        }

        /**
         * Append to batch log
         * @access protected
         */
        protected function writeLog() {
            $messages = func_get_args();

            if ($messages[0] === true) {
                array_shift($messages);

                if (!self::DEBUG) {
                    return false;
                }
            }

            // append output to file (with exclusive lock)
            $filename = $this->tempPath() . self::LOGFILE_STUB;
            if (false === file_put_contents($filename , implode(' ', $messages), FILE_APPEND|LOCK_EX)) {
                echo '<p>Failed appending to log '.$filename.'</p>';
            }

            return true;
        }

        /**
         * Load manifest from file
         * @access protected
         */
        protected function loadManifest() {
            $filename = $this->tempPath() . DIRECTORY_SEPARATOR . __CLASS__ . '-manifest.json';

            // read in existing manifest, if exists
            if (is_readable($filename)) {
                $this->manifest = json_decode(file_get_contents($filename), true);
            }

            return $this->manifest;
        }

        /**
         * Append array of new values to manifest file
         * @access protected
         */
        protected function appendManifest($values) {
            // todo: should we open an exclusive lock here?
            $filename = $this->tempPath() . DIRECTORY_SEPARATOR . __CLASS__ . '-manifest.json';

            // merge with new values
            $this->manifest = array_merge($this->manifest, $values);

            // write updated manifest
            $this->writeLog('<p>Writing manifest...<br>');
            ob_start();
            if(false === file_put_contents($filename, json_encode($this->manifest))) {
                $this->writeLog('Failed to write manifest <code>' . $filename . '</code>: ' . ob_get_flush() . '</p>');
            } else {
                $this->writeLog(true, 'Generated <code>', $filename, '</code></p>') || $this->writeLog('Success!</p>');
            }
            ob_end_flush();
        }

        /**
         * Create new state
         * @access protected
         */
        protected function newState($files = array(), $tables = array()) {
            $this->state = array(
                'step' => 'files',
                'files' => $files,
                'tables' => $tables,
                'archive' => false,
            );
            $this->saveState();

            return $this->state;
        }

        /**
         * Load existing state from file
         * @access protected
         */
        protected function loadState() {
            $filename = $this->tempPath() . self::STATEFILE_STUB;
            $this->state = unserialize(file_get_contents($filename));

            return $this->state;
        }

        /**
         * Save existing state to file
         * @access protected
         */
        protected function saveState() {
            $filename = $this->tempPath() . self::STATEFILE_STUB;
            return file_put_contents($filename, serialize($this->state));
        }

        /**
         * Construct pattern to match inactive plugins
         * @access protected
         */
        protected function findInactivePlugins() {
            // skip exporting ourself, for obvious reasons
            $inactive_plugins = array('cambrian');

            foreach (get_plugins() as $plugin_name => $plugin_info) {
                list($base) = explode(DIRECTORY_SEPARATOR, $plugin_name);

                if (!is_plugin_active($plugin_name) && !is_plugin_active_for_network($plugin_name)) {
                    $inactive_plugins[] = preg_quote($base, '/');

                    if (self::DEBUG)
                        echo 'Skipping inactive plugin <code>' . $base . '</code><br>';
                }
            }

            $sep = preg_quote(DIRECTORY_SEPARATOR, '/');

            return "/^{$sep}?plugins{$sep}(?:" . implode('|', $inactive_plugins) . ')/';
        }

        /**
         * Transform path to use chrooted base path
         * @access protected
         */
        protected function chrootify($path) {
            if ($this->base_dir === $this->manifest['chroot_base_dir']) {
                return $path;
            } else {
                return str_replace($this->base_dir, $this->manifest['chroot_base_dir'], $path);
            }
        }

        /**
         * Recurse path, returning all files and directories
         * @access protected
         */
        protected function recurse($path) {
            if (class_exists('FilesystemIterator')) {
                $files = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::SELF_FIRST
                );

                return array_keys(iterator_to_array($files));
            } else {
                return $this->_recurse($path);
            }
        }

        /**
         * Recurse folders for < 5.3
         * @access protected
         */
        protected function _recurse($folder = '', $levels = 100) {
            if (empty($folder))
                return false;

            if (!$levels)
                return false;

            $files = array();
            if ($dir = opendir($folder)) {
                while (($file = readdir($dir)) !== false) {
                    if (in_array($file, array('.', '..')))
                        continue;
                    if (is_dir($folder . DIRECTORY_SEPARATOR . $file)) {
                        $files[] = $folder . DIRECTORY_SEPARATOR . $file;
                        $files2 = $this->_recurse($folder . DIRECTORY_SEPARATOR . $file, $levels - 1);
                        if ($files2)
                            $files = array_merge($files, $files2);
                    } else {
                        $files[] = $folder . DIRECTORY_SEPARATOR . $file;
                    }
                }
            }
            closedir($dir);

            return $files;
        }
    }

    new cambrian();
}
