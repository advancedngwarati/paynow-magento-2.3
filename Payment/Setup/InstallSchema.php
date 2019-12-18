<?php
/**
 * Created by PhpStorm.
 * User: user
 * Date: 1/27/2018
 * Time: 9:47 AM
 */

namespace Paynow\Payment\Setup;

use Magento\Framework\Setup\InstallSchemaInterface;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\SchemaSetupInterface;
use Magento\Framework\DB\Ddl\Table;

class InstallSchema implements InstallSchemaInterface
{

    public function install(SchemaSetupInterface $setup, ModuleContextInterface $context)
    {
        $installer = $setup;
        $installer->startSetup();

        $installer->run("

            CREATE TABLE IF NOT EXISTS `paynow` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `order_id` int(11) NOT NULL,
            `browser_url` varchar(500) NOT NULL,
            `poll_url` varchar(500) NOT NULL,
            `paynow_id` int(11) NOT NULL,
            `amount` decimal(11,2) NOT NULL,
            `paynow_reference` varchar(20) NOT NULL,
            `reference` varchar(20) NOT NULL,
            `status` varchar(20) NOT NULL,
            PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8;
        
        ");

	    $installer->endSetup();

    }
}