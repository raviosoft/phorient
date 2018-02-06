<?php
/**
 * Created by BoDev Office.
 * User: Erman Titiz ( @ermantitiz )
 * Date: 25/10/2017
 * Time: 15:19
 */

namespace BiberLtd\Bundle\Phorient\Services;
use BiberLtd\Bundle\Phorient\Odm\Entity\BaseClass;
use BiberLtd\Bundle\Phorient\Odm\Types\BaseType;
use BiberLtd\Bundle\Phorient\Odm\Types\ORecordId;
use Doctrine\DBAL\Schema\Column;
use PhpOrient\Protocols\Binary\Data\ID;
use PhpOrient\Protocols\Binary\Data\Record;

class ClassDataManipulator
{

    private $ignored = array('index', 'parent','modified','versionHash', 'typePath', 'updatedProps', 'dateAdded', 'dateRemoved', 'versionHistory','dtFormat');

    private $cm;

    /**
     * ClassDataManipulator constructor.
     * @param $cm
     */
    public function __construct($cm)
    {
        $this->cm = $cm;
    }


    /**
     * @param $object
     * @param string $to
     * @param array|null $props
     * @return mixed|string
     */
    public function output($object, $to = 'json', array $props = array())
    {
        switch($to) {
            case 'json':
                return $this->outputToJson($object,$props);
            case 'xml':
                return $this->outputToXml($object,$props);
            case 'array':
                return json_decode($this->outputToJson($object,$props));
        }
    }

    /**
     * @param array $props
     *
     * @return string
     */
    private function outputToJson($object,$props)
    {
        return json_encode($this->toArray($object,$props));
    }

    /**
     * @param array $props
     *
     * @return string
     *
     * @todo !! BE AWARE !! xmlrpc_encode is an experimental method.
     */
    private function outputToXml($object,$props)
    {
        return xmlrpc_encode($this->toArray($object,$props));
    }

    public function getToMapProperties($object)
    {
        return array_diff_key(get_object_vars($object), array_flip($this->ignored));
    }

    public function sortArray($array)
    {
        if(is_object($array) || is_array($array))
        {
            $array = (array) $array;
            foreach ($array as $index => $value)
            {
                $array[$index] = $this->sortArray($value);
            }
            ksort($array);
        }
        return $array;
    }

    public function toArray($object,$ignored=array())
    {
        $this->ignored=array_merge($ignored,$this->ignored);
        $array = $object instanceof BaseClass ? $this->toJson($this->getToMapProperties($object)): (is_object($object) ? get_object_vars($object) : $object);

        if(!is_array($array)) return $array;
        array_walk_recursive($array, function (&$value, $index) use($object,$ignored) {
            $value = $value instanceof BaseType ? (method_exists($object,'get'.ucfirst($index)) ? $object->{'get'.ucfirst($index)}() : $value->getValue()) : $value;
            $value = (is_object($value) && property_exists($value,'cluster') && property_exists($value,'position')) ? '#'.implode(':',(array)$value) : $value;

            if (is_object($value)) {
                $value = $this->toJson($value);
                $value = $this->toArray($value,$ignored);
            }else{
                $value = is_object($value) || is_array($value) ? (array) $value : $value;
            }
        });

        $this->sortArray($array);
        return $array;
    }
    public function toJson($object)
    {
        if ($object instanceof \DateTime) {
            return $object->format('Y-m-d H:i:s');
        }
        $data = (array) $object;
        if(count($data)==0)
        {
           return [];
        }
        $namespace = implode('', array_slice(explode('\\', get_class($object)),0, -1));
        $data['@class'] = implode('', array_slice(explode('\\', get_class($object)), -1));
        foreach ($data as $key => $value) {

            if($this->checkisRecord($value)) $value = $this->toJson($value);

            $newKey = preg_replace('/[^a-z]/i', null, $key);
            $newKey = str_replace(implode('', explode('\\', get_class($object))), null, $newKey);
            $newKey = str_replace($namespace, null, $newKey);
            if(strpos($newKey,"BiberLtdBundlePhorientOdmEntityBaseClass")!==false){
                unset($data[$key]);
                continue;
            }
            if (is_string($value)) {
                $data[$newKey] = str_replace('%', 'pr.', $value);
            } else {
                $data[$newKey] = $value;
            }
            unset($data[$key]);
            if (is_object($value)) {
                //$object = $value->__invoke();
                $data[$newKey] = $this->toJson($value);
            } elseif ($value instanceof \DateTime) {
                $data[$newKey] = $value->format('Y-m-d H:i:s');
            }
        }

        if(method_exists($object,'getRid'))
        $data['rid'] = $object->getRid();
        if (array_key_exists('version', $data)) {
            $data['@version'] = $data['version'];
            unset($data['version']);
        }
        if (array_key_exists('type', $data)) {
            $data['@type'] = $data['type'];
            unset($data['type']);
        }
        return $data;
    }

    public function objectToRecord($object)
    {
        $record = new Record();
        $data=[];
        foreach ($object as $index => $value)
        {
            switch ($index)
            {
                case '@type':
                    break;
                case '@fieldTypes':
                    break;
                case '@rid':
                    $record->setRid(new \PhpOrient\Protocols\Binary\Data\ID($value));
                    break;
                case '@version':
                    $record->getVersion($value);
                    break;
                case '@class':
                    $record->setOClass($value);
                    break;
                default:
                    if(is_array($value) && array_key_exists('@class',$value))
                    {
                        $value = $this->objectToRecord($value);
                    }
                    $data[$index]=$value;
            }
            $record->setOData($data);

        }
        return $record;
    }
    public function checkisRecord($data)
    {
        if($data instanceof Record) return true;
        if(is_array($data) && array_key_exists('@class',$data)) return true;
        if(is_object($data) && property_exists($data,'class')) return true;
        return false;
    }

    public function convertRecordToOdmObject($record,$bundle)
    {
        if(is_array($record) && array_key_exists('@class',$record))
        {
            $record = $this->objectToRecord($record);
            //dump($record);
        }
        elseif(is_array($record) && array_key_exists('@type',$record))
        {
            $copyRecord = [];
            foreach($record as $key => $value){
                if($key[0] != '@'){
                    $copyRecord[$key] = $value;
                }
            }
            return $copyRecord;
        }
        $oClass = $record->getOClass();
        $oData = $record->getOData();;
        $class = $this->cm->getEntityPath($bundle).$oClass;
        if (!class_exists($class)) return $record->getOData();
        $entityClass =  new $class;
        $metadata = $this->cm->getMetadata($entityClass);
        $recordData = $oData;
        foreach ($metadata->getColumns()->toArray() as $propName => $annotations)
        {

            if(array_key_exists($propName, $recordData)) {
                $value = $this->checkisRecord($recordData[$propName]) ? $this->convertRecordToOdmObject($recordData[$propName],$bundle) : $this->arrayToObject($recordData[$propName],$bundle);
                if(property_exists($annotations,'type') && $annotations->type=="ODateTime")
                {
                    $value = \DateTime::createFromFormat('Y-m-d H:i:s',$value);
                }
                $methodName = 'set' . ucfirst( $propName );
                if ( method_exists( $entityClass, $methodName ) ) {
                    $entityClass->{$methodName}( $value);
                } elseif( property_exists( $entityClass, $propName ) ) {
                    $entityClass->{$key} = $value;
                } else {
                    // skip not existent configuration params
                }

            }
        }
        if(method_exists($entityClass,'setRid'))
            $entityClass->setRid($record->getRid());
        return $entityClass;
    }
    private function arrayToObject($arrayObject,$bundle)
    {

        if(is_array($arrayObject))
            foreach ($arrayObject as &$value) $value = $this->checkisRecord($value) ? $this->convertRecordToOdmObject($value,$bundle) : (is_array($value) ? $this->arrayToObject($value,$bundle): $value);

        return $arrayObject;
    }

    public function odmToClass($record,$toClass=true)
    {
        if(!is_object($record) && !is_array($record)){
            $obj = $record;
            return $obj;
        }

        if ($this->checkisRecord($record)) {
            if (is_array($record)) {
                $oClass = $record['@class'];

                unset($record['@class']);
                unset($record['@type']);
                $record['rid'] = array_key_exists('@rid',$record) ? $record['@rid'] : null;
                unset($record['@rid']);
                unset($record['@version']);
                unset($record['@fieldTypes']);
            }elseif ($record instanceof Record)
            {
                $oClass = $record->getOClass();
                $record = $record->getOData();
                $record['rid'] = $record->getRid();
            }else{
                $oClass = null;
            }
            $obj = $this->dataToClass($record,$oClass,$toClass);
        }else{
            $obj = [];
            foreach ($record as $key => $value) {

                if (!empty($value))
                {
                    $obj[$key] = $this->odmToClass($value,$toClass);
                }
                else
                {
                    $obj[$key] = $value;
                }
            }
        }

        return $obj;
    }
    private function dataToClass($recordData, $oClass,$toClass=true)
    {
        if(!$toClass)
        {
            foreach($recordData as $key => &$value)
            {
                $value = $this->odmToClass($recordData[$key],$toClass);
            }
            return $recordData;
        }
        $class = $this->cm->getEntityPath().$oClass;
        if (!class_exists($class)) return $recordData;
        $entityClass =  new $class;
        $metadata = $this->cm->getMetadata($entityClass);
        foreach ($metadata->getColumns()->toArray() as $propName => $annotations)
        {

            if(array_key_exists($propName, $recordData)) {
                $fieldValue = $this->odmToClass($recordData[$propName],$toClass);
                if(property_exists($annotations,'type') && $annotations->type=="ODateTime")
                {
                    $fieldValue = \DateTime::createFromFormat('Y-m-d H:i:s',$recordData[$propName]);
                }
                $methodName = 'set' . ucfirst( $propName );
                if ( method_exists( $entityClass, $methodName ) ) {
                    $entityClass->{$methodName}( $fieldValue);
                } elseif( property_exists( $entityClass, $propName ) ) {
                    $entityClass->{$key} = $fieldValue;
                } else {
                    // skip not existent configuration params
                }

            }
        }
        if(method_exists($entityClass,'setRid'))
            $entityClass->setRid(new ID($recordData['rid']));

        return $entityClass;
    }


    public function objToArray($obj, &$arr){

        if(!is_object($obj) && !is_array($obj)){
            $arr = $obj;
            return $arr;
        }

        if(is_object($obj))
        {
            $class = get_class($obj);
            /**
             * @var Metadata $metadata;
             */
            $metadata = $this->cm->getMetadata($class);
            $columns = $metadata->getColumns();
            $probs = $metadata->getProps();
            foreach ($columns as $key => $value)
            {
                $arr[$key] = array();

                $this->objToArray(
                    method_exists($obj,'get'.ucfirst($key)) ?
                    $obj->{'get'.ucfirst($key)}() :
                    (
                        property_exists($obj,$key) && $value instanceof Column ?
                            $obj->$key :
                            null
                    )
                , $arr[$key]);
            }

            $arr['@type'] = 'd';
            $arr['@version'] = 0;
            $classname = explode('\\',$class);
            $arr['@class'] = end($classname);
        }
        foreach ($obj as $key => $value)
        {
            if (!empty($value))
            {
                $arr[$key] = array();
                $this->objToArray($value, $arr[$key]);
            }
            else
            {
                $arr[$key] = $value;
            }
        }


        return $arr;
    }

    public function arrayToRecordArray($array)
    {
        $data=[];
        if(!is_array($array) && !is_object($array)) return $this->variableToArray($array);
        foreach ($array as $propName => $value)
        {
            $data[$propName] = $this->variableToArray($value);
        }
        return $data;
    }
    public function variableToArray($variable)
    {
        if(is_string($variable) || is_int($variable) || is_bool($variable) || is_float($variable) || is_null($variable))
            return $variable;
        if(is_object($variable))
        {
            if($variable instanceof \DateTime)
                return $variable->format("Y-m-d H:i:s");
            if(property_exists($variable,"rid"))
                return $this->objectToRecordArray($variable);
            else
                return $this->arrayToRecordArray((array)$variable);
        }
        if(is_array($variable))
            return $this->arrayToRecordArray($variable);

        return $variable;
    }
    public function objectToRecordArray($object)
    {

        $metadata = $this->cm->getMetadata($object);
        $data=[];
        foreach ($metadata->getColumns()->toArray() as $propName => $annotations)
        {
            $value = $object->{"get".ucfirst($propName)}();

            if(array_key_exists("readOnly",$annotations->options) && $annotations->options["readOnly"]) continue;

            if($annotations->type=="OEmbedded")
            {
                $returndata=[];
                $returndata =is_null($value) ? null:$this->objToArray($value,$returndata);
            }elseif($annotations->type=="OLink" && (array_key_exists("class",$annotations->options) && $annotations->options["class"]!=""))
            {
                $returndata=[];
                $returndata = is_null($value) ? null : $value->getRid("string");
            }elseif($annotations->type=="OLinkList" && (array_key_exists("class",$annotations->options) && $annotations->options["class"]!=""))
            {
                $returndata=[];
                if(!is_null($value) && is_array($value) && count($value)>0)
                {
                    foreach ($value as $obj)
                    {
                        if( !method_exists($obj,'getRid')) continue;
                        $returndata[] = $obj->getRid("string");
                    }
                }
            }else{
                $returndata = is_object($value) || is_array($value) ? $this->arrayToRecordArray($value) : $this->variableToArray($value);
            }

            $data[$propName] = $returndata;

        }
        return $data;
    }
}