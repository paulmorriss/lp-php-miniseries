<?php 
/* Validate the postcode passed by the Berg cloud 
** Sees if the corresponding URL from the BBC exists and complain if not
*/

	require_once '../includes/config.php';

	if (!isset($_POST['config'])) die("No POST found.");

	$post = json_decode($_POST['config'], true);

	if (!empty($post)) {
		
		$url = BBCRSS . $post['postcode'] . BBCSUFFIX;
		$ch = curl_init(); // create cURL handle (ch)
		if (!$ch) {
			die("Couldn't initialize a cURL handle");
		}
		// set some cURL options
		$ret = curl_setopt($ch, CURLOPT_URL,            $url);
		$ret = curl_setopt($ch, CURLOPT_HEADER,         1);
		$ret = curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
		$ret = curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		
		// execute
		$ret = curl_exec($ch);
		
		if (empty($ret)) {
			// some kind of an error happened
			die(curl_error($ch));
			curl_close($ch); // close cURL handler
		} else {
			$info = curl_getinfo($ch);
			curl_close($ch); // close cURL handler
		
			if (empty($info['http_code'])) {
					die("No HTTP code was returned"); 
			} else {
				if ($info['http_code'] < '400') {
					header("Content-Type: application/json");
					$return = array('valid' => 'true');
					die(json_encode($return));
				} else {
						
					// Invalid
					$errors = "I don't recognise that postcode (code: ".$info['http_code'].")";
					header("Content-Type: application/json");
					$return = array('valid' => 'false', 'errors' => $errors);
					die(json_encode($return));
				}
			}

		}

	} else echo 'No config passed';
		
?>