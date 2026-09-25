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

$targets = [
    'دلار' => 'dollar',
    'یورو' => 'euro',
    'پوند' => 'pound',
    'درهم' => 'dirham',
];

$result = [];

// Keep only <tr> with 3 <td> — this skips the mobile duplicates (md:hidden)
// and the <thead> row (which has <th>, not <td>).
$rows = $xpath->query(
    "//tr[not(contains(@class, 'md:hidden')) and count(td) = 3]"
);

foreach ($rows as $row) {
    $tds = $xpath->query("./td", $row);

    // --- 1st cell: name ---
    $nameSpans = $xpath->query(
        ".//span[contains(@class, 'font-medium')]",
        $tds->item(0)
    );
    if ($nameSpans->length === 0) continue;

    $name = trim($nameSpans->item(0)->textContent);
    if (!isset($targets[$name])) continue;

    // --- 2nd cell: price — take the span that is *exactly* a comma-number ---
    $price = null;
    foreach ($xpath->query(".//span", $tds->item(1)) as $span) {
        $t = trim($span->textContent);
        if (preg_match('/^\d{1,3}(?:,\d{3})+$/', $t)) {
            $price = $t;
            break;
        }
    }
    if ($price === null) continue;

    // --- 3rd cell: change % ---
    $change = null;
    if (preg_match('/(-?\d+(?:\.\d+)?)\s*%/', $tds->item(2)->textContent, $m)) {
        $change = (float) $m[1];
    }

    $result[$targets[$name]] = [
        'title'  => $name,
        'price'  => $price,
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

echo "Updated: " . implode(', ', array_map(
    fn($k, $v) => "$k={$v['price']}",
    array_keys($result),
    $result
)) . "\n";
