<?php
/*
 * MailChimp API integration
 */

define('THEVINE_MAILER_CONTENT_HTML', 0);
define('THEVINE_MAILER_CONTENT_NODE', 1);
define('THEVINE_MAILER_CONTENT_CALLBACK', 2);
define('THEVINE_MAILER_DATETIME', 'r');
define('THEVINE_MAILER_DATETIME_SHORT', 'D j M Y');
define('THEVINE_MAILER_FREQ_ONCE', 100);

module_load_include('php', 'thevine_mailer', 'includes/MailChimp');

function thevine_mailer_create_campaign($params) {
	$campaign = FALSE;
	$list = thevine_mailer_mailchimp_request('lists/list', array(
		'filters' => array(
			'list_id' => $params['list_id']
		),
	));

	if ($list) {
		$list_data = $list['data'][0];

		// safeguard for localhost environment
		if ($_SERVER['SERVER_NAME'] == 'localhost' && $list_data['stats']['member_count'] >= 10) {
			watchdog('The Vine Mailer', sprintf('Unable to use the "%s" list in a test environment.', $list_data['name']), NULL, WATCHDOG_ALERT);		
		}
		else {
			$html = $params['html'];
			unset($params['html']);

			// create the campaign
			$campaign = thevine_mailer_mailchimp_request('campaigns/create', array(
				'type' => 'regular',
				'options' => $params,
				'content' => array(
					'html' => $html,
					'text' => drupal_html_to_text($html),
				),			
			));
		}
	}
	
	return $campaign;
}

function thevine_mailer_cron() {
	global $base_url;

	// get a list of mailer items
	$time = time();
	$mailers = db_query('
		SELECT * 
		  FROM {thevine_campaign_mailer} m
		 WHERE m.enabled = 1
		   AND m.send_time IS NOT NULL
		   AND :timestamp > m.next_run + m.send_time
	', array(':timestamp' => $time));

	foreach ($mailers as $mailer) {
		// campaign name
		$campaign_name = $mailer->name . ': ' . date('Ymd');
		
		if ($_SERVER['SERVER_NAME'] == 'localhost') {
			$campaign_name = 'TESTING: ' . $campaign_name;
		}

		// does the campaign exist?
		$campaigns = thevine_mailer_mailchimp_request('campaigns/list', array(
			'filters' => array(
				'title' => $campaign_name,
			)
		));

		if ($campaigns && $campaigns['total'] == 0) {
			// create the content
			$header = variable_get('thevine_mailer_default_header', array('value' => '', 'format' => ''))['value'];
			$prefix = $mailer->prefix;

			$node = new stdClass;
			$facebook_post = new stdClass;

			$content = thevine_mailer_get_content($mailer, $node, $facebook_post);
			$suffix = $mailer->suffix;
			$footer = variable_get('thevine_mailer_default_footer', array('value' => '', 'format' => ''))['value'];
			$content = join('', array($header, $prefix, $content, $suffix, $footer));
			$content = token_replace($content, array('node' => $node));

			// theme the content
			$themer = array(
				'#theme' => 'thevine_mailer_email',
				'#body' => $content,
			);
			$html = drupal_render($themer);
			$html = check_markup($html, variable_get('thevine_mailer_default_format', filter_fallback_format()));

			// create the campaign
			$campaign = thevine_mailer_create_campaign(array(
				'list_id' => $mailer->list_id,
				'subject' => html_entity_decode(token_replace($mailer->subject, array('node' => $node))),
				'from_email' => variable_get('thevine_mailer_from_email', ''),
				'from_name' => variable_get('thevine_mailer_from_name', ''),
				'to_name' => variable_get('thevine_mailer_to_name', '*|FNAME|* *|LNAME|*'),
				'title' => $campaign_name,
				'html' => $html,
			));

			// send the campaign
			if ($campaign && $campaign['status'] == 'save') {
				watchdog('The Vine Mailer', "Campaign '$campaign_name' was created OK", NULL, WATCHDOG_INFO);

				$result = thevine_mailer_mailchimp_request('campaigns/send', array(
					'cid' => $campaign['id']
				));

				if ($result && $result['complete'] == 1) {
					// update last_run and next_run timestamps
					$record = array(
						'mailer_id' => $mailer->mailer_id,
						'last_run' => $time,
						
						// set next mailing time
						'next_run' => $mailer->frequency == THEVINE_MAILER_FREQ_ONCE ? NULL : thevine_mailer_get_next_run($mailer->frequency, $time),
						
						// if frequency is "once", disable the mailer once it's been sent
						'enabled' => $mailer->frequency == THEVINE_MAILER_FREQ_ONCE ? 0 : 1,
					);

					drupal_write_record('thevine_campaign_mailer', $record, array('mailer_id'));

					// finally, clear the cache
					cache_clear_all();

					watchdog('The Vine Mailer', "Campaign '$campaign_name' was sent OK", NULL, WATCHDOG_INFO);
					$success = TRUE;
				}
				else {
					watchdog('The Vine Mailer', "Campaign '$campaign_name' could not be sent!", NULL, WATCHDOG_CRITICAL);
				}
			}
			else {
				watchdog('The Vine Mailer', "Campaign '$campaign_name' could not be created!", NULL, WATCHDOG_CRITICAL);	
			}

			// post to Facebook
			if ($success) {
				if ($mailer->facebook_time) {
					//$facebook_post->message = $mailer->subject;
					
					$pub_time = $mailer->next_run + $mailer->facebook_time;
					$facebook_post->scheduled_publish_time = $pub_time > $time + 900 ? $pub_time : $time + 900;
					$facebook_post->published = FALSE;

					thevine_facebook_post($facebook_post);
				}
			}
 		}
	}
}

function thevine_mailer_display_frequency($frequency) {
	$frequencies = thevine_mailer_get_frequencies();
		
	return $frequencies[$frequency][0];
}

function thevine_mailer_get_callback_content($callback, &$node = NULL, &$facebook_post = NULL) {
	if (function_exists($callback)) {
		return $callback($node, $facebook_post);
	}
	
	return NULL;
}

function thevine_mailer_get_content($mailer, &$node = NULL, &$facebook_post = NULL) {
	global $base_url;

	switch ($mailer->content_type) {
		case THEVINE_MAILER_CONTENT_HTML:
			$content = $mailer->html;
			$facebook_post = (object)array(
				'message' => '',
				'link' => $base_url . '/',
				'name' => variable_get('site_name', ''),
				'caption' => $base_url,
				'description' => $content,
				'picture' => $base_url . '/sites/default/files/site-images/thevinetoday.gif',
			);
			break;

		case THEVINE_MAILER_CONTENT_NODE:
			$content = thevine_mailer_get_node_content($mailer->nid, $node, $facebook_post);
			break;

		case THEVINE_MAILER_CONTENT_CALLBACK:
			$content = thevine_mailer_get_callback_content($mailer->callback, $node, $facebook_post);
			break;
	}

	return $content;
}

function thevine_mailer_get_frequencies() {
	$frequencies = array(
		THEVINE_MAILER_FREQ_ONCE => array('Once', 'today'),
		108 => array('Every day', 'tomorrow'),

		101 => array('Every Monday', '%s monday'),
		102 => array('Every Tuesday', '%s tuesday'),
		103 => array('Every Wednesday', '%s wednesday'),
		104 => array('Every Thursday', '%s thursday'),
		105 => array('Every Friday', '%s friday'),
		106 => array('Every Saturday', '%s saturday'),
		107 => array('Every Sunday', '%s sunday'),

		111 => array('First Monday of the month', 'first monday of %s month'),
		112 => array('First Tuesday of the month', 'first tuesday of %s month'),
		113 => array('First Wednesday of the month', 'first wednesday of %s month'),
		114 => array('First Thursday of the month', 'first thursday of %s month'),
		115 => array('First Friday of the month', 'first friday of %s month'),
		116 => array('First Saturday of the month', 'first saturday of %s month'),
		117 => array('First Sunday of the month', 'first sunday of %s month'),

		121 => array('Second Monday of the month', 'second monday of %s month'),
		122 => array('Second Tuesday of the month', 'second tuesday of %s month'),
		123 => array('Second Wednesday of the month', 'second wednesday of %s month'),
		124 => array('Second Thursday of the month', 'second thursday of %s month'),
		125 => array('Second Friday of the month', 'second friday of %s month'),
		126 => array('Second Saturday of the month', 'second saturday of %s month'),
		127 => array('Second Sunday of the month', 'second sunday of %s month'),

		131 => array('Third Monday of the month', 'third monday of %s month'),
		132 => array('Third Tuesday of the month', 'third tuesday of %s month'),
		133 => array('Third Wednesday of the month', 'third wednesday of %s month'),
		134 => array('Third Thursday of the month', 'third thursday of %s month'),
		135 => array('Third Friday of the month', 'third friday of %s month'),
		136 => array('Third Saturday of the month', 'third saturday of %s month'),
		137 => array('Third Sunday of the month', 'third sunday of %s month'),

		191 => array('Last Monday of the month', 'last monday of %s month'),
		192 => array('Last Tuesday of the month', 'last tuesday of %s month'),
		193 => array('Last Wednesday of the month', 'last wednesday of %s month'),
		194 => array('Last Thursday of the month', 'last thursday of %s month'),
		195 => array('Last Friday of the month', 'last friday of %s month'),
		196 => array('Last Saturday of the month', 'last saturday of %s month'),
		197 => array('Last Sunday of the month', 'last sunday of %s month'),
	);
	
	return $frequencies;
}

function thevine_mailer_get_frequency($frequency) {	
	return thevine_mailer_get_frequency()[$frequency];
}

function thevine_mailer_get_lists() {
	$lists = array();

	if ($mailchimp_lists = thevine_mailer_mailchimp_request('lists/list')) {
		foreach($mailchimp_lists['data'] as $item) {
			$lists[$item['id']] = $item['name'] . ' (' . $item['stats']['member_count'] . ')';
		}
	}
	
	return $lists;
}

function thevine_mailer_get_next_run($frequency, $time = NULL) {
	$frequencies = thevine_mailer_get_frequencies();
	
	$relatives = array('this');
	if ($time) $relatives[] = 'next';
	
	$now = $time ? $time : time();
	foreach ($relatives as $relative) {
		$next = strtotime(sprintf($frequencies[$frequency][1], $relative), $now);
		if ($next > $now) break;
	}

	return $next;
}

function thevine_mailer_get_mailer($mailer_id) {
	$mailer = db_query('SELECT * FROM {thevine_campaign_mailer} WHERE mailer_id = :id', array(
		':id' => $mailer_id
	))->fetchObject();

	return $mailer;
}

function thevine_mailer_get_node_content($nid, &$node, &$facebook_post) {
	$content = NULL;
	
	if ($node = node_load($nid)) {
		$view = node_view($node);
		$content = drupal_render($view);
		$facebook_post = thevine_facebook_get_post_from_node($node);
	}

	return $content;
}

function thevine_mailer_token_info() {
	$info['tokens']['node']['path_alias'] = array(
		'name' => t('Node path alias'),
		'description' => t('The path alias of the node'),
	);

	return $info;
}

function thevine_mailer_tokens($type, $tokens, array $data = array(), array $options = array()) {
	$replacements = array();

	if ($type == 'node' && !empty($data['node'])) {
		$node = $data['node'];
		if (isset($tokens['path_alias'])) {
			$replacements[$tokens['path_alias']] = drupal_get_path_alias('node/' . $node->nid);
		}
	}

	return $replacements;
}

/**
 * A preprocess function for theme('thevine_mailer').
 *
 * The $variables array initially contains the following arguments:
 * - $body:	The message body template
 */
function template_preprocess_thevine_mailer_email(&$variables) {
	$css = '';
	$file = drupal_get_path('theme', $GLOBALS['theme']) . '/css/mail.css';

	if (file_exists($file)) {
		 $css = file_get_contents($file);
	}
	else {
		$files = array();
		preg_match_all('/(?:\("(.*)"\))/m', drupal_get_css(), $files);

		// Process each style sheet
		foreach ($files[1] as $file) {
			$file = str_replace(':8080/', '/', $file); // for local dev work
			$css .= file_get_contents($file);
		}
	}

	// Perform some safe CSS optimizations. (derived from core CSS aggregation)
	$css = preg_replace('<
		\s*([@{}:;,]|\)\s|\s\()\s*[^\n\S] |	# Remove whitespace around separators, but keep space around parentheses and new lines between definitions.
		/\*([^*\\\\]|\*(?!/))+\*/ \s+				# Remove comments that are not CSS hacks.
		>x', '\1', $css
	);

	$variables['css'] = $css;
}

/**
 * Implements hook_menu_alter().
 */
function thevine_mailer_menu() {
	$items = array();

	$items['admin/config/services/thevine_mailer'] = array(
		'title' => 'The Vine Mailer',
		'description' => 'Configure The Vine mailchimp settings.',
		'page callback' => 'drupal_get_form',
		'page arguments' => array('thevine_mailer_admin_settings'),
		'access arguments' => array('administer the vine mailer'),
		'file' => 'includes/thevine_mailer.admin.inc',
		'type' => MENU_NORMAL_ITEM,
	);

	$items['admin/config/services/thevine_mailer/settings'] = array(
		'title' => 'Settings',
		'description' => 'Manage mailer settings.',
		'page callback' => 'drupal_get_form',
		'page arguments' => array('thevine_mailer_admin_settings'),
		'access arguments' => array('administer the vine mailer'),
		'file' => 'includes/thevine_mailer.admin.inc',
		'type' => MENU_DEFAULT_LOCAL_TASK,
		'weight' => -10,
	);

	$items['admin/config/services/thevine_mailer/mailers'] = array(
		'title' => 'Campaign Mailers',
		'description' => 'Manage campaign mailers.',
		'page callback' => 'thevine_mailer_admin_mailers',
		'access arguments' => array('administer the vine mailer'),
		'file' => 'includes/thevine_mailer.admin.inc',
		'type' => MENU_LOCAL_TASK,
		'weight' => -9,
	);

	$items['admin/config/services/thevine_mailer/mailers/add'] = array(
		'title' => 'Add Campaign Mailer',
		'description' => 'Add a new campaign mailer',
		'page callback' => 'drupal_get_form',
		'page arguments' => array('thevine_mailer_admin_mailer'),
		'access arguments' => array('administer the vine mailer'),
		'file' => 'includes/thevine_mailer.admin.inc',
		'type' => MENU_LOCAL_ACTION,
	);

		$items['admin/config/services/thevine_mailer/%'] = array(
		'title' => 'Campaign Mailer',
		'description' => 'Manage campaign mailers.',
		'page callback' => 'drupal_get_form',
		'page arguments' => array('thevine_mailer_admin_mailer', 4),
		'access arguments' => array('administer the vine mailer'),
		'file' => 'includes/thevine_mailer.admin.inc',
		'type' => MENU_CALLBACK,
	);

	$items['admin/config/services/thevine_mailer/delete/%'] = array(
		'title' => 'Delete Campaign Mailer',
		'description' => 'Delete a campaign mailer',
		'page callback' => 'drupal_get_form',
		'page arguments' => array('thevine_mailer_admin_mailer_delete', 5),
		'access arguments' => array('administer the vine mailer'),
		'file' => 'includes/thevine_mailer.admin.inc',
		'type' => MENU_CALLBACK,
	);

	$items['admin/config/services/thevine_mailer/sent'] = array(
		'title' => 'Sent Campaigns',
		'description' => 'Sent mailer campaigns.',
		'page callback' => 'thevine_mailer_admin_sent_campaigns',
		'access arguments' => array('administer the vine mailer'),
		'file' => 'includes/thevine_mailer.admin.inc',
		'type' => MENU_LOCAL_TASK,
		'weight' => -8,
	);

	return $items;
}

/**
 * Implementation of hook_theme()
 */
function thevine_mailer_theme() {
	return array(
		'thevine_mailer_email' => array(
			'variables' => array(
				'css' => NULL,
				'header' => NULL,
				'body' => NULL,
				'footer' => NULL,
			),
			'template' => 'thevine_mailer_email',
			'path' => drupal_get_path('module', 'thevine_mailer') . '/templates'
		)
	);
}

function thevine_mailer_mailchimp_request($type, $params = array()) {
	$api_key = variable_get('thevine_mailer_api_key', '');

	if (! $api_key) return FALSE;

	$MailChimp = new MailChimp($api_key);
	$result = $MailChimp->call($type, $params);

	if (!$result) {
		watchdog('The Vine Mailer', "Unable to call MailChimp request: $type", NULL, WATCHDOG_CRITICAL);
	}
	
	return $result;
}
