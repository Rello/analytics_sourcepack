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
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

class Salesforce implements IDatasource
{
    /** @var LoggerInterface */
    private $logger;
    private $db;

    public function __construct(
        IDBConnection $db,
        LoggerInterface $logger
    )
    {
        $this->db = $db;
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
        array_push($template, ['id' => 'object', 'name' => 'Salesforce Object', 'placeholder' => 'Account']);
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
        $data = array();
        $header = array();
        $header[0] = 'Version';
        $header[1] = 'Count';

        return [
            'header' => $header,
            'data' => $data,
            'error' => 0,
        ];
    }
}