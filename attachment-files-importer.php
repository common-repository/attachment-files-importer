<?php
/*
Plugin Name: Attachment Files Importer
Description: Scan your Wordpress installation for all missing attachment files and download them from another Wordpress installation.
Version: 0.3.0
Author: KLicheR
Author URI: https://github.com/KLicheR
Text Domain: attachment-files-importer
License: GPLv2 or later
*/

/*  Copyright 2013  Kristoffer Laurin-Racicot  (email : kristoffer.lr@gmail.com)

	This program is free software; you can redistribute it and/or modify
	it under the terms of the GNU General Public License, version 2, as 
	published by the Free Software Foundation.

	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU General Public License for more details.

	You should have received a copy of the GNU General Public License
	along with this program; if not, write to the Free Software
	Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

if ( ! defined( 'WP_LOAD_IMPORTERS' ) )
	return;

if ( ! defined( 'ATTACHMENT_FILES_IMPORT_DEBUG' ) )
	define('ATTACHMENT_FILES_IMPORT_DEBUG', false);

// Load Importer API
require_once ABSPATH . 'wp-admin/includes/import.php';

if ( ! class_exists( 'WP_Importer' ) ) {
	$class_wp_importer = ABSPATH . 'wp-admin/includes/class-wp-importer.php';
	if ( file_exists( $class_wp_importer ) )
		require $class_wp_importer;
}

/**
 * WordPress Importer class for managing the import process of a WXR file
 *
 * @package WordPress
 * @subpackage Importer
 */
if ( class_exists( 'WP_Importer' ) ) {
class AF_Import extends WP_Importer {
	// information to import from WXR file
	var $posts = array();
	var $base_url = '';

	var $image_sizes_to_process = array();

	function AF_Import() { /* nothing */ }

	/**
	 * Registered callback function for the WordPress Importer
	 *
	 * Manages the three separate stages of the WXR import process
	 */
	function dispatch() {
		$this->header();

		$step = empty( $_GET['step'] ) ? 0 : (int) $_GET['step'];

		switch ( $step ) {
			case 0:
				$this->greet();
				break;
			case 1:
				check_admin_referer( 'import-attachment-files' );
				set_time_limit(0);
				$this->import();
				break;
		}

		$this->footer();
	}

	/**
	 * The main controller for the actual import stage.
	 *
	 * @param string $file Path to the WXR file for importing
	 */
	function import() {
		add_filter( 'import_post_meta_key', array( $this, 'is_valid_meta_key' ) );
		add_filter( 'http_request_timeout', array( &$this, 'bump_request_timeout' ) );

		$this->import_start();

		wp_suspend_cache_invalidation( true );
		$this->process_posts();
		wp_suspend_cache_invalidation( false );

		$this->import_end();
	}

	/**
	 * Parses the WXR file and prepares us for the task of processing parsed data
	 *
	 * @param string $file Path to the WXR file for importing
	 */
	function import_start() {
		if (empty($_POST['server_url'])) {
			echo '<p><strong>' . __( 'An error occured.', 'attachment-files-importer' ) . '</strong><br />';
			echo __( 'The server URL is not valid, please try again.', 'attachment-files-importer' ) . '</p>';
			$this->footer();
			die();
		}
		$import_data = $this->get_import_data();

		$this->posts = $import_data['posts'];
		$this->base_url = untrailingslashit(esc_url($_POST['server_url']));

		if (isset($_POST['thumbnails']))
			$this->image_sizes_to_process = $_POST['thumbnails'];

		do_action( 'import_start' );
	}

	/**
	 * Performs post-import cleanup of files and the cache
	 */
	function import_end() {
		wp_cache_flush();

		echo '<p>' . __( 'All done.', 'attachment-files-importer' ) . ' <a href="' . admin_url() . '">' . __( 'Have fun!', 'attachment-files-importer' ) . '</a>' . '</p>';

		do_action( 'import_end' );
	}

	/**
	 * Create new posts based on import information
	 *
	 * Posts marked as having a parent which doesn't exist will become top level items.
	 * Doesn't create a new post if: the post type doesn't exist, the given post ID
	 * is already noted as imported or a post with the same title and date already exists.
	 * Note that new/updated terms, comments and meta are imported for the last of the above.
	 */
	function process_posts() {
		$this->posts = apply_filters( 'attachment_files_importer_posts', $this->posts );

		$stats = array(
			'imported' => 0,
			'already_exists' => 0,
			'other_error' => 0,
		);

		foreach ( $this->posts as $post ) {
			$post = apply_filters( 'attachment_files_importer_post_data_raw', $post );

			if ( $post['status'] == 'auto-draft' || 'attachment' != $post['post_type'] )
				continue;

			$local_url = ! empty($post['attachment_url']) ? $post['attachment_url'] : $post['guid'];
			$remote_url = $this->base_url . parse_url($local_url, PHP_URL_PATH);

			// try to use _wp_attached file for upload folder placement to ensure the same location as the export site
			// e.g. location is 2003/05/image.jpg but the attachment post_date is 2010/09, see media_handle_upload()
			$postdata = array('upload_date' => $post['post_date']);
			if ( isset( $post['postmeta'] ) ) {
				foreach( $post['postmeta'] as $meta_key => $arr_value ) {
					if ( $meta_key == '_wp_attached_file' ) {
						if ( preg_match( '%^[0-9]{4}/[0-9]{2}%', $arr_value[0], $matches ) )
							$postdata['upload_date'] = $matches[0];
						break;
					}
				}
			}

			$process_result = $this->process_attachment( $postdata, $remote_url );

			if (is_wp_error($process_result)) {
				if (
					array_key_exists('upload_dir_error', $process_result->errors)
					&& in_array('File already exists', $process_result->errors['upload_dir_error'])
				) {
					$stats['already_exists']++;
					if (ATTACHMENT_FILES_IMPORT_DEBUG) {
						echo '<div style="background-color:#bbbbff;color:blue;">File already exists<br><a href="'.$remote_url.'" target="_blank">'.$remote_url.'</a></div>';
					}
				}
				else{
					$stats['other_error']++;
					echo '<div style="background-color:#ffbbbb;color:red;">';
					$errors = array();
					foreach ($process_result->errors as $errors_key => $errors_value) {
						$errors = array_merge($errors, $errors_value);
					}
					echo implode(', ', $errors).'<br><a href="'.$remote_url.'" target="_blank">'.$remote_url.'</a></div>';
				}
			}
			else{
				$stats['imported']++;
				if (ATTACHMENT_FILES_IMPORT_DEBUG) {
					echo '<div style="background-color:#bbffbb;color:green;">File imported<br><a href="'.$remote_url.'" target="_blank">'.$remote_url.'</a></div>';
				}

				// Process image sizes.
				$this->process_image_sizes($post['post_id']);
			}
		}

		$file_html_link = '<a href="'.$this->base_url.'" target="_blank">'.$this->base_url.'</a>';
		$msg = array();

		echo '<p>';
		switch($stats['imported']) {
			case 0:
				$msg[] = sprintf(__('No file has been imported from %s.', 'attachment-files-importer'), $file_html_link);
				break;
			case 1:
				$msg[] = sprintf(__('One file has been imported from %s.', 'attachment-files-importer'), $file_html_link);
				break;
			default:
				$msg[] = sprintf(__('%s files has been imported from %s.', 'attachment-files-importer'), $stats['imported'], $file_html_link);
		}
		switch($stats['already_exists']) {
			case 0:
				break;
			case 1:
				$msg[] = __('One file was already there.', 'attachment-files-importer');
				break;
			default:
				$msg[] = sprintf(__('%s files were already there.', 'attachment-files-importer'), $stats['already_exists']);
		}
		$msg[] = __('You\'re media library is synced!', 'attachment-files-importer');
		echo implode('<br>', $msg);
		echo '</p>';

		unset( $this->posts );
	}

	/**
	 * If fetching attachments is enabled then attempt to create a new attachment
	 *
	 * @param array $post Attachment post details from WXR
	 * @param string $url URL to fetch attachment from
	 * @return int|WP_Error Post ID on success, WP_Error otherwise
	 */
	function process_attachment( $post, $url ) {
		// if the URL is absolute, but does not contain address, then upload it assuming base_site_url
		if ( preg_match( '|^/[\w\W]+$|', $url ) )
			$url = rtrim( $this->base_url, '/' ) . $url;

		$upload = $this->fetch_remote_file( $url, $post );
		if ( is_wp_error( $upload ) )
			return $upload;

		if ( wp_check_filetype( $upload['file'] ) )
			return true;
		else
			return new WP_Error( 'attachment_processing_error', __('Invalid file type', 'attachment-files-importer') );
	}

	/**
	 * Create the different format sizes of an image attachment. Inpired by "ajax-thumbnail-rebuild" plugin.
	 */
	function process_image_sizes($id) {
		set_time_limit( 30 );

		$sizes = $this->get_image_sizes();
		$sizes = apply_filters( 'intermediate_image_sizes_advanced', $sizes );

		$file = get_attached_file( $id );

		foreach ($sizes as $size => $size_data ) {
			// If the image size has been checked for resize.
			if (array_search($size, $this->image_sizes_to_process) !== false) {
				image_make_intermediate_size( $file, $size_data['width'], $size_data['height'], $size_data['crop'] );
			}
		}
	}

	/**
	 * Got from "ajax-thumbnail-rebuild" plugin.
	 *
	 * @return array Images sizes infos.
	 */
	function get_image_sizes() {
		global $_wp_additional_image_sizes;

		foreach ( get_intermediate_image_sizes() as $s ) {
			$sizes[$s] = array( 'name' => '', 'width' => '', 'height' => '', 'crop' => FALSE );

			/* Read theme added sizes or fall back to default sizes set in options... */

			$sizes[$s]['name'] = $s;

			if ( isset( $_wp_additional_image_sizes[$s]['width'] ) )
				$sizes[$s]['width'] = intval( $_wp_additional_image_sizes[$s]['width'] ); 
			else
				$sizes[$s]['width'] = get_option( "{$s}_size_w" );

			if ( isset( $_wp_additional_image_sizes[$s]['height'] ) )
				$sizes[$s]['height'] = intval( $_wp_additional_image_sizes[$s]['height'] );
			else
				$sizes[$s]['height'] = get_option( "{$s}_size_h" );

			if ( isset( $_wp_additional_image_sizes[$s]['crop'] ) )
				$sizes[$s]['crop'] = intval( $_wp_additional_image_sizes[$s]['crop'] );
			else
				$sizes[$s]['crop'] = get_option( "{$s}_crop" );
		}

		return $sizes;
	}

	/**
	 * Check if the file to download already exists
	 *
	 * @param string $filename
	 * @param string $time Optional. Time formatted in 'yyyy/mm'.
	 * @return boolean
	 */
	function is_file_exists( $filename, $time = null ) {
		// get the upload dir
		$upload = wp_upload_dir($time);

		if ( $upload['error'] )
			return new WP_Error( 'upload_dir_error', $upload['error'] );

		$dir = $upload['path'];

		// sanitize the file name before we begin processing
		$filename = sanitize_file_name($filename);

		return file_exists( $dir . "/$filename" );
	}

	/**
	 * Attempt to download a remote file attachment
	 *
	 * @param string $url URL of item to fetch
	 * @param array $post Attachment details
	 * @return array|WP_Error Local file location details on success, WP_Error otherwise
	 */
	function fetch_remote_file( $url, $post ) {
		// extract the file name and extension from the url
		$file_name = basename( $url );

		// get placeholder file in the upload dir if it doesn't already exists
		if ( $this->is_file_exists( $file_name, $post['upload_date'] ) ) {
			$upload['error'] = __('File already exists', 'attachment-files-importer');
		}
		else {
			$upload = wp_upload_bits( $file_name, 0, '', $post['upload_date'] );
		}

		if ( $upload['error'] )
			return new WP_Error( 'upload_dir_error', $upload['error'] );

		// fetch the remote url and write it to the placeholder file
		$headers = wp_get_http( $url, $upload['file'] );

		// request failed
		if ( ! $headers ) {
			@unlink( $upload['file'] );
			return new WP_Error( 'import_file_error', __('Remote server did not respond', 'attachment-files-importer') );
		}

		// make sure the fetch was successful
		if ( $headers['response'] != '200' ) {
			@unlink( $upload['file'] );
			return new WP_Error( 'import_file_error', sprintf( __('Remote server returned error response %1$d %2$s', 'attachment-files-importer'), esc_html($headers['response']), get_status_header_desc($headers['response']) ) );
		}

		$filesize = filesize( $upload['file'] );

		if ( isset( $headers['content-length'] ) && $filesize != $headers['content-length'] ) {
			@unlink( $upload['file'] );
			return new WP_Error( 'import_file_error', __('Remote file is incorrect size', 'attachment-files-importer') );
		}

		if ( 0 == $filesize ) {
			@unlink( $upload['file'] );
			return new WP_Error( 'import_file_error', __('Zero size file downloaded', 'attachment-files-importer') );
		}

		$max_size = (int) $this->max_attachment_size();
		if ( ! empty( $max_size ) && $filesize > $max_size ) {
			@unlink( $upload['file'] );
			return new WP_Error( 'import_file_error', sprintf(__('Remote file is too large, limit is %s', 'attachment-files-importer'), size_format($max_size) ) );
		}

		return $upload;
	}

	/**
	 * Get the data of the attachment files to import, same format as a WXR file.
	 *
	 * @return array Information gathered from the DB
	 */
	function get_import_data() {
		$post_attachments = get_posts(array(
			'posts_per_page' => -1,
			'post_type' => 'attachment',
		));
		$import_data = array(
			'posts' => array(),
		);
		for ($k=0;$k<count($post_attachments);$k++) {
			$userdata = get_userdata($post_attachments[$k]->post_author);
			$import_data['posts'][] = array(
				'post_title' => $post_attachments[$k]->post_title,
				'guid' => $post_attachments[$k]->guid,
				'post_author' => $userdata->user_login,
				'post_content' => $post_attachments[$k]->post_content,
				'post_id' => $post_attachments[$k]->ID,
				'post_date' => $post_attachments[$k]->post_date,
				'post_date_gmt' => $post_attachments[$k]->post_date_gmt,
				'comment_status' => $post_attachments[$k]->comment_status,
				'ping_status' => $post_attachments[$k]->ping_status,
				'post_name' => $post_attachments[$k]->post_name,
				'status' => $post_attachments[$k]->post_status,
				'post_parent' => $post_attachments[$k]->post_parent,
				'menu_order' => $post_attachments[$k]->menu_order,
				'post_type' => $post_attachments[$k]->post_type,
				'post_password' => $post_attachments[$k]->post_password,
				'is_sticky' => is_sticky($post_attachments[$k]->ID)?1:0,
				'attachment_url' => wp_get_attachment_url($post_attachments[$k]->ID),
				'postmeta' => get_post_meta($post_attachments[$k]->ID), // Not the same format.
			);
		}
		return $import_data;
	}

	// Display import page title
	function header() {
		echo '<div class="wrap">';
		screen_icon();
		echo '<h2>' . __( 'Import Attachment Files', 'attachment-files-importer' ) . '</h2>';

		$updates = get_plugin_updates();
		$basename = plugin_basename(__FILE__);
		if ( isset( $updates[$basename] ) ) {
			$update = $updates[$basename];
			echo '<div class="error"><p><strong>';
			printf( __( 'A new version of this importer is available. Please update to version %s to ensure compatibility with newer export files.', 'attachment-files-importer' ), $update->update->new_version );
			echo '</strong></p></div>';
		}
	}

	// Close div.wrap
	function footer() {
		echo '</div>';
	}

	/**
	 * Display introductory text and file upload form
	 */
	function greet() {
		echo '<div class="narrow">';
		echo '<p>'.__( 'Indicate the URL of the server that contain the attachment files to import. You can also specified image sizes for automatic resizes or use later use a plugin like <a href="http://wordpress.org/plugins/ajax-thumbnail-rebuild">AJAX Thumbnail Rebuild</a> to perform the resizes of the originals images.', 'attachment-files-importer' ).'</p>';
		echo '<h4>'.__( 'Enter the URL of the server, then click Import.', 'attachment-files-importer' ).'</h4>';
		
		$bytes = apply_filters( 'import_upload_size_limit', wp_max_upload_size() );
		$size = size_format( $bytes );
		$upload_dir = wp_upload_dir();
		if ( ! empty( $upload_dir['error'] ) ) :
?>
			<div class="error"><p><?php _e('Before you can import files, you will need to fix the following error:', 'attachment-files-importer'); ?></p>
			<p><strong><?php echo $upload_dir['error']; ?></strong></p></div>
<?php
		else :
?>
			<form method="post" action="<?php echo esc_attr(wp_nonce_url('admin.php?import=attachment-files&amp;step=1', 'import-attachment-files')); ?>">
				<p>
					<label for="server_url"><?php _e( 'Server URL (e.g. <em>http://mywordpress.com</em>):', 'attachment-files-importer' ); ?></label>
					<input type="text" id="server_url" name="server_url" />
					<input type="hidden" name="action" value="save" />
				</p>
				<h4><?php _e('Select which image sizes you want to rebuild', 'attachment-files-importer'); ?>:</h4>
				<a href="javascript:void(0);" onclick="if(typeof afi_toggle=='undefined'){afi_toggle=true;}afi_toggle=!afi_toggle;jQuery('#sizeselect input').each(function(){this.checked=afi_toggle;});" id="size-toggle"><?php _e('Toggle all', 'attachment-files-importer'); ?></a>
				<div id="sizeselect">
					<?php
						foreach ($this->get_image_sizes() as $s):
					?>
							<label>
								<input type="checkbox" name="thumbnails[]" id="sizeselect" checked="checked" value="<?php echo $s['name'] ?>">
								<em><?php echo $s['name'] ?></em>
								&nbsp;(<?php echo $s['width'] ?>x<?php echo $s['height'] ?>
								<?php if ($s['crop']) _e('cropped', 'attachment-files-importer'); ?>)
							</label>
							<br/>
					<?php
						endforeach;
					?>
				</div>
				<?php submit_button( __('Import and resize', 'attachment-files-importer'), 'button' ); ?>
			</form>
<?php
		endif;

		echo '</div>';
	}

	/**
	 * Decide if the given meta key maps to information we will want to import
	 *
	 * @param string $key The meta key to check
	 * @return string|bool The key if we do want to import, false if not
	 */
	function is_valid_meta_key( $key ) {
		// skip attachment metadata since we'll regenerate it from scratch
		// skip _edit_lock as not relevant for import
		if ( in_array( $key, array( '_wp_attached_file', '_wp_attachment_metadata', '_edit_lock' ) ) )
			return false;
		return $key;
	}

	/**
	 * Decide what the maximum file size for downloaded attachments is.
	 * Default is 0 (unlimited), can be filtered via import_attachment_size_limit
	 *
	 * @return int Maximum attachment file size to import
	 */
	function max_attachment_size() {
		return apply_filters( 'import_attachment_size_limit', 0 );
	}

	/**
	 * Added to http_request_timeout filter to force timeout at 60 seconds during import
	 * @return int 60
	 */
	function bump_request_timeout() {
		return 60;
	}

	// return the difference in length between two strings
	function cmpr_strlen( $a, $b ) {
		return strlen($b) - strlen($a);
	}
}

} // class_exists( 'WP_Importer' )

function attachment_files_importer_init() {
	load_plugin_textdomain( 'attachment-files-importer', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	// echo('<pre>');var_dump(dirname( plugin_basename( __FILE__ ) ) . '/languages');exit;

	/**
	 * Attachment Files Importer object for registering the import callback
	 * @global AF_Import $af_import
	 */
	$GLOBALS['af_import'] = new AF_Import();
	register_importer( 'attachment-files', 'Attachment files', __('Import attachment files from an external server based on the attachment entries already presents in your Wordpress installation.', 'attachment-files-importer'), array( $GLOBALS['af_import'], 'dispatch' ) );
}
add_action( 'admin_init', 'attachment_files_importer_init' );
