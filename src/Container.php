<?php

namespace Grendizer\Container;

class Container implements ContainerInterface
{
    /**
     * The current globally available container (if any).
     *
     * @var static
     */
    protected static $instance;

    /**
     * 维护共享的“抽象事物”的具体对象的映射表
     *
     * @var array
     */
    protected $instances = array();

    /**
     * 维护“抽象事物”与其“具体实现”的映射表
     *
     * @var array
     */
    protected $bindings = array();

    /**
     * 记录已经解决的“抽象事物”
     *
     * @var array
     */
    protected $resolved = array();

    /**
     * 一个用来维护“实现抽象事物的具体方式”的执行栈
     *
     * @var array
     */
    protected $buildStack = array();

    /**
     * Set the globally available instance of the container.
     *
     * @return static
     */
    public static function getInstance()
    {
        return static::$instance;
    }

    /**
     * Set the shared instance of the container.
     *
     * @param  \Grendizer\Container\ContainerInterface  $container
     * @return void
     */
    public static function setInstance(ContainerInterface $container)
    {
        static::$instance = $container;
    }

    /**
     * @inheritdoc
     */
    public function bound($abstract)
    {
        $abstract = $this->normalize($abstract);

        return isset($this->bindings[$abstract]) || isset($this->instances[$abstract]);
    }

    /**
     * @inheritdoc
     */
    public function resolved($abstract)
    {
        $abstract = $this->normalize($abstract);

        return isset($this->resolved[$abstract]) || isset($this->instances[$abstract]);
    }

    /**
     * 取得所有的绑定
     *
     * @return array
     */
    public function getBindings()
    {
        return $this->bindings;
    }

    /**
     * 删除一个已经解决依赖注入的共享的“抽象事物”
     *
     * @param  string  $abstract
     * @return void
     */
    public function forgetInstance($abstract)
    {
        unset($this->instances[$this->normalize($abstract)]);
    }

    /**
     * 删除所有的共享的“抽象事物”
     *
     * @return void
     */
    public function forgetInstances()
    {
        $this->instances = array();
    }

    /**
     * 属性所有的绑定
     *
     * @return void
     */
    public function flush()
    {
        $this->resolved = array();
        $this->bindings = array();
        $this->instances = array();
    }

    /**
     * @inheritdoc
     */
    public function bind($abstract, $concrete = null, $shared = false)
    {
        $abstract = $this->normalize($abstract);
        $concrete = $this->normalize($concrete);

        // 删除以前绑定的共享实例
        unset($this->instances[$abstract]);

        // 平滑过渡一个未指定“具体实现方式”的“抽象事物”
        if (is_null($concrete)) {
            $concrete = $abstract;
        }

        if (!$concrete instanceof \Closure) {
            $concrete = $this->getClosure($abstract, $concrete);
        }

        $this->bindings[$abstract] = compact('concrete', 'shared');

        // 如果之前已经解决了该“抽象事物”，则需要刷新“该绑定”
        // 以解决引用该“抽象事物”的环境不能正确取得“该事物”的实例
        if ($this->resolved($abstract)) {
            $this->make($abstract);
        }
    }

    /**
     * 使用闭包来包装“抽象事物”
     *
     * @param  string  $abstract
     * @param  string  $concrete
     * @return \Closure
     */
    protected function getClosure($abstract, $concrete)
    {
        return function ($c, $parameters = array()) use ($abstract, $concrete) {
            $resolve = ($abstract == $concrete) ? 'build' : 'make';

            return $c->{$resolve}($concrete, $parameters);
        };
    }

    /**
     * @inheritdoc
     */
    public function singleton($abstract, $concrete = null)
    {
        $this->bind($abstract, $concrete, true);
    }

    /**
     * 使用闭包类包装给定的闭包，是被包装的闭包的结果得以被共享
     *
     * @param  \Closure  $closure
     * @return \Closure
     */
    public function share(\Closure $closure)
    {
        return function ($container) use ($closure) {
            // 利用静态绑定的特性，来实现结果静态化共享
            static $object;

            if (is_null($object)) {
                $object = $closure($container);
            }

            return $object;
        };
    }

    /**
     * @inheritdoc
     */
    public function instance($abstract, $instance)
    {
        $abstract = $this->normalize($abstract);
        $bound = $this->bound($abstract);

        $this->instances[$abstract] = $instance;

        if ($bound) {
            $this->make($abstract);
        }
    }

    /**
     * 使用给定的依赖包装给定闭包，其依赖项在执行被注入。
     *
     * @param  \Closure  $callback
     * @param  array  $parameters
     * @return \Closure
     */
    public function wrap(\Closure $callback, array $parameters = array())
    {
        return function () use ($callback, $parameters) {
            return $this->call($callback, $parameters);
        };
    }

    /**
     * @inheritdoc
     */
    public function call($callback, array $parameters = array(), $defaultMethod = null)
    {
        // #1 执行一个类的方法
        if (is_string($callback) && false !== strpos($callback, '@') || $defaultMethod) {
            $segments = explode('@', $callback);

            // If the listener has an @ sign, we will assume it is being used to delimit
            // the class name from the handle method name. This allows for handlers
            // to run multiple handler methods in a single class for convenience.
            $method = count($segments) == 2 ? $segments[1] : $defaultMethod;

            if (is_null($method)) {
                throw new \InvalidArgumentException('Method not provided.');
            }

            return $this->call(array($this->make($segments[0]), $method), $parameters);
        }

        // #2 执行一个函数、方法或闭包

        $dependencies = array();

        if (is_string($callback) && strpos($callback, '::') !== false) {
            $callback = explode('::', $callback);
        }

        if (is_array($callback)) {
            $callbackParameters = new \ReflectionMethod($callback[0], $callback[1]);
        } else {
            $callbackParameters = new \ReflectionFunction($callback);
        }

        foreach ($callbackParameters->getParameters() as $parameter) {
            if (array_key_exists($parameter->name, $parameters)) {
                $dependencies[] = $parameters[$parameter->name];

                unset($parameters[$parameter->name]);
            } elseif ($parameter->getClass()) {
                $dependencies[] = $this->make($parameter->getClass()->name);
            } elseif ($parameter->isDefaultValueAvailable()) {
                $dependencies[] = $parameter->getDefaultValue();
            }
        }

        return call_user_func_array($callback, array_merge($dependencies, $parameters));
    }

    /**
     * 为实现“抽象事物”的“具体方式”注入依赖，并实现该“抽象事物”
     *
     * @param  string $concrete
     * @param  array  $parameters
     * @return object
     *
     * @throws \Grendizer\Container\ContainerException
     */
    public function build($concrete, array $parameters = array())
    {
        // 实现抽象事物的具体方式是一个闭包
        if ($concrete instanceof \Closure) {
            return $concrete($this, $parameters);
        }

        $reflector = new \ReflectionClass($concrete);
        $stack = &$this->buildStack;

        // 针对不能实例化的对象（接口类和抽象类）
        if (!$reflector->isInstantiable()) {
            $previous = !empty($stack) ? ' while building '.implode(', ', $stack) : '';
            $message = sprintf('Target [%s] is not instantiable%s.', $concrete, $previous);
            throw new ContainerException($message);
        }

        // 将当前需要解决的“具体方式”加入到执行栈中
        // 方便跟踪处理后续事宜（如：...）
        $stack[] = $concrete;

        $constructor = $reflector->getConstructor();

        // 当前需要解决的类没有构造函数，则
        // 表示可以直接实例化（没有依赖）
        if (is_null($constructor)) {
            array_pop($stack);
            return new $concrete;
        }

        // 获取构造函数的参数列表，方便后面
        // 解析该“具体方式”所依赖的抽象事物
        $dependencies = $constructor->getParameters();

        // 阵列比较传递进来的参数和“具体方式”的参数
        // 通过小标参数，确定用于实现抽象事物的依赖
        foreach ($parameters as $key => $value) {
            if (!is_numeric($key)) {
                continue;
            }

            unset($parameters[$key]);

            // TODO 可能会不存在 $dependencies[$key]
            $parameters[$dependencies[$key]->name] = $value;
        }

        // 依赖的“抽象事物”的“具体方式”的结果
        $instances = array();

        foreach ($dependencies as $parameter) {
            $dependency = $parameter->getClass();
            $parameterName = $parameter->name;

            // 如果外部传递了参数进来
            if (array_key_exists($parameterName, $parameters)) {
                $instances[] = $parameters[$parameterName];
            }
            // 如果是依赖一个类
            elseif (!is_null($dependency)) {
                try {
                    $instances[] = $this->make($parameter->getClass()->name);
                } catch (ContainerException $e) {
                    // 如果无法得到该依赖类的实例，而且
                    // 该依赖类是一个可选的参数，则
                    // 使用该依赖参数的默认值
                    if ($parameter->isOptional()) {
                        $instances[] = $parameter->getDefaultValue();
                    } else {
                        throw $e;
                    }
                }
            }
            // 依赖的不是一个类
            else {
                // TODO 可能会存在一个上下文的依赖 ...

                // 检查是否有可用的默认值
                if ($parameter->isDefaultValueAvailable()) {
                    $instances[] = $parameter->getDefaultValue();
                } else {
                    $message = 'Unresolvable dependency resolving [%s] in class %s.';
                    $message = sprintf($message, $parameter, $parameter->getDeclaringClass()->getName());
                    throw new ContainerException($message);
                }
            }
        }

        array_pop($stack);

        // 所有的依赖都已经解决了
        return $reflector->newInstanceArgs($instances);
    }

    /**
     * @inheritdoc
     */
    public function resolve($abstract, array $parameters = array())
    {
        return $this->make($abstract, $parameters);
    }

    /**
     * 从容器中获取给定的抽象类型
     *
     * @param  string  $abstract
     * @param  array  $parameters
     * @return mixed
     */
    public function make($abstract, array $parameters = array())
    {
        // TODO 后续版本实现别名机制
        $abstract = $this->normalize($abstract);

        if (isset($this->instances[$abstract])) {
            // todo 是否需要刷新该共享的实例？
            return $this->instances[$abstract];
        }

        // 取得绑定的实现“抽象事物”的“具体方式”
        // todo 可能会存在一个上下文的绑定
        $concrete = isset($this->bindings[$abstract]) ? $this->bindings[$abstract]['concrete'] : $abstract;
        $resolve = $this->isBuildable($concrete, $abstract) ? 'build' : 'make';
        $object = $this->{$resolve}($concrete, $parameters);

        // todo 对得到的“抽象事物”的“具体实现”进行扩展操作？

        // 如果绑定的是共享的“抽象事物”
        if ($this->isShared($abstract)) {
            $this->instances[$abstract] = $object;
        }

        // todo 是否需要触发解决注入的事件？

        // 记录该“抽象事物”已经解决
        $this->resolved[$abstract] = true;

        return $object;
    }

    /**
     * 判断给定的“抽象事物”是否共享
     *
     * @param  string  $abstract
     * @return bool
     */
    public function isShared($abstract)
    {
        $abstract = $this->normalize($abstract);

        if (isset($this->instances[$abstract])) {
            return true;
        }

        if (! isset($this->bindings[$abstract]['shared'])) {
            return false;
        }

        return $this->bindings[$abstract]['shared'] === true;
    }

    /**
     * 给定类的名称正常化（删除打头的斜杠）

     * @param  mixed $service
     * @return mixed
     */
    protected function normalize($service)
    {
        return is_string($service) ? ltrim($service, '\\') : $service;
    }

    /**
     * 判断给定的“具体方式”是否可以被注入依赖，
     * 即：是不是一个真正的“具体方式”
     *
     * @param  mixed   $concrete
     * @param  string  $abstract
     * @return bool
     */
    protected function isBuildable($concrete, $abstract)
    {
        return $concrete === $abstract || $concrete instanceof \Closure;
    }

    /**
     * 判断给定的“抽象事物”是否绑定
     *
     * @param  string  $offset
     * @return bool
     */
    public function offsetExists($offset)
    {
        return $this->bound($offset);
    }

    /**
     * 获取“抽象事物”的“具体实现”的结果
     *
     * @param  string  $offset
     * @return mixed
     */
    public function offsetGet($offset)
    {
        return $this->make($offset);
    }

    /**
     * 类似于数组赋值的方式实现绑定
     *
     * @param  string  $offset
     * @param  mixed   $value
     * @return void
     */
    public function offsetSet($offset, $value)
    {
        // If the value is not a Closure, we will make it one. This simply gives
        // more "drop-in" replacement functionality for the Pimple which this
        // container's simplest functions are base modeled and built after.
        // 对于非闭包，使用闭包对其返回
        if (! $value instanceof \Closure) {
            $value = function () use ($value) {
                return $value;
            };
        }

        $this->bind($offset, $value);
    }

    /**
     * 销毁绑定的“抽象事物”
     *
     * @param  string  $offset
     * @return void
     */
    public function offsetUnset($offset)
    {
        $offset = $this->normalize($offset);

        unset(
            $this->bindings[$offset],
            $this->instances[$offset],
            $this->resolved[$offset]
        );
    }

    /**
     * 动态访问容器服务，
     * 当执行时，其依赖将被注入
     *
     * @param  string  $key
     * @return mixed
     */
    public function __get($key)
    {
        return $this[$key];
    }

    /**
     * 动态设置容器服务
     *
     * @param  string  $key
     * @param  mixed   $value
     * @return void
     */
    public function __set($key, $value)
    {
        $this[$key] = $value;
    }
}
