<?php

/*
Plugin Name: WP Uninstaller
Plugin URI:  http://www.simonemery.co.uk/wordpress/uninstaller/
Description: Uninstall WordPress with just a couple of clicks!
Version: 1.00
Author: Simon Emery
Author URI: http://www.simonemery.co.uk
*/


//no direct access
if(reset(get_included_files()) === __FILE__) die();


//core class
class wp_uninstaller {

	var $db;
	var $error;

	var $blog_url;
	var $blog_path;

	//constructor
	function wp_uninstaller() {
		global $wpdb;
		//is admin?
		if(!is_admin()) {
			return;
		}
		//set vars
		$this->db =& $wpdb;
		$this->blog_path = ABSPATH;
		$this->blog_url = get_option('siteurl');
		//add hooks
		add_action('init', array(&$this, 'run'));
		add_action('admin_menu', array(&$this, 'admin_menu'));
		register_activation_hook(__FILE__, array(&$this, 'admin_activate'));
	}

	//run uninstall
	function run() {
		//valid request?
		if(!isset($_POST['process']) || $_POST['process'] != "wp-uninstaller") {
			return;
		}
		//has permission?
		if(!current_user_can('manage_options')) {
			die("Access Denied!");
		}
		//nonce checks?
		if(!wp_verify_nonce($_GET['nonce'], "wp-uninstaller-nonce-url") || !wp_verify_nonce($_POST['nonce'], "wp-uninstaller-nonce-form")) {
			$this->error = "Uninstall aborted, invalid request made";
			return;
		}
		//post vars
		$tables = isset($_POST['delete-tables']) ? (int) $_POST['delete-tables'] : 0;
		$files = isset($_POST['delete-files']) ? (int) $_POST['delete-files'] : 0;
		//tables option selected?
		if($tables < 1 || $tables > 3) {
			$this->error = "Please select whether to delete your WordPress database tables, in order to continue";
			return;
		}
		//files option selected?
		if($files < 1 || $files > 3) {
			$this->error = "Please select whether to delete your WordPress files, in order to continue";
			return;
		}
		//delete files test?
		if($files > 1 && !$this->delete_files_test()) {
			$this->error = "Unable to delete files automatically. Please select 'do not delete files' and then delete the files manually after uninstalling";
			return;
		}
		//anything to delete?
		if($tables <= 1 && $files <= 1) {
			$this->error = "Uninstall aborted, you don't seem to want to delete anything!";
			return;
		}
		//delete tables?
		if($tables == 2 && !$this->delete_tables()) {
			$this->error = "Uninstall aborted, unable to delete database tables";
			return;
		}
		//delete files?
		if($files == 2) {
			$this->delete_config();
		} elseif($files == 3) {
			$this->delete_files();
		}
		//redirect user
		header("Location: " . $this->blog_url);
		exit();
	}

	//delete tables
	function delete_tables() {
		//query table names
		if(!$tables = $this->db->get_results("SHOW TABLES FROM " . DB_NAME)) {
			return false;
		}
		//loop through tables
		foreach($tables as $table) {
			$table = array_values((array) $table);
			if(empty($this->db->prefix) || strpos($table[0], $this->db->prefix) === 0) {
				$this->db->query("DROP TABLE " . $table[0]);
			}
		}
		//success
		return true;
	}

	//delete config
	function delete_config() {
		if(is_file($this->blog_path . "wp-config.php")) {
			return @unlink($this->blog_path . "wp-config.php");
		} elseif(is_file(dirname($this->blog_path) . "/wp-config.php")) {
			return @unlink(dirname($this->blog_path) . "/wp-config.php");
		}
	}

	//delete files
	function delete_files($source=null) {
		//get source?
		if($source === null) {
			$source = $this->blog_path;
		}
		//format source
		$source = rtrim($source, "/");
		//is this a dir?
		if(is_dir($source)) {
			//loop through all files
			if($files = @glob($source . '/*')) {
				//delete all contents
				foreach($files as $file) {
					$this->delete_files($file);
				}
			}
			//delete dir
			return @rmdir($source); 
		}
		//delete file
		return @unlink($source);
	}

	//delete files test
	function delete_files_test($dir=null) {
		//set vars
		$result = false;
		//format directory
		$dir = $dir ? $dir : dirname(__FILE__);
		$dir = rtrim($dir, "/");
		//begin test
		if(function_exists('getmyuid') && function_exists('fileowner')) {
			//temp file name
			$temp_file = $dir . "/file-test-" . time();
			//attempt' to create file
			if($fp = @fopen($temp_file, 'w')) {
				//check ownership
				if(getmyuid() == fileowner($temp_file)) {
					$result = true;
				}
				@fclose($fp);
				@unlink($temp_file);
			}
		}
		return $result;
	}

	//admin menu
	function admin_menu() {
		add_options_page('WP Uninstaller', 'WP Uninstaller', 'manage_options', 'wp-uninstaller', array(&$this, 'admin_options'));
	}

	//admin options
	function admin_options() {
		//has permission?
		if(!current_user_can('manage_options')) {
			die("Access Denied!");
		}
		//post vars
		$tables = isset($_POST['delete-tables']) ? (int) $_POST['delete-tables'] : 0;
		$files = isset($_POST['delete-files']) ? (int) $_POST['delete-files'] : 0;
		//create nonces
		$nonce1 = wp_create_nonce("wp-uninstaller-nonce-url");
		$nonce2 = wp_create_nonce("wp-uninstaller-nonce-form");
		//generate html
		echo '<div class="wrap">' . "\n";
		echo '<h2>Uninstall WordPress!</h2>' . "\n";
		if($this->error) {
			echo '<div id="message" class="error">' . $this->error , '</div>' . "\n";
		}
		echo '<h3>(1) Database Tables</h3>' . "\n";
		echo '<form method="post" action="options-general.php?page=wp-uninstaller&nonce=' . $nonce1 . '" onsubmit="return confirm(\'Are you sure you want to uninstall WordPress?\');">' . "\n";
		echo '<input type="hidden" name="process" value="wp-uninstaller" />' . "\n";
		echo '<input type="hidden" name="nonce" value="' . $nonce2 . '" />' . "\n";
		echo '<p><input type="radio" name="delete-tables" value="1"' . ($tables == 1 ? ' checked="checked"' : '') . ' /> Do not delete any database tables</p>' . "\n";
		if(empty($this->db->prefix)) {
			echo '<p><input type="radio" name="delete-tables" value="2"' . ($tables == 2 ? ' checked="checked"' : '') . ' /> Delete WordPress database tables (ALL tables in the database "' . DB_NAME . '" will be deleted)</p>' . "\n";
		} else {
			echo '<p><input type="radio" name="delete-tables" value="2"' . ($tables == 2 ? ' checked="checked"' : '') . ' /> Delete WordPress database tables (only tables starting with "' . $this->db->prefix . '" will be deleted)</p>' . "\n";
		}
		echo '<h3 style="margin-top:30px;">(2) WordPress Files</h3>' . "\n";
		echo '<p><input type="radio" name="delete-files" value="1"' . ($files == 1 ? ' checked="checked"' : '') . ' /> Do not delete any files</p>' . "\n";
		echo '<p><input type="radio" name="delete-files" value="2"' . ($files == 2 ? ' checked="checked"' : '') . ' /> Delete wp-config.php file (this will only delete your config file)</p>' . "\n";
		echo '<p><input type="radio" name="delete-files" value="3"' . ($files == 3 ? ' checked="checked"' : '') . ' /> Delete ALL files in the WordPress directory (includes non-WordPress files!)</p>' . "\n";
		echo '<div class="submit"><input type="submit" value="Uninstall Now!" />' . "\n";
		echo '</form>' . "\n";
		echo '</div>' . "\n";
	}

	//admin activate
	function admin_activate() {
		header("Location: admin.php?page=wp-uninstaller");
		exit();
	}

}

//launch!
new wp_uninstaller();