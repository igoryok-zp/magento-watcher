<?php

class In_Watcher_Model_Observer {

    protected $_isActive = NULL;
    protected $_xml      = '<?xml version="1.0" encoding="UTF-8"?>';
    protected $_results  = array();

    public function __construct() {
        if ($this->isActive()) {
            $this->_getTimeSpan($this);
            $this->_xml .= '<watcher>';
            $this->_xml .= '<start time="' . $this->_getTime() . '" />';
            Varien_Profiler::enable();
        }
    }

    /**
     *
     * @return string
     */
    protected function _getTime() {
        return Varien_Date::now();
    }

    /**
     * 
     * @param mixed $object
     * @return float
     */
    protected function _getTimeSpan($object, $reset = TRUE) {
        $id     = spl_object_hash($object);
        $time   = microtime(TRUE);
        $result = isset($this->_results[$id]) ? $time - $this->_results[$id] : -1;
        if ($reset) {
            $this->_results[$id] = $time;
        }
        return $result;
    }

    /**
     *
     * @return array
     */
    protected function _getProfilerGroupedTimers() {
        $result = array();
        foreach (Varien_Profiler::getTimers() as $timer => $data) {
            $result = array_merge_recursive($result, $this->_groupTimers($timer, $data));
        }
        return $result;
    }

    /**
     *
     * @param string $name
     * @param array $data
     * @return array
     */
    protected function _groupTimers($name, $data) {
        $result    = array();
        $delimiter = strpos($name, ':') !== FALSE ? ':' : (strpos($name, '/') !== FALSE ? '/' : '');
        if ($delimiter) {
            $names = explode($delimiter, $name);
            $index = !trim($names[0]) && count($names) > 1 ? 1 : 0;
            $key   = trim($names[$index]);
            $value = count($names) > $index + 1 ? $this->_groupTimers(implode($delimiter, array_slice($names, $index + 1)), $data) : $data;
        } else {
            $key   = trim($name);
            $value = $data;
        }
        $key          = empty($key) ? 'UNDEFINED' : $key;
        $result[$key] = $value;
        return $result;
    }

    /**
     *
     * @param array $groups
     * @return string
     */
    protected function _renderProfilerGroupedTimers($groups) {
        $result = '';
        foreach ($groups as $key => $value) {
            if (is_array($value)) {
                $node = isset($value['sum']) ? 'timer' : 'group';
                $result .= '<' . $node . ' name="' . $key . '">';
                $result .= $this->_renderProfilerGroupedTimers($value);
                $result .= '</' . $node . '>';
            } else {
                $result .= '<' . $key . '>';
                $result .= $value;
                $result .= '</' . $key . '>';
            }
        }
        return $result;
    }

    /**
     *
     * @return string
     */
    protected function _getProfilerXml() {
        $result = '<profiler>';
        $result .= $this->_renderProfilerGroupedTimers($this->_getProfilerGroupedTimers());
        $result .= '</profiler>';
        return $result;
    }

    /**
     *
     * @return boolean
     */
    public function isActive() {
        if ($this->_isActive === NULL) {
            $this->_isActive = Mage::app()->getRequest()->getParam('watch') != NULL;
        }
        return $this->_isActive;
    }

    /**
     *
     * @param string $message
     * @return In_Watcher_Model_Observer
     */
    public function addMessage($message) {
        if ($this->isActive()) {
            $this->_xml .= '<message time="' . $this->_getTime() . '">';
            $this->_xml .= '<![CDATA[';
            $this->_xml .= $message;
            $this->_xml .= ']]>';
            $this->_xml .= '</message>';
        }
        return $this;
    }

    /**
     *
     * @param Varien_Event_Observer $observer
     * @return In_Watcher_Model_Observer
     */
    public function onActionPredispatch($observer) {
        if ($this->isActive()) {
            $controller = $observer->getControllerAction();
            /* @var $controller Mage_Core_Controller_Front_Action */
            $this->_xml .= '<request>';
            $this->_xml .= '<![CDATA[';
            $this->_xml .= $controller->getRequest()->getRequestUri();
            $this->_xml .= ']]>';
            $this->_xml .= '</request>';
        }
        return $this;
    }

    /**
     *
     * @param Varien_Event_Observer $observer
     * @return In_Watcher_Model_Observer
     */
    public function onActionPostdispatch($observer) {
        if ($this->isActive()) {
            $this->_xml .= '<end time="' . $this->_getTime() . '" />';
            $this->_xml .= '<result time="' . $this->_getTimeSpan($this) . '" />';
            $this->_xml .= $this->_getProfilerXml();
            $this->_xml .= '</watcher>';
            $controller = $observer->getControllerAction();
            /* @var $controller Mage_Core_Controller_Front_Action */
            $response   = $controller->getResponse();
            $response->setHeader('Content-type', 'text/xml');
            $response->setBody($this->_xml);
        }
        return $this;
    }

    /**
     * 
     * @param Varien_Event_Observer $observer
     * @return In_Watcher_Model_Observer
     */
    public function onBlockToHtmlBefore($observer) {
        if ($this->isActive()) {
            $block = $observer->getBlock();
            /* @var $block Mage_Core_Block_Abstract */
            $this->_xml .= '<block type="' . $block->getType() . '" class="' . get_class($block) . '" name="' . $block->getNameInLayout() . '"' . ($block->getTemplate() ? ' template="' . $block->getTemplate() . '"' : '' ) . '>';
            if (!Mage::getStoreConfig('advanced/modules_disable_output/' . $block->getModuleName())) {
                if (count($block->getChild())) {
                    $this->_xml .= '<child>';
                }
            } else {
                $this->_xml .= 'OUTPUT DISABLED';
                $this->_xml .= '</block>';
            }
            $this->_getTimeSpan($block);
        }
        return $this;
    }

    /**
     *
     * @param Varien_Event_Observer $observer
     * @return In_Watcher_Model_Observer
     */
    public function onBlockToHtmlAfter($observer) {
        if ($this->isActive()) {
            $block = $observer->getBlock();
            /* @var $block Mage_Core_Block_Abstract */
            if (count($block->getChild())) {
                $this->_xml .= '</child>';
            }
            $this->_xml .= '<result time="' . $this->_getTimeSpan($block) . '" />';
            $this->_xml .= '<watching time="' . $this->_getTimeSpan($this, FALSE) . '" />';
            $this->_xml .= '<!-- / ' . $block->getNameInLayout() . ' -->';
            $this->_xml .= '</block>';
        }
        return $this;
    }

    /**
     *
     * @param Varien_Event_Observer $observer
     * @return In_Watcher_Model_Observer
     */
    public function onCollectionLoadBefore($observer) {
        if ($this->isActive()) {
            $collection = $observer->getCollection();
            /* @var $collection Mage_Core_Model_Resource_Db_Collection_Abstract */
            $this->_getTimeSpan($collection);
        }
        return $this;
    }

    /**
     *
     * @param Varien_Event_Observer $observer
     * @return In_Watcher_Model_Observer
     */
    public function onCollectionLoadAfter($observer) {
        if ($this->isActive()) {
            $collection = $observer->getCollection();
            /* @var $collection Mage_Core_Model_Resource_Db_Collection_Abstract */
            $this->_xml .= '<collection class="' . get_class($collection) . '">';
            $this->_xml .= '<query>';
            $this->_xml .= '<![CDATA[';
            $this->_xml .= $collection->getSelectSql(TRUE);
            $this->_xml .= ']]>';
            $this->_xml .= '</query>';
            $this->_xml .= '<result time="' . $this->_getTimeSpan($collection) . '" />';
            $this->_xml .= '<watching time="' . $this->_getTimeSpan($this, FALSE) . '" />';
            $this->_xml .= '</collection>';
        }
        return $this;
    }

    /**
     *
     * @param Varien_Event_Observer $observer
     * @return In_Watcher_Model_Observer
     */
    public function onModelLoadBefore($observer) {
        if ($this->isActive()) {
            $model = $observer->getObject();
            /* @var $model Mage_Core_Model_Abstract */
            $this->_getTimeSpan($model);
            $model->setData('__cache_watcher_field', $observer->getField() ? $observer->getField() : $model->getIdFieldName());
            $model->setData('__cache_watcher_value', $observer->getValue());
        }
        return $this;
    }

    /**
     *
     * @param Varien_Event_Observer $observer
     * @return In_Watcher_Model_Observer
     */
    public function onModelLoadAfter($observer) {
        if ($this->isActive()) {
            $model = $observer->getObject();
            /* @var $model Mage_Core_Model_Abstract */
            $this->_xml .= '<model class="' . get_class($model) . '" action="load">';
            foreach (array('field', 'value') as $key) {
                $this->_xml .= '<' . $key . '>' . $model->getData('__cache_watcher_' . $key) . '</' . $key . '>';
            }
            $this->_xml .= '<result time="' . $this->_getTimeSpan($model) . '" />';
            $this->_xml .= '<watching time="' . $this->_getTimeSpan($this, FALSE) . '" />';
            $this->_xml .= '</model>';
        }
        return $this;
    }

    /**
     *
     * @param Varien_Event_Observer $observer
     * @return In_Watcher_Model_Observer
     */
    public function onModelSaveBefore($observer) {
        if ($this->isActive()) {
            $model = $observer->getObject();
            /* @var $model Mage_Core_Model_Abstract */
            $this->_getTimeSpan($model);
        }
        return $this;
    }

    /**
     *
     * @param Varien_Event_Observer $observer
     * @return In_Watcher_Model_Observer
     */
    public function onModelSaveAfter($observer) {
        if ($this->isActive()) {
            $model = $observer->getObject();
            /* @var $model Mage_Core_Model_Abstract */
            $this->_xml .= '<model class="' . get_class($model) . '" action="save">';
            $this->_xml .= '<result time="' . $this->_getTimeSpan($model) . '" />';
            $this->_xml .= '<watching time="' . $this->_getTimeSpan($this, FALSE) . '" />';
            $this->_xml .= '</model>';
        }
        return $this;
    }

    /**
     *
     * @param Varien_Event_Observer $observer
     * @return In_Watcher_Model_Observer
     */
    public function onModelDeleteBefore($observer) {
        if ($this->isActive()) {
            $model = $observer->getObject();
            /* @var $model Mage_Core_Model_Abstract */
            $this->_getTimeSpan($model);
        }
        return $this;
    }

    /**
     *
     * @param Varien_Event_Observer $observer
     * @return In_Watcher_Model_Observer
     */
    public function onModelDeleteAfter($observer) {
        if ($this->isActive()) {
            $model = $observer->getObject();
            /* @var $model Mage_Core_Model_Abstract */
            $this->_xml .= '<model class="' . get_class($model) . '" action="delete">';
            $this->_xml .= '<field>' . $model->getIdFieldName() . '</field>';
            $this->_xml .= '<value>' . $model->getId() . '</value>';
            $this->_xml .= '<result time="' . $this->_getTimeSpan($model) . '" />';
            $this->_xml .= '<watching time="' . $this->_getTimeSpan($this, FALSE) . '" />';
            $this->_xml .= '</model>';
        }
        return $this;
    }

}
