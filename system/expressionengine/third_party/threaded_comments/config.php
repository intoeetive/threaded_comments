<?php

if ( ! defined('THREADED_COMMENTS_ADDON_NAME'))
{
	define('THREADED_COMMENTS_ADDON_NAME',         'Threaded Comments');
	define('THREADED_COMMENTS_ADDON_VERSION',      '2.4.8');
}

$config['name'] = THREADED_COMMENTS_ADDON_NAME;
$config['version'] = THREADED_COMMENTS_ADDON_VERSION;

$config['nsm_addon_updater']['versions_xml']='http://www.intoeetive.com/index.php/update.rss/24';