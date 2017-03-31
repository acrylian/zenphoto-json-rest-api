<?php

/**
 * A JSON REST API for ZenPhoto.  Supports building mobile apps,
 * javascript-heavy web apps, and other types of integrations.
 *
 * This filter will detect a query string parameter (?api) and
 * return a JSON representation of the album or search results
 * instead of the normal HTML response.
 *
 * This is read-only.  It cannot create or modify albums or images.
 *
 * @author Dean Moses (deanmoses)
 * @package plugins
 * @subpackage development
 */
$plugin_is_filter = 900 | FEATURE_PLUGIN;
$plugin_description = gettext('REST API for Zenphoto');
$plugin_author = "Dean Moses (deanmoses)";
$plugin_version = '0.2.0';

// Handle REST API calls before anything else
// This is necessary because it sets response headers that are different from Zenphoto's normal ones  
if (!OFFSET_PATH && isset($_GET['json'])) {
	zp_register_filter('load_theme_script', 'do_rest_api', 9999);
}

/**
 * Respond to the request with JSON rather than the normal HTML
 */
function do_rest_api() {
	global $_zp_gallery, $_zp_current_album, $_zp_current_image, $_zp_albums,$_zp_current_search,$_zp_current_context;
	header('Content-type: application/json; charset=UTF-8');

	// If the request is coming from a subdomain, send the headers
	// that allow cross domain AJAX.  This is important when the web 
	// front end is being served from sub.domain.com, but its AJAX
	// requests are hitting this zenphoto installation on domain.com

	// Browsers send the Origin header only when making an AJAX request
	// to a different domain than the page was served from.  Format: 
	// protocol://hostname that the web app was served from.  In most 
	// cases it'll be a subdomain like http://cdn.zenphoto.com
    if (isset($_SERVER['HTTP_ORIGIN'])) {
    	// The Host header is the hostname the browser thinks it's 
    	// sending the AJAX request to. In most casts it'll be the root 
    	// domain like zenphoto.com

    	// If the Host is a substring within Origin, Origin is most likely a subdomain
    	// Todo: implement a proper 'endsWith'
        if (strpos($_SERVER['HTTP_ORIGIN'], $_SERVER['HTTP_HOST']) !== false) {
        	// Allow CORS requests from the subdomain the ajax request is coming from
        	header("Access-Control-Allow-Origin: {$_SERVER['HTTP_ORIGIN']}");

        	// Allow credentials to be sent in CORS requests. 
        	// Really only needed on requests requiring authentication
        	header('Access-Control-Allow-Credentials: true');
        }
    }

    // Add a Vary header so that browsers and CDNs know they need to cache different
	// copies of the response when browsers send different Origin headers.  
	// This allows us to have clients on foo.zenphoto.com and bar.zenphoto.com, 
	// and the CDN will cache different copies of the response for each of them, 
	// with the appropriate Access-Control-Allow-Origin header set.
	header('Vary: Origin', false /* Allow for multiple Vary headers because other things could be adding a Vary as well. */);

	$_zp_gallery_page = 'rest_api.php';

	// the data structure we will be returning via JSON
	$ret = array();
	
	// If there's a search, return it instead of albums
	if ($_zp_current_search) {
		$ret['thumb_size'] = (int) getOption('thumb_size');
		
		$_zp_current_search->setSortType('date');
		$_zp_current_search->setSortDirection('DESC');
		
		// add search results that are images
		$imageResults = array();
		$images = $_zp_current_search->getImages();
		foreach ($images as $image) {
			$imageIndex = $_zp_current_search->getImageIndex($image['folder'], $image['filename']);
			$imageObject = $_zp_current_search->getImage($imageIndex);
			$imageResults[] = to_image($imageObject);
		}
		if ($imageResults) {
			$ret['images'] = $imageResults;
		}
		
		// add search results that are albums
		$albumResults = array();
		while (next_album()) {
			$albumResults[] = to_album_thumb($_zp_current_album);
		}
		if ($albumResults) {
			$ret['albums'] = $albumResults;
		}
	}
	// Else if the system in the context of an image. Return info about the image.
	else if ($_zp_current_image) {
		$ret['image'] = to_image($_zp_current_image);
	}
	// Else if the system is in the context of an album. Return info about the album.
	else if ($_zp_current_album) {
		if (!$_zp_current_album->exists) {
			http_response_code(404);
			$ret['error'] = true;
			$ret['status'] = 404;
			$ret['message'] = "Album $_zp_current_album->name does not exist.";
			print(json_encode($ret));
			exitZP();
		}
		$ret['path'] = $_zp_current_album->name;
		$ret['title'] = $_zp_current_album->getTitle();
		if ($_zp_current_album->getCustomData()) $ret['summary'] = $_zp_current_album->getCustomData();
		if ($_zp_current_album->getDesc()) $ret['description'] = $_zp_current_album->getDesc();
		if (!(boolean) $_zp_current_album->getShow()) $ret['unpublished'] = true;
		$ret['image_size'] = (int) getOption('image_size');
		$ret['thumb_size'] = (int) getOption('thumb_size');
		
		$thumb_path = $_zp_current_album->get('thumb');
		if (!is_numeric($thumb_path)) {
			$ret['thumb'] = $thumb_path;
		}
		
		//format:  2014-11-24 01:40:22
		$a = strptime($_zp_current_album->getDateTime(), '%Y-%m-%d %H:%M:%S');
		$ret['date'] = mktime($a['tm_hour'], $a['tm_min'], $a['tm_sec'], $a['tm_mon']+1, $a['tm_mday'], $a['tm_year']+1900);
	
		// Add info about this albums' subalbums
		$albums = array();
		while (next_album()):
			$albums[] = to_album_thumb($_zp_current_album);
		endwhile;
		if ($albums) {
			$ret['albums'] = $albums;
		}
	
		// Add info about this albums' images
		$images = array();
		while (next_image()):
			$images[] = to_image($_zp_current_image);
		endwhile;
		if ($images) {
			$ret['images'] = $images;
		}
		
		// Add info about parent album
		$parentAlbum = to_related_album($_zp_current_album->getParent());
		if ($parentAlbum) {
			$ret['parent_album'] = $parentAlbum; // would like to use 'parent' but that's a reserved word in javascript
		}
		
		// Add info about next album
		$nextAlbum = to_related_album($_zp_current_album->getNextAlbum());
		if ($nextAlbum) {
			$ret['next'] = $nextAlbum;
		}
		
		// Add info about prev album
		$prevAlbum = to_related_album($_zp_current_album->getPrevAlbum());
		if ($prevAlbum) {
			$ret['prev'] = $prevAlbum;
		}
	}
	// Else if no current search, image or album, return info about the root albums of the site
	else {
		$ret['image_size'] = (int) getOption('image_size');
		$ret['thumb_size'] = (int) getOption('thumb_size');

		// Get the top-level albums
	   	$subAlbumNames = $_zp_gallery->getAlbums();
		if (is_array($subAlbumNames)) {
			$subAlbums = array();
			foreach ($subAlbumNames as $subAlbumName) {
				$subAlbum = new Album($subAlbumName, $_zp_gallery);
				$subAlbums[] = to_album_thumb($subAlbum);
			}
			if ($subAlbums) {
				$ret['albums'] = $subAlbums;
			}
		}
	}
	
	// Return the results to the client in JSON format
	print(json_encode($ret));
	exitZP();
}

// just enough info about a parent / prev / next album to navigate to it
function to_related_album($album) {
	if ($album) {
		$ret = array();
		$ret['path'] = $album->name;
		$ret['title'] = $album->getTitle();
		$ret['date'] = to_timestamp($album->getDateTime());
		return $ret;
	}
	return;
}

// just enough info about a child album to render its thumbnail
function to_album_thumb($album) {
	$ret = array();
	$ret['path'] = $album->name;
	$ret['title'] = $album->getTitle();
	$ret['date'] = to_timestamp($album->getDateTime());
	if ($album->getCustomData()) $ret['summary'] = $album->getCustomData();
	if (!(boolean) $album->getShow()) $ret['unpublished'] = true;
	$thumbImage = $album->getAlbumThumbImage();
	if ($thumbImage) {
		$ret['urlThumb'] = $album->getAlbumThumbImage()->getThumb();
	}
	
	return $ret;
}

// just enough info about an image to render it on a standalone page
function to_image($image) {
	$ret = array();
	// strip /zenphoto/albums/ so that the path starts something like 2014/...
	$ret['path'] = str_replace('/zenphoto/albums/', '', $image->getFullImage());
	$ret['title'] = $image->getTitle();
	$ret['date'] = to_timestamp($image->getDateTime());
	$ret['description'] = $image->getDesc();
	$ret['urlFull'] = $image->getFullImageURL();
	$ret['urlSized'] = $image->getSizedImage(getOption('image_size'));
	$ret['urlThumb'] = $image->getThumb();
	$ret['width'] = (int) $image->getWidth();
	$ret['height'] = (int) $image->getHeight();
	return $ret;
}

// take a zenphoto date string and turn it into an integer timestamp
function to_timestamp($dateString) {
	$a = strptime($dateString, '%Y-%m-%d %H:%M:%S'); //format:  2014-11-24 01:40:22
	return (int) mktime($a['tm_hour'], $a['tm_min'], $a['tm_sec'], $a['tm_mon']+1, $a['tm_mday'], $a['tm_year']+1900);
}

?>