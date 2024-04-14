<?php
/**
 * Analytics
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the LICENSE.md file.
 *
 * @author Marcel Scherello <audioplayer@scherello.de>
 * @copyright 2020 Marcel Scherello
 */

declare(strict_types=1);

namespace OCA\Analytics_Sourcepack\Datasource;

use OCA\Analytics\Datasource\IDatasource;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

class Odoo implements IDatasource
{
    /** @var LoggerInterface */
    private $logger;
    private $cookieFile = "cookie.txt";

    public function __construct(
        LoggerInterface $logger
    )
    {
        $this->logger = $logger;
    }

    public function getName(): string
    {
        return 'Odoo CRM';
    }

    public function getId(): int
    {
        return 25;
    }

    public function getTemplate(): array
    {
        $template = array();
        $template[] = ['id' => 'url', 'name' => 'URL', 'placeholder' => 'url'];
        $template[] = ['id' => 'db', 'name' => 'Database', 'placeholder' => ''];
        $template[] = ['id' => 'username', 'name' => 'Username', 'placeholder' => 'Username'];
        $template[] = ['id' => 'password', 'name' => 'Password', 'placeholder' => 'Password'];
        $template[] = ['id' => 'filter', 'name' => 'Filter', 'placeholder' => '["field", "ilike", "value"],[..]', 'type' => 'longtext'];
        $template[] = ['id' => 'groupBy', 'name' => 'Group by', 'placeholder' => 'create_date:week', 'type' => 'longtext'];
        $template[] = ['id' => 'path', 'name' => 'Object path', 'placeholder' => 'x/y/z', 'type' => 'longtext'];
        return $template;
    }

    /**
     * Get the items for the selected category
     *
     * @NoAdminRequired
     * @param array $option
     * @return array
     */
    public function readData($option): array
    {
        $url = htmlspecialchars_decode($option['url'], ENT_NOQUOTES);
        $username = $option['username'];
        $password = $option['password'];
        $path = $option['path'];
        $db = $option['db'];
        $groupBy = explode(',', $option['groupBy']);
        $filter = !empty($option['filter']) ? json_decode(htmlspecialchars_decode('[' . $option['filter'] . ']', ENT_NOQUOTES), true) : [];

        // Modify the context to include the timezone
        $context = [
            "lang" => "en_GB",
            "tz" => "Europe/Berlin",
            //"uid" => 70,
            "allowed_company_ids" => [1],
                "default_type" => "lead",
                "search_default_type" => "lead",
                "search_default_to_process" => 1
        ];

        // Authenticate and store session ID
        $this->cookieFile = \OC::$server->getTempManager()->getTemporaryFile();
        $this->authenticate($url, $db, $username, $password);

        // Subsequent request using stored session ID
        $ch = curl_init("$url/web/dataset/call_kw");
        $postData = json_encode([
            "jsonrpc" => "2.0",
            "method" => "call",
            "params" => [
                "model" => "crm.lead",
                "method" => "read_group",
                "args" => [
                    $filter,
                    ["id:count", "x_studio_matched_leads", "expected_revenue:sum","prorated_revenue:sum"],  // fields
                    $groupBy
                ],
                "kwargs" => [
                    "context" => $context
                    // Include other kwargs parameters as required
                ],
            ],
        ]);

        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $this->cookieFile);

        $rawResult = curl_exec($ch);
        curl_close($ch);
        unlink($this->cookieFile);

        $json = json_decode($rawResult, true);

        // check if a specific array of values should be extracted
        // e.g. {BTC,tmsp,price}
        preg_match_all("/(?<={).*(?=})/", $path, $matches);
        if (count($matches[0]) > 0) {
            // array extraction

            // check if absolute path is in front of the array
            // e.g. data/data{from,to,intensity/forecast}
            $firstArray = strpos($path, '{');
            if ($firstArray && $firstArray !== 0) {
                $singlePath = substr($path, 0, $firstArray);
                $json = $this->get_nested_array_value($json, $singlePath);
            }

            // separate the fields of the array {BTC,tmsp,price}
            $paths = explode(',', $matches[0][0]);
            // fill up with dummies in case of missing columns
            while (count($paths) < 3) {
                array_unshift($paths, 'empty');
            }
            foreach ($json as $rowArray) {
                // get the array fields from the json
                // if no match is not found, the field name will be used as a constant string
                $dim1 = $this->get_nested_array_value($rowArray, $paths[0]) ?: $paths[0];
                $dim2 = $this->get_nested_array_value($rowArray, $paths[1]) ?: $paths[1];
                $val = $this->get_nested_array_value($rowArray, $paths[2]) ?: $paths[2];
                $data[] = [$dim1, $dim2, $val];
            }
        } else {
            // single value extraction
            // e.g. data/currentHashrate,data/averageHashrate
            $paths = explode(',', $path);
            foreach ($paths as $singlePath) {
                // e.g. data/currentHashrate
                $array = $this->get_nested_array_value($json, $singlePath);

                if (is_array($array)) {
                    // if the tartet is an array itself
                    foreach ($array as $key => $value) {
                        $pathArray = explode('/', $singlePath);
                        $group = end($pathArray);
                        $data[] = [$group, $key, $value];
                    }
                } else {
                    $pathArray = explode('/', $singlePath);
                    $key = end($pathArray);
                    $data[] = ['', $key, $array];
                }
            }
        }

        $header = array();
        $header[0] = 'Version';
        $header[1] = 'Count';

        return [
            'header' => $header,
            'data' => $data,
            'rawData' => json_encode($rawResult), // $postData
            'error' => 0,
        ];
    }

    /**
     * check if the existing token is still valid and renew
     */
    private function authenticate($odooUrl, $db, $username, $password)
    {
        $ch = curl_init("$odooUrl/web/session/authenticate");
        $postData = json_encode([
            "jsonrpc" => "2.0",
            "params" => [
                "db" => $db,
                "login" => $username,
                "password" => $password,
            ],
        ]);

        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_COOKIEJAR, $this->cookieFile);

        $rawResult = curl_exec($ch);
        if (curl_errno($ch)) {
            $this->logger->error('Curl error: ' . curl_error($ch));
        }

        curl_close($ch);

        $result = json_decode($rawResult, true);
        if (isset($result['error'])) {
            $this->logger->error('Odoo error: ' . $result['error']['message']);
        }

        if (!is_readable($this->cookieFile)) {
            $this->logger->error('Cookie file is not readable. Check permissions.');
        }
    }

    /**
     * get array object from string
     *
     * @NoAdminRequired
     * @param $array
     * @param $path
     * @return array|string|null
     */
    private function get_nested_array_value(&$array, $path)
    {
        $pathParts = explode('/', $path);
        $current = &$array;
        foreach ($pathParts as $key) {
            $current = &$current[$key];
        }
        return $current;
    }
}