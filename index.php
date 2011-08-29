<?php

set_include_path(get_include_path() . PATH_SEPARATOR . 'simplepie/');
/**
 * Contains only something like:
 * define('LIFEFEED_DB_USERNAME', 'username');
 * define('LIFEFEED_DB_PASSWORD', 'secret');
 * define('LIFEFEED_DEV_KEY', 'something');
 */
include 'lifefeed/credentials.php';
include 'SimplePieAutoloader.php';

ini_set('memory_limit', '64M');

header('Content-Type: text/html; charset=utf-8');

// FIXME This is a temporary dirty hack
if (isset($_GET['supersecret']) && $_GET['supersecret'] == LIFEFEED_DEV_KEY) {
        define('DEV_MODE', true);
        ini_set('display_errors', true);
} else {
        define('DEV_MODE', false);
}



// XXX Dirty hack
function is_on_test_server() {
    return $_SERVER['REMOTE_ADDR'] == '127.0.0.1';
}

class DB {
    private static $db;
    public static function get() {
        if (false == (self::$db instanceof PDO)) {
            self::$db = new PDO('mysql:host=localhost;dbname=lifefeed', LIFEFEED_DB_USERNAME, LIFEFEED_DB_PASSWORD);
            self::$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
	        self::$db->query("SET NAMES utf8;");
            self::$db->query("SET CHARACTER SET utf8;");            
        }
        return self::$db;
    }
}

function feed_items_from_simplepie($url) {
 	$feed = new SimplePie();
	$feed->set_feed_url(urldecode($url));
	// $feed->set_cache_duration(0);
	$feed->enable_cache(false);
	$feed->handle_content_type();
	$success = $feed->init();

	if (!$success) throw new Exception("Could not fetch the feed.");

	return $feed->get_items();
}

function feed_items_from_feedparser($url) {

	include_once('FeedParser.php');
	$Parser     = new FeedParser();
	$Parser->parse($url);
	$raw_items      = $Parser->getItems();
	
	$items = array();
	foreach ($raw_items as $item) {
		$title = "[no title]";
		if (isset($item['TITLE'])) $title = $item['TITLE'];
		
		$description = "[no description]";
		if (isset($item['CONTENT'])) $description = $item['CONTENT'];
		if (isset($item['DESCRIPTION'])) $description = $item['DESCRIPTION'];
		
		$link = "[no link]";
		if (isset($item['AUTHOR']) && is_array($item['AUTHOR']) && isset($item['AUTHOR']['URI'])) $link = $item['AUTHOR']['URI'];
		if (isset($item['LINK'])) $link = $item['LINK'];
		 
		$date = "[no date]";
		if (isset($item['PUBLISHED'])) $date = $item['PUBLISHED'];
		if (isset($item['PUBDATE'])) $date = date('Y-m-d H:i:s', $item['PUBDATE']);
		
		$items[] = new FeedItem($title, $description, $link, $date);
	}
	return $items;
}



function facebook_style_timestamp($timestamp_string)
{
	$one_minute = 60;
	$one_hour = $one_minute * 60;
	$one_day = $one_hour * 24;
	$one_week = $one_day * 7;

	$timestamp = strtotime($timestamp_string);
	$time_since_event = time() - $timestamp;
	// TODO Handle future events better
	if ($time_since_event < $one_minute) {
		return $time_since_event . " seconds ago";
	} else if ($time_since_event < $one_hour) {
		return floor($time_since_event / 60) . " minutes ago";
	} else if ($time_since_event < $one_day) {
		return floor($time_since_event / 60 / 60) . " hours ago";
	} else if ($time_since_event < $one_day * 2) {
		return " Yesterday at " . date('H:i', $timestamp);
	} else if ($time_since_event < $one_week) {
		return " " . date('l', $timestamp) . " at " . date('H:i', $timestamp);
	}
	return date("Y-m-d H:i:s", $timestamp);
}

function get_items($hideFeeds = null) {
	if (!$hideFeeds) $hideFeeds = array(0);
    
    // XXX Hack
    $limit = is_on_test_server() ? " LIMIT 10" : "";
    $limit = "";
    
	$result = DB::get()->query("SELECT feeds.icon, items.title, items.description, items.link, items.date
        	FROM items
        	JOIN feeds ON feeds.id = items.idFeed
        	WHERE feeds.id NOT IN (" . implode(",", $hideFeeds)  . ")
        	ORDER BY items.date DESC$limit;")->fetchAll(PDO::FETCH_ASSOC);

    // Tidy up descriptions, we want valid HTML
    // XXX Should not be done on every request, should be done in the background and stored in the database or something.
    $func = function($value) {
        $config = array(
            'doctype' => 'strict',
            'show-body-only' => true,
            'alt-text' => '',
            'clean' => true,
            'char-encoding' => 'utf8',
            'input-encoding' => 'utf8',
            'output-encoding' => 'utf8'
        );
        $value['title'] = tidy_repair_string($value['title'], $config);
        $value['description'] = tidy_repair_string($value['description'], $config);
        
        $value['description'] = preg_replace('/width="(\d*)" valign="top"/is', 'style="width: $1px; vertial-align: top;"', $value['description']);
        $value['description'] = str_replace('valign="top"', 'style="vertical-align: top;"', $value['description']);
        $value['description'] = str_replace('img align="top"', 'img style="vertical-align: top;"', $value['description']);
        $value['description'] = str_replace('cellspacing="0" cellpadding="0" border="0"', 'style="border-spacing: 0; border-collapse:collapse; border: 0;"', $value['description']);
        return $value;
    };
    
    return array_map($func, $result);
}

function fetch_new_items_from_all_feeds() {
    $result = DB::get()->query('SELECT id, url FROM feeds');
	foreach($result as $row) {
		log_event("Fetching items from feed #" . $row['id'] . " - " . $row['url']);
		fetch_new_items_from_feed($row['id']);
	}
}

function fetch_new_items_from_feed($id) {
    $row = DB::get()->query("SELECT id, url FROM feeds WHERE id=$id")->fetch(PDO::FETCH_ASSOC);

	log_event("Fetching " . $row['url'] . "...");

	$items = array();
	try {
		$items = feed_items_from_simplepie($row['url']);
	} catch (Exception $e) {
		log_event("Exception: " . $e->getMessage());
		$items = array();
	}

	log_event("Got " . count($items) . " items.");

	if (count($items)) {
		DB::get()->query('UPDATE feeds SET last_refreshed = NOW() WHERE id = ' . $row['id']);
	}

	foreach ($items as $item) {

		$sql = 'SELECT * FROM items WHERE idFeed = :idFeed AND title = :title AND description = :description AND link = :link AND date = :date';
		$sth = $db->prepare($sql, array(PDO::ATTR_CURSOR => PDO::CURSOR_FWDONLY));
		$sth->execute(array(
			':idFeed'	=> $row['id'],
			':title'	=> $item->get_title(),
			':description'	=> $item->get_description(),
			':link'	=> $item->get_link(),
			':date'	=> date('Y-m-d H:i:s', strtotime($item->get_date()))
			));
		$already_existing_result = $sth->fetchAll();

		// XXX Use $item->get_id() instead?
		if (count($already_existing_result) > 0) {
			// This item is already in here...
			continue;
		}

		log_event("Got a new item: '" . $item->get_title() . "'.");

		$sql = 'INSERT INTO items (idFeed, title, description, link, date) VALUES (:idFeed, :title, :description, :link, :date);';
		$sth = $db->prepare($sql, array(PDO::ATTR_CURSOR => PDO::CURSOR_FWDONLY));
		$sth->execute(array(
			':idFeed'	=> $row['id'],
			':title'	=> $item->get_title(),
			':description'	=> $item->get_description(),
			':link'	=> $item->get_link(),
			':date'	=> date('Y-m-d H:i:s', strtotime($item->get_date()))
			));
	}
}

function get_feeds() {
	return DB::get()->query('SELECT feeds.id, feeds.name, feeds.url, feeds.icon, feeds.last_refreshed FROM feeds ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC);
}

function get_number_of_feeds() {
	$result = DB::get()->query('SELECT COUNT(*) FROM feeds')->fetch(PDO::FETCH_NUM);
    return $result[0];
}

function get_number_of_feed_items($id) {
	$result =  DB::get()->query('SELECT COUNT(*) FROM items WHERE idFeed=' . $id)->fetch(PDO::FETCH_NUM);
	return $result[0];
}

function log_event($message) {
	file_put_contents("/tmp/lifefeed.debug.log", $message . PHP_EOL, FILE_APPEND);
}

function is_feed_hidden($id) {
	return in_array($id, get_hidden());
}

function get_hidden() {
	
	if (isset($_GET['hide']) && $_GET['hide'] == 'all') {
		$hidden = array();
		foreach (get_feeds() as $feed) {
			$hidden[] = $feed['id'];
		}
		return $hidden;
	}
	
	$hidden = isset($_COOKIE['hidden']) ? explode(',', $_COOKIE['hidden']) : array();
	if (isset($_GET['hide'])) $hidden[] = $_GET['hide'];
	if (isset($_GET['show'])) {
		foreach ($hidden as $key => $h) {
			if ($h == $_GET['show']) {
				unset($hidden[$key]);
			}
		}
	}
	
	if ((isset($_GET['hide']) && $_GET['hide'] == 'none')
		|| (isset($_GET['show']) && $_GET['show'] == 'all')) {
			return array();
	}
	
	return array_filter(array_unique($hidden));
}



if (isset($_GET['hide'])) {
	if ($_GET['hide'] == 'none') {
		setcookie('hidden', null);
	} else if ($_GET['hide'] == 'all') {
		$hidden = array();
		foreach (get_feeds() as $feed) {
			$hidden[] = $feed['id'];
		}
		setcookie('hidden', implode(',', array_unique($hidden)));
	} else {
		$hidden = get_hidden();
		$hidden[] = $_GET['hide'];
		setcookie('hidden', implode(',', array_unique($hidden)));
	}
}

if (isset($_GET['show'])) {
	if ($_GET['show'] == 'all') {
		setcookie('hidden', null);
	} else {
		$hidden = get_hidden();
		foreach ($hidden as $key => $h) { if ($h == $_GET['show']) { unset($hidden[$key]); }}
		setcookie('hidden', implode(',', $hidden));
	}
}

if (DEV_MODE) {
	if (isset($_GET['refresh'])) {
		if ($_GET['refresh'] == 'all') {
			fetch_new_items_from_all_feeds();
		} else {
			fetch_new_items_from_feed($_GET['refresh']);
		}
	}

	if (isset($_GET['clear'])) {
		if ($_GET['clear'] == 'all') {
			DB::get()->query('TRUNCATE items');
		} else {
			DB::get()->query('DELETE FROM items WHERE idFeed=' . $_GET['clear']);
		}
	}

	if (isset($_GET['remove'])) {
		if ($_GET['remove'] == 'all') {
			DB::get()->query('TRUNCATE feeds');
			DB::get()->query('TRUNCATE items');
		} else {
			DB::get()->query('DELETE FROM items WHERE idFeed=' . $_GET['remove']);
			DB::get()->query('DELETE FROM feeds WHERE id=' . $_GET['remove']);
		}
	}

	if (isset($_POST['add_feed'])) {
		if (empty($_POST['name']) || empty($_POST['url']) || empty($_POST['icon'])) {
			echo "<p>ERROR: You need to fill in all the fields, sorry...</p>";
		} else {
			if (count(DB::get()->query("SELECT * FROM feeds WHERE url='" . trim($_POST['url']) . "'")->fetchAll())) {
				echo "<p>ERROR: That feed already exists... sorry :/.</p>";
			} else {
				DB::get()->query("INSERT INTO feeds SET name='" . trim($_POST['name']) . "', url='" . trim($_POST['url']) . "', icon='" . trim($_POST['icon']) . "'");
				fetch_new_items_from_feed($db->lastInsertId());
			}
		}
	}
}



?><!DOCTYPE html> 
<html>
<head>
	<meta charset="utf-8" />
	<title>Lifefeed.me</title> 
	<link rel="stylesheet" href="main.css" type="text/css" />
	<link rel="stylesheet" href="forms.css" type="text/css" />

    <script src="http://ajax.googleapis.com/ajax/libs/jquery/1.5.2/jquery.min.js" type="text/javascript"></script>

	<script type="text/javascript">
	  var _gaq = _gaq || [];
	  _gaq.push(['_setAccount', 'UA-822266-8']);
	  _gaq.push(['_trackPageview']);
	  (function() {
	    var ga = document.createElement('script'); ga.type = 'text/javascript'; ga.async = true;
	    ga.src = ('https:' == document.location.protocol ? 'https://ssl' : 'http://www') + '.google-analytics.com/ga.js';
	    var s = document.getElementsByTagName('script')[0]; s.parentNode.insertBefore(ga, s);
	  })();
	</script>
    
    <script type="text/javascript">
        $(document).ready(function() {
            $(".feed-list").hide();
            
            $("#filter-items").keyup(function() {
                var query = $(this).val().toLowerCase();
                if (query.length > 2) {
                    $(".item").each(function(index) {
                        // TODO Search the href of the link as well
                        $(this).css('display', (-1 != jQuery(".item-link, .item-description", this).text().toLowerCase().indexOf(query)) ? 'block' : 'none');
                    });
                } else if (query.length == 0) {
                    $(".item").show();
                }
            });
        });
    </script>

</head>
<body>

<h1 style="float:left;"><a href="index.php" style="font-weight: bold; color: black;">Lifefeed.me</a></h1>

<div style="float:left; margin: 21px -0.5em 21px 0.5em; font-size: 2em;">|</div>

<ul class="navigation" style="float:left; margin: 21px 0;">
	<li><a href="index.php?hide=none">Show all</a></li>
	<li><a href="index.php?hide=all">Hide all</a></li>
	<?php if (DEV_MODE) : ?>
	<li><a href="index.php?refresh=all">Refresh</a></li>
	<li><a href="index.php?clear=all">Clear</a></li>
	<?php endif; ?>
</ul>

<h2 style="clear:both;">Feeds</h2>

<?php if (DEV_MODE): ?>
<h3>Add a feed</h3>
<form action="index.php" method="post" class="form">
	<p class="name">
		<input type="text" name="name" id="add_name" value="<?php echo isset($_POST['name']) ? $_POST['name'] : ""  ?>" />
		<label for="add_name">Name</label>
	</p>
	<p class="url">
		<input type="text" name="url" id="add_url" value="<?php echo isset($_POST['url']) ? $_POST['url'] : ""  ?>" />
		<label for="add_url">Url</label>
	</p>
	<p class="icon">
		<input type="text" name="icon" id="add_icon_url" value="<?php echo isset($_POST['icon']) ? $_POST['icon'] : ""  ?>" />
		<label for="add_icon_url">Icon url</label>
	</p>
	<p class="submit">
		<input type="submit" name="add_feed" />
	</p>
</form>
<?php endif; ?>

<p>This lifefeed has <?php echo get_number_of_feeds(); ?> sources | <a href="#" onclick="$('.feed-list').toggle(); $(this).html($('.feed-list').is(':visible') ? 'boooring, hide!' : 'show me them!');">show me them!</a></p>
<ul class="feed-list">
	<?php foreach (get_feeds() as $feed): ?>
		<li class="feed<?php echo is_feed_hidden($feed['id']) ? " hidden" : "";  ?>" style="background-image: url(<?php echo $feed['icon']; ?>); background-repeat: no-repeat; <?php echo !$feed['last_refreshed'] ? "background-color: #fcc;" : ""; ?>">
			
			<?php if (DEV_MODE) : ?>
			<a href="<?php echo $feed['url']; ?>"><?php echo $feed['url']; ?></a><br />
			<?php else : ?>
			<strong><?php echo $feed['name'];?></strong><br />
			<?php endif; ?>
			
			<a href="index.php?<?php echo is_feed_hidden($feed['id']) ? "show" : "hide"; ?>=<?php echo $feed['id']; ?>"><?php echo is_feed_hidden($feed['id']) ? "Show" : "Hide"; ?></a> |
			<?php if (DEV_MODE) : ?>
			<a href="index.php?refresh=<?php echo $feed['id']; ?>">Refresh</a> | 
			<a href="index.php?clear=<?php echo $feed['id']; ?>">Clear</a> |
			<a href="index.php?remove=<?php echo $feed['id']; ?>">Remove</a><br />
			<?php endif; ?>
			
			<strong><?php echo get_number_of_feed_items($feed['id']); ?></strong> Items | 
			Refreshed <span class="date"><?php echo $feed['last_refreshed'] ? facebook_style_timestamp($feed['last_refreshed']) : "Never"; ?></span>
			
		</li>
	<?php endforeach; ?>
</ul>


<hr style="clear:both;" />

<h2>Events</h2>

<form action="index.php" method="get">
    <input name="filter-items" id="filter-items" type="text" placeholder="filter" />
</form>

<ul class="items">
	<?php foreach (get_items(get_hidden()) as $item) : ?>
	<li class="item" style="background: url(<?php echo $item['icon']; ?>) no-repeat; padding-left: 20px;">
		<a href="<?php echo $item['link']; ?>" class="title item-link"><?php echo $item['title']; ?></a><br />
		<?php if ($item['description'] != $item['title']) : ?>
		<div class="item-description"><?php echo $item['description']; ?></div>
		<?php endif; ?>
		<span class="date"><?php echo facebook_style_timestamp($item['date']); ?></span>
	</li>
	<?php endforeach; ?>
</ul>

</body>
</html>
