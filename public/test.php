<?php
// Simple error reporting for debugging
ini_set('display_errors', 1);
error_reporting(E_ALL);

// --- Configuration ---
$store_url = 'https://kaikkimerkit.fi';
$consumer_key = 'ck_e844e91f1a0b440d11c70ba5d683438f96e4de59';
$consumer_secret = 'cs_4ecca9a402bd1d90446366d868554186c762c1e5';

// Endpoint to test with trailing slash
$endpoint = '/wp-json/wc/v3/products/categories/';
$request_url = $store_url . $endpoint;

// --- Display Test Information ---
echo "<!DOCTYPE html><html><head><title>API Test</title><style>body { font-family: sans-serif; } pre { white-space: pre-wrap; word-wrap: break-word; }</style></head><body>";
echo "<h1>WooCommerce API Test</h1>";
echo "<p><strong>Testing URL:</strong> " . htmlspecialchars($request_url) . "</p>";

// --- cURL Request ---
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $request_url);
curl_setopt($ch, CURLOPT_USERPWD, $consumer_key . ":" . $consumer_secret);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
curl_setopt($ch, CURLOPT_HEADER, true); // Include headers in the response
curl_setopt($ch, CURLOPT_USERAGENT, 'ElementaFeeds-Test-Script/1.0');
// Bypasses SSL certificate verification issues, which can be a problem in some server environments
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

// Execute the request
$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
$header_str = substr($response, 0, $header_size);
$body_str = substr($response, $header_size);
$curl_error = curl_error($ch);
curl_close($ch);

// --- Display Results ---
echo "<h2>Test Results</h2>";
echo "<p><strong>HTTP Status Code Received:</strong> " . htmlspecialchars($http_code) . "</p>";

if ($curl_error) {
    echo "<h3>cURL Error:</h3>";
    echo "<pre style='background-color: #ffecec; border: 1px solid #f5c6cb; padding: 15px;'>" . htmlspecialchars($curl_error) . "</pre>";
} else {
    echo "<h3>Response Headers:</h3>";
    echo "<pre style='background-color: #f0f0f0; border: 1px solid #ccc; padding: 15px;'>" . htmlspecialchars($header_str) . "</pre>";

    echo "<h3>Response Body:</h3>";
    echo "<pre style='background-color: #e8f5e9; border: 1px solid #c8e6c9; padding: 15px;'>" . htmlspecialchars($body_str) . "</pre>";
}

echo "</body></html>";
?>