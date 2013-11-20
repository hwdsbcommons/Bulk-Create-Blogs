<?php
/*

Plugin Name: Bulk Create Blogs
Plugin URI:
Description: WordPressMU plugin for site admin to allow the bulk creation of blogs using CSV data.
Version: 2.0
Author: Greg Breese (gregsurname@gmail.com)

Installation: Just place this file in your wp-content/mu-plugins/ directory.

Usage: A page is created under the Site Admin menu called "Bulk Create Blogs"

To use the plugin you must create correctly formatted data. The plugin takes CSV formatted data where each row contains the following data: domain, user_id, blog_title, blog_topic,

Each of these fields is described below.

blog_domain (Mandatory): the domain name of the blog, this should only contain alphanumeric characters and be in all lowercase. If the blog domain is empty then users will be added to the site's root blog.

user_name (Mandatory): the login id of the user to be added to the blog.
blog_title (Optional): the title of the blog.
blog_topic (Optional): the name of the blog topic that this blog will be categorised under. These topics are setup under the 'Blog Topic Management' tab. A blog_title must be chosen if you wish to set the blog topic. (Requires cets_blog_topics plugin.)

When each line is processed, if the blog named already exists then the user given is added to the blog. If the blog does not exist then it is created. If a blog title is not provided then the blog domain is used.

This plugin also provides support for LDAP user creation. If a user does not exist and the ldap_auth plugin is installed then it will attempt to create a new user.

At the moment this plugin only supports sites that use domain blog naming. Support for sub-directories is not planned but you could easily add it yourself.

The number of blogs that can be imported at a time is limited due to php's max_execution_time. You can change the defined limit at the top of the code, but if you do then I suggest increasing your max_execution_time in your php.ini. Having the script get cut off half way through creating a blog may lead to undocumented behaviour. 	:)

Copyright: Copyright 2010 Greg Breese

This program is free software; you can redistribute it and/or modify it under the terms of the GNU General Public License as published by the Free Software Foundation; either version 2 of the License, or (at your option) any later version.

This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU General Public License for more detail.

You should have received a copy of the GNU General Public License along with this program; if not, write to the Free Software Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA

*/

/* 	Limit blogs created each time script is run. This has to be done because
	otherwise script can easily time out due php's max_execution_time ...
*/
define("GB_BULK_CREATE_BLOGS_LIMIT", "20");


class bulk_create_from_csv
{
	private $errors;
	private $lines_processed;

	function __construct() {
		$this->errors = new WP_Error();
		$this->lines_processed = 0;
		$this->num_added = 0;
		$this->num_failed = 0;
	}

	/* 	Imports bulk blog creation data from $_POST['gb_importdata']

		Data is CSV data, with each row representing a blog to be created.

		- If a blog already exists then the user is added to the existing blog.
		- If the blog does not already exist then it is created. If no title is
		provided then the domain is used.
		- Includes optional support for the cets_blog_topics plugin as the
		fourth argument of each line.
		- Includes optional LDAP user creation.

		If runs successfully returns number of lines successfully processed.
	 	If any errors found returns instance of WP_Error
	*/
    public function import_data() {

		$csvdata = htmlspecialchars($_POST['gb_importdata']);

		// Split each row and strip some whitespace
		$data = preg_split("/\s*\n\s*/", $csvdata, GB_BULK_CREATE_BLOGS_LIMIT, PREG_SPLIT_NO_EMPTY);

		foreach ($data as $data_row) {
			$this->lines_processed++;

			// check for special characters
			if( !preg_match('/^[A-Za-z0-9,.\s\-]+$/', $data_row)) {
				$this->errors->add('bad_characters_in_line',"Bad characters in line $this->lines_processed, line skipped. ($data_row)");
			} else {
				$this->import_row($data_row);
			}
		}
		if( empty($this->errors)) {
			return $this->lines_processed;
		}
		return $this->errors;
	}

	/*
		Processed one row of data
	 	Each row consists of up to four comma separated values, with values in these order;

			domain, user_id, blog_title, blog_topic

	*/
	public function import_row($data_row) {
		global $wpdb, $site_id;

		// split the row up and trim whitespace
		$data_row = preg_split("/\s*,\s*/", $data_row, 5 );
		if ( sizeof($data_row) < 2 ) {
			// not enough arguments
			$this->errors->add('too_few_arguments',"Not enough arguments on line $this->lines_processed, line skipped.");					$this->num_failed++;
			return;
		}
		//check if user only contains valid characters
		if( strspn($data_row[1], "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789-") != strlen($data_row[1])) {
			// Invalid characters in username
			$this->errors->add('bad_characters_in_username',"The username $data_row[1] on line $this->lines_processed contains bad characters, line skipped.");
			$this->num_failed++;
			return;
		}

		// check that domain contains only valid characters
		if( strspn($data_row[0], "-abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789") != strlen($data_row[0])) {
			// non alpha-numeric characters in domain
			$this->errors->add('non_alphanumeric_domain',"Non alphanumeric characters in domain '$data_row[0]' on line $this->lines_processed, line skipped.");
			$this->num_failed++;
			return;
		}

		//check if user exists
		$user_id = get_user_id_from_string($data_row[1]);
		if ($user_id == null) {
			// Try to create the user from LDAP details
			if( function_exists('wpmuSetupLdapOptions') ) {
				$ldapString = wpmuSetupLdapOptions();
				$server = new LDAP_ro($ldapString);
				$server->DebugOff();

				$user_data = null;
				$result = $server->GetUserInfo( $data_row[1], $user_data);
				if($result == LDAP_OK) {
					// Make surname proper case
					$user_data[LDAP_INDEX_SURNAME] = ucfirst( strtolower($user_data[LDAP_INDEX_SURNAME] ));
					// Create the new user
					$new_user = wpmuLdapCreateWPUserFromLdap($data_row[1], "123456", $user_data);
					$ID = $new_user->ID;
					$user_id = $ID;
					// Fix the display name from the default
					$display_name = "$new_user->first_name $new_user->last_name";
					$user_data = compact('ID', 'display_name');
					wp_update_user($user_data);
				} else {
					// users does not exist in ldap error
					$this->errors->add('no_ldap_user',"The user $data_row[1] does not exist in LDAP database. Check line $this->lines_processed. Line skipped.");
					$this->num_failed++;
					return;
				}
			} else {
				$this->errors->add('no_user', "The user $data_row[1] does not exist. Check line $this->lines_processed. Line skipped.");
				$this->num_failed++;
				return;
			}
		}

		// see if blog already exists for domain
		$site_domain = $wpdb->get_var( $wpdb->prepare("SELECT domain FROM $wpdb->site WHERE id = %s", $site_id) );
		if($data_row[0] == "") {
			$new_domain = $site_domain;
			$new_path = "/";
		} else {
			if(defined('VHOST') && VHOST == 'yes') {
				// sub-domain sites
				$domain_prefix = strtolower($data_row[0]);
				$new_domain = "$domain_prefix.$site_domain";
				$new_path = "/";

			} else {
				// sub-directory sites
				$new_domain = $site_domain;
				$new_path = "/".strtolower($data_row[0])."/";
			}

		}
		$new_blog_id = $this->find_blog_id_by_domain_and_path($new_domain, $new_path);
		if( !is_wp_error($new_blog_id)) {
			// Blog already exists, add user and exit.
			//switch_to_blog($new_blog_id);
			//add_user_to_blog($new_blog_id, $user_id, $_POST[role]);
			$this->errors->add( 'site_exists', "The site {$new_path} already exists." );
			$this->num_failed++;
			return;
		}

		if( $new_blog_id->errors['no_blog_exists'] ) {
			// Create a new blog

			// set title
			if (sizeof($data_row) > 2) {
				$title = $data_row[2];
			}
			else {
				$title = $data_row[0];
			}

			// create an empty blog
			$new_blog_id = $this->gb_create_empty_blog($new_domain, $new_path, $title, $site_id);
			if ( is_wp_error($new_blog_id) ) {
				// couldn't create blog error
				$this->errors->add('failed_to_create_blog',"Couldn't create blog $new_domain on line $this->lines_processed.");
				$this->num_failed++;
				return;
			}

			switch_to_blog($new_blog_id);
			update_option('blogname', $title);
			// Add the user
			add_user_to_blog($new_blog_id, $user_id, $_POST[role]);
			// Install defaults
			install_blog_defaults($new_blog_id, $user_id);

			if ( is_array($meta) ) foreach ($meta as $key => $value) {
				update_blog_status( $new_blog_id, $key, $value );
				update_blog_option( $new_blog_id, $key, $value );
			}

			add_blog_option( $new_blog_id, 'WPLANG', get_site_option( 'WPLANG' ) );

			if(get_user_meta( $user_id, 'primary_blog' ) == 1 )
				update_user_meta( $user_id, 'primary_blog', $new_blog_id );

			do_action( 'wpmu_new_blog', $blog_id, $user_id, $new_domain, $new_path, 1, $meta );

			// check if cets_blog_topic exists
			if( function_exists('cets_get_topic_id_from_name') && sizeof($data_row) > 3) {
				$topic_id = cets_get_topic_id_from_name($data_row[3]);
				if ($topic_id == null) {
					// topic does not exist, try creating it
					//add_topic($data_row[3]);
					$this->errors->add('no_blog_topic',"The blog topic $data_row[3] does not exist on line $this->lines_processed. Blog created without topic.");
					return;
				}
				// set blog topic
				cets_set_blog_topic($new_blog_id, $topic_id);
			}

			do_action('gb_bulk_create_blogs_import_blog', $new_blog_id);
			$this->num_added++;
			return;
		} else if( $new_blog_id->errors[blog_not_in_site]) {
			// blog not in site error
			$this->errors->add('blog_not_in_site',"Couldn't add user to blog $new_domain on line $this->lines_processed because this blog is not in the current site.");
			$this->num_failed++;
			return;
		}

		$this->errors->add('unknown error',"Unknown error on line 	.");
		$this->num_failed++;
		return;
	}

	/* Creates bulk create blogs management page

	*/
	function bulk_create_management_page(){
	 	// Display the defaults that can be set by site admins

	 	global $wpdb, $site_id;

		// only allow site admins to come here.
		if( is_site_admin() == false ) {
			wp_die( __('You do not have permission to access this page.') );
		}

		// process form submission
    	if ($_POST['action'] == 'update') {
			$errors = $this->import_data($_POST);
			$messages = $errors->get_error_messages();
			if( ! empty( $this->num_added ) ) { ?>
				<div id="message" class="updated fade">
					<?php echo "<p>$this->num_added sites successfully added!</p>"; ?>
			   	</div>
<?php 		}

			if( ! empty( $this->num_failed ) ) { ?>
				<div id="message" class="updated fade">
					<?php echo "<p>$this->num_failed sites failed to be added.  View the error messages below for details.</p>"; ?>
			   	</div>
<?php 		}

			if ( ! empty( $messages ) ) {
	?>
				<div id="message" class="updated fade"><?php
					foreach( $messages as $message ) {
						echo "<p><strong>ERROR: </strong>$message</p>";
					} ?>
				</div>
<?php		}
    	}
?>

        <form name="blogdefaultsform" action="" method="post">

        <div class="wrap">
        	<h2><?php _e('Bulk Create Blogs') ?></h2>
	        <p>This plugin allows you to bulk create blogs by importing data in csv format. It includes optional support for the cets_blog_topics plugin that allows you to categorise posts. To use it you should paste the data to import into the textbox below. The data should written in CSV format. It is important that the data is correctly formatted using the format described here.</p>
			<p>Each line contains these arguments:</p>
			<ul><li>blog_address (<strong>Mandatory</strong>): For <em>sub-domain</em> sites this should be the domain prefix for the blog. For <em>sub-directory</em> sites this should be the directory for the blog. This should only contain alphanumeric characters and be in all lowercase. If the blog address is empty then users will be added to the site's root blog.</li>
			<li>user_name (<strong>Mandatory</strong>): the login id of the user to be added as the administrator of the blog.</li>
			<li>blog_title (<strong>Optional</strong>): the title of the blog.</li>
			<li>blog_topic (<strong>Optional</strong>): the name of the blog topic that this blog will be categorised under. These topics are setup under the 'Blog Topic Management' tab. A blog_title must be chosen if you wish to set the blog topic. (Requires cets_blog_topics plugin.)</li>
			</ul>
			<p>Each line of data should adhere to this format -  <strong>blog_address, user_name, blog_title, blog_topic</strong></p>
			<p>For example: exampleblog, aaa0001, Aaron Aanderson, topic</p>
			<p>When each line is processed, if the blog doesn't exist then it is created. The user provided is set as a registered user of the blog with the user level specified.</p>
	        <table class="form-table">
				<tr valign="top">
					<th scope="row"><?php _e('Site') ?></th>
					<td ><strong><?php echo $wpdb->get_var( $wpdb->prepare("SELECT domain FROM $wpdb->site WHERE id = %s", $site_id)); ?></strong>
					<p>Please check that this is the site that you wish to import blogs into!</p></td></tr>
					<tr valign="top"><th scope="row"><?php _e('Permissions') ?></th>
					<td>What user level would you like the users to be set to?<br>
					<select name="role"><?php wp_dropdown_roles(); ?></select>
					</td>
				</tr>
				<?php
					// hook for other plugins to add to interface
					do_action('gb_bulk_create_blogs_form');
				?>
       			 <tr valign="top">
			        <th scope="row"><?php _e('Data') ?><p>Only <strong><?php echo GB_BULK_CREATE_BLOGS_LIMIT; ?></strong> lines will be processed.</p></th>
			        <td><textarea name="gb_importdata" type="text" id="gb_importdata" value="" rows="10" cols="50" />Enter your csv data here.</textarea><br/>
			        </td>
			     </tr>
	        </table>
        </div>

        <p>&nbsp;</p>
        <p>
        	<input type="hidden" name="action" value="update" />
	        <input type="submit" name="Submit" value="<?php _e('Create blogs') ?>" />
        </p>

        <?php

		// End of management page
	 }

	// returns the blog_id for a given domain
	// $domain must be the fully qualified domain name of the blog.
	// Checks that the blog belongs to the currently set site.
	public function find_blog_id_by_domain_and_path( $domain, $path) {
		global $wpdb, $site_id;

		$query = "SELECT * FROM {$wpdb->blogs} WHERE domain='" . $wpdb->escape($domain) . "' AND path='" . $wpdb->escape($path) . "' LIMIT 1";
		$blog = $wpdb->get_row($query);
		if($blog) {
			if( $blog->site_id == $site_id) {
				return $blog->blog_id;
			} else {
				return new WP_Error('blog_not_in_site', __('The blog does not belong to the current site.'));
			}
		} else {
			return new WP_Error('no_blog_exists',__('Blog does not exist.'));
		}
	}

	// Creates an empty blog with no content loaded.
	// Calls refresh_wp_queries() so can be used more than once
	// per WP instance.
	public function gb_create_empty_blog( $domain, $path, $weblog_title, $site_id = 1 ) {
		$domain       = addslashes( $domain );
		$weblog_title = addslashes( $weblog_title );

		if( empty($path) )
			$path = '/';

		// Check if the domain has been used already. We should return an error message.
		if ( domain_exists($domain, $path, $site_id) )
			return new WP_Error('blog_url_taken', "Blog URL already taken.");

		// Need to backup wpdb table names, and create a new wp_blogs entry for new blog.
		// Need to get blog_id from wp_blogs, and create new table names.
		// Must restore table names at the end of function.

		if ( ! $blog_id = insert_blog($domain, $path, $site_id) )
			return new WP_Error('could_not_create_blog', "Couldn't create blog.");

		switch_to_blog($blog_id);
		$this->refresh_wp_queries();
		install_blog($blog_id);
		restore_current_blog();

		return $blog_id;
	}

	// Refreshes the wp_queries global variable, required to create more than
	// one new blog in a WP instance.
	// These values are just copied out of wp-admin/includes/schema.php from wpmu version 2.6
	public function refresh_wp_queries () {
		global $wpdb, $wp_queries;

		$wp_queries="CREATE TABLE $wpdb->terms (
		 term_id bigint(20) NOT NULL auto_increment,
		 name varchar(200) NOT NULL default '',
		 slug varchar(200) NOT NULL default '',
		 term_group bigint(10) NOT NULL default 0,
		 PRIMARY KEY  (term_id),
		 UNIQUE KEY slug (slug),
		 KEY name (name)
		) $charset_collate;
		CREATE TABLE $wpdb->term_taxonomy (
		 term_taxonomy_id bigint(20) NOT NULL auto_increment,
		 term_id bigint(20) NOT NULL default 0,
		 taxonomy varchar(32) NOT NULL default '',
		 description longtext NOT NULL,
		 parent bigint(20) NOT NULL default 0,
		 count bigint(20) NOT NULL default 0,
		 PRIMARY KEY  (term_taxonomy_id),
		 UNIQUE KEY term_id_taxonomy (term_id,taxonomy)
		) $charset_collate;
		CREATE TABLE $wpdb->term_relationships (
		 object_id bigint(20) NOT NULL default 0,
		 term_taxonomy_id bigint(20) NOT NULL default 0,
		 term_order int(11) NOT NULL default 0,
		 PRIMARY KEY  (object_id,term_taxonomy_id),
		 KEY term_taxonomy_id (term_taxonomy_id)
		) $charset_collate;
		CREATE TABLE $wpdb->comments (
		  comment_ID bigint(20) unsigned NOT NULL auto_increment,
		  comment_post_ID int(11) NOT NULL default '0',
		  comment_author tinytext NOT NULL,
		  comment_author_email varchar(100) NOT NULL default '',
		  comment_author_url varchar(200) NOT NULL default '',
		  comment_author_IP varchar(100) NOT NULL default '',
		  comment_date datetime NOT NULL default '0000-00-00 00:00:00',
		  comment_date_gmt datetime NOT NULL default '0000-00-00 00:00:00',
		  comment_content text NOT NULL,
		  comment_karma int(11) NOT NULL default '0',
		  comment_approved varchar(20) NOT NULL default '1',
		  comment_agent varchar(255) NOT NULL default '',
		  comment_type varchar(20) NOT NULL default '',
		  comment_parent bigint(20) NOT NULL default '0',
		  user_id bigint(20) NOT NULL default '0',
		  PRIMARY KEY  (comment_ID),
		  KEY comment_approved (comment_approved),
		  KEY comment_post_ID (comment_post_ID),
		  KEY comment_approved_date_gmt (comment_approved,comment_date_gmt),
		  KEY comment_date_gmt (comment_date_gmt)
		) $charset_collate;
		CREATE TABLE $wpdb->links (
		  link_id bigint(20) NOT NULL auto_increment,
		  link_url varchar(255) NOT NULL default '',
		  link_name varchar(255) NOT NULL default '',
		  link_image varchar(255) NOT NULL default '',
		  link_target varchar(25) NOT NULL default '',
		  link_category bigint(20) NOT NULL default '0',
		  link_description varchar(255) NOT NULL default '',
		  link_visible varchar(20) NOT NULL default 'Y',
		  link_owner int(11) NOT NULL default '1',
		  link_rating int(11) NOT NULL default '0',
		  link_updated datetime NOT NULL default '0000-00-00 00:00:00',
		  link_rel varchar(255) NOT NULL default '',
		  link_notes mediumtext NOT NULL,
		  link_rss varchar(255) NOT NULL default '',
		  PRIMARY KEY  (link_id),
		  KEY link_category (link_category),
		  KEY link_visible (link_visible)
		) $charset_collate;
		CREATE TABLE $wpdb->options (
		  option_id bigint(20) NOT NULL auto_increment,
		  blog_id int(11) NOT NULL default '0',
		  option_name varchar(64) NOT NULL default '',
		  option_value longtext NOT NULL,
		  autoload varchar(20) NOT NULL default 'yes',
		  PRIMARY KEY  (option_id,blog_id,option_name),
		  KEY option_name (option_name)
		) $charset_collate;
		CREATE TABLE $wpdb->postmeta (
		  meta_id bigint(20) NOT NULL auto_increment,
		  post_id bigint(20) NOT NULL default '0',
		  meta_key varchar(255) default NULL,
		  meta_value longtext,
		  PRIMARY KEY  (meta_id),
		  KEY post_id (post_id),
		  KEY meta_key (meta_key)
		) $charset_collate;
		CREATE TABLE $wpdb->posts (
		  ID bigint(20) unsigned NOT NULL auto_increment,
		  post_author bigint(20) NOT NULL default '0',
		  post_date datetime NOT NULL default '0000-00-00 00:00:00',
		  post_date_gmt datetime NOT NULL default '0000-00-00 00:00:00',
		  post_content longtext NOT NULL,
		  post_title text NOT NULL,
		  post_category int(4) NOT NULL default '0',
		  post_excerpt text NOT NULL,
		  post_status varchar(20) NOT NULL default 'publish',
		  comment_status varchar(20) NOT NULL default 'open',
		  ping_status varchar(20) NOT NULL default 'open',
		  post_password varchar(20) NOT NULL default '',
		  post_name varchar(200) NOT NULL default '',
		  to_ping text NOT NULL,
		  pinged text NOT NULL,
		  post_modified datetime NOT NULL default '0000-00-00 00:00:00',
		  post_modified_gmt datetime NOT NULL default '0000-00-00 00:00:00',
		  post_content_filtered text NOT NULL,
		  post_parent bigint(20) NOT NULL default '0',
		  guid varchar(255) NOT NULL default '',
		  menu_order int(11) NOT NULL default '0',
		  post_type varchar(20) NOT NULL default 'post',
		  post_mime_type varchar(100) NOT NULL default '',
		  comment_count bigint(20) NOT NULL default '0',
		  PRIMARY KEY  (ID),
		  KEY post_name (post_name),
		  KEY type_status_date (post_type,post_status,post_date,ID)
		) $charset_collate;
		CREATE TABLE IF NOT EXISTS $wpdb->users (
		  ID bigint(20) unsigned NOT NULL auto_increment,
		  user_login varchar(60) NOT NULL default '',
		  user_pass varchar(64) NOT NULL default '',
		  user_nicename varchar(50) NOT NULL default '',
		  user_email varchar(100) NOT NULL default '',
		  user_url varchar(100) NOT NULL default '',
		  user_registered datetime NOT NULL default '0000-00-00 00:00:00',
		  user_activation_key varchar(60) NOT NULL default '',
		  user_status int(11) NOT NULL default '0',
		  display_name varchar(250) NOT NULL default '',
		  spam tinyint(2) NOT NULL default '0',
		  deleted tinyint(2) NOT NULL default '0',
		  PRIMARY KEY  (ID),
		  KEY user_login_key (user_login),
		  KEY user_nicename (user_nicename)
		) $charset_collate;
		CREATE TABLE IF NOT EXISTS $wpdb->usermeta (
		  umeta_id bigint(20) NOT NULL auto_increment,
		  user_id bigint(20) NOT NULL default '0',
		  meta_key varchar(255) default NULL,
		  meta_value longtext,
		  PRIMARY KEY  (umeta_id),
		  KEY user_id (user_id),
		  KEY meta_key (meta_key)
		) $charset_collate;
		CREATE TABLE IF NOT EXISTS $wpdb->blogs (
		  blog_id bigint(20) NOT NULL auto_increment,
		  site_id bigint(20) NOT NULL default '0',
		  domain varchar(200) NOT NULL default '',
		  path varchar(100) NOT NULL default '',
		  registered datetime NOT NULL default '0000-00-00 00:00:00',
		  last_updated datetime NOT NULL default '0000-00-00 00:00:00',
		  public tinyint(2) NOT NULL default '1',
		  archived enum('0','1') NOT NULL default '0',
		  mature tinyint(2) NOT NULL default '0',
		  spam tinyint(2) NOT NULL default '0',
		  deleted tinyint(2) NOT NULL default '0',
		  lang_id int(11) NOT NULL default '0',
		  PRIMARY KEY  (blog_id),
		  KEY domain (domain(50),path(5)),
		  KEY lang_id (lang_id)
		) $charset_collate;
		CREATE TABLE IF NOT EXISTS $wpdb->blog_versions (
		  blog_id bigint(20) NOT NULL default '0',
		  db_version varchar(20) NOT NULL default '',
		  last_updated datetime NOT NULL default '0000-00-00 00:00:00',
		  PRIMARY KEY  (blog_id),
		  KEY db_version (db_version)
		) $charset_collate;
		CREATE TABLE IF NOT EXISTS $wpdb->registration_log (
		  ID bigint(20) NOT NULL auto_increment,
		  email varchar(255) NOT NULL default '',
		  IP varchar(30) NOT NULL default '',
		  blog_id bigint(20) NOT NULL default '0',
		  date_registered datetime NOT NULL default '0000-00-00 00:00:00',
		  PRIMARY KEY  (ID),
		  KEY IP (IP)
		) $charset_collate;
		CREATE TABLE IF NOT EXISTS $wpdb->site (
		  id bigint(20) NOT NULL auto_increment,
		  domain varchar(200) NOT NULL default '',
		  path varchar(100) NOT NULL default '',
		  PRIMARY KEY  (id),
		  KEY domain (domain,path)
		) $charset_collate;
		CREATE TABLE IF NOT EXISTS $wpdb->sitemeta (
		  meta_id bigint(20) NOT NULL auto_increment,
		  site_id bigint(20) NOT NULL default '0',
		  meta_key varchar(255) default NULL,
		  meta_value longtext,
		  PRIMARY KEY  (meta_id),
		  KEY meta_key (meta_key),
		  KEY site_id (site_id)
		) $charset_collate;
		CREATE TABLE IF NOT EXISTS $wpdb->sitecategories (
		  cat_ID bigint(20) NOT NULL auto_increment,
		  cat_name varchar(55) NOT NULL default '',
		  category_nicename varchar(200) NOT NULL default '',
		  last_updated timestamp NOT NULL,
		  PRIMARY KEY  (cat_ID),
		  KEY category_nicename (category_nicename),
		  KEY last_updated (last_updated)
		) $charset_collate;
		CREATE TABLE IF NOT EXISTS $wpdb->signups (
		  domain varchar(200) NOT NULL default '',
		  path varchar(100) NOT NULL default '',
		  title longtext NOT NULL,
		  user_login varchar(60) NOT NULL default '',
		  user_email varchar(100) NOT NULL default '',
		  registered datetime NOT NULL default '0000-00-00 00:00:00',
		  activated datetime NOT NULL default '0000-00-00 00:00:00',
		  active tinyint(1) NOT NULL default '0',
		  activation_key varchar(50) NOT NULL default '',
		  meta longtext,
		  KEY activation_key (activation_key),
		  KEY domain (domain)
		) $charset_collate;
		";
	}

	//Add the site-wide administrator menu
	public function add_siteadmin_page(){
       add_submenu_page('wpmu-admin.php', 'Bulk Create Blogs', 'Bulk Create Blogs', 10, 'bulk_create_management_page', array(&$this, 'bulk_create_management_page'));

	 }
};


$bulk_create_from_csv_instance = new bulk_create_from_csv();


// Add the site admin config page
add_action('admin_menu', array(&$bulk_create_from_csv_instance, 'add_siteadmin_page'));
