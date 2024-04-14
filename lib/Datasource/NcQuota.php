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
use OCP\IUserManager;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

class NcQuota implements IDatasource
{
    /** @var LoggerInterface */
    private $logger;
    private $userManager;
    private $rootFolder;

    public function __construct(
        LoggerInterface $logger,
        IUserManager $userManager,
        IRootFolder $rootFolder
    )
    {
        $this->logger = $logger;
        $this->userManager = $userManager;
        $this->rootFolder = $rootFolder;
    }

    public function getName(): string
    {
        return 'User Quota *beta*';
    }

    public function getId(): int
    {
        return 28;
    }

    public function getTemplate(): array
    {
        $template = array();
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
        $allUsersQuota = [];
        $formattedOutput = [];

        $this->userManager->callForAllUsers(function (\OCP\IUser $user) use (&$formattedOutput) {
            $userId = $user->getUID();
            $displayName = $user->getDisplayName();
            $quota = $user->getQuota();

            // Parsing quota to numeric value and unit
            preg_match('/([0-9.]+)\s*([A-Za-z]+)/', $quota, $matches);
            $quotaValue = isset($matches[1]) ? $matches[1] : 0;
            $quotaUnit = isset($matches[2]) ? $matches[2] : 'B';

            $quotaInMB = $this->convertToMB($quotaValue, $quotaUnit);

            // Get current quota usage
            $userFolder = $this->rootFolder->getUserFolder($userId);
            $currentUsage = $userFolder->getSize();
            $currentUsageInMB = round($currentUsage / 1048576, 1);

            $formattedOutput[] = ["quota", $displayName, $quotaInMB];
            $formattedOutput[] = ["used", $displayName, $currentUsageInMB];

        });

        $header = array();
        $header[0] = 'Version';
        $header[1] = 'Count';

        return [
            'header' => $header,
            'data' => $formattedOutput,
            'rawData' => '',
            'error' => 0,
        ];
    }

    private function convertToMB($size, $unit) {
        $units = [
            'B' => 1 / 1048576,
            'KB' => 1 / 1024,
            'MB' => 1,
            'GB' => 1024,
            'TB' => 1048576
        ];

        return $size * $units[$unit];
    }
}