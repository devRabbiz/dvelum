<?php
use Dvelum\Orm;
class Backend_Designer_Sub_Orm extends Backend_Designer_Sub
{ 
	/**
	 * Get list of objects from ORM
	 */
	public function listAction()
	{
		$manager = new Orm\Object\Manager();
		$objects = $manager->getRegisteredObjects();
		$data = array();
		
		if(!empty($objects))
			foreach ($objects as $name)
				$data[] = array('name'=>$name ,'title'=>Orm\Object\Config::factory($name)->getTitle());
			
		Response::jsonSuccess($data);
	}
	
	/**
	 * Get list of ORM object fields
	 */
	public function fieldsAction()
	{
		$objectName = Request::post('object','string', false);
		if(!$objectName)
			Response::jsonError($this->_lang->WRONG_REQUEST);
			
		try{
			$config = Orm\Object\Config::factory($objectName);
		}catch (Exception $e){
			Response::jsonError($this->_lang->WRONG_REQUEST);
		}
			
		$fields =  $config->getFieldsConfig();
		if(empty($fields))
			Response::jsonSuccess(array());
		
		$data = array();
			
		foreach ($fields as $name=>$cfg)
		{
			$type = $cfg['db_type']; 	

			if($config->isLink($name))
			{			
				if($config->isDictionaryLink($name))
				{
					$type = $this->_lang->DICTIONARY_LINK . '"'.$config->getLinkedDictionary($name).'"';
				}
				else 
				{
					 $obj = $config->getLinkedObject($name);
					 $oName = $obj . '';
					 try{
					 	$oCfg = Orm\Object\Config::factory($obj);
					 	$oName.= ' ('.$oCfg->get('title').')';
					 }catch (Exception $e){
					 	//empty on error
					 }				
					 $type = $this->_lang->OBJECT_LINK . ' - '.$oName;
				}
			}

			$data[] = array(
				'name'=>$name,
				'title'=>$cfg['title'],
				'type'=>$type
			);
		}
		
		Response::jsonSuccess($data);	
	}
}