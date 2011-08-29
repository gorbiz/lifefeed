<?php
if (!isset($argv) || !isset($argv[1])) {
    echo "Please pass a bash history file as an argument." . PHP_EOL;
    die;
}

$lines = file($argv[1], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

$items = array();
foreach($lines as $i => $line) {
    if ($line[0] == "#") {
        $items[] = array(
            'title'  => $lines[$i+1],
            'description' => $lines[$i+1],
            'link' => 'http://www.example.com',
            'pubDate' => date('r', substr($line, 1))
            );
    }
}

?><rss version="2.0">
<channel>
<title>Bash history</title>
<description>Bash history from the command line</description>
<link>http://example.com</link>
<lastBuildDate><?php echo date('r'); ?></lastBuildDate>
<pubDate><?php echo date('r'); ?></pubDate>
<?php foreach ($items as $item) : ?>
<item>
    <title><?php echo htmlspecialchars($item['title']); ?></title>
    <description><?php echo htmlspecialchars($item['description']); ?></description>
    <link><?php echo htmlspecialchars($item['link']); ?></link>
    <pubDate><?php echo $item['pubDate']; ?></pubDate>
</item>
<?php endforeach; ?>
</channel>
</rss>