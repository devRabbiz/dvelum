<?php
/**
 *  DVelum project https://github.com/dvelum/dvelum
 *  Copyright (C) 2011-2017  Kirill Yegorov
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation, either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */
declare(strict_types=1);

namespace Dvelum\Orm;

use Dvelum\Config;
use Dvelum\Orm;
use Dvelum\Db;
use Dvelum\Service;

/**
 * Base class for data models
 */
class Model
{
    /**
     * @var Config\ConfigInterface
     *
     * // Global (For all Models) Hard caching time
     * 'hardCacheTime'  => 60,
     * // Default Cache_Interface
     * 'dataCache' => false  ,
     * // Db object storage interface
     * 'dbObjectStore'  => false,
     * // Default Connection manager
     * 'defaultDbManager' => false,
     * // Default error log adapter
     * 'errorLog' =>false
     */
    protected $settings;
    /**
     * DB Object Storage
     * @var Orm\Object\Store
     */
    protected $store;

    /**
     * Database connection
     * @var \Db_Adapter
     */
    protected $db;

    /**
     * Slave DB connection
     * @var Db\Adapter
     */
    protected $dbSlave;

    /**
     * Db_Object config
     * @var Orm\Object\Config
     */
    private $objectConfig = null;

    /**
     * @var Config\ConfigInterface
     */
    private $lightConfig = null;

    /**
     * Object / model name
     * @var string
     */
    protected $name;

    /**
     * Hard caching time (without validation) for frondend , seconds
     * @var int
     */
    protected $cacheTime;

    /**
     * Current Cache_Interface
     * @var \Cache_Interface
     */
    protected $cache;

    /**
     * DB table prefix
     * @var string
     */
    protected $dbPrefix = '';

    /**
     * Connection manager
     * @var \Db_Manager_Interface
     */
    protected $dbManager;

    /**
     * Table name
     * @var string
     */
    protected $table;

    /**
     * Current error log adapter
     * @var \Psr\Log\LoggerInterface | false
     */
    protected $log = false;

    /**
     * List of search fields
     * @var array | false
     */
    protected $searchFields = null;

    /**
     * Get DB table prefix
     * @return string
     */
    public function getDbPrefix(): string
    {
        return $this->dbPrefix;
    }

    /**
     * @param string $objectName
     * @throws \Exception
     */
    public function __construct(string $objectName, Config\ConfigInterface $settings)
    {
        $this->settings = $settings;

        $ormConfig = Config\Factory::storage()->get('orm.php', true, false);

        $this->store = $settings->get('dbObjectStore');
        $this->name = strtolower($objectName);
        $this->cacheTime = $settings->get('hardCacheTime');

        if ($settings->offsetExists('dataCache')) {
            $this->cache = $settings->get('dataCache');
        } else {
            $this->cache = false;
        }
        // backward compatibility
        $this->_cache =  &$this->cache;

        $this->dbManager = $settings->get('defaultDbManager');

        $this->lightConfig = Config\Factory::storage()->get($ormConfig->get('object_configs') . $this->name . '.php',
            true, false);

        $conName = $this->lightConfig->get('connection');
        $this->db = $this->dbManager->getDbConnection($conName);
        if ($this->lightConfig->offsetExists('slave_connection') && !empty($this->lightConfig->get('slave_connection'))) {
            $this->dbSlave = $this->dbSlave = $this->dbManager->getDbConnection($this->lightConfig->get('slave_connection'));
        } else {
            $this->dbSlave = $this->db;
        }

        if ($this->lightConfig->get('use_db_prefix')) {
            $this->dbPrefix = $this->dbManager->getDbConfig($conName)->get('prefix');
        } else {
            $this->dbPrefix = '';
        }

        $this->table = $this->lightConfig->get('table');

        if ($settings->get('errorLog')) {
            $this->log = $settings->get('errorLog');
        }
    }

    /**
     * Lazy load of ORM\Object\Config
     * @return Object\Config
     * @throws \Exception
     */
    public function getObjectConfig(): Orm\Object\Config
    {
        if (empty($this->objectConfig)) {
            try {
                $this->objectConfig = Orm\Object\Config::factory($this->name);
            } catch (\Exception $e) {
                throw new \Exception('Object ' . $this->name . ' is not exists');
            }
        }
        return $this->objectConfig;
    }

    /**
     * Get Object Storage
     * @return Orm\Object\Store
     */
    protected function getObjectsStore(): Orm\Object\Store
    {
        return $this->store;
    }

    /**
     * Set Database connector for concrete model
     * @param Db\Adapter $db
     */
    public function setDbConnection(Db\Adapter $db)
    {
        $this->db = $db;
    }

    /**
     * Set the adapter of the object store
     * @param Orm\Object\Store $store
     */
    public function setObjectsStore(Orm\Object\Store $store)
    {
        $this->store = $store;
    }

    /**
     * Set hardcaching time for concrete model
     * @param integer $time
     */
    public function setHardCacheTitme($time)
    {
        $this->cacheTime = $time;
    }

    /**
     * Get Master Db connector
     * return Db\Adapter
     */
    public function getDbConnection(): Db\Adapter
    {
        return $this->db;
    }

    /**
     * Get Slave Db Connection
     * @return Db\Adapter
     */
    public function getSlaveDbConnection(): Db\Adapter
    {
        return $this->dbSlave;
    }

    /**
     * Get current db manager
     * @return \Db_Manager_Interface
     */
    public function getDbManager(): \Db_Manager_Interface
    {
        return $this->dbManager;
    }

    /**
     * Get storage adapter
     * @return Orm\Object\Store
     */
    public function getStore(): Orm\Object\Store
    {
        return $this->store;
    }

    /**
     * Factory method of model instantiation
     * @param string $objectName — the name of the object in ORM
     * @return Model
     */
    static public function factory(string $objectName): Model
    {
        /**
         * @var Orm $service
         */
        $service = Service::get('orm');
        return $service->model($objectName);
    }

    /**
     * Get the name of the object, which the model refers to
     * @return string
     */
    public function getObjectName(): string
    {
        return $this->name;
    }

    /**
     * Get key for cache
     * @param array $params - parameters can not contain arrays, objects and resources
     * @return string
     */
    public function getCacheKey(array $params): string
    {
        return md5($this->getObjectName() . '-' . implode('-', $params));
    }

    /**
     * Get the name of the database table (with prefix)
     * @return string
     */
    public function table(): string
    {
        return $this->dbPrefix . $this->table;
    }

    /**
     * Get record by id
     * @param integer $id
     * @param array|string $fields — optional — the list of fields to retrieve
     * @return array|false
     */
    final public function getItem($id, $fields = '*')
    {
        $primaryKey = $this->getPrimaryKey();
        $result = $this->query()
                    ->filters([
                        $primaryKey  => $id
                    ])
                    ->fields($fields)
                    ->fetchRow();

        if(empty($result)){
            $result = false;
        }
        return $result;
    }

    /**
     *  Get the object data using cache
     * @param integer $id - object identifier
     * @return array
     */
    public function getCachedItem($id)
    {
        if (!$this->cache) {
            return $this->getItem($id);
        }

        $cacheKey = $this->getCacheKey(array('item', $id));
        $data = $this->cache->load($cacheKey);

        if ($data !== false) {
            return $data;
        }

        $data = $this->getItem($id);

        if ($this->cache) {
            $this->cache->save($data, $cacheKey);
        }

        return $data;
    }

    /**
     * Get data record by field value using cache. Returns first occurrence
     * @param string $field - field name
     * @param string $value - field value
     * @return array
     */
    public function getCachedItemByField(string $field, $value)
    {
        $cacheKey = $this->getCacheKey(array('item', $field, $value));
        $data = false;

        if ($this->cache) {
            $data = $this->cache->load($cacheKey);
        }

        if ($data !== false) {
            return $data;
        }

        $data = $this->getItemByField($field, $value);

        if ($this->cache && $data) {
            $this->cache->save($data, $cacheKey);
        }

        return $data;
    }

    /**
     * Get Item by field value. Returns first occurrence
     * @param string $fieldName
     * @param $value
     * @param string $fields
     * @return array|null
     */
    public function getItemByField(string $fieldName, $value, $fields = '*')
    {
        $sql = $this->dbSlave->select()->from($this->table(), $fields);
        $sql->where($this->dbSlave->quoteIdentifier($fieldName) . ' = ?', $value)->limit(1);
        return $this->dbSlave->fetchRow($sql);
    }

    /**
     * Get a number of entries a list of IDs
     * @param array $ids - list of IDs
     * @param mixed $fields - optional - the list of fields to retrieve
     * @param bool $useCache - optional, defaul false
     * @return array / false
     */
    final public function getItems(array $ids, $fields = '*', $useCache = false)
    {
        $data = false;

        if (empty($ids)) {
            return [];
        }

        if ($useCache && $this->cache) {
            $cacheKey = $this->getCacheKey(array('list', serialize(func_get_args())));
            $data = $this->cache->load($cacheKey);
        }

        if ($data === false) {
            $sql = $this->dbSlave->select()->from($this->table(),
                    $fields)->where($this->dbSlave->quoteIdentifier($this->getPrimaryKey()) . ' IN(' . \Utils::listIntegers($ids) . ')');
            $data = $this->dbSlave->fetchAll($sql);

            if (!$data) {
                $data = [];
            }

            if ($useCache && $this->cache) {
                $this->cache->save($data, $cacheKey, $this->cacheTime);
            }

        }
        return $data;
    }

    /**
     * Create Model\Query
     * @return Model\Query
     */
    public function query(): Model\Query
    {
        return new Model\Query($this);
    }

    /**
     * Get object title
     * @param Orm\Object $object - object for getting title
     * @return mixed|string - object title
     * @throws \Exception
     */
    public function getTitle(Orm\Object $object)
    {
        $objectConfig = $object->getConfig();
        $title = $objectConfig->getLinkTitle();
        if (strpos($title, '{') !== false) {
            $fields = $objectConfig->getFieldsConfig(true);
            foreach ($fields as $name => $cfg) {
                $value = $object->get($name);
                if (is_array($value)) {
                    $value = implode(', ', $value);
                }
                $title = str_replace('{' . $name . '}', (string)$value, $title);
            }
        } else {
            if ($object->fieldExists($title)) {
                $title = $object->get($title);
            }
        }
        return $title;
    }

    /**
     * Delete record
     * @param mixed $recordId record ID
     * @return bool
     */
    public function remove($recordId): bool
    {
        try {
            $object = Orm\Object::factory($this->name, $recordId);
        } catch (\Exception $e) {
            $this->logError('Remove record ' . $recordId . ' : ' . $e->getMessage());
            return false;
        }

        if ($this->getObjectsStore()->delete($object)) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * Check whether the field value is unique
     * Returns true if value $fieldValue is unique for $fieldName field
     * otherwise returns false
     * @param int $recordId — record ID
     * @param string $fieldName — field name
     * @param mixed $fieldValue — field value
     * @return boolean
     */
    public function checkUnique(int $recordId, string $fieldName, $fieldValue): bool
    {
        return !(boolean)$this->dbSlave->fetchOne($this->dbSlave->select()->from($this->table(),
                array('count' => 'COUNT(*)'))->where($this->dbSlave->quoteIdentifier($this->getPrimaryKey()) . ' != ?',
                $recordId)->where($this->dbSlave->quoteIdentifier($fieldName) . ' =?', $fieldValue));
    }

    /**
     * Get primary key name
     * @return string
     */
    public function getPrimaryKey(): string
    {
        $key = '';

        if ($this->lightConfig->offsetExists('primary_key')) {
            $key = $this->lightConfig->get('primary_key');
        }

        if (empty($key)) {
            return 'id';
        } else {
            return $key;
        }
    }

    /**
     * Set DB connections manager (since 0.9.1)
     * @param \Db_Manager_Interface $manager
     * @return void
     */
    public function setDbManager(\Db_Manager_Interface $manager): void
    {
        $conName = $this->lightConfig->get('connection');
        $this->dbManager = $manager;
        $this->db = $this->dbManager->getDbConnection($conName);
        $this->dbSlave = $this->dbManager->getDbConnection($this->lightConfig->get('slave_connection'));
        $this->refreshTableInfo();
    }

    public function refreshTableInfo()
    {
        $conName = $this->lightConfig->get('connection');
        $this->db = $this->dbManager->getDbConnection($conName);

        if ($this->objectConfig->hasDbPrefix()) {
            $this->dbPrefix = $this->dbManager->getDbConfig($conName)->get('prefix');
        } else {
            $this->dbPrefix = '';
        }

        $this->table = $this->lightConfig->get('table');
    }

    /**
     * Set current log adapter
     * @param mixed \Log | false  $log
     */
    public function setLog($log): void
    {
        $this->log = $log;
    }

    /**
     * Get logs Adapter
     * @return \Log
     */
    public function getLogsAdapter()
    {
        return $this->log;
    }

    /**
     * Log error message
     * @param string $message
     * @return void
     */
    public function logError(string $message): void
    {
        if (!$this->log) {
            return;
        }

        $this->log->log(\Psr\Log\LogLevel::ERROR, get_called_class() . ': ' . $message);
    }

    /**
     * Get list of search fields (get from ORM)
     */
    public function getSearchFields()
    {
        if (is_null($this->searchFields)) {
            $this->searchFields = $this->getObjectConfig()->getSearchFields();
        }
        return $this->searchFields;
    }

    /**
     * Set
     * @param array $fields
     * @return void
     */
    public function setSearchFields(array $fields): void
    {
        $this->searchFields = $fields;
    }

    /**
     * Reset search fields list (get from ORM)
     * @return void
     */
    public function resetSearchFields(): void
    {
        $this->searchFields = null;
    }

    /**
     * Get Orm\Object config array
     * @return Config\ConfigInterface
     */
    public function getLightConfig(): Config\ConfigInterface
    {
        return $this->lightConfig;
    }

    /**
     * @return bool|\Cache_Interface
     */
    public function getCacheAdapter()
    {
        return $this->cache;
    }

    public function getCacheTime()
    {
        return $this->cacheTime;
    }

    public function __call($name, $arguments)
    {
        static $deprecatedFunctions = [];

        $objectName = $this->getObjectName();
        if(!isset($deprecatedFunctions[$objectName])){
            $deprecatedFunctions[$objectName] = new Model\Deprecated($this);
        }

        if(method_exists($deprecatedFunctions[$objectName], $name)){
           // trigger_error('Deprecated method call'. get_called_class().'::'.$name,E_USER_NOTICE);
            return call_user_func_array([$deprecatedFunctions[$objectName],$name], $arguments);
        }
    }
}