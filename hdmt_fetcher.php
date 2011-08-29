<?php
header('Content-Type:application/rss+xml');

function get_viewing_log($username, $password, $pages = 3) {
    $cookie_file = "/tmp/cookie/cookie1.txt";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookie_file);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookie_file);
    
    curl_setopt($ch, CURLOPT_URL,"http://hdmt.net/checkin.php?action=login");
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, "username=$username&password=$password&submit_button=Login");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
    
    $result = curl_exec($ch);
    
    curl_setopt($ch, CURLOPT_POST, 0);
  
    $items = array();
    for ($i = 1; $i < $pages + 1; $i++) {
        curl_setopt($ch, CURLOPT_URL,"http://hdmt.net/membercenter.php?&sub=history&page=$i");
        $result = curl_exec($ch);
        $items = array_merge($items, parse_html($result));
    }
    return $items;
}

function parse_html($html) {
    $a = explode('href="/shows/', $html);
    unset($a[0]);
    
    $items = array();
    foreach($a as $b) {
        $item = array();
        $item['link'] = "http://hdmt.net/shows/" . substr($b, 0, strpos($b, '"'));
        $hack = array_values(array_filter(explode("\n", strip_tags("<a href=\">" . $b))));
        $item['title'] = $hack[0];
        $item['description'] = $hack[0];
        $item['pubDate'] = reformat_date($hack[1]);
        $item['paid'] = $hack[2];
        $items[] = $item;
    }
    
    return $items;
}

function reformat_date($string) {
    // Example of expected input
    // 08-28-2011, 2:15 AM

    $matches = array();
    preg_match('/(\d+)-(\d+)-(\d+),\s?(\d+:\d+)\s?(AM|PM)/', $string, $matches);

    $month = $matches[1];
    $day = $matches[2];
    $year = $matches[3];
    $time = $matches[4];
    $ampm = $matches[5];
    
    // They appear to be on the american west coast, hence the -0700
    $date = strtotime("$year-$month-$day $time$ampm -0700");

    return date("r", $date);
}

if (!isset($argv) || !isset($argv[0]) || !isset($argv[1]) || !isset($argv[2])) {
    echo "Please pass username and password" . PHP_EOL;
    die;
}

$pages = isset($argv[3]) ? $argv[3] : 3;

$items = get_viewing_log($argv[1], $argv[2], $pages);

?><rss version="2.0">
<channel>
<title>HDMT viewing logs</title>
<description>Viewing logs from HDMT.net</description>
<link>http://hdmt.net</link>
<lastBuildDate><?php echo date('r'); ?></lastBuildDate>
<pubDate><?php echo date('r'); ?></pubDate>
<?php foreach ($items as $item) : ?>
<item>
    <title><?php echo $item['title']; ?></title>
    <description><?php echo $item['description']; ?></description>
    <link><?php echo $item['link']; ?></link>
    <pubDate><?php echo $item['pubDate']; ?></pubDate>
</item>
<?php endforeach; ?>
</channel>
</rss>