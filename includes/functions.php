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
 *
 * It will display either the image, or include the HTML file in /editions/.
 *
 * If there is no more content to display for this delivery in the /editions/
 * directory, we return a status of 410 to show that this partwork is finished -
 * the subscriber will be unsubscribed from this publication.
 *
 * If called from /edition/ then we expect to receive two parameters in the 
 * URL:
 *
 * `delivery_count` 
 * This counts up from 0, and indicates which edition should be published. BERG 
 * Cloud increments this every time we return content. So if we don't deliver 
 * an edition on a particular day, deliver_count will be the same the next day.
 * This value determines which image or * HTML file we display.
 * eg, if delivery_count is 0, we display /editions/1.png or /editions/1.html
 *
 * `local_delivery_time`
 * This will contain the time in the timezone where the Little Printer we're
 * delivering to is based, eg "2013-07-31T19:20:30.45+01:00".
 * We use this to determine if it's the correct day for a delivery.
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
		$edition_number = (int) $_GET['delivery_count'] + 1;
		lp_etag_header($edition_number, $local_delivery_time);

		// Which weekday is this Little Printer on?
		$weekday = lp_day_of_week($local_delivery_time);

		if ( ! in_array($weekday, $DELIVERY_DAYS)) {
			// This is a day that there's no delivery.
			http_response_code(204);
			exit;
		}

	} else { // 'sample'
		$edition_number = $EDITION_FOR_SAMPLE;
		lp_etag_header('sample', $local_delivery_time);
	}
	$rss = fetch_rss("http://newsrss.bbc.co.uk/weather/forecast/4276/Next3DaysRSS.xml?area=HP12");
	$weatherword = $rss->items[0]['title'];
	$weatherword = substr($weatherword, strpos($weatherword,":")+2);
	$weatherword = substr($weatherword, 0, strpos($weatherword, ","));
	echo $weatherword;
	lp_page_header();

	require lp_directory_path().'includes/header.php';	
	require lp_directory_path().'includes/footer.php';	

	lp_page_footer();
	
	// Get the path of the image or file for this edition (if any).
/*	$file_path_data = lp_get_edition_file_path($edition_number);

	if ($file_path_data === FALSE) {
		// No edition is available for this edition_number. End the subscription.
		http_response_code(410);
		exit;
	
	} else {
		// We have content to display!

		lp_page_header();

		require lp_directory_path().'includes/header.php';	

		if ($file_path_data[0] == 'image') {
			echo '<img src="' . $file_path_data[1] . '" />';
		} else { // 'file'
			require $file_path_data[1];
		}

		require lp_directory_path().'includes/footer.php';	

		lp_page_footer();
	}
	*/
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
		if ( ! array_key_exists('delivery_count', $_GET)) {
			// Sending an ETag shouldn't really be necessary at /edition/ with 
			// no parameters, but the publication validator currently queries 
			// that URL and expects an ETag.
			lp_etag_header('delivery_count_error', gmdate('Y-m-d\TH:i:s.0+00:00'));
			lp_fatal_error(
				"Requests for /edition/ need a delivery_count, eg '?delivery_count=0'",
				"Make sure 'send_delivery_count' is set to true in meta.json"	
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
