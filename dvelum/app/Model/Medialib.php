<?php

use Dvelum\Config;
use Dvelum\Orm;
use Dvelum\Orm\Model;

class Model_Medialib extends Model
{
    static protected $scriptsIncluded = false;

    /**
     * Get item record by its path
     * @param string $path
     * @param array $fields - optional
     * @return array
     */
    public function getIdByPath($path)
    {
        $recId = $this->dbSlave->fetchOne(
            $this->dbSlave->select()
                ->from($this->table() , array('id'))
                ->where('`path` =?', $path)
        );
        return intval($recId);
    }

    /**
     * Add media item
     * @param string $name
     * @param string $path
     * @param integer $size (bytes)
     * @param string $type
     * @param string $ext  - extension
     * @param string $hash - file hash, optional default null
     * @return integer
     */
    public function addItem($name, $path, $size, $type, $ext, $category = null, $hash=null)
    {
        $size = number_format(($size/1024/1024) , 3);

        $data = [
            'title'=>$name,
            'path'=>$path,
            'size'=>$size,
            'type'=>$type,
            'user_id'=>User::getInstance()->id,
            'ext'=>$ext,
            'date'=>date('Y-m-d H:i:s'),
            'category'=>$category,
            'hash'=> $hash
        ];

        $obj = Orm\Object::factory($this->name);
        $obj->setValues($data);

        if($obj->save()){
            return $obj->getId();
        } else{
            return false;
        }
    }

    /**
     * Delete item from library
     * @param integer $id
     * @return bool
     */
    public function remove($id) : bool
    {
        if(!$id)
            return false;

        $obj = Orm\Object::factory($this->name, $id);
        $data = $obj->getData();

        if(empty($data))
            return false;

        $docRoot = Config::storage()->get('main.php')->get('docRoot');

        if(strlen($data['path']))
        {
            @unlink($docRoot . $data['path']);
            if($data['type'] == 'image'){
                $conf = $this->getConfig()->__toArray();
                foreach ($conf['image']['sizes'] as $k=>$v){
                    @unlink($docRoot . self::getImgPath($data['path'],$data['ext'], $k));
                }
            }
        }
        $obj->delete();
        return true;
    }

    /**
     * Calculate image path
     * @param string $path
     * @param string $ext
     * @param string $type
     * @param boolean $prependWebRoot add wwwRoot prefix, optional
     * @return string
     */
    static public function getImgPath($path, $ext , $type , $prependWebRoot = false)
    {
        if(empty($ext))
            $ext = File::getExt($path);

        $str = str_replace($ext, '-' . $type . $ext , $path);

        if($prependWebRoot)
            return self::addWebRoot($str);
        else
            return $str;
    }
    /**
     * Create url for media file add wwwRoot prefix
     * @param string $itemPath
     * @return string
     */
    static public function addWebRoot($itemPath)
    {
        if(Request::wwwRoot()!=='/')
        {
            if($itemPath[0] === '/')
                $itemPath = substr($itemPath, 1);

            $itemPath =  Request::wwwRoot() . $itemPath;
        }
        return $itemPath;
    }

    /**
     * Add author selection join to the query.
     * Used with rev_control objects
     * @param \Db_Select | Zend_Db_Select $sql
     * @param string $fieldAlias
     * @return void
     */
    protected function _queryAddAuthor($sql , $fieldAlias) : void
    {
        $sql->joinLeft(
            array('u1' =>  Model::factory('User')->table()) ,
            'user_id = u1.id' ,
            array($fieldAlias => 'u1.name')
        );
    }

    /**
     * Update media item
     * @param integer $id
     * @param array $data
     * @return boolean
     */
    public function update($id ,array $data)
    {
        if(!$id)
            return false;
        try{
            $obj = Orm\Object::factory($this->name, $id);
            $obj->setValues($data);
            $obj->save();
            $hLog = Model::factory('Historylog');
            $hLog->log(\Dvelum\App\Session\User::getInstance()->id, $id, Model_Historylog::Update, $this->table());
            return true;
        }catch (Exception $e){
            return false;
        }
    }

    /**
     * Include required sources
     */
    public function includeScripts()
    {
        $version = Config::storage()->get('versions.php')->get('medialib');
        $appConfig = Config::storage()->get('main.php');

        if(self::$scriptsIncluded)
            return;

        $conf = $this->getConfig()->__toArray();

        $resource = \Dvelum\Resource::factory();
        $resource->addCss('/js/lib/jquery.Jcrop.css');

        $editor = $appConfig->get('html_editor');

        if($editor === 'tinymce'){
            $resource->addJs('/js/lib/tiny_mce/tiny_mce.js',0,true);
            $resource->addJs('/js/lib/ext_ux/Ext.ux.TinyMCE.js',1,true);
            $resource->addJs('/js/app/system/medialib/HtmlPanel_tinymce.js', 3);
        }elseif($editor === 'ckeditor'){
            $resource->addJs('/js/lib/ckeditor/ckeditor.js',0,true);
            $resource->addJs('/js/lib/ext_ux/ckplugin.js',1,true);
            $resource->addJs('/js/app/system/medialib/HtmlPanel_ckeditor.js'  , 3);
        }

        // $resource->addJs('/js/lib/ext_ux/AjaxFileUpload.js',1,false);
        $resource->addJs('/js/app/system/SearchPanel.js',1);
        $resource->addJs('/js/lib/ext_ux/AjaxFileUpload.js',1);
        $resource->addJs('/js/app/system/ImageField.js',1);
        $resource->addJs('/js/app/system/Medialib.js?v='.$version, 2);
        $resource->addJs('/js/lib/jquery.Jcrop.min.js', 2,true);

        $resource->addInlineJs('
      	app.maxFileSize = "'.ini_get('upload_max_filesize').'";
      	app.mediaConfig = '.json_encode($conf).';
      	app.imageSize = '.json_encode($conf['image']['sizes']).';
      	app.medialibControllerName = "medialib";
    ');
        self::$scriptsIncluded = true;
    }

    /**
     *
     * @param array $types
     * @return int
     */
    public function resizeImages($types = false) : int
    {
        $data = Model::factory('Medialib')->getListVc(false , array('type'=>'image'),false,array('path' , 'ext'));
        ini_set('max_execution_time' , 18000);
        ini_set('ignore_user_abort' ,'On');
        ini_set('memory_limit', '384M');

        $conf = $this->getConfig()->__toArray();

        $thumbSizes = $conf['image']['sizes'];
        $count =0;

        foreach ($data as $v)
        {
            $path = DOC_ROOT.$v['path'];
            if(!file_exists($path))
                continue;

            if($types && is_array($types))
            {
                foreach ($types as $typename)
                {
                    if(isset($thumbSizes[$typename]))
                    {
                        $saveName = str_replace($v['ext'], '-'.$typename.$v['ext'], $path);

                        if($conf['image']['thumb_types'][$typename] == 'crop'){
                            Image_Resize::resize($path, $thumbSizes[$typename][0], $thumbSizes[$typename][1],$saveName, true,true);
                        } else{
                            Image_Resize::resize($path, $thumbSizes[$typename][0], $thumbSizes[$typename][1],$saveName, true,false);
                        }
                    }
                }
            }
            else
            {
                foreach ($thumbSizes as $k=>$item)
                {
                    $saveName = str_replace($v['ext'], '-'.$k.$v['ext'], $path);

                    if($conf['image']['thumb_types'][$k] == 'crop'){
                        Image_Resize::resize($path, $item[0], $item[1], $saveName, true,true);
                    }else{
                        Image_Resize::resize($path, $item[0], $item[1], $saveName, true,false);
                    }
                }
            }
            $count ++;
        }
        return $count;
    }

    /**
     * Crop image and create thumbs
     * @param array $srcData  - media library record
     * @param integer $x
     * @param integer $y
     * @param integer $w
     * @param integer $h
     */
    public function cropAndResize($srcData , $x,$y,$w,$h , $type)
    {
        $appConfig = Config::storage()->get('main.php');
        ini_set('max_execution_time' , 18000);
        ini_set('memory_limit', '384M');
        $docRoot = $appConfig['wwwPath'];
        $conf = $this->getConfig()->__toArray();
        $thumbSizes = $conf['image']['sizes'];

        $path = $docRoot.$srcData['path'];

        if(!file_exists($path))
            false;

        $tmpPath = $appConfig['tmp'].basename($path);

        $path = str_replace('//','/', $path);

        if(!Image_Resize::cropImage($path, $tmpPath , $x, $y, $w, $h)){
            return false;
        }

        if(!isset($thumbSizes[$type]))
            return false;

        $saveName = str_replace($srcData['ext'], '-'.$type.$srcData['ext'], $path);
        if(!\Image_Resize::resize($tmpPath, $thumbSizes[$type][0], $thumbSizes[$type][1], $saveName, true,false))
            return false;

        unlink($tmpPath);
        return true;
    }

    /**
     * Update modification date
     * @param integer $id
     * @return void
     */
    public function updateModifyDate($id)
    {
        $obj = Orm\Object::factory($this->name, $id);
        $obj->set('modified', date('Y-m-d h:i:s'));
        $obj->save();
    }

    /**
     * Mark object as hand croped
     * @param integer $id
     * @return void
     */
    public function markCroped($id)
    {
        $obj = Orm\Object::factory($this->name, $id);
        $obj->set('croped', 1);
        $obj->save();
    }

    /**
     * Get media library config
     * @return Config_File_Array
     */
    public function getConfig()
    {
        return Config::storage()->get('media_library.php' , true, false);
    }

    /**
     * Update media items, set category to null
     * @param integer $id
     */
    public function categoryRemoved($id)
    {
        $this->db->update($this->table(), array('category'=>null), '`category` = '.intval($id));
    }

    /**
     * Update category for set of items
     * @param array $items
     * @param integer $catalog
     * @return number
     */
    public function updateItemsCategory(array $items , $catalog)
    {
        $items = array_map('intval', $items);

        if($catalog==0)
            $catalog = null;

        try{
            $this->db->update($this->table(), array('category'=>$catalog),' `'.$this->getPrimaryKey().'` IN('.implode(',', $items).')');
            return true;
        }catch(Exception $e){
            $this->logError('updateItemsCategory: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get file icon
     * @param $filename
     * @return string
     */
    static public function getFilePic($filename)
    {
        $ext = File::getExt($filename);
        $icon = 'i/system/file.png';
        switch($ext){
            case '.jpg':
            case '.jpeg':
            case '.gif':
            case '.bmp':
            case '.png':
                $icon = 'i/system/folder-image.png';
                break;
            case  '.doc':
            case  '.docx':
            case  '.odt':
            case  '.txt':
                $icon = 'i/system/doc.png';
                break;

            case  '.xls':
            case  '.xlsx':
            case  '.ods':
            case  '.csv':
                $icon = 'i/system/excel.png';
                break;
            case '.pdf':
                $icon = 'i/system/pdf.png';
                break;

            case '.zip':
            case '.rar':
            case '.7z':
                $icon = 'i/system/archive.png';
                break;


            default :  $icon = 'i/system/file.png';
        }
        return $icon;
    }
}