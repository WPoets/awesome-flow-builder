<?php
namespace aw2\wp;

\aw2_library::add_service('wp.image_resize','Will resize the image file using WordPress functions',['namespace'=>__NAMESPACE__]);


/**
 * Resolve an image URL (absolute or relative, on this site or its uploads host)
 * to a real, on-disk file path — safely.
 *
 * Handles:
 *  - absolute URLs (http/https), protocol-relative URLs ("//host/..."), and
 *    plain relative paths
 *  - installs where docroot != ABSPATH (subdirectory installs)
 *  - uploads served from a different host/basedir (wp_upload_dir() mapping,
 *    e.g. mapped upload paths or a URL-rewriting CDN plugin that still points
 *    at the same physical basedir)
 *  - URL-encoded characters in the path (spaces, unicode filenames, etc.)
 *  - path traversal ("../..") and files outside the allowed roots
 *
 * Returns the resolved real path on success, or false if the URL can't be
 * safely mapped to a local file (e.g. it points at a genuinely external host).
 */
function image_url_to_path( $url ) {

	if ( empty( $url ) || ! is_string( $url ) ) {
		return false;
	}

	// Normalize protocol-relative URLs ("//example.com/x.jpg") so parse_url() gets a host.
	if ( strpos( $url, '//' ) === 0 ) {
		$url = ( is_ssl() ? 'https:' : 'http:' ) . $url;
	}

	$parsed = parse_url( $url );
	if ( $parsed === false || empty( $parsed['path'] ) ) {
		return false;
	}

	// Decode %20 etc. so file_exists()/realpath() see the real filename.
	$path = urldecode( $parsed['path'] );

	$upload_dir  = wp_upload_dir();
	$site_host   = parse_url( home_url(), PHP_URL_HOST );
	$upload_host = parse_url( $upload_dir['baseurl'], PHP_URL_HOST );

	// If the URL carries a host, it must be this site's host or its uploads host.
	// Otherwise we'd be resolving an arbitrary external URL's path against our
	// own filesystem, which is both wrong and a local-file-disclosure risk.
	if ( ! empty( $parsed['host'] ) ) {
		$allowed_hosts = array_map( 'strtolower', array_filter( [ $site_host, $upload_host ] ) );
		if ( ! in_array( strtolower( $parsed['host'] ), $allowed_hosts, true ) ) {
			return false;
		}
	}

	// Prefer mapping via the uploads baseurl -> basedir (covers most images,
	// including setups where uploads are mapped to a non-default directory).
	$upload_url_path = parse_url( $upload_dir['baseurl'], PHP_URL_PATH );

	if ( $upload_url_path && strpos( $path, $upload_url_path ) === 0 ) {
		$file_path = $upload_dir['basedir'] . substr( $path, strlen( $upload_url_path ) );
	} else {
		// Fall back to home_url() -> ABSPATH, correctly handling subdirectory installs
		// (e.g. https://example.com/blog/ -> /var/www/blog/) instead of assuming
		// the web root equals ABSPATH.
		$home_path = parse_url( home_url(), PHP_URL_PATH );
		$home_path = $home_path ? untrailingslashit( $home_path ) : '';

		if ( $home_path && strpos( $path, $home_path ) === 0 ) {
			$path = substr( $path, strlen( $home_path ) );
		}
		$file_path = untrailingslashit( ABSPATH ) . $path;
	}

	// Collapse any ".." segments and confirm the file actually exists.
	$real_path = realpath( $file_path );
	if ( $real_path === false ) {
		return false;
	}

	// Belt and braces: the resolved file must live inside the uploads dir or
	// ABSPATH, never outside it (blocks path-traversal payloads in image_url).
	$allowed_roots = array_filter( [
		realpath( $upload_dir['basedir'] ),
		realpath( ABSPATH ),
	] );

	foreach ( $allowed_roots as $root ) {
		if ( $root && strpos( $real_path, $root ) === 0 ) {
			return $real_path;
		}
	}

	return false;
}


function image_resize($atts,$content=null,$shortcode = array()){
	
	if(\aw2_library::pre_actions('all',$atts,$content,$shortcode)==false)return;
	
	extract( \aw2_library::shortcode_atts(array(
	'image_url' =>'',
	'width' =>'',
	'height' =>'',
	'crop'=>'no'
	), $atts ) );
	
	if(empty( $image_url )){
		\aw2_library::set_error('one of image_url or image_path is required'); 
		return '';
	}
	
	$crop = ( $crop === true || $crop === 'yes' );

	$blank_response = array(
		'url' => '#',
		'path' => '',
		'width' => -1,
		'height' => -1
	  );

	// Width/height must be positive integers — an empty/non-numeric value would
	// otherwise silently produce a 0x0 (or garbage-named) resize target below.
	$width  = absint( $width );
	$height = absint( $height );
	if ( $width < 1 || $height < 1 ) {
		\aw2_library::set_error('width and height must both be positive numbers');
		return $blank_response;
	}

	$image_found=false;

	// Resolve the (possibly absolute) URL to a real local file path.
	$image_path = image_url_to_path( $image_url );

	if ( ! $image_path ) {
		// Don't leak the resolved server path in the error for external/invalid URLs.
		\aw2_library::set_error('Invalid, missing, or unresolvable image URL');
		return $blank_response;
	}

	$orig_size = @getimagesize($image_path);
	if ($orig_size === false) {
		\aw2_library::set_error('Unable to retrieve image size for: ' . $image_url);
		return $blank_response;
	}

	$image_src[0] = $image_url;    
    $image_src[1] = $orig_size[0];
    $image_src[2] = $orig_size[1];
	
	// default output - without resizing
	$vt_image = array (
		'url' => $image_src[0],
		'path' => $image_path,
		'width' => $image_src[1],
		'height' => $image_src[2]
	);

	$file_info = pathinfo( $image_path );
	$extension='';
	if(isset($file_info['extension']))
		$extension = '.'. $file_info['extension'];

	// the image path without the extension
	$no_ext_path = $file_info['dirname'].'/'.$file_info['filename'];

	$cropped_img_path = $no_ext_path.'-'.$width.'x'.$height.$extension;
  
    if ( file_exists( $cropped_img_path ) ) {

      $cropped_img_url = str_replace( basename( $image_src[0] ), basename( $cropped_img_path ), $image_src[0] );
      
      $vt_image = array (
        'url' => $cropped_img_url,
		'path' => $cropped_img_path,
        'width' => $width,
        'height' => $height
      );
      $image_found=true;
    }
	
	$final_img_path = $cropped_img_path;
	
	if ( $crop == false ) {
    
      // calculate the size proportionaly
      $proportional_size = wp_constrain_dimensions( $image_src[1], $image_src[2], $width, $height );
      $resized_img_path = $no_ext_path.'-'.$proportional_size[0].'x'.$proportional_size[1].$extension;      

      // checking if the file already exists
      if ( file_exists( $resized_img_path ) ) {
      
        $resized_img_url = str_replace( basename( $image_src[0] ), basename( $resized_img_path ), $image_src[0] );
		$new_img_size = @getimagesize( $resized_img_path );

		if ( $new_img_size !== false ) {
			$vt_image = array (
			  'url' => $resized_img_url,
			  'path' => $resized_img_path,
			  'width' => $new_img_size[0],
			  'height' => $new_img_size[1]
			);
			$image_found=true;
		}
      }
	  
	  $final_img_path = $resized_img_path;
    }
  // checking if the file size is larger than the target size if it is smaller or the same size, stop right here and return default
  if ( !$image_found && ($image_src[1] > $width || $image_src[2] > $height )) {

	$target_dir = dirname( $final_img_path );
	if ( ! is_writable( $target_dir ) ) {
		\aw2_library::set_error('Target directory is not writable: cannot save resized image');
		return $vt_image;
	}

	$editor = wp_get_image_editor( $image_path, array() );
	if (!is_wp_error($editor)) {

		// Resize the image.
		$result = $editor->resize($width, $height, $crop);

		// If there's no problem, save it; otherwise, print the problem.
		if (!is_wp_error($result)) {
			$saved = $editor->save($final_img_path);

			if ( is_wp_error( $saved ) ) {
				\aw2_library::set_error( 'Failed to save resized image: ' . $saved->get_error_message() );
				return $vt_image;
			}

			$new_img = str_replace( basename( $image_src[0] ), basename( $final_img_path ), $image_src[0] );
			$new_img_size = @getimagesize( $final_img_path );

			if ( $new_img_size !== false ) {
				// resized output
				$vt_image = array (
					'url' => $new_img,
					'path' => $final_img_path,
					'width' => $new_img_size[0],
					'height' => $new_img_size[1]
				);
			}
		} else {
			\aw2_library::set_error( 'Image resize failed: ' . $result->get_error_message() );
		}
	} else {
		\aw2_library::set_error( 'Could not load image editor: ' . $editor->get_error_message() );
	}

  }
  
	$return_value=\aw2_library::post_actions('all',$vt_image,$atts);
	return $return_value;
  
}
