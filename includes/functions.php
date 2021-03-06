<?php
// v1.0.2

require 'config.php';
require_once('../magpierss/rss_fetch.inc');


/**
 * Output at the top of both /edition/index.php and /sample/index.php
 */
function lp_page_header() {
	?><!DOCTYPE html>
<meta charset="utf-8">
<html>
<head>
	<title>Little Printer Publication</title>

	<style type="text/css">
<?php
	// Include styles inline as it's more reliable when rendering publications.
	require ('../style.css');
	?>
	</style>

</head>
<body>
	<div id="lp-container">
<?php
}


/**
 * Output at the bottom of both /edition/index.php and /sample/index.php
 */
function lp_page_footer() {
	?>
	</div> <!-- #lp-container -->
</body>
</html><?php
}


/**
 * Generates the HTML for the whole page, for both /edition/ and /sample/.
 * Reads the BBC RSS feed for the given postcode and then prints out
 * the day, the weather description and a picture for the weather
 * from the editions directory.
 *
 * If called from /edition/ then we expect to receive one parameter in the 
 * URL:
 *
 * `local_delivery_time`
 * This will contain the time in the timezone where the Little Printer we're
 * delivering to is based, eg "2013-07-31T19:20:30.45+01:00".
 * We use this to determine if it's the correct day for a delivery. (Which it always is in this weather app
 */
function lp_display_page() {
	global $DELIVERY_DAYS, $EDITION_FOR_SAMPLE;

	// Check everything's OK.
	lp_check_parameters();

	// We ignore timezones, but have to set a timezone or PHP will complain.
	date_default_timezone_set('UTC');

	// We should always receive local_delivery_time but in case we don't,
	// we'll set one. This also makes testing each edition's URL easier.
	if (array_key_exists('local_delivery_time', $_GET)) {
		$local_delivery_time = $_GET['local_delivery_time'];
	} else {
		$local_delivery_time = gmdate('Y-m-d\TH:i:s.0+00:00');
	}

	// Will be either 'edition' or 'sample'.
	$directory_name = basename(getcwd());

	// Work out whether this is a regular edition, or the sample, and what 
	// edition to show (if any).
	if ($directory_name == 'edition') {

		// Which weekday is this Little Printer on?
		$weekday = lp_day_of_week($local_delivery_time);

		if ( ! in_array($weekday, $DELIVERY_DAYS)) {
			// This is a day that there's no delivery.
			http_response_code(204);
			exit;
		}
		$url = BBCRSS . $_GET['postcode']. BBCSUFFIX;
	} else { // 'sample'
		$edition_number = $EDITION_FOR_SAMPLE;
		$url = BBCRSS . SAMPLE_POSTCODE . BBCSUFFIX;
	}
	// Fetch the weather and get the word from the first item
	$rss = fetch_rss($url);
	
	//Emit the unique etag
	lp_etag_header($rss->items[0]['pubdate'], $local_delivery_time);
	header("Content-Type: text/html; charset=utf-8");
	
	$weatherword = $rss->items[0]['title'];
	$dayname = substr($weatherword, 0, strpos($weatherword,":"));
	$weatherword = substr($weatherword, strpos($weatherword,":")+2);
	// Get the text after the word, includes temperatures
	$extratext = substr($weatherword, strpos($weatherword, ",")+1);
	//	RSS parser converts entities, so we have to put them back so they get interpreted properly by the browser
	$extratext = htmlentities($extratext);
	// Get the actual word
	$weatherword = strtolower(substr($weatherword, 0, strpos($weatherword, ",")));
	
	//Display the contents of the corresponding png file
	lp_page_header();
	require lp_directory_path().'includes/header.php';	
	echo '<div class="topwords"><p>'.$dayname.'<br>'.$extratext.'<br>'.$weatherword.'</p></div>';
	// SVG not supported, otherwise we'd do this
	//echo file_get_contents(lp_directory_path().'editions/'.$weatherword.'.svg');

	//Check if file exists for weatherword, otherwise displays whoops.png
	if (file_exists(lp_directory_path().'editions/'.$weatherword.'.png')) {
		echo '<img class="dither" src="http://'.$_SERVER['SERVER_NAME'].lp_directory_url().'editions/'.$weatherword.'.png'.'" />';
	} else {
		echo '<img class="dither" src="http://'.$_SERVER['SERVER_NAME'].lp_directory_url().'editions/whoops.png'.'" />';
	}
		
	require lp_directory_path().'includes/footer.php';	

	lp_page_footer();
	
}


/**
 * Some basic checking to make sure everything's roughly OK before going 
 * any further.
 * Script execution will end here with an error if anything's not OK.
 */
function lp_check_parameters() {
	// 'edition' or 'sample'.
	$directory_name = basename(getcwd());

	// Some checking of parameters first...
	if ( ! in_array($directory_name, array('edition', 'sample'))) {
		lp_fatal_error("This can only be run from either the 'edition' or 'sample' directories, but this is in '$directory_name'.");
	}
	if ($directory_name == 'edition') {
		//Check for required postcode
		if ( ! array_key_exists('postcode', $_GET)) {
			lp_etag_header('postcode_error', gmdate('Y-m-d\TH:i:s.0+00:00'));
			lp_fatal_error(
				"Requests for /edition/ need a postcode, eg '?postcode=HP12'"	
			);
		}
	}

}


/**
 * Gets rid of the timezone part of a date string.
 * @param string $time eg, "2013-07-31T19:20:30.45+01:00".
 * @return string eg "2013-07-31T19:20:30.45".
 */
function lp_local_time($time) {
	return substr($time, 0, -6);
}


/**
 * Get the day of the week from a time_string.
 * @param string $time_string eg, "2013-07-31T19:20:30.45+01:00".
 * @return string Lowercased weekday name. eg 'monday'.
 */
function lp_day_of_week($time_string) {
	// We don't care about the timezone, so get rid of it.
	$time_string = lp_local_time($time_string);

	return strtolower(date('l', strtotime($time_string)));
}


/**
 * Send an ETag header, based on a string and a time.
 * @param mixed $id Probably either an edition number (eg, 1) or 'sample'.
 * @param string $time eg, "2013-07-31T19:20:30.45+01:00".
 */
function lp_etag_header($id, $time) {
	header('ETag: ' . md5($id . date('dmY', lp_local_time($time))));
}


/**
 * Gets the URL path (without domain) to this directory.
 * @return string eg, '/lp-php-partwork/edition/../'
 */
function lp_directory_url() {
	return dirname($_SERVER['PHP_SELF']) . "/../";
}


/**
 * Gets the full filesystem path to this directory.
 * @return string eg, '/users/home/phil/web/public/lp-php-partwork/edition/../'
 */
function lp_directory_path() {
	return $_SERVER['DOCUMENT_ROOT'] . lp_directory_url();
}


/**
 * Generate the path to the edition file we want to display.
 *
 * @param int $edition_number The 1-based number of the edition we're displaying.
 * @returns mixed FALSE if there's no file for this $edition_number, or an array.
 *		The array will have a first element of either 'image' or 'file', and a
 *		second element of either the image's URL, or the path to the file.
 */
function lp_get_edition_file_path($edition_number) {
	if (file_exists(lp_directory_path()."editions/$edition_number.png")) {
		return array(
			'image',
			"http://".$_SERVER['SERVER_NAME'].lp_directory_url()."editions/$edition_number.png");

	} else if (file_exists(lp_directory_path()."editions/$edition_number.html")) {
		return array('file', lp_directory_path()."editions/$edition_number.html");

	# We'll be nice and make it work for PHP files too:
	} else if (file_exists(lp_directory_path()."editions/$edition_number.php")) {
		return array('file', lp_directory_path()."editions/$edition_number.php");

	} else {
		return FALSE;
	}
}


/**
 * Displays an error message, ends the HTML, and finishes script execution.
 *
 * @param string $message The error message to display.
 * @param string $explanation An optional extra bit of helpful text.
 */
function lp_fatal_error($message, $explanation=FALSE) {
	?>
	<p><strong>ERROR: <?php echo $message; ?></strong></p>
<?php
	if ($explanation !== FALSE) {
		?>
		<p><?php echo $explanation; ?></p>
<?php
	}
	lp_page_footer();
	exit;
}


/**
 * For 4.3.0 <= PHP <= 5.4.0
 * PHP >= 5.4 already has a http_response_code() function.
 */
if ( ! function_exists('http_response_code')) {
	function http_response_code($newcode = NULL) {
		static $code = 200;
		if ($newcode !== NULL) {
			header('X-PHP-Response-Code: '.$newcode, true, $newcode);
			if ( ! headers_sent()) {
				$code = $newcode;
			}
		}
		return $code;
	}
}

?>
