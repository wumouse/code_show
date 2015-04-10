<?php

namespace Library;

use Phalcon\Config;
use Phalcon\DI\InjectionAwareInterface;
use Phalcon\DiInterface;

/**
 * 兼容DI结构的基类，用来作可以注入DI的类，包含了设置选项和获取选项
 *
 * @author wuhao <wumouse@qq.com>
 * @version $Id$
 */
abstract class InjectionAware implements InjectionAwareInterface
{

    /**
     * 注入容器
     *
     * @var DiInterface
     */
    protected static $_di;

    /**
     * 选项
     *
     * @var array|Config
     */
    protected $_options;

    /**
     * 设置 DI，在注入时会自动调用
     *
     * @param \Phalcon\DiInterface $di
     * @return void
     */
    public function setDI($di)
    {
        self::$_di = $di;
    }

    /**
     * 获取DI
     *
     * @return \Phalcon\DiInterface
     */
    public function getDI()
    {
        return self::$_di;
    }

    /**
     * 获取选项
     *
     * @return Config
     */
    public function getOptions()
    {
        return $this->_options;
    }

    /**
     * 设置选项，必须在子类的构造函数中将 $this->_options 初始化为 Phalcon\Config 对象
     *
     * @param array|Config $options
     * @throws Exception
     * @return void
     */
    public function setOptions($options)
    {
        if (!$this->_options instanceof Config) {
            $this->_options = new Config($this->_options);
        }
        if (!is_array($options) && !$options instanceof Config) {
            $type = gettype($options);
            throw new Exception(
                "Argument 1 passed to setOptions() must be of the type array or Phalcon\Config, {$type} given"
            );
        }
        $this->_options->merge($options);
    }

}
