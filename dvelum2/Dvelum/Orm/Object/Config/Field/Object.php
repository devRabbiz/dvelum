<?php
/**
 *  DVelum project http://code.google.com/p/dvelum/ , https://github.com/k-samuel/dvelum , http://dvelum.net
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
 *
 */
declare(strict_types=1);

namespace Dvelum\Orm\Object\Config\Field;

use Dvelum\Orm\Object\Config\Field;
use Dvelum\Orm;

class Object extends \Dvelum\Orm\Object\Config\Field
{

    /**
     * Apply value filter
     * @param mixed $value
     * @return mixed
     */
    public function filter($value)
    {
        if(is_object($value))
        {
            if($value instanceof Orm\Object)
            {
                if(!$value->isInstanceOf($this->getLinkedObject())){
                    throw new Exception('Invalid value type for field '. $this->getName().' expects ' . $this->getLinkedObject() . ', ' . $value->getName() . ' passed');
                }
                $value = $value->getId();
            }else{
                $value = $value->__toString();
            }
        }

        if(empty($value)){
            return null;
        }

        return (integer)$value;
    }
    /**
     * Validate value
     * @param $value
     * @return boolean
     */
    public function validate($value) : bool
    {
        if(!parent::validate($value)){
            return false;
        }

        if(!empty($value)) {

            if(!is_int($value)){
                return false;
            }
            return Orm\Object::objectExists($this->config['link_config']['object'], $value);
        }

        return true;
    }
}
