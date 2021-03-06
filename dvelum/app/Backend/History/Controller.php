<?php
use Dvelum\Orm;
use Dvelum\Orm\Model;
use Dvelum\Config;

class Backend_History_Controller extends Backend_Controller
{
    public function indexAction(){}

    /**
     * Get object history
     */
    public function listAction()
    {
        $object = Request::post('object', 'string' , false);

        if(!$object)
            Response::jsonSuccess(array());

        $pager = Request::post('pager', 'array', array());
        $filter = Request::post('filter', 'array', array());

        if(!isset($filter['record_id']) || empty($filter['record_id']))
            Response::jsonSuccess(array());

        try{
            $o = Orm\Object::factory($object);
        }catch (Exception $e){
            Response::jsonSuccess(array());
        }

        $filter['object'] = $o->getName();

        $history = Model::factory('Historylog');

        $data = $history->query()
                        ->filters($filter)
                        ->params($pager)
                        ->fields(['date','type','id'])
                        ->fetchAll();

        $objectConfig = Orm\Object\Config::factory('Historylog');
        $this->addLinkedInfo($objectConfig,['user_name'=>'user_id'], $data, $objectConfig->getPrimaryKey());

        if(!empty($data))
        {
            foreach ($data as $k=>&$v)
            {
                if(isset(Model_Historylog::$actions[$v['type']]))
                    $v['type'] = Model_Historylog::$actions[$v['type']];
            }unset($v);
        }
        Response::jsonSuccess($data , ['count'=>$history->query()->filters($filter)->getCount()]);
    }
}