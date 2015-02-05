<?php
/*
 * Small script to download and convert the apple pricing matrix.
 * Based on the iTunesConnect version from 2015-02-05T14:40:51+00:00
 *
 * This script and the information it generates is supplied without liability.
 *
 * Very fragile! As soon as Apple changes the structure of their iTunesConnect page it may fail!
 * I'll try to keep it up to date. Feel free to fork and adapt it if I don't!
 *
 * Copyright 2015 Affinitas GmbH
 * Created by Tobias Plaputta
 *
 * Released under the MIT license
 *
 * Usage: $ php matrix.php -a yourAccount@example.com -p YourPassword12345 -o matrix.json
 * (You can call the script without parameters, it'll ask you then)
 *
 */

/**
 * Class MiniCurl
 *
 * Small curl helper used for iTunesConnect
 *
 */
class MiniCurl
{
    protected $_cookies;

    protected function updateCookies($cookies)
    {
        foreach ($cookies as $cookie) {
            list($name, $value) = explode('=', $cookie, 2);
            if ($name != 'DefaultAppleID') {
                $this->_cookies[$name] = $value;
            }
        }
    }

    protected function getCookieString()
    {
        $cookies = '';
        foreach ($this->_cookies as $k => $v) {
            $cookies .= $k . '=' . $v . ';';
        }
        return $cookies;
    }

    public function __construct()
    {
        $this->_cookies = array();
    }

    public function post($url, $data)
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HEADER, 1);
        curl_setopt($ch, CURLOPT_COOKIE, $this->getCookieString());

        $tmp_data = array();
        foreach ($data as $k => $v) {
            $tmp_data[] = urlencode($k) . '=' . urlencode($v);
        }

        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, implode('&', $tmp_data));

        $result = curl_exec($ch);
        curl_close($ch);

        preg_match_all('/^Set-Cookie:\s*([^;]*)/mi', $result, $m);

        if (!empty($m) && !empty($m[1])) {
            $this->updateCookies($m[1]);
        }

        preg_match('/^Location:\s+(.*)$/im', $result, $m);

        return array('redirect' => (!empty($m) && !empty($m[1])) ? $m[1] : null, 'result' => $result);
    }

    public function get($url, $headers = true)
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HEADER, $headers ? 1 : 0);
        curl_setopt($ch, CURLOPT_COOKIE, $this->getCookieString());

        $result = curl_exec($ch);
        curl_close($ch);

        preg_match_all('/^Set-Cookie:\s*([^;]*)/mi', $result, $m);

        if (!empty($m) && !empty($m[1])) {
            $this->updateCookies($m[1]);
        }

        preg_match('/^Location:\s+(.*)$/im', $result, $m);

        return array('redirect' => (!empty($m) && !empty($m[1])) ? $m[1] : null, 'result' => $result);
    }
}

/**
 * Class DataHelper
 *
 * Small helper class to grab needed data either from $argv oder StdIn
 *
 */
class DataHelper {

    static function param($param)
    {
        GLOBAL $argv;
        for ($i = 0, $l = count($argv); $i < $l; $i++) {
            if ($argv[$i] == '-' . $param && $i + 1 < $l) {
                return $argv[$i + 1];
            }
        }
        return null;
    }

    static function input($msg)
    {
        echo $msg . ': ';
        $handle = fopen("php://stdin", "r");
        $line = fgets($handle);
        fclose($handle);
        return trim($line);
    }

    static function data($name, $param) {
        $tmp = self::param($param);
        if (!isset($tmp)) {
            $tmp = self::input($name);
        }
        return $tmp;
    }

}

$account = DataHelper::data('Account','a');
$password = DataHelper::data('Password','p');
$output = DataHelper::data('Output file','o');

$itc = new miniCurl();

echo "Contacting iTunesConnect...\n";

// To grab the pricing matrix, we have to simulate a login
$itunesconnectResponse = $itc->get('https://itunesconnect.apple.com/WebObjects/iTunesConnect.woa', false);

if (!preg_match('/<form name="appleConnectForm" method="post" action="(.*?)">/si', $itunesconnectResponse['result'], $regs)) {
    echo "Unable to locate iTunesConnect login action!\n";
    exit;
}

echo "Trying to login...\n";

$loginResponse = $itc->post('https://itunesconnect.apple.com/' . $regs[1], array(
    'theAccountName' => $account,
    'theAccountPW' => $password,
    '1.Continue.x' => '0',
    '1.Continue.y' => '0',
    'inframe' => '0'));

if (!$loginResponse['redirect']) {
    echo "Please check your credentials!\n";
    exit;
}

echo "Following first redirect after login...\n";

// After login, it seems like we need to follow two redirects or grabbing the pricing matrix will most likely fail.
$redirect1AfterLoginResponse = $itc->get('https://itunesconnect.apple.com/' . $loginResponse['redirect']);

if (!$redirect1AfterLoginResponse['redirect']) {
    echo "Second redirect after login not found!\n";
    exit;
}

echo "Following second redirect after login...\n";

$redirect2AfterLoginResponse = $itc->get('https://itunesconnect.apple.com/' . $redirect1AfterLoginResponse['redirect']);

echo "Opening contracts page...\n";

// Now we need to "open" the contracts, tax, ... page
$contractsResponse = $itc->get('https://itunesconnect.apple.com/WebObjects/iTunesConnect.woa/da/jumpTo?page=contracts');

// To find the current link for the pricing matrix
if (!preg_match('/href="(.*?)">View Pricing Matrix/i', $contractsResponse['result'], $regs)) {
    echo "Unable to locate pricing matrix link!\n";
    exit;
}

echo "Loading pricing matrix...\n";

// Now we can load the pricing matrix
$pricingResponse = $itc->get('https://itunesconnect.apple.com' . $regs[1], false);

// And extract and convert the HTML Table
if (!preg_match('%<h1>App Store Pricing Matrix</h1>\s+(<table.*?</table>)%si', $pricingResponse['result'], $regs)) {
    echo "Unable to extract pricing matrix table!\n";
    exit;
}

echo "Parsing pricing matrix...\n";

// Extract the rows
preg_match_all('%(<tr[>\s]+.*?</tr>)%si', $regs[1], $rows_match, PREG_PATTERN_ORDER);

// Extract the types (Customer Price/Proceeds) from the first row
preg_match_all('%<td[^>]*>(.*?)</td>%si', $rows_match[1][0], $types_match, PREG_PATTERN_ORDER);

// Extract the targets (Currency/Country) from the second row
preg_match_all('%<td[^>]*>(.*?)</td>%si', $rows_match[1][1], $targets_match, PREG_PATTERN_ORDER);

$matrix = array();

foreach ($targets_match[1] as $k => $v) {
    $matrix[] = array('target' => $v, 'type' => $types_match[1][$k], 'tiers' => array());
}

// Run over all rows, match the columns and add the tiers
for ($i = 2; $i < count($rows_match[1]); $i++) {
    preg_match_all('%<td[^>]*>(.*?)</td>%si', $rows_match[1][$i], $item_result, PREG_PATTERN_ORDER);
    $tier = $item_result[1][0];
    for ($x = 1; $x < count($item_result[1]); $x++) {
        $matrix[$x]['tiers'][] = array('tier' => $tier, 'value' => $item_result[1][$x]);
    }
}

// Remove the first item in out matrix (which is just the tiers listed)
unset($matrix[0]);

echo "Saving pricing matrix to: ".$output."\n";

file_put_contents($output, json_encode(array('created' => @date('c'), 'matrix' => array_values($matrix))));

echo "Done.\n";





