<?php

/*
Plugin Name: Cambrian
Plugin URI: https://github.com/evolution/wordpress-plugin-cambrian
Description: Backup and export for Wordpress Evolution
Version: 0.1.0-beta
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
         * Tag identifier used throughout the plugin
         * @var string
         */
        protected $tag = __CLASS__;

        /**
         * User friendly name used to identify the plugin
         * @var string
         */
        protected $name = 'Cambrian Explosion';

        /**
         * Current version of the plugin
         * @var string
         */
        protected $version = '0.1.0-beta';

        /**
         * Show debug output
         * @var boolean
         */
        protected $debug = false;

        /**
         * Number of rows to generate per bulk insert
         * @var int
         */
        protected $bulk_insert = 100;

        /**
         * Full path to wp-content directory, no trailing slash
         * @var string
         */
        protected $content_dir = WP_CONTENT_DIR;

        /**
         * Full path to temporary subdirectory in which to package backup
         * @var string
         */
        protected $tmp_dir;

        /**
         * Full path to wordpress document root
         * @var string
         */
        protected $base_dir;

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

            // compute temp dir
            $this->tmp_dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid($this->tag . '-');

            // compute base dir + url
            $plugin_dir_path = plugin_dir_path(__FILE__);
            $plugin_dir_url = plugin_dir_url(__FILE__);

            $base_url = is_multisite() ? network_home_url() : home_url();

            if (stripos($plugin_dir_url, $base_url) !== false) {
                $plugin_dir_url = str_replace($base_url, '', $plugin_dir_url);
            }

            if (stripos($plugin_dir_path, $plugin_dir_url) !== false) {
                $this->base_dir = str_replace($plugin_dir_url, '', $plugin_dir_path);
            }
            elseif (defined('ABSPATH')) {
                $this->base_dir = rtrim(ABSPATH, DIRECTORY_SEPARATOR);
            }
            else {
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
            wp_die('This plugin (' . $this->name . ') does not support network wide activation');
        }

        /**
         * Add menu item (under Tools)
         * @access public
         */
        public function adminMenu() {
            add_submenu_page(
                'tools.php',
                $this->name,
                $this->name,
                'import',
                $this->tag . '_opt_menu',
                array(&$this, 'showBackupPage')
            );
        }

        /**
         * Render backup page (under Tools)
         * @access public
         */
        public function showBackupPage() {
            echo '<div class="wrap">';
            echo '<h2>' . $this->name . ' v ' . $this->version . '</h2>';

            if ($archive = $this->doBackup()) {
                if ($archive !== true) {
                    $hash = sha1_file($archive);
                    echo '<form id="' . $this->tag . '_export_form" action="">';
                    echo '<input type="hidden" name="download_' . $this->tag . '_export" value="' . substr($hash, 0, 7) . '">';
                    echo '<input type="submit" id="' . $this->tag . '_export_submit" value="Download Archive" class="button button-primary">';
                    echo '</form>';
                    echo '<script>';
                    echo 'document.getElementById("' . $this->tag . '_export_form").submit();';
                    echo 'var submitButton = document.getElementById("' . $this->tag . '_export_submit");';
                    echo 'submitButton.style.visibility = "hidden";';
                    echo 'var submitReplacement = document.createElement("p");';
                    echo 'submitReplacement.appendChild(document.createTextNode("Starting download..."));';
                    echo 'submitButton.parentNode.insertBefore(submitReplacement, submitButton);';
                    echo '</script>';
                }
            }
            else {
                echo '<hr>';
                echo '<p>When you click the button below, Wordpress will create a zip archive of your content directory (in <code>' . $this->content_dir . '</code>) and a SQL export of your database.</p>';
                echo '<p>You can then import said archive into an <a target="_blank" href="https://github.com/evolution/wordpress">Evolution Wordpress</a> site.</p>';
                echo '<form action="" method="post">';
                wp_nonce_field($this->tag . '-plugin-trigger');
                echo '<input type="submit" value="Create Archive" class="button button-primary">';
                echo '</form>';
            }

            echo '</div>';
        }

        /**
         * Send backup file (before headers are sent)
         * @access public
         */
        public function downloadBackup() {
            global $pagenow;
            if ($pagenow == 'tools.php' && current_user_can('import') && isset($_GET['download_' . $this->tag . '_export'])) {

                $zipname = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $this->tag . '-backup.zip';
                $hash    = sha1_file($zipname);

                if (substr($hash, 0, 7) === substr($_GET['download_' . $this->tag . '_export'], 0, 7)) {
                    header('Content-Description: File Transfer');
                    header('Content-Type: application/octet-stream');
                    header('Content-Disposition: attachment; filename=' . $this->tag . '-export-' . substr($hash, 0, 7) . '.zip');
                    header('Expires: 0');
                    header('Cache-Control: no-cache');
                    header('Content-Length: ' . filesize($zipname));
                    readfile($zipname);
                    unlink($zipname);
                    exit();
                }
                else {
                    wp_die('No export found for key!');
                }
            }
        }

        /**
         * Initate backup/export process
         * @access protected
         */
        protected function doBackup() {
            if (empty($_POST))
                return false;

            check_admin_referer($this->tag . '-plugin-trigger');

            // request filesystem credentials, as necessary
            $url = wp_nonce_url('tools.php?page=' . $this->tag . '_opt_menu', $this->tag . '-plugin-trigger');
            if (false === ($creds = request_filesystem_credentials($url, '', false, false) ) ) {
                return true;
            }

            // error and re-prompt when bad filesystem credentials
            if (!WP_Filesystem($creds)) {
                request_filesystem_credentials($url, '', true, false);
                return true;
            }

            global $wp_filesystem;

            // start collecting manifest info
            $this->manifest = array(
                'wp_filesystem_abspath' => $wp_filesystem->abspath(),
                'const_abspath' => ABSPATH,
            );

            $base_offset = stripos($this->base_dir, $wp_filesystem->abspath());
            $this->chroot_base_dir = ($base_offset === 0) ? $this->base_dir : rtrim($wp_filesystem->abspath(), DIRECTORY_SEPARATOR);

            echo '<p>Creating working directory...';
            if (mkdir($this->tmp_dir, 0777, true))
                if ($this->debug)
                    echo 'Created <code>' . $this->tmp_dir . '</code></p>';
                else
                    echo 'Success!</p>';
            else
                return false;

            echo '<p>Generating SQL dump...<br>';
            if ($sqlfile = $this->doSql())
                if ($this->debug)
                    echo 'Generated <code>' . $sqlfile . '</code></p>';
                else
                    echo 'Success!</p>';
            else
                return false;

            echo '<p>Copying <code>' . $this->content_dir . '</code>...<br>';
            $copied_files = 0;
            $copied_dirs  = 0;
            foreach ($this->recurse($this->content_dir) as $file) {
                $destination = preg_replace(
                    '/^' . preg_quote($this->content_dir, '/') . '([\s\S]*)$/',
                    $this->tmp_dir . '$1',
                    $file
                );

                if (is_dir($file)) {
                    if(mkdir($destination, 0777, true)) {
                        $copied_files++;
                        if ($this->debug)
                            echo 'Created subdir <code>'.$file.'</code><br>';
                    } else {
                        echo 'Failed to replicate directory <code>' . $file . '</code><br>';
                    }
                }
                else {
                    $content = $wp_filesystem->get_contents($this->chrootify($file));
                    if (file_put_contents($destination, $content) !== false) {
                        $copied_dirs++;
                    } else {
                        echo 'Failed to copy file <code>' . $file . '</code><br>';
                    }
                }
            }

            echo 'Copied ' . $copied_dirs . ' directories and ' . $copied_files . ' files</p>';

            echo '<p>Generating manifest...<br>';
            if ($manifestfile = $this->doManifest())
                if ($this->debug)
                    echo 'Generated <code>' . $manifestfile . '</code></p>';
                else
                    echo 'Success!</p>';
            else
                return false;

            // generate archive of temp dir
            echo '<p>Creating zip archive...<br>';
            if ($archivefile = $this->doArchive())
                if ($this->debug)
                    echo 'Created <code>' . $archivefile . '</code></p>';
                else
                    echo 'Success!</p>';
            else
                return false;

            // remove temp dir and its contents
            echo '<p>Cleaning up working directory...</p>';
            $this->doCleanup($this->tmp_dir);

            return $archivefile;
        }

        /**
         * Export SQL dump
         * @access protected
         */
        protected function doSql() {
            global $wpdb;

            $dumpname = $this->tmp_dir . DIRECTORY_SEPARATOR . 'wordpress.sql';
            $dumpfile = fopen($dumpname, 'w');

            foreach (array('prefix', 'base_prefix', 'blogid', 'siteid') as $prop) {
                $this->manifest[$prop] = $wpdb->$prop;
            }

            $wpdb_methods = array(
                array('db_version',      array()),
                array('get_blog_prefix', array()),
                array('tables',          array('all')),
                array('tables',          array('blog')),
                array('tables',          array('global')),
                array('tables',          array('ms_global')),
            );

            foreach ($wpdb_methods as $method) {
                $key = $method[0] . '[' . implode(',', $method[1]) . ']';
                $this->manifest[$key] = call_user_func_array(array($wpdb, $method[0]), $method[1]);
            }

            foreach ($wpdb->tables('all', true) as $nonprefixed_tablename => $tablename) {
                $results = $wpdb->get_row("SHOW CREATE TABLE `{$tablename}`", ARRAY_A);

                if (isset($results['Create Table'])) {
                    fwrite($dumpfile, "-- Table {$tablename}\n");
                    fwrite($dumpfile, "-- CAMBRIAN[[/{$nonprefixed_tablename}/{$tablename}/]]\n");
                    fwrite($dumpfile, "DROP TABLE IF EXISTS `{$tablename}`;\n");
                    fwrite($dumpfile, $results['Create Table'] . ";\n\n");

                    $names     = null;
                    $row_queue = array( array() );

                    foreach ($wpdb->get_results("SELECT * FROM `{$tablename}`", ARRAY_A) as $row) {
                        if (!isset($names)) {
                            $names = '`' . implode('`, `', array_keys($row)) . '`';
                        }

                        $queue_index = count($row_queue) - 1;

                        if (count($row_queue[$queue_index]) >= $this->bulk_insert) {
                            $row_queue[] = array();
                            $queue_index = count($row_queue) - 1;
                        }

                        $row_queue[$queue_index][] = '(\'' . implode('\', \'', $wpdb->_escape(array_values($row))) . '\')';
                    }

                    if (!empty($row_queue[count($row_queue) - 1])) {
                        foreach ($row_queue as $row_set) {
                            $values = implode(",\n  ", $row_set);
                            fwrite($dumpfile, "INSERT INTO `{$tablename}`\n  ({$names})\nVALUES\n  {$values};\n\n");
                        }
                    }
                }
                else {
                    echo 'Warning: Could not get CREATE statement for ' . $tablename . '<br>';
                }
            }

            fclose($dumpfile);

            return $dumpname;
        }

        /**
         * Export manifest of various wordpress runtime values
         * @access protected
         */
        protected function doManifest() {
            $filename = $this->tmp_dir . DIRECTORY_SEPARATOR . $this->tag . '-manifest.json';

            if(false === file_put_contents($filename, json_encode($this->manifest)))
                echo 'Failed to generate manifest <code>' . $filename . '</code></p>';
            else
                return $filename;
        }

        /**
         * Package working directory into zip archive
         * @access protected
         */
        protected function doArchive() {
            $zipname = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $this->tag . '-backup.zip';

            if (class_exists('ZipArchive')) {
                $zip = new ZipArchive();
                $res = $zip->open($zipname, ZipArchive::CREATE | ZipArchive::OVERWRITE);

                if ($res !== true) {
                    foreach ($this->zip_errors as $const => $message) {
                        if (constant('ZipArchive::'.$const) === $res) {
                            echo '<code>ZipArchive::open()</code> failure: ' . $this->zip_errors[$const] . '<br>';
                            return;
                        }
                    }
                }
            }
            else {
                include_once(dirname(__FILE__) . DIRECTORY_SEPARATOR . '_inc' . DIRECTORY_SEPARATOR . 'class.Splitbrain_Zip.php');
                $zip = new Splitbrain_Zip();
                $zip->create($zipname);
            }

            $basepath = $this->tmp_dir . DIRECTORY_SEPARATOR;

            foreach($this->recurse($basepath) as $file){
                $localname = preg_replace('/^' . preg_quote($basepath, '/') . '/', '', $file);

                // don't need to back ourselves up!
                if (stripos(ltrim($localname, '/'), 'plugins/cambrian') === 0) {
                    continue;
                }

                if ($this->debug)
                    echo 'Zipping <code>' . $file . '</code> as <code>' . $localname . '</code><br>';

                if (is_dir($file)) {
                    if (class_exists('ZipArchive')) {
                        if (!$zip->addEmptyDir($localname)) {
                            echo '<code>ZipArchive::addEmptyDir()</code> failure for ' . $file . '<br>';
                        }
                    }
                }
                else {
                    if (class_exists('ZipArchive')) {
                        if (!$zip->addFile($file, $localname)) {
                            echo '<code>ZipArchive::addFile()</code> failure for ' . $file . '<br>';
                        }
                    }
                    else {
                        try {
                            $zip->addFile($file, $localname);
                        } catch (Exception $e) {
                            echo '<code>Splitbrain_Zip::addFile()</code> failure: <code>' . $e . '</code><br>';
                        }
                    }
                }
            }

            $zip->close();

            return $zipname;
        }

        /**
         * Clean and remove working directory
         * @access protected
         */
        protected function doCleanup($base) {
            $dirs = array($base);

            foreach ($this->recurse($base) as $file) {
                if (is_dir($file)) {
                    $dirs[] = $file;
                }
                else {
                    unlink($file);
                }
            }

            foreach (array_reverse($dirs) as $path) {
                rmdir($path);
            }
        }

        /**
         * Transform path to use chrooted base path
         * @access private
         */
        private function chrootify($path) {
            if ($this->base_dir === $this->chroot_base_dir) {
                return $path;
            }
            else {
                return str_replace($this->base_dir, $this->chroot_base_dir, $path);
            }
        }

        /**
         * Recurse path, returning all files and directories
         * @access private
         */
        private function recurse($path) {
            if (class_exists('FilesystemIterator')) {
                $files = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::SELF_FIRST
                );

                return array_map(array(&$this, 'reduce'), $files);
            }
            else {
                return $this->_recurse($path);
            }
        }

        /**
         * Reduce SplFileInfo objects to string path
         * @access private
         */
        private function reduce($n) {
            return $n->getPathName();
        }

        /**
         * Recurse folders for < 5.3
         * @access private
         */
        private function _recurse($folder = '', $levels = 100) {
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
                    }
                    else {
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
