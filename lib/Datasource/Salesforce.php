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
use OCA\Analytics_Sourcepack\Salesforce\Authentication\PasswordAuthentication;
use OCA\Analytics_Sourcepack\Salesforce\SalesforceFunctions;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

class Salesforce implements IDatasource
{
    /** @var LoggerInterface */
    private $logger;
    private $endPoint = 'https://login.salesforce.com/';
    private $instanceUrl;
    private $accessToken;

    public function __construct(
        LoggerInterface $logger
    )
    {
        $this->logger = $logger;
    }

    public function getName(): string
    {
        return 'Salesforce';
    }

    public function getId(): int
    {
        return 23;
    }

    public function getTemplate(): array
    {
        $template = array();
        $template[] = ['id' => 'client_id', 'name' => 'Client id', 'placeholder' => 'Client id'];
        $template[] = ['id' => 'client_secret', 'name' => 'Client secret', 'placeholder' => 'Client secret'];
        $template[] = ['id' => 'username', 'name' => 'Username', 'placeholder' => 'Username'];
        $template[] = ['id' => 'password', 'name' => 'Password', 'placeholder' => 'Password'];
        $template[] = ['id' => 'select', 'name' => 'Salesforce SOQL select', 'placeholder' => 'SOQL'];
        $template[] = ['id' => 'name', 'name' => 'Data series description', 'placeholder' => 'optional'];
        return $template;
    }

    /**
     * Get the items for the selected category
     *
     * @NoAdminRequired
     * @param array $option
     * @return array
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \OCA\Analytics_Sourcepack\Salesforce\Exception\SalesforceAuthenticationException
     * @throws \OCA\Analytics_Sourcepack\Salesforce\Exception\SalesforceException
     */
    public function readData($option): array
    {
        $parameter = [
            'grant_type' => 'password',
            'client_id' => $option['client_id'],
            'client_secret' => $option['client_secret'],
            'username' => $option['username'],
            'password' => $option['password'],
            ];

        $auth = $this->authCheck($parameter);
        $query = htmlspecialchars_decode($option['select'], ENT_NOQUOTES);
        //$query = $option['select'];

        $salesforceFunctions = new SalesforceFunctions($this->instanceUrl, $this->accessToken);
        $paymentList = $salesforceFunctions->query($query);
        $data = $paymentList['records'];
        foreach ($data as &$row) {
            unset($row['attributes']);
            $row = array_values($row);
            if ($option['name'] && $option['name'] !== '') {
                array_unshift($row, $option['name']);
            }
        }

        //$this->logger->info('data result: '.json_encode($data));

        $header = array();
        $header[0] = 'Version';
        $header[1] = 'Count';

        return [
            'header' => $header,
            'data' => $data,
            'error' => 0,
        ];
    }

    /**
     * check if the existing token is still valid and renew
     * @param $parameter
     * @return bool
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \OCA\Analytics_Sourcepack\Salesforce\Exception\SalesforceAuthenticationException
     * @throws \OCA\Analytics_Sourcepack\Salesforce\Exception\SalesforceException
     */
    private function authCheck($parameter)
    {
        //$this->logger->info('parameter: '.json_encode($parameter));

        $salesforce = new PasswordAuthentication($parameter);
        $salesforce->setEndpoint($this->endPoint);
        $salesforce->authenticate();

        /* if you need access token or instance url */
        $this->accessToken = $salesforce->getAccessToken();
        $this->instanceUrl = $salesforce->getInstanceUrl();
        return true;
    }

}