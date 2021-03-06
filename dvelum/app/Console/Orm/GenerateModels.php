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

use Dvelum\Orm;
class Console_Orm_GenerateModels extends Console_Action
{
    public function run()
    {
        $dbObjectManager = new Orm\Object\Manager();
        $modelPath = Config::storage()->get('main.php')->get('local_models');

        foreach($dbObjectManager->getRegisteredObjects() as $object)
        {
           $list = explode('_', $object);
           $list = array_map('ucfirst', $list);
           $class = 'Model_'.implode('_',$list);
           $path = $modelPath.str_replace(['_','\\'],'/',$class).'.php';

           if(!class_exists($class))
           {
               echo $class."\n";
               $dir = dirname($path);

               if(!is_dir($dir) && !mkdir($dir,0755,true)) {
                   echo Lang::lang()->get('CANT_WRITE_FS').' '. $dir;
                   return false;
               }
               $data = '<?php ' . PHP_EOL . 'class '.$class.' extends \Dvelum\Orm\Model {}';

               if(!file_put_contents($path, $data)){
                   echo Lang::lang()->get('CANT_WRITE_FS').' '. $path;
                   return false;
               }
           }
        }
        return true;
    }
}