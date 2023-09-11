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

namespace OCA\Analytics_Sourcepack\Listener;

use OCA\Analytics_Sourcepack\Datasource\Salesforce;
use OCA\Analytics_Sourcepack\Datasource\Odoo;
use OCA\Analytics\Datasource\DatasourceEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

class Listener implements IEventListener {
    public function handle(Event $event): void {
        if (!($event instanceof DatasourceEvent)) {
            // Unrelated
            return;
        }
        $event->registerDatasource(Salesforce::class);
        $event->registerDatasource(Odoo::class);
    }
}