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

namespace Dvelum\App\Backend\Api;

use Dvelum\{
    Request, Response, Config, App, Orm, Service, Utils
};
use Dvelum\Orm\{ObjectInterface, Model};
use Dvelum\App\{Data,Session,Dictionary};
use Dvelum\App\Controller\EventManager;

class Controller extends App\Backend\Controller
{
    /**
     * List of ORM object field names displayed in the main list (listAction)
     * They may be assigned a value, as well as an array
     * Empty value means all fields will be fetched from DB, except long text fields
     */
    protected $listFields = [];
    /**
     * List of ORM objects accepted via linkedListAction and otitleAction
     * @var array
     */
    protected $canViewObjects = [];
    /**
     * List of ORM object link fields displayed with related values in the main list (listAction)
     * (dictionary, object link, object list) key - result field, value - object field
     * object field will be used as result field for numeric keys
     * Requires primary key in result set
     * @var array
     */
    protected $listLinks = [];
    /**
     * Controller events manager
     * @var App\Controller\EventManager
     */
    protected $eventManager;

    /**
     * API Request object
     * @var Data\Api\Request
     */
    protected $apiRequest;

    /**
     * Object titles separator
     * @var string $linkedInfoSeparator
     */
    protected $linkedInfoSeparator = '; ';

    /**
     * Controller constructor.
     * @param Request $request
     * @param Response $response
     */
    public function __construct(Request $request, Response $response)
    {
        parent::__construct($request, $response);

        $this->apiRequest = $this->getApiRequest($this->request);
        $this->eventManager = new App\Controller\EventManager();
        $this->canViewObjects[] = $this->objectName;
        $this->canViewObjects = \array_map('strtolower', $this->canViewObjects);

        $this->initListeners();
    }

    /**
     *  Event listeners can be defined here
     */
    public function initListeners(){}

    /**
     * @param Data\Api\Request $request
     * @param Session\User $user
     * @return Data\Api
     */
    protected function getApi(Data\Api\Request $request, Session\User $user) : Data\Api
    {
        $api = new Data\Api($request, $user);
        if(!empty($this->listFields)){
            $api->setFields($this->listFields);
        }
        return $api;
    }

    /**
     * @param Request $request
     * @return Data\Api\Request
     */
    protected function getApiRequest(Request $request) : Data\Api\Request
    {
        $request = new Data\Api\Request($request);
        $request->setObjectName($this->getObjectName());
        return $request;
    }

    /**
     * Get list of objects which can be linked
     */
    public function linkedListAction()
    {
        $object = $this->request->post('object', 'string', false);
        $filter = $this->request->post('filter' , 'array' , []);
        $pager = $this->request->post('pager' , 'array' , []);
        $query = $this->request->post('search' , 'string' , null);

        $filter = array_merge($filter , $this->request->extFilters());

        if($object === false || !Orm\Object\Config::configExists($object)){
            $this->response->error($this->lang->get('WRONG_REQUEST'));
            return;
        }

        if(!in_array(strtolower($object), $this->canViewObjects , true)){
            $this->response->error($this->lang->get('CANT_VIEW'));
            return;
        }


        $objectCfg = Orm\Object\Config::factory($object);
        $primaryKey = $objectCfg->getPrimaryKey();

        $objectConfig = Orm\Object\Config::factory($object);

        // Check ACL permissions
        $acl = $objectConfig->getAcl();

        if($acl){
            if(!$acl->can(Orm\Object\Acl::ACCESS_VIEW , $object)){
                $this->response->error($this->lang->get('ACL_ACCESS_DENIED'));
                return;
            }
        }
        /**
         * @var Model
         */
        $model = Model::factory($object);
        $rc = $objectCfg->isRevControl();

        if($objectCfg->isRevControl())
            $fields = array('id'=>$primaryKey, 'published');
        else
            $fields = array('id'=>$primaryKey);

        $count = $model->query()->search($query)->getCount();
        $data = array();
        if($count)
        {
            $data = $model->query()
                            ->filters($filter)
                            ->params($pager)
                            ->fields($fields)
                            ->search($query)
                            ->fetchAll();

            if(!empty($data))
            {
                $objectIds = \Utils::fetchCol('id' , $data);
                try{
                    $objects = Orm\Object::factory($object ,$objectIds);
                }catch (\Exception $e){
                    Model::factory($object)->logError('linkedlistAction ->'.$e->getMessage());
                    $this->response->error($this->lang->get('CANT_EXEC'));
                }

                foreach ($data as &$item)
                {
                    if(!$rc)
                        $item['published'] = true;


                    $item['deleted'] = false;

                    if(isset($objects[$item['id']])){
                        $o = $objects[$item['id']];
                        $item['title'] = $o->getTitle();
                        if($rc)
                            $item['published'] = $o->get('published');
                    }else{
                        $item['title'] = $item['id'];
                    }

                }unset($item);
            }
        }
        $this->response->success($data, ['count'=>$count]);
    }

    /**
     * @deprecated
     */
    public function oTitleAction()
    {
        $this->objectTitleAction();
    }
    /**
     * Get object title
     */
    public function objectTitleAction()
    {
        $object = $this->request->post('object','string', false);
        $id = $this->request->post('id', 'string', false);

        if(!$object || !Orm\Object\Config::configExists($object))
            $this->response->error($this->lang->get('WRONG_REQUEST'));

        if(!in_array(strtolower($object), $this->canViewObjects , true))
            $this->response->error($this->lang->get('CANT_VIEW'));

        $objectConfig = Orm\Object\Config::factory($object);
        // Check ACL permissions
        $acl = $objectConfig->getAcl();
        if($acl){
            if(!$acl->can(Orm\Object\Acl::ACCESS_VIEW , $object)){
                $this->response->error($this->lang->get('ACL_ACCESS_DENIED'));
            }
        }

        try {
            $o = Orm\Object::factory($object, $id);
            $this->response->success(array('title'=>$o->getTitle()));
        }catch (\Exception $e){
            Model::factory($object)->logError('Cannot get title for '.$object.':'.$id);
            $this->response->error($this->lang->get('CANT_EXEC'));
        }
    }

    /**
     * Get list of items. Returns JSON reply with
     * ORM object field data or return array with data and count;
     * Filtering, pagination and search are available
     * Sends JSON reply in the result
     * and closes the application (by default).
     * @throws \Exception
     * @return void
     */
    public function listAction()
    {
        if(!$this->eventManager->fireEvent(EventManager::BEFORE_LIST, new \stdClass())){
            $this->response->error($this->eventManager->getError());
        }

        $result = $this->getList();

        $eventData = new \stdClass();
        $eventData->data = $result['data'];
        $eventData->count = $result['count'];

        if(!$this->eventManager->fireEvent(EventManager::AFTER_LIST, $eventData)){
            $this->response->error($this->eventManager->getError());
        }

        $this->response->success(
            $eventData->data,
            ['count'=>$eventData->count]
        );
    }

    /**
     * Prepare data for listAction
     * backward compatibility
     * @return array
     * @throws \Exception
     */
    protected function getList()
    {
        $api = $this->getApi($this->apiRequest, $this->user);

        $count = $api->getCount();

        if(!$count){
            return ['data'=>[],'count'=>0];
        }

        $data = $api->getList();

        if(!empty($this->listLinks))
        {
            $objectConfig = Orm\Object\Config::factory($this->objectName);
            if(!in_array($objectConfig->getPrimaryKey(),'',true)){
                throw new \Exception('listLinks requires primary key for object '.$objectConfig->getName());
            }
            $this->addLinkedInfo($objectConfig, $this->listLinks, $data, $objectConfig->getPrimaryKey());
        }

        return ['data' =>$data , 'count'=> $count];
    }


    /**
     * Create/edit object data
     * The type of operation is defined as per the parameters being transferred
     * Sends JSON reply in the result and
     * closes the application
     */
    public function editAction()
    {
        $id = $this->request->post('id' , 'integer' , false);
        if(! $id)
            $this->createAction();
        else
            $this->updateAction();
    }

    /**
     * Create object
     * Sends JSON reply in the result and
     * closes the application
     */
    public function createAction()
    {
        $this->checkCanEdit();
        $this->insertObject($this->getPostedData($this->objectName));
    }

    /**
     * Update object data
     * Sends JSON reply in the result and
     * closes the application
     */
    public function updateAction()
    {
        $this->checkCanEdit();
        $this->updateObject($this->getPostedData($this->objectName));
    }

    /**
     * Delete object
     * Sends JSON reply in the result and
     * closes the application
     */
    public function deleteAction()
    {
        $this->checkCanDelete();
        $id = $this->request->post('id' , 'integer' , false);

        if(!$id){
            $this->response->error($this->lang->get('WRONG_REQUEST'));
            return;
        }

        try{
            $object =  Orm\Object::factory($this->objectName , $id);
        }catch(\Exception $e){
            $this->response->error($this->lang->get('WRONG_REQUEST'));
            return;
        }

        $acl = $object->getAcl();
        if($acl && !$acl->canDelete($object)){
            $this->response->error($this->lang->get('CANT_DELETE'));
        }

        if($this->appConfig->get('vc_clear_on_delete')){
            /**
             * @var \Model_Vc $vcModel
             */
            $vcModel = Model::factory('Vc');
            $vcModel->removeItemVc($this->objectName , $id);
        }

        if(!$object->delete())
            $this->response->error($this->lang->get('CANT_EXEC'));

        $this->response->success();
    }

    /**
     * Save new ORM object (insert data)
     * Sends JSON reply in the result and
     * closes the application
     * @param Orm\Object $object
     * @return void
     */
    public function insertObject(Orm\Object $object)
    {
        if(!$recId = $object->save()){
            $this->response->error($this->lang->get('CANT_EXEC'));
            return;
        }

        $this->response->success(['id' => $recId]);
    }

    /**
     * Update ORM object data
     * Sends JSON reply in the result and
     * closes the application
     * @param Orm\Object $object
     */
    public function updateObject(Orm\Object $object)
    {
        if(!$object->save()){
            $this->response->error($this->lang->get('CANT_EXEC'));
            return;
        }

        $this->response->success(['id' => $object->getId()]);
    }

    /**
     * Get posted data and put it into Orm\Object
     * (in case of failure, JSON error message is sent)
     * @param string $objectName
     * @return Orm\Object
     */
    public function getPostedData($objectName)
    {
        $formCfg = $this->config->get('form');
        $adapterConfig = Config::storage()->get($formCfg['config']);
        $adapterConfig->set('orm_object', $objectName);
        /**
         * @var App\Form\Adapter $form
         */
        $form = new $formCfg['adapter'](
            $this->request,
            $this->lang,
            $adapterConfig
        );

        if(!$form->validateRequest())
        {
            $errors = $form->getErrors();
            $formMessages = [$this->lang->get('FILL_FORM')];
            $fieldMessages = [];
            /**
             * @var App\Form\Error $item
             */
            foreach ($errors as $item)
            {
                $field = $item->getField();
                if(empty($field)){
                    $formMessages[] = $item->getMessage();
                }else{
                    $fieldMessages[$field] = $item->getMessage();
                }
            }
            $this->response->error(implode('; <br>', $formMessages) , $fieldMessages);
        }
        return $form->getData();
    }

    /**
     * Get ORM object data
     * Sends a JSON reply in the result and
     * closes the application
     */
    public function loadDataAction()
    {
        $objectName = $this->getObjectName();

        if(!$this->eventManager->fireEvent(EventManager::BEFORE_LOAD, new \stdClass())){
            $this->response->error($this->eventManager->getError());
            return;
        }
        try{
           $result = $this->getData();
        }catch (OwnerException $e){
            $this->response->error($this->lang->get('CANT_ACCESS'));
            return;
        }catch (LoadException $e){
            $this->response->error($this->lang->get('CANT_LOAD'));
            return;
        } catch (\Exception $e){
            $this->response->error($this->lang->get('CANT_EXEC'));
            return;
        }

        $eventData = new \stdClass();
        $eventData->data = $result;

        if(!$this->eventManager->fireEvent(EventManager::AFTER_LOAD, $eventData)){
            $this->response->error($this->eventManager->getError());
            return;
        }

        if(empty($eventData->data)){
            $this->response->error($this->lang->get('CANT_EXEC'));
            return;
        }else{
            $this->response->success($eventData->data);
            return;
        }
    }

    /**
     * Prepare data for loaddataAction
     * @return array
     * @throws \Exception
     */
    protected function getData()
    {
        $id = $this->request->post('id' , 'int' , false);
        $objectName = $this->getObjectName();
        $objectConfig = Orm\Object\Config::factory($objectName);

        if(!$id){
            return [];
        }

        try{
            /**
             * @var $obj Orm\Object
             */
            $obj = Orm\Object::factory($objectName , $id);
        }catch(\Exception $e){
            Model::factory($objectName)->logError($e->getMessage());
            return [];
        }


        if($objectConfig->isRevControl())
        {
            if(!$this->checkOwner($obj)){
                throw new OwnerException($this->lang->get('CANT_ACCESS'));
            }
            /**
             * @var \Model_Vc $vc
             */
            $vc = Model::factory('Vc');
            $version = $this->request->post('version' , 'int' , 0);
            if(!$version){
                $version = $vc->getLastVersion($objectName , $id);
            }

            try {
                $obj->loadVersion($version);
            } catch (\Exception $e) {
                Model::factory($objectName)->logError('Cannot load version ' . $version . ' for ' . $objectName . ':' . $obj->getId());
                throw new LoadException($e->getMessage());
            }

            $data = $obj->getData();
            $data['id'] = $id;
            $data['version'] = $version;
            $data['published'] = $obj->get('published');
            $data['staging_url'] = $this->getStagingUrl($obj);

        }else{
            $data = $obj->getData();
            $data['id'] = $obj->getId();
        }



        /*
         * Prepare object list properties
         */
        $linkedObjects = $obj->getConfig()->getLinks([Orm\Object\Config::LINK_OBJECT_LIST]);

        foreach($linkedObjects as $linkObject => $fieldCfg){
            foreach($fieldCfg as $field => $linkCfg){
                $data[$field] = $this->collectLinksData($field , $obj , $linkObject);
            }
        }
        $data['id'] = $obj->getId();
        return $data;
    }

    /**
     * Add related objects info into getList results
     * @param Orm\Object\Config $cfg
     * @param array $fieldsToShow  list of link fields to process ( key - result field, value - object field)
     * object field will be used as result field for numeric keys
     * @param array & $data rows from  Model::getList result
     * @param string $pKey - name of Primary Key field in $data
     * @throws \Exception
     */
    protected function addLinkedInfo(Orm\Object\Config $cfg, array $fieldsToShow, array  & $data, $pKey)
    {
        $fieldsToKeys = [];
        foreach($fieldsToShow as $key=>$val){
            if(is_numeric($key)){
                $fieldsToKeys[$val] = $val;
            }else{
                $fieldsToKeys[$val] = $key;
            }
        }

        $links = $cfg->getLinks(
            [
                Orm\Object\Config::LINK_OBJECT,
                Orm\Object\Config::LINK_OBJECT_LIST,
                Orm\Object\Config::LINK_DICTIONARY
            ],
            false
        );

        foreach($fieldsToShow as $resultField => $objectField)
        {
            if(!isset($links[$objectField]))
                throw new \Exception($objectField.' is not Link');
        }

        foreach ($links as $field=>$config)
        {
            if(!isset($fieldsToKeys[$field])){
                unset($links[$field]);
            }
        }

        $rowIds = Utils::fetchCol($pKey , $data);
        $rowObjects = Orm\Object::factory($cfg->getName() , $rowIds);
        $listedObjects = [];

        foreach($rowObjects as $object)
        {
            foreach ($links as $field=>$config)
            {
                if($config['link_type'] === Orm\Object\Config::LINK_DICTIONARY){
                    continue;
                }

                if(!isset($listedObjects[$config['object']])){
                    $listedObjects[$config['object']] = [];
                }

                $oVal = $object->get($field);

                if(!empty($oVal))
                {
                    if(!is_array($oVal)){
                        $oVal = [$oVal];
                    }
                    $listedObjects[$config['object']] = array_merge($listedObjects[$config['object']], array_values($oVal));
                }
            }
        }

        foreach($listedObjects as $object => $ids){
            $listedObjects[$object] = Orm\Object::factory($object, array_unique($ids));
        }

        /**
         * @var Dictionary\Service $dictionaryService
         */
        $dictionaryService = Service::get('dictionary');

        foreach ($data as &$row)
        {
            if(!isset($rowObjects[$row[$pKey]]))
                continue;

            foreach ($links as $field => $config)
            {
                $list = [];
                $rowObject = $rowObjects[$row[$pKey]];
                $value = $rowObject->get($field);

                if(!empty($value))
                {
                    if($config['link_type'] === Orm\Object\Config::LINK_DICTIONARY)
                    {
                        $dictionary = $dictionaryService->get($config['object']);
                        if($dictionary->isValidKey($value)){
                            $row[$fieldsToKeys[$field]] = $dictionary->getValue($value);
                        }
                        continue;
                    }

                    if(!is_array($value))
                        $value = [$value];

                    foreach($value as $oId)
                    {
                        if(isset($listedObjects[$config['object']][$oId])){
                            $list[] = $this->linkedInfoObjectRenderer($rowObject, $field, $listedObjects[$config['object']][$oId]);
                        }else{
                            $list[] = '[' . $oId . '] ('.$this->lang->get('DELETED').')';
                        }
                    }
                }
                $row[$fieldsToKeys[$field]] =  implode($this->linkedInfoSeparator, $list);
            }
        }unset($row);
    }

    /**
     * Get ready the data for fields of the ‘link to object list’ type;
     * Takes an array of identifiers as a parameter. expands the data adding object name,
     * status (deleted or not deleted), publication status for objects under
     * version control (used in child classes)
     * The provided data is necessary for the RelatedGridPanel component,
     * which is used for visual representation of relationship management.
     * @param string $fieldName
     * @param ObjectInterface $object
     * @param string $targetObjectName
     * @return array
     */
    protected function collectLinksData($fieldName, ObjectInterface $object , $targetObjectName)
    {
        $result = [];

        $data = $object->get($fieldName);

        if(!empty($data))
        {
            /**
             * @var Orm\Object[] $list
             */
            $list = Orm\Object::factory($targetObjectName , $data);

            $isVc = Orm\Object\Config::factory($targetObjectName)->isRevControl();
            foreach($data as $id)
            {
                if(isset($list[$id])){
                    $result[] = [
                        'id' => $id,
                        'deleted' => 0,
                        'title' => $list[$id]->getTitle(),
                        'published' => $isVc?$list[$id]->get('published'):1
                    ];

                }else{
                    $result[] = [
                        'id' => $id,
                        'deleted' => 1,
                        'title' => $id,
                        'published' => 0
                    ];
                }
            }
        }
        return $result;
    }

    /**
     * String representation of related object for addLinkedInfo method
     * @param ObjectInterface $rowObject
     * @param string $field
     * @param ObjectInterface $relatedObject
     * @return string
     */
    protected function linkedInfoObjectRenderer(ObjectInterface $rowObject, $field, ObjectInterface $relatedObject)
    {
        return $relatedObject->getTitle();
    }

    /**
     * Check object owner
     * @param ObjectInterface  $object
     * @return bool
     */
    protected function checkOwner(ObjectInterface $object) : bool
    {
        if($this->moduleAcl->onlyOwnRecords($this->getModule()) && $object->get('author_id') !== $this->user->getId()){
            return false;
        }
        return true;
    }

    /**
     * Define the object data preview page URL
     * (needs to be redefined in the child class
     * as per the application structure)
     * @param ObjectInterface $object
     * @return string
     */
    public function getStagingUrl(ObjectInterface $object) : string
    {
        $frontConfig = Config::storage()->get('frontend.php');

        $routerClass =  '\\Dvelum\\App\\Router\\' . $frontConfig->get('router');
        if(!class_exists($routerClass)){
            $routerClass = $frontConfig->get('router');
        }
        /**
         * @var \Dvelum\App\Router\RouterInterface $frontendRouter
         */
        $frontendRouter = new $routerClass();

        $stagingUrl = $frontendRouter->findUrl(strtolower($object->getName()));

        if(!strlen($stagingUrl))
            return $this->request->url(['/']);

        return $this->request->url([$stagingUrl,'item',$object->getId()]);
    }

    /**
     * Publish object data changes
     * Sends JSON reply in the result
     * and closes the application.
     */
    public function publishAction()
    {
        $objectName = $this->getObjectName();
        $objectConfig = Orm\Object\Config::factory($objectName);

        if(!$objectConfig->isRevControl()){
            $this->response->error($this->lang->get('WRONG_REQUEST'));
            return;
        }

        $this->checkCanPublish();

        $id = $this->request->post('id' , 'integer' , false);
        $vers = $this->request->post('vers' , 'integer' , false);

        if(!$id || !$vers){
            $this->response->error($this->lang->get('WRONG_REQUEST'));
            return;
        }

        try{
            /**
             * @var Orm\Object $object
             */
            $object = Orm\Object::factory($objectName , $id);
        }catch(\Exception $e){
            $this->response->error($this->lang->get('CANT_EXEC' . ' ' .  $e->getMessage()));
            return;
        }

        if(!$this->checkOwner($object)){
            $this->response->error($this->lang->get('CANT_ACCESS'));
            return;
        }

        $acl = $object->getAcl();

        if($acl && !$acl->canPublish($object)){
            $this->response->error($this->lang->get('CANT_PUBLISH'));
            return;
        }

        try{
            $object->loadVersion($vers);
        }catch(\Exception $e){
            $this->response->error($this->lang->get('VERSION_INCOPATIBLE'));
            return;
        }

        if(!$object->publish()){
            $this->response->error($this->lang->get('CANT_EXEC'));
            return false;
        }
        $this->response->success();
    }

}