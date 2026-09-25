<?php

/**
 * Scrapes Tabdeal for currency and gold prices.
 * Combines everything into a single data.json.
 */

// --- Configuration ---
$pages = [
    'currencies' => [
        'url' => 'https://tabdeal.org/live/currency',
        'targets' => [
            'دلار' => 'dollar',
            'یورو' => 'euro',
            'پوند' => 'pound',
            'درهم' => 'dirham',
        ],
        // Currencies have 3 <td> per row; skip mobile duplicates
        'row_condition' => "count(td) = 3",
        'skip_mobile'  => true,
    ],
    'gold' => [
        'url' => 'https://tabdeal.org/live/gold',
        'targets' => [
            'طلای ۱۸ عیار' => 'gold_18k',
            'طلای ۲۴ عیار' => 'gold_24k',
        ],
        // Gold mobile rows have 1 <td>; we need them for the 18k price
        'row_condition' => "count(td) = 1",
        'skip_mobile'  => false,
    ],
];

$output = [
    'currencies' => [],
    'gold'       => [],
    'unit'       => 'toman',
    'updated_at' => date('c'),
];

// --- Helper: fetch HTML ---
function fetchHtml(string $url): ?string
{
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
        fwrite(STDERR, "Failed to fetch $url: $err\n");
        return null;
    }
    return $html;
}

// --- Helper: extract data from a DOM using XPath ---
function extractData(
    DOMXPath $xpath,
    array $targets,
    string $rowCondition,
    bool $skipMobile
): array {
    $result = [];
    $query  = "//tr[$rowCondition]";

    if ($skipMobile) {
        $query = "//tr[not(contains(@class, 'md:hidden')) and $rowCondition]";
    }

    $rows = $xpath->query($query);
    if ($rows === false) {
        return $result;
    }

    foreach ($rows as $row) {
        // Find the name
        $nameSpans = $xpath->query(
            ".//span[contains(@class, 'font-medium')]",
            $row
        );
        if ($nameSpans->length === 0) {
            continue;
        }

        $name = trim($nameSpans->item(0)->textContent);
        if (!isset($targets[$name])) {
            continue;
        }

        // Find the price. We look for a span whose text is a comma-number.
        $price = null;
        foreach ($xpath->query(".//span", $row) as $span) {
            $t = trim($span->textContent);
            // Match numbers like 233,100 or 23,754,560
            if (preg_match('/^\d{1,3}(?:,\d{3})+$/', $t)) {
                $price = $t;
                break;
            }
        }

        // Skip if price is still null or just a dash
        if ($price === null || $price === '-') {
            continue;
        }

        // Find the change percentage
        $change = null;
        if (preg_match('/(-?\d+(?:\.\d+)?)\s*%/', $row->textContent, $m)) {
            $change = (float) $m[1];
        }

        $result[$targets[$name]] = [
            'title'  => $name,
            'price'  => $price,
            'change' => $change,
        ];
    }

    return $result;
}

// --- Main loop ---
foreach ($pages as $key => $config) {
    $html = fetchHtml($config['url']);
    if ($html === null) {
        continue;
    }

    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $dom->loadHTML($html);
    libxml_clear_errors();

    $xpath = new DOMXPath($dom);

    $data = extractData(
        $xpath,
        $config['targets'],
        $config['row_condition'],
        $config['skip_mobile']
    );

    if (!empty($data)) {
        $output[$key] = $data;
    } else {
        fwrite(STDERR, "No data extracted for $key\n");
    }
}

// --- Check if we got at least something ---
if (empty($output['currencies']) && empty($output['gold'])) {
    fwrite(STDERR, "No data extracted from any page.\n");
    exit(1);
}

// --- Save ---
file_put_contents(
    __DIR__ . '/data.json',
    json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
);

// --- Log summary ---
$summary = [];
foreach ($output['currencies'] as $k => $v) {
    $summary[] = "$k={$v['price']}";
}
foreach ($output['gold'] as $k => $v) {
    $summary[] = "$k={$v['price']}";
}
echo "Updated: " . implode(', ', $summary) . "\n";
