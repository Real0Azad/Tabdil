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
    fwrite(STDERR, "Failed to fetch data: $err\n");
    exit(1);
}

libxml_use_internal_errors(true);
$dom = new DOMDocument();
$dom->loadHTML($html);
libxml_clear_errors();

$xpath = new DOMXPath($dom);
$nodes = $xpath->query(
    "//span[contains(@class, 'font-medium') and normalize-space()='دلار']"
);

if ($nodes->length === 0) {
    fwrite(STDERR, "Dollar price not found\n");
    exit(1);
}

$row  = $nodes->item(0)->parentNode->parentNode;
$text = trim($row->textContent);

if (!preg_match('/[\d,]+/', $text, $m)) {
    fwrite(STDERR, "Price not found\n");
    exit(1);
}

$data = [
    'price'      => $m[0],
    'currency'   => 'toman',
    'updated_at' => date('c'),
];

file_put_contents(
    __DIR__ . '/data.json',
    json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
);

echo "Updated: {$m[0]}\n";
