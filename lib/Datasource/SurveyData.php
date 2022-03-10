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

class SurveyData implements IDatasource
{
    /** @var LoggerInterface */
    private $logger;
    private $db;
    const TABLE_NAME = 'survey_results';

    public function __construct(
        IDBConnection $db,
        LoggerInterface $logger
    )
    {
        $this->db = $db;
        $this->logger = $logger;
        self::TABLE_NAME;
    }

    public function getName(): string
    {
        return 'Survey Data';
    }

    public function getId(): int
    {
        return 22;
    }

    public function getTemplate(): array
    {
        $template = array();
        array_push($template, ['id' => 'datatype', 'name' => 'Type of Data', 'type' => 'tf', 'placeholder' => 'App - adaptation/App - absolute numbers/Server versions/Server parameters']);
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
        $this->logger->error($option['datatype']);
        if ($option['datatype'] === 'App - adaptation') {
            $sql = $this->getAppAdaptation();
        } elseif ($option['datatype'] === 'App - absolute numbers') {
            $sql = $this->getAppNumbers();
        } elseif ($option['datatype'] === 'Server versions') {
            $sql = $this->getServerNumbers();
        } elseif ($option['datatype'] === 'Server parameters') {
            $sql = $this->getAllParameters();
        }


        $statement = $sql->execute();
        $data = $statement->fetchAll();
        $statement->closeCursor();

        // remove db-column keys
        $data = array_values($data);
        foreach ($data as $key => $value) {
            $data[$key] = array_values($value);
        }

        if ($option['datatype'] === 'App - adaptation') {
            foreach ($data as &$row) {
                if ($row[3] !== 0 && $row[3] !== null) {
                    $row[2] = round(($row[2] / $row[3]) * 100 , 0);
                } else {
                    $row[2] = 0;
                }
            }
        }

        $header = array();
        $header[0] = 'Version';
        $header[1] = 'Count';

        return [
            'header' => $header,
            'data' => $data,
            'error' => 0,
        ];
    }

    private function getAppNumbers ()
    {
        $sql = $this->db->getQueryBuilder();
        $subQuery_serverversion = $this->db->getQueryBuilder();
        $subQuery_serverversion->select('source')
            ->selectAlias($subQuery_serverversion->func()->substring('value',$subQuery_serverversion->expr()->literal(1, IQueryBuilder::PARAM_INT),$subQuery_serverversion->expr()->literal(2, IQueryBuilder::PARAM_INT)), 'serverversion')
            ->from(self::TABLE_NAME)
            ->where($subQuery_serverversion->expr()->eq('category', $sql->createNamedParameter('server')))
            ->andWhere($subQuery_serverversion->expr()->eq('key', $sql->createNamedParameter('version')))
            ->andWhere($subQuery_serverversion->expr()->gt('timestamp',$sql->createNamedParameter('1596232800')));

        $sql->select('app.key')
            ->addSelect('server.serverversion')
            ->addSelect($sql->func()->count('app.source'))

            ->from(self::TABLE_NAME, 'app')
            ->leftJoin('app', $sql->createFunction('(' . $subQuery_serverversion->getSQL() . ')'), 'server', $sql->expr()->eq('app.source', 'server.source'))

            ->where($sql->expr()->eq('app.category', $sql->createNamedParameter('apps')))
            ->andWhere($sql->expr()->gt('app.timestamp',$sql->createNamedParameter('1596232800')))
            //->andWhere($sql->expr()->eq('app.key', $sql->createNamedParameter('contacts')))
            ->andWhere($sql->expr()->neq('server.serverversion', $sql->createNamedParameter('')))
            ->andWhere($sql->expr()->neq('server.serverversion', $sql->createNamedParameter('9.')))
            ->addGroupBy('server.serverversion')
            ->addGroupBy('app.key')
        ;
        return $sql;
    }

    private function getAppAdaptation ()
    {
        $sql = $this->db->getQueryBuilder();
        $subQuery_serverversion = $this->db->getQueryBuilder();
        $subQuery_serverversion->select('source')
            ->selectAlias($subQuery_serverversion->func()->substring('value',$subQuery_serverversion->expr()->literal(1, IQueryBuilder::PARAM_INT),$subQuery_serverversion->expr()->literal(2, IQueryBuilder::PARAM_INT)), 'serverversion')
            ->from(self::TABLE_NAME)
            ->where($subQuery_serverversion->expr()->eq('category', $sql->createNamedParameter('server')))
            ->andWhere($subQuery_serverversion->expr()->eq('key', $sql->createNamedParameter('version')))
            ->andWhere($subQuery_serverversion->expr()->gt('timestamp',$sql->createNamedParameter('1596232800')));

        $subQueryServerTotals = $this->db->getQueryBuilder();
        $subQueryServerTotals->select('serverversion.serverversion')
            ->selectAlias($sql->func()->count('serverversion.serverversion'), 'servertotals')
            ->from($sql->createFunction('(' . $subQuery_serverversion->getSQL() . ')'), 'serverversion')
            ->addGroupBy('serverversion.serverversion');


        $sql->select('app.key')
            ->addSelect('server.serverversion')
            ->selectAlias($sql->func()->count('app.source') , 'appcount')
            ->selectAlias('servertotals.servertotals', 'servertotals')
            ->from(self::TABLE_NAME, 'app')
            ->leftJoin('app', $sql->createFunction('(' . $subQuery_serverversion->getSQL() . ')'), 'server', $sql->expr()->eq('app.source', 'server.source'))
            ->leftJoin('app', $sql->createFunction('(' . $subQueryServerTotals->getSQL() . ')'), 'servertotals', $sql->expr()->eq('server.serverversion', 'servertotals.serverversion'))

            ->where($sql->expr()->eq('app.category', $sql->createNamedParameter('apps')))
            ->andWhere($sql->expr()->neq('server.serverversion', $sql->createNamedParameter('')))
            ->andWhere($sql->expr()->neq('server.serverversion', $sql->createNamedParameter('9.')))
            ->andWhere($sql->expr()->gt('app.timestamp',$sql->createNamedParameter('1596232800')))
            //->andWhere($sql->expr()->eq('app.key', $sql->createNamedParameter('contacts')))
            ->addGroupBy('server.serverversion')
            ->addGroupBy('app.key')
            ->addGroupBy('servertotals.servertotals')
            //->setMaxResults( 100 )
        ;
        return $sql;
    }

    private function getServerNumbers ()
    {
        $sql = $this->db->getQueryBuilder();
        $subQuery = $this->db->getQueryBuilder();
        $subQuery->select('source')
            ->selectAlias($subQuery->func()->substring('value',$subQuery->expr()->literal(1, IQueryBuilder::PARAM_INT),$subQuery->expr()->literal(2, IQueryBuilder::PARAM_INT)), 'serverversion')
            ->from(self::TABLE_NAME)
            ->where($subQuery->expr()->eq('category', $sql->createNamedParameter('server')))
            ->andWhere($subQuery->expr()->eq('key', $sql->createNamedParameter('version')))
            ->andWhere($subQuery->expr()->gt('timestamp',$sql->createNamedParameter('1596232800')));

        $sql->select('server.serverversion')
            ->addSelect($sql->func()->count('server.serverversion'))

            ->from($sql->createFunction('(' . $subQuery->getSQL() . ')'), 'server')
            ->andWhere($sql->expr()->neq('server.serverversion', $sql->createNamedParameter('')))
            ->andWhere($sql->expr()->neq('server.serverversion', $sql->createNamedParameter('9.')))
            ->addGroupBy('server.serverversion')
        ;
        return $sql;
    }

    private function getAllParameters ()
    {
        $sql = $this->db->getQueryBuilder();
        $sql->select('key')
            ->addSelect('value')
            ->addSelect($sql->func()->count('source'))

            ->from(self::TABLE_NAME)
            ->where($sql->expr()->gt('timestamp',$sql->createNamedParameter('1596232800')))
            ->andWhere($sql->expr()->neq('category', $sql->createNamedParameter('app')))
            ->addGroupBy('key', 'value')
        ;
        return $sql;
    }

}