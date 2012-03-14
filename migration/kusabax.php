﻿<?php
	/* Config */
	
	$kusabaxc = Array('db' => Array('timeout' => 5, 'persistent' => false));
	$kusabaxc['db']['type']		= 'mysql';
	$kusabaxc['db']['server']	= 'localhost';
	$kusabaxc['db']['user']		= '';
	$kusabaxc['db']['password']	= '';
	$kusabaxc['db']['database']	= '';
	// KusabaX table prefix
	$kusabaxc['db']['prefix']	= '';
	// Anything more to add to the DSN string (eg. port=xxx;foo=bar)
	$kusabaxc['db']['dsn']		= '';
	// From your KusabaX config; needed to decode IP addresses
	$kusabaxc['randomseed']		= ''; //KU_RANDOMSEED
	// KusabaX directory (without trailing slash)
	$kusabaxc['root'] = '/var/www/kusabax';
	
	/* End Config */
	
	if(empty($kusabaxc['db']['user']))
		die('Did you forget to configure the script?');
	
	// Infinite timeout
	set_time_limit(0);
	
	// KusabaX functions
	function md5_decrypt($enc_text, $password, $iv_len = 16) {
		$enc_text = base64_decode($enc_text);
		$n = strlen($enc_text);
		$i = $iv_len;
		$plain_text = '';
		$iv = substr($password ^ substr($enc_text, 0, $iv_len), 0, 512);
		while ($i < $n) {
			$block = substr($enc_text, $i, 16);
			$plain_text .= $block ^ pack('H*', md5($iv));
			$iv = substr($block . $iv, 0, 512) ^ $password;
			$i += 16;
		}
		return preg_replace('/\\x13\\x00*$/', '', $plain_text);
	}
	
	// KusabaX -> Tinyboard HTML
	function convert_markup($body) {
		global $config;
		$body = stripslashes($body);
		
		// >quotes
		$body = str_replace('"unkfunc"', '"quote"', $body);
		
		// >>cites
		$body = preg_replace('/<a href="[^"]+?\/(\w+)\/res\/(\d+).html#(\d+)" onclick="return highlight\(\'\d+\', true\);" class="[^"]+">/', '<a onclick="highlightReply(\'$3\');" href="' . $config['root'] . '$1/res/$2.html#$3">', $body);
		
		// Public bans
		$body = preg_replace('/<br \/><font color="#FF0000"><b>\((.+?)\)<\/b><\/font>/', '<span class="public_ban">($1)</span>', $body);
		
		return $body;
	}
	
	require 'inc/functions.php';
	require 'inc/display.php';
	require 'inc/template.php';
	require 'inc/database.php';
	require 'inc/user.php';
	$step = isset($_GET['step']) ? round($_GET['step']) : 0;
	$page = Array(
		'config' => $config,
		'title' => 'KusabaX Database Migration',
		'body' => ''
	);
	
	$log = Array();
	
	// Trick Tinyboard into opening the KusabaX databse instead
	$__temp = $config['db'];
	$config['db'] = $kusabaxc['db'];
	
	// Get databse link
	$kusabax = $pdo;
	// Clear
	unset($pdo);
	
	// Open Tinyboard database
	$config['db'] = $__temp;
	unset($__temp);
	
	$k_query = $kusabax->query('SELECT * FROM `' . $kusabaxc['db']['prefix'] . 'boards`');
	$boards = listBoards();
	
	// Copy boards table, briefly
	$kusabax_boards = Array();
	while($board = $k_query->fetch()) {
		// For later use...
		$kusabax_boards[(int)$board['id']] = $board['name'];
		
		$already_exists = false;
		foreach($boards as &$_board) {
			if($_board['uri'] == $board['name']) {
				// Board already exists in Tinyboard...
				$log[] = 'Board /' . $board['name'] . '/ already exists.';
				$already_exists = true;
				break;
			}
		}
		if($already_exists)
			continue;
		
		$log[] = 'Creating board: <strong>/' . $board['name'] . '/</strong>';
		
		// Go ahead and create this new board...
		$query = prepare('INSERT INTO `boards` VALUES (NULL, :uri, :title, :subtitle)');
		$query->bindValue(':uri', $board['name']);
		$query->bindValue(':title', $board['desc']);
		$query->bindValue(':subtitle', null, PDO::PARAM_NULL);
		$query->execute() or error(db_error($query));
		
		// Posting table
		query(Element('posts.sql', Array('board' => $board['name']))) or error(db_error());
		
		// Set up board (create directories, etc.) by opening it
		openBoard($board['name']);
	}
	
	
	$k_query = $kusabax->query('SELECT * FROM `' . $kusabaxc['db']['prefix'] . 'posts` WHERE `IS_DELETED` = 0');
	while($post = $k_query->fetch()) {
		if(!isset($kusabax_boards[(int)$post['boardid']])) {
			// Board doesn't exist...
			continue;
		}
		$board = $kusabax_boards[(int)$post['boardid']];
		
		$log[] = 'Replicating post <strong>' . $post['id'] . '</strong> on /' . $board . '/';
		
		$query = prepare(sprintf("INSERT INTO `posts_%s` VALUES (:id, :thread, :subject, :email, :name, :trip, :capcode, :body, :time, :bump, :thumb, :thumbwidth, :thumbheight, :file, :width, :height, :filesize, :filename, :filehash, :password, :ip, :sticky, :locked, 0, :embed)", $board));
		
		// Post ID
		$query->bindValue(':id', $post['id'], PDO::PARAM_INT);
		
		// Thread (`parentid`)
		if($post['parentid'] == 0)
			$query->bindValue(':thread', null, PDO::PARAM_NULL);
		else
			$query->bindValue(':thread', (int)$post['parentid'], PDO::PARAM_INT);
		
		// Name
		if(empty($post['name']))
			$post['name'] = $config['anonymous'];
		$query->bindValue(':name', $post['name'], PDO::PARAM_INT);
		
		// Trip
		if(empty($post['tripcode']))
			$query->bindValue(':trip', null, PDO::PARAM_NULL);
		else
			$query->bindValue(':trip', $post['tripcode'], PDO::PARAM_STR);
		
		// Email
		$query->bindValue(':email', $post['email'], PDO::PARAM_STR);
		
		// Subject
		$query->bindValue(':subject', $post['subject'], PDO::PARAM_STR);
		
		// Body (`message`)
		$query->bindValue(':body', convert_markup($post['message']), PDO::PARAM_STR);
		
		// File
		if(empty($post['file']) || $post['file'] == 'removed') {
			if($post['file'] == 'removed')
				$query->bindValue(':file', 'deleted', PDO::PARAM_STR);
			else
				$query->bindValue(':file', null, PDO::PARAM_NULL);
			$query->bindValue(':width', null, PDO::PARAM_NULL);
			$query->bindValue(':height', null, PDO::PARAM_NULL);
			$query->bindValue(':filesize', null, PDO::PARAM_NULL);
			$query->bindValue(':filename', null, PDO::PARAM_NULL);
			$query->bindValue(':filehash', null, PDO::PARAM_NULL);
			$query->bindValue(':thumb', null, PDO::PARAM_NULL);
			$query->bindValue(':thumbwidth', null, PDO::PARAM_NULL);
			$query->bindValue(':thumbheight', null, PDO::PARAM_NULL);
		} else {
			$query->bindValue(':file', $post['file'] . '.' . $post['file_type'], PDO::PARAM_STR);
			$query->bindValue(':width', $post['image_w'], PDO::PARAM_INT);
			$query->bindValue(':height', $post['image_h'], PDO::PARAM_INT);
			$query->bindValue(':filesize', $post['file_size'], PDO::PARAM_INT);
			$query->bindValue(':filename', $post['file_original'] . '.' . $post['file_type'], PDO::PARAM_STR);
			// They use MD5; we use SHA1 by default.
			$query->bindValue(':filehash', null, PDO::PARAM_NULL);
			
			$query->bindValue(':thumb', $post['file'] . '.' . $post['file_type'], PDO::PARAM_STR);
			$query->bindValue(':thumbwidth', $post['thumb_w'], PDO::PARAM_INT);
			$query->bindValue(':thumbheight', $post['thumb_h'], PDO::PARAM_INT);
			
			// Copy file
			$file_path = $kusabaxc['root'] . '/' . $board . '/src/' . $post['file'] . '.' . $post['file_type'];
			$thumb_path = $kusabaxc['root'] . '/' . $board . '/thumb/' . $post['file'] . 's.' . $post['file_type'];
			
			$to_file_path = sprintf($config['board_path'], $board) . $config['dir']['img'] . $post['file'] . '.' . $post['file_type'];
			$to_thumb_path = sprintf($config['board_path'], $board) . $config['dir']['thumb'] . $post['file'] . '.' . $post['file_type'];
			
			if(!file_exists($to_file_path)) {
				$log[] = 'Copying file: <strong>' . $file_path . '</strong>';
				if(!@copy($file_path, $to_file_path)) {
					$err = error_get_last();
					$log[] = 'Could not copy <strong>' . $file_path . '</strong>: ' . $err['message'];
				}
			}
			
			if(!file_exists($to_thumb_path)) {
				$log[] = 'Copying file: <strong>' . $thumb_path . '</strong>';
				if(!@copy($thumb_path, $to_thumb_path)) {
					$err = error_get_last();
					$log[] = 'Could not copy <strong>' . $thumb_path. '</strong>: ' . $err['message'];
				}
			}
		}
		
		// IP
		$ip = md5_decrypt($post['ip'], $kusabaxc['randomseed']);
		if(!preg_match('/^\d+\.\d+\.\d+\.\d+$/', $ip)) {
			// Invalid IP address. Wrong KU_RANDOMSEED?
			
			$log[] = 'Invalid IP address returned after decryption. Wrong KU_RANDOMSEED?';
			$ip = '0.0.0.0'; // just set it to something valid and continue
		}
		$query->bindValue(':ip', $ip, PDO::PARAM_STR);
		
		// Time (`timestamp`)
		$query->bindValue(':time', $post['timestamp'], PDO::PARAM_INT);
		
		// Bump (`bumped`)
		$query->bindValue(':bump', $post['bumped'], PDO::PARAM_INT);
		
		// Locked
		$query->bindValue(':locked', $post['locked'], PDO::PARAM_INT);
		
		// Sticky
		$query->bindValue(':sticky', $post['stickied'], PDO::PARAM_INT);
		
		// Stuff we can't do (yet)
		$query->bindValue(':embed', null, PDO::PARAM_NULL);
		$query->bindValue(':password', null, PDO::PARAM_NULL);
		$query->bindValue(':capcode', null, PDO::PARAM_NULL);
		
		// Insert post
		$query->execute() or $log[] = 'Error: ' . db_error($query);
	}
	
	// News
	$k_query = $kusabax->query('SELECT * FROM `' . $kusabaxc['db']['prefix'] . 'front` WHERE `page` = 0');
	while($news = $k_query->fetch()) {
		// Check if already exists
		$query = prepare("SELECT 1 FROM `news` WHERE `body` = :body AND `time` = :time");
		$query->bindValue(':time', $news['timestamp'], PDO::PARAM_INT);
		$query->bindValue(':body', $news['message'], PDO::PARAM_STR);
		$query->execute() or error(db_error($query));
		if($query->fetch())
			continue;		
		
		$query = prepare("INSERT INTO `news` VALUES (NULL, :name, :time, :subject, :body)");
		$query->bindValue(':name', $news['poster'], PDO::PARAM_STR);
		$query->bindValue(':time', $news['timestamp'], PDO::PARAM_INT);
		$query->bindValue(':subject', $news['subject'], PDO::PARAM_STR);
		$query->bindValue(':body', $news['message'], PDO::PARAM_STR);
		$query->execute() or $log[] = 'Error: ' . db_error($query);
	}
	
	$page['body'] = '<div class="ban"><h2>Migrating…</h2><p>';
	foreach($log as &$l) {
		$page['body'] .= $l . '<br/>';
	}
	$page['body'] .= '</p></div>';
	
	echo Element('page.html', $page);

