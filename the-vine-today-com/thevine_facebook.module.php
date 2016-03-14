<?php
/*
 * Facebook API integration
 */

define('FB_USERNAME_DELIMITER', '-');
define('FB_LOGIN_PATH', 'facebook/login');
define('FB_REDIRECT_PAGE', 'page');
define('FB_REDIRECT_QUERY', 'hash');
define('FB_REDIRECT_FRAGMENT', 'hash');
define('FB_DEFAULT_HEIGHT', 100);
define('FB_DEFAULT_WIDTH', 100);

require libraries_get_path('facebook-php-sdk') . '/autoload.php';

use Facebook\FacebookSession;
use Facebook\FacebookRedirectLoginHelper;
use Facebook\FacebookRequest;
use Facebook\FacebookSDKException;
use Facebook\FacebookRequestException;
use Facebook\GraphObject;

function thevine_facebook_get_post_from_node($node) {
	global $base_url;

	$post = array(
		'message' => '',
		'link' => $base_url . '/' . drupal_get_path_alias('node/' . $node->nid),
		'name' => sprintf('%s | %s', _thevine_title_case($node->title), variable_get('site_name', '')),
		'caption' => $base_url,
		'description' => $node->body[LANGUAGE_NONE][0]['value'],
		'picture' => $base_url . '/sites/default/files/site-images/thevinetoday.gif',
	);

	return (object)$post;
}

function thevine_facebook_comment_view_alter(&$build) {
	$links = &$build['links']['comment']['#links'];

	if (isset($links['comment_forbidden'])) {
		$links['comment_forbidden']['title'] = str_replace('/user/login?destination', '/' . FB_LOGIN_PATH . '?' . FB_REDIRECT_PAGE, $links['comment_forbidden']['title']);
	}
}

function thevine_facebook_form_alter(&$form, &$form_state, $form_id) {
	switch ($form_id) {
		case 'user_login':
		case 'user_login_block':
			$form['link'] = array('#markup' => l('Login with Facebook', 'facebook/login', array(
				'class' => 'facebook-login',
			)));
			break;
	}
}

function thevine_facebook_login() {
	global $base_url;

	if (!isset($_SESSION)) {
		// start session
		session_start();
	}
	
	$query = drupal_get_query_parameters();

	if (isset($query[FB_REDIRECT_PAGE])) {
		$_SESSION[FB_REDIRECT_PAGE] = $query[FB_REDIRECT_PAGE];
		$_SESSION[FB_REDIRECT_FRAGMENT] = @$query[FB_REDIRECT_FRAGMENT];
	}

	$app_id = variable_get('thevine_facebook_app_id', '');
	$app_secret = variable_get('thevine_facebook_app_secret', '');

	// init app with app id and secret
	FacebookSession::setDefaultApplication($app_id, $app_secret);

	// login helper with redirect_uri
	$helper = new FacebookRedirectLoginHelper($base_url . '/' . FB_LOGIN_PATH);

	try {
		$session = $helper->getSessionFromRedirect();
	}
	catch(FacebookRequestException $e) {
		watchdog('The Vine Facebook', $e->getMessage(), NULL, WATCHDOG_CRITICAL);
	}
	catch(Exception $e) {
		watchdog('The Vine Facebook', $e->getMessage(), NULL, WATCHDOG_CRITICAL);
	}

	if (isset($session)) {
		_thevine_facebook_user_update($session);
		$url = @$_SESSION[FB_REDIRECT_PAGE];
	}
	else {
		// see: https://www.drupal.org/node/2326615
		drupal_session_started(TRUE);

		$params = array('scope' => 'email, user_location');
		$url = $helper->getLoginUrl($params);
	}
	
	drupal_goto($url, array(
		'fragment' => @$_SESSION[FB_REDIRECT_FRAGMENT],
	));
}

/* prefix prevents it from becoming a hook! */
function _thevine_facebook_user_update(FacebookSession $session) {
	global $user;

	// get FB user data
	$request = new FacebookRequest($session, 'GET', '/me');
	$response = $request->execute();
	$graphObject = $response->getGraphObject();

	// does Drupal user exist?
	$fb_id = $graphObject->getProperty('id');
	$email = $graphObject->getProperty('email');
	$name = $graphObject->getProperty('name');
	$fname = $graphObject->getProperty('first_name');

	try {
		$location = $graphObject->getProperty('location');
		
		if ($location) {
			$location = $location->getProperty('name');
		}
	}
	catch(exception $e) {
		$location = FALSE;		
	}

	$user = user_load_by_name($fb_id);

	if (! $user) {
		$fields = array(
			'name' => $fb_id,
			'mail' => $email,
			'pass' => user_password(8),
			'status' => 1,
			'init' => $email,
			'roles' => array(DRUPAL_AUTHENTICATED_RID => 'authenticated user'),
		);

		$user = user_save('', $fields);
	}

	// seems to fix timezone bug by reloading user
	$user = user_load($user->uid);

	// update user photo
	// get Facebook profile picture
	$request = new FacebookRequest($session, 'GET', '/me/picture', array(
		'redirect' => FALSE,
		'type' => 'large',
	));
	$response = $request->execute();
	$graphObject = $response->getGraphObject();
	$pic_url = $graphObject->getProperty('url');
	$response = drupal_http_request($pic_url);

	if ($response->code == 200) {
		$picture_directory = file_default_scheme() . '://' . variable_get('user_picture_path', 'pictures');

		file_prepare_directory($picture_directory, FILE_CREATE_DIRECTORY);
		$file = file_save_data($response->data, $picture_directory . '/' . check_plain($fb_id . '.jpg'), FILE_EXISTS_REPLACE);

		if (is_object($file)) {
			$fields = array(
				'picture' => $file,
				'login' => time(),
				'data' => array(
					'fb_id' => $fb_id,
					'fb_name' => $name,
					'fb_location' => $location,
					'fb_photo' => $pic_url,
					'fb_fname' => $fname,
				),
			);

			// remove any cached images
			image_path_flush($file->uri);

			// save the user
			$user = user_save($user, $fields);
		}
	}

	// log the user in
	drupal_session_regenerate();
	
	if ($user->status == 1) {
		drupal_set_message(sprintf('Hello %s. Welcome to The Vine!', $fname));
	}
	else {
		drupal_set_message('Unable to log you in at this time.', 'error');		
	}
}

function thevine_facebook_logout() {
	/*
	 * kill session
	 * 
	 */
}

function thevine_facebook_post($params) {
	$app_id = variable_get('thevine_facebook_app_id', '');
	$app_secret = variable_get('thevine_facebook_app_secret', '');

	try {
		FacebookSession::setDefaultApplication($app_id, $app_secret);
		$session = FacebookSession::newAppSession();
	}
	catch (FacebookRequestException $e) {
		watchdog('The Vine Facebook', $e->getMessage(), NULL, WATCHDOG_CRITICAL);
	}
	catch (Exception $e) {
		watchdog('The Vine Facebook', $e->getMessage(), NULL, WATCHDOG_CRITICAL);
	}

	if (isset($session)) {
		try {
			$params = (array)$params;
			$params['access_token'] = variable_get('thevine_facebook_token', '');
			
			// sanitize text
			$keys = array('caption', 'description', 'message', 'name');
			foreach ($keys as $key) {
				$params[$key] = strip_tags($params[$key]);
			}

			$request = new FacebookRequest($session, 'POST', '/thevinetoday/feed', $params);
			$response = $request->execute()->getGraphObject()->asArray();

			watchdog('The Vine Facebook', "Posted '" . $params['name'] . "' to Facebook", NULL, WATCHDOG_INFO);	
		}
		catch (FacebookRequestException $e) {
			watchdog('The Vine Facebook', $e->getMessage(), NULL, WATCHDOG_CRITICAL);
		}
	}
}

function thevine_facebook_permission() {
	return array(
		'edit own user account' => array(
			'title' => t('Edit own user account'),
			'description' => t('Allow user to edit their own account.'),
		),
	);
}

function thevine_facebook_user_account_access() {
	global $user;

	switch ($user->uid) {
		case 0: // anonymous user
			return TRUE;

		case 1: // admin user
			return TRUE;
	}

	// authenticated user
	return FALSE;
}

function thevine_facebook_user_edit_access($account) {
	global $user;

	return
		($user->uid == $account->uid && user_access('edit own user account'))
		|| user_access('administer users')
		|| $account->uid > 1;
}

function thevine_facebook_user_view_access($account) {
	global $user;

	return $user->uid == 1;
}

function thevine_facebook_menu_alter(&$items) {
	// restrict access to user login
	$items['user']['access callback'] = 'thevine_facebook_user_account_access';

	// restrict access to viewing users
	$items['user/%user']['access callback'] = 'thevine_facebook_user_view_access';

	// restrict access to editing user accounts
	$items['user/%user/edit']['access callback'] = 'thevine_facebook_user_edit_access';

	// No user can reset ther password. Admin can reset passwords
	// using drush: drush upwd --password="<password>" <user>
	$items['user/password']['access callback'] = FALSE;
}

/**
 * Implementation of hook_menu().
 */
function thevine_facebook_menu() {
	$items = array();

	$items[FB_LOGIN_PATH] = array(
		'title' => 'Login with Facebook',
		'page callback' => 'thevine_facebook_login',
		'access callback' => TRUE,//'user_is_anonymous',
		'type' => MENU_CALLBACK,
	);

	$items['facebook/logout'] = array(
		'title' => 'Login with Facebook',
		'page callback' => 'thevine_facebook_logout',
		'access callback' => 'user_is_logged_in',
		'type' => MENU_CALLBACK,
	);

	$items['admin/config/services/thevine_facebook'] = array(
		'title' => 'The Vine Facebook',
		'description' => 'Configure The Vine Facebook settings.',
		'page callback' => 'drupal_get_form',
		'page arguments' => array('thevine_facebook_admin_app_settings'),
		'access arguments' => array('administer the vine facebook'),
		'file' => 'includes/thevine_facebook.admin.inc',
		'type' => MENU_NORMAL_ITEM,
	);

	$items['admin/config/services/thevine_facebook/settings'] = array(
		'title' => 'App Settings',
		'description' => 'Manage Facebook app settings.',
		'page callback' => 'drupal_get_form',
		'page arguments' => array('thevine_facebook_admin_app_settings'),
		'access arguments' => array('administer the vine facebook'),
		'file' => 'includes/thevine_facebook.admin.inc',
		'type' => MENU_DEFAULT_LOCAL_TASK,
		'weight' => -10,
	);

	$items['admin/config/services/thevine_facebook/token'] = array(
		'title' => 'Page Token',
		'description' => 'Manage Facebook page token.',
		'page callback' => 'drupal_get_form',
		'page arguments' => array('thevine_facebook_admin_token_settings'),
		'access arguments' => array('administer the vine facebook'),
		'file' => 'includes/thevine_facebook.admin.inc',
		'type' => MENU_LOCAL_TASK,
	);

	return $items;
}

function _thevine_facebook_location($uid = NULL) {
	global $user;

	$account = $uid == NULL ? $user : user_load($uid);
	
	if (isset($account->data['fb_location'])) {
		return $account->data['fb_location'];
	}

	return '';
}

function _thevine_facebook_photo($uid, $style = 'thumbnail') {
	$user = user_load($uid);
	
	if (isset($user->picture)) {
		$file = file_load($user->picture->fid);
		$image = image_load($file->uri);
		$photo = array(
			'file' => array(
				'#theme' => 'image_style',
				'#style_name' => $style,
				'#path' => $image->source,
				'#width' => $image->info['width'],
				'#height' => $image->info['height'],
			),
		);
	
		return drupal_render($photo);
	}

	return '';
}

function _thevine_facebook_username($uid = NULL) {
	global $user;

	$account = $uid == NULL ? $user : user_load($uid);

	if (isset($account->data['fb_name'])) {
		return $account->data['fb_name'];
	}

	return @$user->name;
}
