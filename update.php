<?php

$url = 'https://tabdeal.org/live/currency';

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_USERAGENT      => 'Mozilla/5.0',
    CURLOPT_TIMEOUT        => 30,
]);

$html = curl_exec($ch);
$err  = curl_error($ch);
curl_close($ch);

if (!$html) {
    fwrite(STDERR, "Failed to fetch: $err\n");
    exit(1);
}

libxml_use_internal_errors(true);
$dom = new DOMDocument();
$dom->loadHTML($html);
libxml_clear_errors();

$xpath = new DOMXPath($dom);

// Persian name on page => English key in JSON
$targets = [
    'دلار' => 'dollar',
    'یورو' => 'euro',
    'پوند' => 'pound',
    'درهم' => 'dirham',
];

$result = [];

// The page has two copies of each row: mobile (class "md:hidden") and desktop.
// We skip the mobile ones so we don't get duplicates.
$rows = $xpath->query("//tr[not(contains(@class, 'md:hidden'))]");

foreach ($rows as $row) {
    $nameNode = $xpath->query(
        ".//span[contains(@class, 'font-medium')]",
        $row
    )->item(0);

    if (!$nameNode) continue;

    $name = trim($nameNode->textContent);
    if (!isset($targets[$name])) continue;

    $text = trim($row->textContent);

    // Price: comma-formatted number, e.g. 233,100
    if (!preg_match('/\b\d{1,3}(?:,\d{3})+\b/', $text, $pm)) continue;

    // Change: signed number followed by %, e.g. 0.17%  or  -0.38%
    $change = null;
    if (preg_match('/(-?\d+(?:\.\d+)?)\s*%/', $text, $cm)) {
        $change = (float) $cm[1];
    }

    $result[$targets[$name]] = [
        'title'  => $name,
        'price'  => $pm[0],
        'change' => $change,
    ];
}

if (empty($result)) {
    fwrite(STDERR, "No currencies found\n");
    exit(1);
}

$data = [
    'currencies' => $result,
    'unit'       => 'toman',
    'updated_at' => date('c'),
];

file_put_contents(
    __DIR__ . '/data.json',
    json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
);

// Log summary for the GitHub Actions run
$summary = array_map(fn($c) => $c['price'], $result);
echo "Updated: " . json_encode($summary, JSON_UNESCAPED_UNICODE) . "\n";
