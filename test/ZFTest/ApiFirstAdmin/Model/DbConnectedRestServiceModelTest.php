<?php

namespace ZFTest\ApiFirstAdmin\Model;

use BarConf;
use PHPUnit_Framework_TestCase as TestCase;
use ReflectionClass;
use Zend\Config\Writer\PhpArray;
use Zend\EventManager\Event;
use ZF\ApiFirstAdmin\Model\DbConnectedRestServiceModel;
use ZF\ApiFirstAdmin\Model\DbConnectedRestServiceEntity;
use ZF\ApiFirstAdmin\Model\RestServiceEntity;
use ZF\ApiFirstAdmin\Model\RestServiceModel;
use ZF\Configuration\ResourceFactory;
use ZF\Configuration\ModuleUtils;

require_once __DIR__ . '/TestAsset/module/BarConf/Module.php';

class DbConnectedRestServiceModelTest extends TestCase
{
    /**
     * Remove a directory even if not empty (recursive delete)
     *
     * @param  string $dir
     * @return boolean
     */
    protected function removeDir($dir)
    {
        $files = array_diff(scandir($dir), array('.', '..'));
        foreach ($files as $file) {
            $path = "$dir/$file";
            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                unlink($path);
            }
        }
        return rmdir($dir);
    }

    protected function cleanUpAssets()
    {
        $basePath   = sprintf('%s/TestAsset/module/%s', __DIR__, $this->module);
        $configPath = $basePath . '/config';
        $srcPath    = $basePath . '/src';
        if (is_dir($srcPath)) {
            $this->removeDir($srcPath);
        }
        copy($configPath . '/module.config.php.dist', $configPath . '/module.config.php');
    }

    public function setUp()
    {
        $this->module = 'BarConf';
        $this->cleanUpAssets();

        $modules = array(
            'BarConf' => new BarConf\Module()
        );

        $this->moduleManager = $this->getMockBuilder('Zend\ModuleManager\ModuleManager')
                                    ->disableOriginalConstructor()
                                    ->getMock();
        $this->moduleManager->expects($this->any())
                            ->method('getLoadedModules')
                            ->will($this->returnValue($modules));

        $this->writer   = new PhpArray();
        $this->modules  = new ModuleUtils($this->moduleManager);
        $this->resource = new ResourceFactory($this->modules, $this->writer);
        $this->codeRest = new RestServiceModel($this->module, $this->modules, $this->resource->factory('BarConf'));
        $this->model    = new DbConnectedRestServiceModel($this->codeRest);
        $this->codeRest->getEventManager()->attach('fetch', array($this->model, 'onFetch'));
    }

    public function tearDown()
    {
        $this->cleanUpAssets();
    }

    public function getCreationPayload()
    {
        $payload = new DbConnectedRestServiceEntity();
        $payload->exchangeArray(array(
            'adapter_name'               => 'DB\Barbaz',
            'table_name'                 => 'barbaz',
            'hydrator_name'              => 'ObjectProperty',
            'resource_http_methods'      => array('GET', 'PATCH'),
            'collection_http_methods'    => array('GET', 'POST'),
            'collection_query_whitelist' => array('sort', 'filter'),
            'page_size'                  => 10,
            'page_size_param'            => 'p',
            'selector'                   => 'HalJson',
            'accept_whitelist'           => array('application/json', 'application/*+json'),
            'content_type_whitelist'     => array('application/json'),
        ));
        return $payload;
    }

    public function testCreateServiceReturnsDbConnectedRestServiceEntity()
    {
        $originalEntity = $this->getCreationPayload();
        $result         = $this->model->createService($originalEntity);
        $this->assertSame($originalEntity, $result);

        $this->assertEquals('BarConf\Rest\Barbaz\Controller', $result->controllerServiceName);
        $this->assertEquals('BarConf\Rest\Barbaz\BarbazResource', $result->resourceClass);
        $this->assertEquals('BarConf\Rest\Barbaz\BarbazEntity', $result->entityClass);
        $this->assertEquals('BarConf\Rest\Barbaz\BarbazCollection', $result->collectionClass);
        $this->assertEquals('BarConf\Rest\Barbaz\BarbazResource\Table', $result->tableService);
        $this->assertEquals('barbaz', $result->tableName);
        $this->assertEquals('bar-conf.rest.barbaz', $result->routeName);
    }

    public function testEntityCreatedViaCreateServiceIsAnArrayObjectExtension()
    {
        $originalEntity = $this->getCreationPayload();
        $result         = $this->model->createService($originalEntity);
        include __DIR__ . '/TestAsset/module/BarConf/src/BarConf/Rest/Barbaz/BarbazEntity.php';
        $r = new ReflectionClass('BarConf\Rest\Barbaz\BarbazEntity');
        $parent = $r->getParentClass();
        $this->assertInstanceOf('ReflectionClass', $parent);
        $this->assertEquals('ArrayObject', $parent->getName());
    }

    public function testCreateServiceWritesDbConnectedConfigurationUsingResourceClassAsKey()
    {
        $originalEntity = $this->getCreationPayload();
        $result         = $this->model->createService($originalEntity);
        $config = include __DIR__ . '/TestAsset/module/BarConf/config/module.config.php';

        $this->assertArrayHasKey('zf-api-first', $config);
        $this->assertArrayHasKey('db-connected', $config['zf-api-first']);
        $this->assertArrayHasKey($result->resourceClass, $config['zf-api-first']['db-connected']);

        $resourceConfig = $config['zf-api-first']['db-connected'][$result->resourceClass];
        $this->assertArrayHasKey('table_name', $resourceConfig);
        $this->assertArrayHasKey('hydrator_name', $resourceConfig);
        $this->assertArrayHasKey('controller_service_name', $resourceConfig);

        $this->assertEquals('barbaz', $resourceConfig['table_name']);
        $this->assertEquals($result->hydratorName, $resourceConfig['hydrator_name']);
        $this->assertEquals($result->controllerServiceName, $resourceConfig['controller_service_name']);
    }

    public function testCreateServiceDoesNotCreateResourceClass()
    {
        $originalEntity = $this->getCreationPayload();
        $result         = $this->model->createService($originalEntity);
        $this->assertFalse(file_exists(__DIR__ . '/TestAsset/module/BarConf/src/BarConf/Rest/Barbaz/BarbazResource.php'));
    }

    public function testOnFetchWillRecastEntityToDbConnectedIfDbConnectedConfigurationExists()
    {
        $originalData = array(
            'controller_service_name' => 'BarConf\Rest\Barbaz\Controller',
            'resource_class'          => 'BarConf\Rest\Barbaz\BarbazResource',
            'route_name'              => 'bar-conf.rest.barbaz',
            'route_match'             => '/api/barbaz',
            'entity_class'            => 'BarConf\Rest\Barbaz\BarbazEntity',
        );
        $entity = new RestServiceEntity();
        $entity->exchangeArray($originalData);
        $config = array( 'zf-api-first' => array('db-connected' => array(
            'BarConf\Rest\Barbaz\BarbazResource' => array(
                'adapter_name'  => 'Db\Barbaz',
                'table_name'    => 'barbaz',
                'hydrator_name' => 'ObjectProperty',
            ),
        )));

        $event = new Event();
        $event->setParam('entity', $entity);
        $event->setParam('config', $config);
        $result = $this->model->onFetch($event);
        $this->assertInstanceOf('ZF\ApiFirstAdmin\Model\DbConnectedRestServiceEntity', $result);
        $asArray = $result->getArrayCopy();
        foreach ($originalData as $key => $value) {
            $this->assertArrayHasKey($key, $asArray);
            $this->assertEquals($value, $asArray[$key], sprintf("Failed testing key '%s'\nEntity is: %s\n", $key, var_export($asArray, 1)));
        }
        foreach ($config['zf-api-first']['db-connected']['BarConf\Rest\Barbaz\BarbazResource'] as $key => $value) {
            $this->assertArrayHasKey($key, $asArray);
            $this->assertEquals($value, $asArray[$key]);
        }
        $this->assertArrayHasKey('table_service', $asArray);
        $this->assertEquals($entity->resourceClass . '\\Table', $asArray['table_service']);
    }

    public function testUpdateServiceReturnsUpdatedDbConnectedRestServiceEntity()
    {
        $originalEntity = $this->getCreationPayload();
        $this->model->createService($originalEntity);

        $newProps = array(
            'table_service' => 'My\Custom\Table',
            'adapter_name'  => 'My\Db',
            'hydrator_name' => 'ClassMethods',
        );
        $originalEntity->exchangeArray($newProps);
        $result = $this->model->updateService($originalEntity);

        $this->assertInstanceOf('ZF\ApiFirstAdmin\Model\DbConnectedRestServiceEntity', $result);
        $this->assertNotSame($originalEntity, $result);
        $this->assertEquals($newProps['table_service'], $result->tableService);
        $this->assertEquals($newProps['adapter_name'], $result->adapterName);
        $this->assertEquals($newProps['hydrator_name'], $result->hydratorName);
    }

    public function testUpdateServiceUpdatesDbConnectedConfiguration()
    {
        $originalEntity = $this->getCreationPayload();
        $this->model->createService($originalEntity);

        $newProps = array(
            'table_service' => 'My\Custom\Table',
            'adapter_name'  => 'My\Db',
            'hydrator_name' => 'ClassMethods',
        );
        $originalEntity->exchangeArray($newProps);
        $result = $this->model->updateService($originalEntity);

        $config = include __DIR__ . '/TestAsset/module/BarConf/config/module.config.php';
        $this->assertArrayHasKey('zf-api-first', $config);
        $this->assertArrayHasKey('db-connected', $config['zf-api-first']);
        $this->assertArrayHasKey($result->resourceClass, $config['zf-api-first']['db-connected']);

        $resourceConfig = $config['zf-api-first']['db-connected'][$result->resourceClass];
        $this->assertArrayHasKey('adapter_name', $resourceConfig);
        $this->assertArrayHasKey('table_service', $resourceConfig);
        $this->assertArrayHasKey('table_name', $resourceConfig);
        $this->assertArrayHasKey('hydrator_name', $resourceConfig);

        $this->assertEquals($newProps['adapter_name'], $resourceConfig['adapter_name']);
        $this->assertEquals($newProps['table_service'], $resourceConfig['table_service']);
        $this->assertEquals('barbaz', $resourceConfig['table_name']);
        $this->assertEquals($newProps['hydrator_name'], $resourceConfig['hydrator_name']);
    }

    public function testDeleteServiceRemovesDbConnectedConfigurationForEntity()
    {
        $originalEntity = $this->getCreationPayload();
        $this->model->createService($originalEntity);
        $this->model->deleteService($originalEntity);

        $config = include __DIR__ . '/TestAsset/module/BarConf/config/module.config.php';
        $this->assertArrayHasKey('zf-api-first', $config);
        $this->assertArrayHasKey('db-connected', $config['zf-api-first']);
        $this->assertArrayNotHasKey($originalEntity->resourceClass, $config['zf-api-first']['db-connected']);
    }
}
