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
use Psr\Log\LoggerInterface;

class Odoo implements IDatasource
{
    /** @var LoggerInterface */
    private $logger;
    private $lastStatusCode = 0;

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
        $template[] = ['id' => 'username', 'name' => 'Username', 'placeholder' => 'optional'];
        $template[] = ['id' => 'password', 'name' => 'API key', 'placeholder' => 'API key'];
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
        $url = rtrim(htmlspecialchars_decode($option['url'], ENT_NOQUOTES), '/');
        $username = $option['username'];
        $apiKey = $option['password'];
        $path = $option['path'];
        $db = $option['db'];
        $groupBy = array_map('trim', explode(',', $option['groupBy']));
        $filter = !empty($option['filter']) ? json_decode(htmlspecialchars_decode('[' . $option['filter'] . ']', ENT_NOQUOTES), true) : [];
        $data = [];

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

        $rawResult = $this->readGroup($url, $db, $apiKey, $filter, $groupBy, $context);
        if ($this->isHtmlRedirect($rawResult)) {
            if ($username === '') {
                $this->logger->error('Odoo JSON-2 is not available and username is required for JSON-RPC fallback.');
                return $this->errorResult($rawResult);
            }
            $rawResult = $this->readGroupJsonRpc($url, $db, $username, $apiKey, $filter, $groupBy, $context);
        }

        $apiResult = json_decode($rawResult, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->error('Odoo JSON error: ' . json_last_error_msg());
            return $this->errorResult($rawResult);
        }

        if ($this->lastStatusCode >= 400 || isset($apiResult['error'])) {
            $message = $apiResult['message'] ?? $apiResult['error'] ?? 'HTTP ' . $this->lastStatusCode;
            $message = is_array($message) ? json_encode($message) : $message;
            $this->logger->error('Odoo error: ' . $message);
            return $this->errorResult($rawResult);
        }

        $odooResult = $apiResult['result'] ?? $apiResult;

        // Keep the previous /web/dataset/call_kw response shape for existing object paths.
        $json = ['result' => $odooResult];

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
            'rawData' => json_encode($json),
            'error' => 0,
        ];
    }

    /**
     * Read grouped CRM lead data via Odoo's JSON-2 API.
     */
    private function readGroup($odooUrl, $db, $apiKey, $filter, $groupBy, $context): string
    {
        $postData = json_encode([
            "domain" => $filter,
            "fields" => ["id:count", "x_studio_matched_leads", "expected_revenue:sum", "prorated_revenue:sum"],
            "groupby" => $groupBy,
            "context" => $context,
        ]);

        $headers = [
            'Authorization: bearer ' . $apiKey,
            'Content-Type: application/json; charset=utf-8',
            'User-Agent: Nextcloud Analytics Sourcepack',
        ];
        if ($db !== '') {
            $headers[] = 'X-Odoo-Database: ' . $db;
        }

        return $this->postJson("$odooUrl/json/2/crm.lead/read_group", $postData, $headers);
    }

    /**
     * Read grouped CRM lead data via Odoo's external JSON-RPC API.
     */
    private function readGroupJsonRpc($odooUrl, $db, $username, $apiKey, $filter, $groupBy, $context): string
    {
        $uid = $this->authenticateJsonRpc($odooUrl, $db, $username, $apiKey);
        if (!$uid) {
            return json_encode([
                'error' => 'Odoo JSON-RPC authentication failed',
            ]);
        }

        $postData = json_encode([
            'jsonrpc' => '2.0',
            'method' => 'call',
            'params' => [
                'service' => 'object',
                'method' => 'execute_kw',
                'args' => [
                    $db,
                    $uid,
                    $apiKey,
                    'crm.lead',
                    'read_group',
                    [
                        $filter,
                        ["id:count", "x_studio_matched_leads", "expected_revenue:sum", "prorated_revenue:sum"],
                        $groupBy,
                    ],
                    [
                        'context' => $context,
                    ],
                ],
            ],
            'id' => time(),
        ]);

        return $this->postJson("$odooUrl/jsonrpc", $postData, ['Content-Type: application/json']);
    }

    private function authenticateJsonRpc($odooUrl, $db, $username, $apiKey)
    {
        $postData = json_encode([
            'jsonrpc' => '2.0',
            'method' => 'call',
            'params' => [
                'service' => 'common',
                'method' => 'login',
                'args' => [
                    $db,
                    $username,
                    $apiKey,
                ],
            ],
            'id' => time(),
        ]);

        $rawResult = $this->postJson("$odooUrl/jsonrpc", $postData, ['Content-Type: application/json']);
        $result = json_decode($rawResult, true);
        if (isset($result['error'])) {
            $this->logger->error('Odoo JSON-RPC authentication error: ' . json_encode($result['error']));
        }

        return $result['result'] ?? false;
    }

    private function postJson($url, $postData, $headers): string
    {
        $ch = curl_init($url);

        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $rawResult = curl_exec($ch);
        if (curl_errno($ch)) {
            $this->logger->error('Curl error: ' . curl_error($ch));
        }
        $this->lastStatusCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($this->lastStatusCode >= 400) {
            $this->logger->error('Odoo HTTP error: ' . $this->lastStatusCode);
        }

        return $rawResult ?: '';
    }

    private function isHtmlRedirect(string $rawResult): bool
    {
        return stripos($rawResult, '<html') !== false
            && stripos($rawResult, '/web/login') !== false;
    }

    private function errorResult(string $rawResult): array
    {
        $header = array();
        $header[0] = 'Version';
        $header[1] = 'Count';

        return [
            'header' => $header,
            'data' => [],
            'rawData' => $rawResult,
            'error' => 1,
        ];
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
