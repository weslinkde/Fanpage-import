<?php
/**
 * Facebook Fanpage Import Component.
 *
 * Importing Facebook entries
 *
 * @author  mahype, awesome.ug <very@awesome.ug>
 * @package Facebook Fanpage Import
 * @version 1.0.0
 * @since   1.0.0
 * @license GPL 2
 *
 * Copyright 2016 Awesome UG (very@awesome.ug)
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License, version 2, as
 * published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
 */

if( !defined( 'ABSPATH' ) )
{
	exit;
}

class FacebookFanpageImportFacebookStream
{
	var $name;
	var $fb;
	var $app_id;
	var $app_secret;
	var $page_id;
	var $stream_language;
	var $update_interval;
	var $update_num;
	var $errors = array();
	var $notices = array();

	/**
	 * Initializes the Component.
	 *
	 * @since 1.0.0
	 */
	function __construct()
	{
		$this->name = get_class( $this );

		$this->page_id = get_option( 'fbfpi_fanpage_id' );
		$this->stream_language = get_option( 'fbfpi_fanpage_stream_language' );
		$this->update_interval = get_option( 'fbfpi_import_interval' );
		$this->update_num = get_option( 'fbfpi_import_num' );
		$this->link_target = get_option( 'fbfpi_insert_link_target' );

		if( '' == $this->page_id )
		{
			$this->errors[] = sprintf( __( '<a href="%s">Fanpage ID have to be provided.</a>', 'facebook-fanpage-import' ), admin_url( 'tools.php?page=ComponentFacebookFanpageImportAdminSettings' ) );
		}

		if( '' == $this->stream_language )
		{
			$this->stream_language = 'en_US';
		}

		if( '' == $this->update_interval )
		{
			$this->update_interval = 'hourly';
		}

		if( '' == $this->update_num )
		{
			$this->update_num = FALSE;
		}

		// Schedule import if interval set
		if ( $this->update_interval != 'never' )
		{
			if( !wp_next_scheduled( 'fanpage_import' ) )
			{
				wp_schedule_event( time(), $this->update_interval, 'fanpage_import' );
			}
		}
		else
		{
			// get next scheduled event
			$timestamp = wp_next_scheduled( 'fanpage_import' );

			// unschedule it if there is one
			if ( $timestamp !== false ) {
				wp_unschedule_event( $timestamp, 'fanpage_import' );
			}

			// it's not clear whether wp_unschedule_event() clears everything,
			// so remove existing scheduled hook as well
			wp_clear_scheduled_hook( 'fanpage_import' );
		}

		add_action( 'fanpage_import', array( $this, 'import' ) );

		if( array_key_exists( 'bfpi-now', $_POST ) && '' != $_POST[ 'bfpi-now' ] )
		{
			add_action( 'init', array( $this, 'import' ), 12 );
		}

		if( array_key_exists( 'bfpi-next', $_POST ) && '' != $_POST[ 'bfpi-next' ] )
		{
			add_action( 'init', array( $this, 'import' ), 12 );
		}

		if( array_key_exists( 'bfpi-stop', $_POST ) && '' != $_POST[ 'bfpi-stop' ] )
		{
			delete_option( '_facebook_fanpage_import_next' );
		}

		add_action( 'admin_notices', array( $this, 'admin_notices' ) );
	}

	/**
	 * Importing Stream
	 *
	 * @since 1.0.0
	 */
	public function import()
	{
		global $wpdb;

		set_time_limit( 240 );

		$ffbc = new FacebookFanpageConnect( $this->page_id, '', get_locale() );
		$page_details = $ffbc->get_page();

		// get initial posts on first run or via schedule
		if (
			( isset( $_POST ) && array_key_exists( 'bfpi-now', $_POST ) && '' != $_POST[ 'bfpi-now' ] ) OR
			doing_action( 'fanpage_import' )
		) {

			$entries = $ffbc->get_posts( $this->update_num );

		}

		// get paged posts when selecting "next"
		if ( isset( $_POST ) && array_key_exists( 'bfpi-next', $_POST ) && '' != $_POST[ 'bfpi-next' ] ) {

			$url = get_option( '_facebook_fanpage_import_next', '' );
			if ( ! empty( $url ) ) {
				$entries = $ffbc->get_posts_paged( $url );
			}

		}

		// save the "next" page URL
		$paging = $ffbc->get_paging();
		if ( is_object( $paging ) && property_exists( $paging, 'next' ) ) {
			update_option( '_facebook_fanpage_import_next', $paging->next );
		} else {
			delete_option( '_facebook_fanpage_import_next' );
		}

		if( 'status' == get_option( 'fbfpi_insert_post_type' ) )
		{
			$post_type = 'status-message';
		}
		else
		{
			$post_type = 'post';
		}

		$post_status = get_option( 'fbfpi_insert_post_status' );
		if( '' == $post_status )
		{
			$post_status = 'draft';
		}

		$author_id = get_option( 'fbfpi_insert_user_id' );

		$post_format = get_option( 'fbfpi_insert_post_format' );
		if( '' == $post_format )
		{
			$post_format = 'none';
		}

		$i = 0;

		$found_entries = count( $entries );

		if( $found_entries > 0 )
		{
			$skip_existing_count = 0;
			$skip_unknown_count = 0;
			$skip_without_message = 0;

			foreach( $entries AS $entry )
			{

				$sql = $wpdb->prepare( "SELECT COUNT(*) FROM $wpdb->posts AS p, $wpdb->postmeta AS m WHERE p.ID = m.post_id  AND p.post_type='%s' AND p.post_status <> 'trash' AND m.meta_key = '_fbfpi_entry_id'  AND m.meta_value = '%s'", $post_type, $entry->id );
				$post_count = $wpdb->get_var( $sql );

				if( $post_count > 0 ) // If entry already exists
				{
					$skip_existing_count++;
					continue;
				}

				// init picture URL and attach ID
				$picture_url = '';
				$attach_id = '';

				// Get post picture URL (Made here, because needed twice)
				$post_picture = $ffbc->get_post_picture( $entry->id );
				if( property_exists( $post_picture, 'full_picture' ) )
				{
					$picture_url = $post_picture->full_picture;
				}

				// Post title
				$post_title = '';

				if( !property_exists( $entry, 'message' ) )
				{

					if( property_exists( $entry, 'story' ) && '' != $entry->story ) {
						$post_title = $entry->story;
						$entry->message = '';
					} else {
						$post_title = __( 'Untitled post', 'facebook-fanpage-import' );
						$entry->message = '';
					}

				}
				elseif( property_exists( $entry, 'message' ) && '' != $entry->message )
				{
					$post_title = $entry->message;
				}
				elseif( property_exists( $entry, 'description' ) && '' != $entry->description && '' == $post_title )
				{
					$post_title = $entry->description;
				}

				$post_title = $this->filter_title( $post_title );

				$post_excerpt = '';
				if( property_exists( $entry, 'message' ) )
				{
					$post_excerpt = $entry->message;
				}

				// Inserting raw post without content
				$post = array(
					'comment_status' => 'closed',
					// 'closed' means no comments.
					'ping_status'    => 'open',
					// 'closed' means pingbacks or trackbacks turned off
					'post_date'      => date( 'Y-m-d H:i:s', strtotime( $entry->created_time ) ),
					'post_status'    => $post_status,
					//Set the status of the new post.
					'post_title'     => $post_title,
					//The title of your post.
					'post_type'      => $post_type,
					//You may want to insert a regular post, page, link, a menu item or some custom post type
					'post_excerpt'   => $post_excerpt,
					'post_author'    => $author_id
				);

				$post_id = wp_insert_post( $post );
				$post = get_post( $post_id );
				$attach_id = '';

				$entry->message = $this->replace_urls_by_links( $entry->message );

				// Getting Hashtags
				preg_match_all( "/(#\w+)/", $entry->message, $found_hash_tags );
				$found_hash_tags = $found_hash_tags[ 1 ];

				$tags = array();
				foreach( $found_hash_tags AS $hash_tag )
				{
					$tags[] = substr( $hash_tag, 1, strlen( $hash_tag ) );
				}

				if( count( $tags ) > 0 )
				{
					wp_set_post_tags( $post_id, $tags );
				}

				// Post content
				switch ( $entry->type )
				{

					case 'link':
						if( !empty( $picture_url ) )
						{
							$attach_id = $this->fetch_picture( $picture_url, $post_id );
						}

						$post->post_content = $this->get_link_content( $entry, $attach_id );
						break;

					case 'photo':
						$picture_url = $ffbc->get_photo_by_object( $entry->object_id );

						if( !empty( $picture_url ) )
						{
							$attach_id = $this->fetch_picture( $picture_url, $post_id );
						}

						$post->post_content = $this->get_photo_content( $entry, $attach_id );

						if( !empty( $attach_id ) )
						{
							set_post_thumbnail( $post_id, $attach_id );
						}

						break;

					case 'video':
						if( !empty( $entry->picture ) )
						{
							$attach_id = $this->fetch_picture( $entry->picture, $post_id );
						}

						$post->post_content = $this->get_video_content( $entry, $attach_id );

						if( !empty( $attach_id ) )
						{
							set_post_thumbnail( $post_id, $attach_id );
						}

						break;

					case 'status':
						$post->post_content = $entry->message;

						if( !empty( $attach_id ) )
						{
							set_post_thumbnail( $post_id, $attach_id );
						}

						break;

					default:
						$skip_unknown_count++;

						break;
				}
				wp_update_post( $post );

				// assign term if one is set
				$term_id = get_option( 'fbfpi_insert_term_id' );
				if ( $term_id != 'none' ) {
					$cat_ids = array( intval( $term_id ) );
					$term_taxonomy_ids = wp_set_object_terms( $post->ID, $cat_ids, 'category' );
					if ( is_wp_error( $term_taxonomy_ids ) ) {
						// term could not be set
						error_log( print_r( array(
							'method' => __METHOD__,
							'term could not be set' => $term_id,
							'entry' => $entry,
						), true ) );
					}
				}

				// Updating post meta
				$ids = explode( '_', $entry->id );
				$pure_entry_id = $ids[ 1 ];
				$entry_url = $page_details->link . '/posts/' . $pure_entry_id;

				if( property_exists( $entry, 'id' ) )
				{
					update_post_meta( $post_id, '_fbfpi_entry_id', $entry->id );
				}
				if( property_exists( $entry, 'message' ) )
				{
					update_post_meta( $post_id, '_fbfpi_message', $entry->message );
				}
				if( property_exists( $entry, 'description' ) )
				{
					update_post_meta( $post_id, '_fbfpi_description', $entry->description );
				}

				update_post_meta( $post_id, '_fbfpi_image_url', $post_picture );
				update_post_meta( $post_id, '_fbfpi_fanpage_id', $this->page_id );
				update_post_meta( $post_id, '_fbfpi_fanpage_name', $page_details->name );
				update_post_meta( $post_id, '_fbfpi_fanpage_link', $page_details->link );
				update_post_meta( $post_id, '_fbfpi_entry_url', $entry_url );
				update_post_meta( $post_id, '_fbfpi_type', $entry->type );

				if( 'none' != $post_format )
				{
					set_post_format( $post_id, $post_format );
				}

				/**
				 * Allow plugins to do additional processing.
				 *
				 * @param WP_Post $post The post object
				 * @param object $entry The Facebook entry object
				 */
				do_action( 'fbfpi_entry_created', $post, $entry );

				$i++;
			}

			$notice = '<br /><br />' . sprintf( __( '%d entries have been found.', 'facebook-fanpage-import' ), $found_entries );
			$notice .= '<br />' . sprintf( __( '%d entries have been imported.', 'facebook-fanpage-import' ), $i ) . '<br />';

			if( $skip_without_message > 0 )
			{
				$notice .= '<br />' . sprintf( __( '%d skipped because containing no message.', 'facebook-fanpage-import' ), $skip_without_message );
			}

			if( $skip_existing_count > 0 )
			{
				$notice .= '<br />' . sprintf( __( '%d skipped because already existing.', 'facebook-fanpage-import' ), $skip_existing_count );
			}

			if( $skip_unknown_count > 0 )
			{
				$notice .= '<br />' . sprintf( __( '%d skipped because entry type unknown.', 'facebook-fanpage-import' ), $skip_unknown_count );
			}

			$this->notices[] = $notice;
		}
	}

	/**
	 * Filter title
	 *
	 * @param $string
	 *
	 * @return array|mixed
	 */
	private function filter_title( $string )
	{
		$title = explode( ':', $string );
		$title = $title[ 0 ];

		$title = explode( '!', $title );
		$title = $title[ 0 ];

		$title = str_replace( '+', '', $title );

		$title = trim( $title );

		$desired_width = 50;

		if( strlen( $title ) > $desired_width )
		{
			$title = wordwrap( $title, $desired_width );
			$i = strpos( $title, "\n" );
			if( $i )
			{
				$title = substr( $title, 0, $i );
			}
			$title = $title . ' ...';
		}

		/**
		 * Allow overrides.
		 *
		 * @param string $title The filtered title
		 * @param string $string The unfiltered title
		 * @return string $title The filtered title
		 */
		return apply_filters( 'fbfpi_entry_title', $title, $string );
	}

	/**
	 * Replacing URLs with HTML Links
	 *
	 * @param $content
	 *
	 * @return mixed
	 */
	private function replace_urls_by_links( $content )
	{
		$content = preg_replace( '@(https?://([-\w.]+[-\w])+(:\d+)?(/([\w-.~:/?#\[\]\@!$&\'()*+,;=%]*)?)?)@', '<a href="$1" target="_blank">$1</a>', $content );

		return $content;
	}

	/**
	 * Fetching picture
	 *
	 * @param $picture_url
	 * @param $post_id
	 *
	 * @return int
	 */
	private function fetch_picture( $picture_url, $post_id )
	{

		require_once( ABSPATH . 'wp-admin/includes/image.php' );

		$upload_dir = wp_upload_dir();
		$md5 = md5( time() );
		$new_filename = $upload_dir[ 'path' ] . '/fbfpi_' . $md5;
		$new_fileurl = $upload_dir[ 'url' ] . '/fbfpi_' . $md5;

		if( ini_get( 'allow_url_fopen' ) === TRUE || ini_get( 'allow_url_fopen' ) == 1 )
		{
			if( copy( $picture_url, $new_filename ) )
			{
				$image_info = getImageSize( $new_filename );
				$mime_type = $image_info[ 'mime' ];
			}
		}
		else
		{
			$c = curl_init( $picture_url );
			curl_setopt( $c, CURLOPT_RETURNTRANSFER, 1 );
			curl_setopt( $c, CURLOPT_CONNECTTIMEOUT, 3 );
			/***********************************************/
			// you need the curl ssl_opt_verifypeer
			curl_setopt( $c, CURLOPT_SSL_VERIFYPEER, FALSE );
			/***********************************************/
			$imgdata = curl_exec( $c );
			curl_close( $c );

			if( $imgdata )
			{
				// Save to disk
				file_put_contents( $new_filename, $imgdata );

				$f = finfo_open();
				$mime_type = finfo_buffer( $f, $imgdata, FILEINFO_MIME_TYPE );
			}
		}

		switch ( $mime_type )
		{
			case 'image/gif':
				$extension = 'gif';
				break;
			case 'image/jpeg':
				$extension = 'jpg';
				break;
			case 'image/png':
				$extension = 'png';
				break;
			default:
				$extension = 'jpg';
				break;
		}
		rename( $new_filename, $new_filename . '.' . $extension );

		$new_filename = $new_filename . '.' . $extension;
		$new_fileurl = $new_fileurl . '.' . $extension;

		$filetype = wp_check_filetype( basename( $new_filename ), NULL );

		$attachment = array(
			'guid'           => $new_fileurl,
			'post_mime_type' => $filetype[ 'type' ],
			'post_title'     => preg_replace( '/\.[^.]+$/', '', basename( $new_filename ) ),
			'post_content'   => '',
			'post_status'    => 'inherit'
		);

		$attach_id = wp_insert_attachment( $attachment, $new_filename, $post_id );
		$attach_data = wp_generate_attachment_metadata( $attach_id, $new_filename );

		wp_update_attachment_metadata( $attach_id, $attach_data );

		return $attach_id;
	}

	/**
	 * Get link content
	 *
	 * @param $entry
	 * @param $attach_id
	 *
	 * @return string
	 */
	private function get_link_content( $entry, $attach_id )
	{
		$attach_url = wp_get_attachment_url( $attach_id );

		if( property_exists( $entry, 'caption' ) )
		{
			$copyright = '&copy; ' . $entry->caption . ' - ' . $entry->name;
		}
		else
		{
			$copyright = '&copy; ' . $entry->name;
		}

		$content = $entry->message;
		$content .= '<div class="fbfpi_link">';
		if( '' != $attach_url )
		{
			$content .= '<div class="fbfpi_image">';
			$content .= '<a href="' . $entry->link . '" target="' . $this->link_target . '" title="' . $copyright . '"><img src="' . $attach_url . '" title="' . $copyright . '"></a>';
			$content .= '</div>';
		}
		$content .= '<div class="fbfpi_text">';
		$content .= '<h4><a href="' . $entry->link . '" target="' . $this->link_target . '" title="' . $copyright . '">' . $entry->name . '</a></h4>';

		if( property_exists( $entry, 'caption' ) )
		{
			$content .= '<p><small>' . $entry->caption . '</small><br /></p>';
		}

		if( property_exists( $entry, 'description' ) )
		{
			$content .= '<p>' . $entry->description . '</p>';
		}
		$content .= '</div>';
		$content .= '</div>';

		/**
		 * Allow overrides.
		 *
		 * @param string $content The constructed content
		 * @param object $entry The entry object
		 * @param integer $attach_id The numeric ID of the attachment
		 * @return string $content The constructed content
		 */
		return apply_filters( 'fbfpi_entry_link', $content, $entry, $attach_id );
	}

	/**
	 * Get photo content
	 *
	 * @param $entry
	 * @param $attach_id
	 *
	 * @return string
	 */
	private function get_photo_content( $entry, $attach_id )
	{
		$attach_url = wp_get_attachment_url( $attach_id );

		$content = $entry->message;
		$content .= '<div class="fbfpi_photo">';
		$content .= '<img src="' . $attach_url . '">';

		// conditionally add descriptive content
		if (
			property_exists( $entry, 'name' ) OR
			property_exists( $entry, 'link' ) OR
			property_exists( $entry, 'description' )
		) {

			// wrapper
			$content .= '<div class="fbfpi_text">';

			// add name if present
			$title = '';
			if ( property_exists( $entry, 'name' ) ) {
				$title = $entry->name;
			} else {
				$title = __( 'Untitled photo', 'facebook-fanpage-import' );
			}

			// wrap in link if present
			if ( property_exists( $entry, 'link' ) ) {
				$title = '<a href="' . $entry->link . '" target="' . $this->link_target . '">' . $title. '</a>';
			}

			// make heading and add
			$title = '<h4>' . $title . '</h4>';
			$content .= $title;

			// add description if present
			if ( property_exists( $entry, 'description' ) ) {
				$content .= '<p>' . $entry->description . '</p>';
			}

			$content .= '</div>';

		}

		$content .= '</div>';

		/**
		 * Allow overrides.
		 *
		 * @param string $content The constructed content
		 * @param object $entry The entry object
		 * @param integer $attach_id The numeric ID of the attachment
		 * @return string $content The constructed content
		 */
		return apply_filters( 'fbfpi_entry_photo', $content, $entry, $attach_id );
	}

	/**
	 * Get video content
	 *
	 * @param $entry
	 *
	 * @return string
	 */
	private function get_video_content( $entry )
	{

		$content = $entry->message . "\n\n";

		$content .= '<div class="fbfpi_video">';

		// support JetPack's "facebook" shortcode for Facebook videos
		if ( shortcode_exists( 'facebook' ) && false !== strpos( $entry->link, 'www.facebook.com' ) ) {
			$content .= '[facebook url="' . $entry->link . '"]';
		} else {
			$content .= '[embed]' . $entry->link . '[/embed]';
		}
		$content .= '<div class="fbfpi_text">';

		// set a default title if none exists
		if( property_exists( $entry, 'name' ) ) {
			$name = $entry->name;
		} else {
			$name = __( 'Untitled video', 'facebook-fanpage-import' );
		}

		$content .= '<h4><a href="' . $entry->link . '" target="' . $this->link_target . '">' . $name . '</a></h4>';

		if( property_exists( $entry, 'description' ) )
		{
			$content .= '<p>' . $entry->description . '</p>';
		}
		$content .= '</div>';
		$content .= '</div>';

		/**
		 * Allow overrides.
		 *
		 * @param string $content The constructed content
		 * @param object $entry The entry object
		 * @return string $content The constructed content
		 */
		return apply_filters( 'fbfpi_entry_video', $content, $entry );
	}

	/**
	 * Admin notices
	 */
	public function admin_notices()
	{
		if( count( $this->errors ) > 0 ):
			foreach( $this->errors AS $error )
				echo '<div class="updated"><p>' . __( 'Facebook Fanpage Import', 'facebook-fanpage-import' ) . ': ' . $error . '</p></div>';
		endif;

		if( count( $this->notices ) > 0 ):
			foreach( $this->notices AS $notice )
				echo '<div class="updated"><p>' . __( 'Facebook Fanpage Import', 'facebook-fanpage-import' ) . ': ' . $notice . '</p></div>';
		endif;
	}
}

$FacebookFanpageImportFacebookStream = new FacebookFanpageImportFacebookStream();
