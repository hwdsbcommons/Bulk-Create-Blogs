<?php
/*
Plugin Name: Bulk Create Blogs
Plugin URI:
Description: WordPressMU plugin for site admin to allow the bulk creation of blogs using CSV data.
Version: 2.0
Author: Greg Breese (gregsurname@gmail.com)

Copyright: Copyright 2010 Greg Breese

This program is free software; you can redistribute it and/or modify it
under the terms of the GNU General Public License as published by the Free
Software Foundation; either version 2 of the License, or (at your option)
any later version.

This program is distributed in the hope that it will be useful, but
WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU General
Public License for more detail.
*/

/**
 * Limit blogs created each time script is run. This has to be done because
 * otherwise script can easily time out due php's max_execution_time ...
 */
define( "GB_BULK_CREATE_BLOGS_LIMIT", "20" );

/**
 * Bulk Create Blogs
 *
 * Installation: Place this file in your /wp-content/mu-plugins/ directory.
 *
 * Usage: A page is created under the "Sites" menu called "Bulk Create Blogs"
 *
 * To use the plugin you must create correctly formatted data. The plugin
 * takes CSV formatted data where each row contains the following data:
 *
 *   domain, user_id, blog_title, blog_topic
 *
 * Each of these fields is described below.
 *
 * - blog_domain (Mandatory): the domain name of the blog, this should only
 *    contain alphanumeric characters and be in all lowercase. If the blog
 *    domain is empty then users will be added to the site's root blog.
 *
 * - user_name (Mandatory): the login id of the user to be added to the blog.
 *
 * - blog_title (Optional): the title of the blog.
 *
 * - blog_topic (Optional): the name of the blog topic that this blog will be
 *    categorised under. These topics are setup under the 'Blog Topic
 *    Management' tab. A blog_title must be chosen if you wish to set the blog
 *    topic. (Requires cets_blog_topics plugin.)
 *
 * When each line is processed, if the blog named already exists then the user
 * given is added to the blog. If the blog does not exist then it is created.
 * If a blog title is not provided then the blog domain is used.
 *
 * This plugin also provides support for LDAP user creation. If a user does
 * not exist and the ldap_auth plugin is installed then it will attempt to
 * create a new user.
 *
 * The number of blogs that can be imported at a time is limited due to php's
 * max_execution_time. You can change the defined limit at the top of the
 * code, but if you do then I suggest increasing your max_execution_time in
 * your php.ini. Having the script get cut off half way through creating a
 * blog may lead to undocumented behaviour. 	:)
 */
class Bulk_Create_From_CSV {
	private $errors;
	private $lines_processed;

	public function __construct() {
		$this->errors          = new WP_Error();
		$this->lines_processed = 0;
		$this->num_added       = 0;
		$this->num_failed      = 0;
	}

	/**
	 * Imports bulk blog creation data from $_POST['gb_importdata']
	 *
	 * Data is CSV data, with each row representing a blog to be created.
	 *
	 * - If a blog already exists then the user is added to the existing blog.
	 * - If the blog does not already exist then it is created. If no title is
	 *   provided then the domain is used.
	 * - Includes optional support for the cets_blog_topics plugin as the
	 *   fourth argument of each line.
	 * - Includes optional LDAP user creation.
	 *
	 * If runs successfully returns number of lines successfully processed.
	 * If any errors found returns instance of WP_Error
	 */
	public function import_data() {

		$csvdata = htmlspecialchars($_POST['gb_importdata']);

		// Split each row and strip some whitespace
		$data = preg_split("/\s*\n\s*/", $csvdata, GB_BULK_CREATE_BLOGS_LIMIT, PREG_SPLIT_NO_EMPTY);

		foreach ( $data as $data_row ) {
			$this->lines_processed++;

			// check for special characters
			if( ! preg_match( '/^[A-Za-z0-9,.\s\-]+$/', $data_row ) ) {
				$this->errors->add( 'bad_characters_in_line', "Bad characters in line $this->lines_processed, line skipped. ($data_row)" );
			} else {
				$this->import_row( $data_row );
			}
		}

		if ( empty( $this->errors ) ) {
			return $this->lines_processed;
		}

		return $this->errors;
	}

	/**
	 * Processed one row of data.
	 *
	 * Each row consists of up to four comma separated values, with values in these order;
	 *
	 *   domain, user_id, blog_title, blog_topic
	 *
	 * @param string $data_row Comma-delimited data string to be processed
	 */
	public function import_row( $data_row ) {
		global $wpdb, $current_site;

		// split the row up and trim whitespace
		$data_row = preg_split( "/\s*,\s*/", $data_row, 5 );
		if ( sizeof( $data_row ) < 2 ) {
			// not enough arguments
			$this->errors->add( 'too_few_arguments', "Not enough arguments on line $this->lines_processed, line skipped." );
			$this->num_failed++;
			return;
		}
		//check if user only contains valid characters
		if( strspn( $data_row[1], "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789-" ) != strlen( $data_row[1] ) ) {
			// Invalid characters in username
			$this->errors->add( 'bad_characters_in_username', "The username $data_row[1] on line $this->lines_processed contains bad characters, line skipped." );
			$this->num_failed++;
			return;
		}

		// check that domain contains only valid characters
		if( strspn( $data_row[0], "-abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789") != strlen( $data_row[0] ) ) {
			// non alpha-numeric characters in domain
			$this->errors->add( 'non_alphanumeric_domain', "Non alphanumeric characters in domain '$data_row[0]' on line $this->lines_processed, line skipped." );
			$this->num_failed++;
			return;
		}

		//check if user exists
		$user_id = self::get_user( $data_row[1] );
		if ( empty( $user_id ) ) {
			// Try to create the user from LDAP details
			if( function_exists( 'wpmuSetupLdapOptions' ) ) {
				$ldapString = wpmuSetupLdapOptions();
				$server = new LDAP_ro( $ldapString );
				$server->DebugOff();

				$user_data = null;
				$result = $server->GetUserInfo( $data_row[1], $user_data );
				if ( $result == LDAP_OK ) {
					// Make surname proper case
					$user_data[LDAP_INDEX_SURNAME] = ucfirst( strtolower( $user_data[LDAP_INDEX_SURNAME] ) );
					// Create the new user
					$new_user = wpmuLdapCreateWPUserFromLdap( $data_row[1], "123456", $user_data );
					$ID = $new_user->ID;
					$user_id = $ID;
					// Fix the display name from the default
					$display_name = "$new_user->first_name $new_user->last_name";
					$user_data = compact( 'ID', 'display_name' );
					wp_update_user( $user_data );
				} else {
					// users does not exist in ldap error
					$this->errors->add( 'no_ldap_user', "The user $data_row[1] does not exist in LDAP database. Check line $this->lines_processed. Line skipped." );
					$this->num_failed++;
					return;
				}
			} else {
				$this->errors->add( 'no_user', "The user $data_row[1] does not exist. Check line $this->lines_processed. Line skipped." );
				$this->num_failed++;
				return;
			}
		}

		$domain = $data_row[0];

		// subdomain install
		if ( is_subdomain_install() ) {
			$newdomain = $domain . '.' . preg_replace( '|^www\.|', '', $current_site->domain );
			$path      = $current_site->path;

		// subdirectory install
		} else {
			$subdirectory_reserved_names = apply_filters( 'subdirectory_reserved_names', array( 'page', 'comments', 'blog', 'files', 'feed' ) );
			if ( in_array( $domain, $subdirectory_reserved_names ) ) {
				$this->errors->add(
					'domain_reserved',
					sprintf( __('The following words are reserved for use by WordPress functions and cannot be used as blog names: <code>%s</code>' ), implode( '</code>, <code>', $subdirectory_reserved_names ) )
				);

				$this->num_failed++;
				return;
			}

			$newdomain = $current_site->domain;
			$path      = $current_site->path . $domain . '/';
		}

		// set title
		if ( sizeof( $data_row ) > 2 ) {
			$title = $data_row[2];
		} else {
			// fallback to blog slug if no title
			$title = $data_row[0];
		}

		// install the blog
		$new_blog_id = wpmu_create_blog( $newdomain, $path, $title, $user_id, array( 'public' => 1 ) );

		// error in creating blog
		if ( is_wp_error( $new_blog_id ) ) {
			$new_blog_error_msg = $new_blog_id->get_error_message();
			$this->errors->add('failed_to_create_blog',"Couldn't create blog $path on line $this->lines_processed. Error message is: '$new_blog_error_msg'");
			$this->num_failed++;
			return;
		}

		// check if cets_blog_topic exists
		if( function_exists( 'cets_get_topic_id_from_name' ) && sizeof( $data_row ) > 3 ) {
			$topic_id = cets_get_topic_id_from_name( $data_row[3] );
			if ( $topic_id == null ) {
				// topic does not exist, try creating it
				//add_topic($data_row[3]);
				$this->errors->add( 'no_blog_topic', "The blog topic $data_row[3] does not exist on line $this->lines_processed. Blog created without topic." );
				return;
			}
			// set blog topic
			cets_set_blog_topic( $new_blog_id, $topic_id );
		}

		do_action( 'gb_bulk_create_blogs_import_blog', $new_blog_id );
		$this->num_added++;
		return;
	}

	/**
	 * Callback to process and display the admin page.
	 */
	public function bulk_create_management_page(){

		// process form submission
    		if ( ! empty( $_POST['action'] ) && $_POST['action'] == 'update' ) {
			// More Privacy Options plugin check
			// Disables annoying super admin email
    			global $ds_more_privacy_options;
    			if ( ! empty( $ds_more_privacy_options ) ) {
				remove_action( 'update_blog_public', array( $ds_more_privacy_options,'ds_mail_super_admin' ) );
			}

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

			<ul>
				<li>blog_address (<strong>Mandatory</strong>): For <em>sub-domain</em> sites this should be the domain prefix for the blog. For <em>sub-directory</em> sites this should be the directory for the blog. This should only contain alphanumeric characters and be in all lowercase. If the blog address is empty then users will be added to the site's root blog.</li>
				<li>user_name (<strong>Mandatory</strong>): the login id of the user to be added as the administrator of the blog.</li>
				<li>blog_title (<strong>Optional</strong>): the title of the blog.</li>
				<li>blog_topic (<strong>Optional</strong>): the name of the blog topic that this blog will be categorised under. These topics are setup under the 'Blog Topic Management' tab. A blog_title must be chosen if you wish to set the blog topic. (Requires cets_blog_topics plugin.)</li>
			</ul>

			<p>Each line of data should adhere to this format -  <strong>blog_address, user_name, blog_title, blog_topic</strong></p>
			<p>For example: exampleblog, aaa0001, Aaron Aanderson, topic</p>
			<p>When each line is processed, if the blog doesn't exist then it is created. The user provided is set as a registered user of the blog with the user level specified.</p>

		        <table class="form-table">
				<tr>
					<th scope="row"><?php _e('Site') ?></th>
					<td>
						<strong><?php self::the_site(); ?></strong>
						<p>Please check that this is the site that you wish to import blogs into!</p>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php _e('Permissions') ?></th>
					<td>
						What user level would you like the users to be set to?<br />
						<select name="role"><?php wp_dropdown_roles(); ?></select>
					</td>
				</tr>

				<?php
					// hook for other plugins to add to interface
					do_action('gb_bulk_create_blogs_form');
				?>

				<tr>
					<th scope="row"><?php _e('Data') ?><p>Only <strong><?php echo GB_BULK_CREATE_BLOGS_LIMIT; ?></strong> lines will be processed.</p></th>
					<td><textarea name="gb_importdata" id="gb_importdata" rows="10" cols="50"></textarea><br /></td>
				</tr>
			</table>
	        </div>

	        <p>&nbsp;</p>
	        <p>
	        	<input type="hidden" name="action" value="update" />
		        <input type="submit" name="Submit" value="<?php _e( 'Create blogs' ) ?>" />
	        </p>

		</form>
        <?php
	}

	/**
	 * Registers the admin page.
	 */
	public function add_siteadmin_page(){
		add_submenu_page(
			'sites.php',
			'Bulk Create Blogs',
			'Bulk Create Blogs',
			'manage_sites',
			'bulk_create_management_page',
			array( &$this, 'bulk_create_management_page' )
		);
	}

	/** HELPER METHODS ************************************************/

	/**
	 * Static method to query a user's data to return the user ID.
	 *
	 * Can pass either the user ID, email, login or nicename.
	 *
	 * @since 3.0
	 *
	 * @param int|string $string Either user ID, user email or user login / nicename
	 * @return int The user ID
	 */
	public static function get_user( $string = '' ) {
		if ( is_email( $string ) ) {
			$user = get_user_by( 'email', $string );
		} elseif ( is_numeric( $string ) ) {
			$user = get_user_by( 'id',    $string );
		} else {
			$user = get_user_by( 'login', $string );

			if ( empty( $user ) ) {
				$user = get_user_by( 'slug', $string );
			}
		}

		if ( $user ) {
			return $user->ID;
		}

		return 0;
	}

	/**
	 * Outputs the site.
	 *
	 * Works in either subdirectory or subdomain installs.
	 *
	 * @since 3.0
	 */
	public static function the_site() {
		global $current_site;

		if ( is_subdomain_install() ) {
			$site = preg_replace( '|^www\.|', '', $current_site->domain );
		} else {
			$site = $current_site->domain . $current_site->path;
		}

		echo $site;
	}
}

// Initialize the class
$bulk_create_blogs = new Bulk_Create_From_CSV();

// Add the site admin config page
add_action( 'network_admin_menu', array( &$bulk_create_blogs, 'add_siteadmin_page' ) );
