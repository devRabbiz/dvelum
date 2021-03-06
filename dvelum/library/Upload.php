<?php
/**
 * File Uploader
 * @author Kirill Egorov 2010
 */
class Upload
{
	protected $_config;
	protected $_uploaders;
    protected $_errors = [];

	public function __construct(array $config)
	{
		$this->_config = $config;
		$this->_uploaders = array();
	}

	/**
	 * Auto create dirs for upload
	 * @param string $path
	 * @return boolean
	 */
	static public function createDirs($root , $path)
	{
		$path = str_replace('//' , '/' , $root.'/'.$path);

		if(file_exists($path)){
			return true;
		}

		if(!@mkdir($path, 0775 , true)) {
			return false;
		}

		return true;
	}

	/**
	 * Identify file type
	 * @param string $extension
	 * @return mixed string / false
	 */
	protected function _identifyType($extension)
	{
		foreach($this->_config as $k => $v)
			if(in_array($extension , $v['extensions'], true))
				return $k;

		return false;
	}

	/**
	 * Multiple upload files
	 *
	 * @property array $data - array of Request::files() items
	 * @param string $path
     * @param boolean $formUpload  - optional, default true
	 * @return mixed - uploaded files Info / false on error
	 */
	public function start(array $files , $path  , $formUpload = true)
	{
	    $this->_errors = [];

		$uploadedFiles = array();
		foreach($files as $k => $item)
		{
			if(isset($item['error']) && $item['error']){
			    $this->_errors[] = 'Server upload error';
                continue;
            }

			$item['name'] = str_replace(' ' , '_' , $item['name']);
			$item['name'] = strtolower(preg_replace("/[^A-Za-z0-9_\-\.]/i" , '' , $item['name']));

			$item['ext'] = File::getExt($item['name']);
			$item['title'] = str_replace($item['ext'] , '' , $item['name']);
			$type = $this->_identifyType($item['ext']);

			if(! $type)
				continue;

			switch($type)
			{
				case 'image' :
					if(!isset($this->_uploaders['image'])){
                        $this->_uploaders['image'] = new Upload_Image($this->_config['image']);
                    }
                    /**
                     * @var Upload_AbstractAdapter $uploader
                     */
					$uploader = $this->_uploaders['image'];

					$file = $uploader->upload($item , $path , $formUpload);

					if(!empty($file)) {
						$file['type'] = $type;
						$file['title'] = $item['title'];
						if(isset($item['old_name'])){
                            $file['old_name'] = $item['old_name'];
                        } else{
                            $file['old_name'] = $item['name'];
                        }
						$uploadedFiles[] = $file;
					}else{
                        if(!empty($uploader->getError())){
                            $this->_errors[] = $uploader->getError();
                        }
                    }
					break;

				case 'audio' :
				case 'video' :
				case 'file' :
					if(!isset($this->_uploaders['file'])){
                        $this->_uploaders['file'] = new Upload_File($this->_config[$type]);
                    }
                    /**
                     * @var Upload_AbstractAdapter $uploader
                     */
                    $uploader = $this->_uploaders['file'];
                    $file = $uploader->upload($item , $path , $formUpload);

					if(!empty($file))
					{
						$file['type'] = $type;
						$file['title'] = $item['title'];

						if(isset($item['old_name'])){
                            $file['old_name'] = $item['old_name'];
                        } else{
                            $file['old_name'] = $item['name'];
                        }
						$uploadedFiles[] = $file;
					}else{
                        if(!empty($uploader->getError())){
                            $this->_errors[] = $uploader->getError();
                        }
                    }
					break;
			}
		}

		return $uploadedFiles;
	}

    /**
     * Get upload errors
     * @return array
     */
	public function getErrors()
    {
       return $this->_errors;
    }
}