<?php

namespace Grendizer\Container;

interface ContainerInterface extends \ArrayAccess
{
    /**
     * 判断给定的“抽象事物”是否绑定到容器
     *
     * @param  string $abstract
     * @return bool
     */
    public function bound($abstract);

    /**
     * 判断给定的“抽象事物”是否解决了依赖注入
     *
     * @param  string $abstract
     * @return bool
     */
    public function resolved($abstract);

    /**
     * 绑定给定的“抽象事物”到当前容器
     *
     * @param string  $abstract
     * @param \Closure|string|null $concrete
     * @param bool $shared
     * @return void
     */
    public function bind($abstract, $concrete = null, $shared = false);

    /**
     * 注册一个共享的“抽象事物”到容器
     *
     * @param  string|array  $abstract
     * @param  \Closure|string|null  $concrete
     * @return void
     */
    public function singleton($abstract, $concrete = null);

    /**
     * 绑定一个共享的对象实例
     *
     * @param  string  $abstract
     * @param  mixed   $instance
     * @return void
     */
    public function instance($abstract, $instance);

    /**
     * 执行闭包（函数）或类的方法（class@method），并主动注入依赖
     *
     * @param  callable|string  $callback
     * @param  array  $parameters
     * @param  string|null  $defaultMethod
     * @return mixed
     */
    public function call($callback, array $parameters = [], $defaultMethod = null);

    /**
     * 从容器中获取给定的抽象类型
     *
     * @param  string  $abstract
     * @param  array  $parameters
     * @return mixed
     */
    public function resolve($abstract, array $parameters = array());
}
