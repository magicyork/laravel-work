#laravel框架内核运行注解

### laravel扩展包安装时做了什么
1.composer事件
[composer事件](https://docs.phpcomposer.com/articles/scripts.html)
在运行composer命令时，会触发响应指定的事件，从而运行指定的脚本文件

2.laravel 的composer.json文件
![composer.json文件结构](images/composerjson.png)
3.每次安装第三方扩展时，会自动运行包发现指令，并读取vendor/composer/installed.json文件
里的内容，并将每个扩展包的extra额外选项下指定的laravel里的providers,alias下的配置获取
并写入指定的文件packages.php并保存在项目的bootstrap/cache/packages.php里
```php
$packages = [];

        if ($this->files->exists($path = $this->vendorPath.'/composer/installed.json')) {
            $packages = json_decode($this->files->get($path), true);
        }

        $ignoreAll = in_array('*', $ignore = $this->packagesToIgnore());

        $this->write(collect($packages)->mapWithKeys(function ($package) {
            return [$this->format($package['name']) => $package['extra']['laravel'] ?? []];
        })->each(function ($configuration) use (&$ignore) {
            $ignore = array_merge($ignore, $configuration['dont-discover'] ?? []);
        })->reject(function ($configuration, $package) use ($ignore, $ignoreAll) {
            return $ignoreAll || in_array($package, $ignore);
        })->filter()->all());
```

### laravel Illuminate\Foundation\Http\Kernel 内核大体流程
##### http 内核注册到实例化
```php
$app->singleton(
    /**
    运行后会将其以key,value形式保存在容器的bindings[]里
    我这里叫注册
     **/
    Illuminate\Contracts\Http\Kernel::class,
    App\Http\Kernel::class
);
 //从里面检索并实例化【反射】返回
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
```

http内核实例化时 路由添加中间件类
```php
public function __construct(Application $app, Router $router)
    {
        $this->app = $app;
        $this->router = $router;

        $router->middlewarePriority = $this->middlewarePriority;
        /**
        向路由类添加路由中间件类
        向路由类添加中间件类组
         **/
        foreach ($this->middlewareGroups as $key => $middleware) {
            $router->middlewareGroup($key, $middleware);
        }

        foreach ($this->routeMiddleware as $key => $middleware) {
            $router->aliasMiddleware($key, $middleware);
        }
    }
```
运行handle方法
```php 
 protected function sendRequestThroughRouter($request)
    {
        /**
        将当前的请求对象进行绑定，绑定到Application类的对象下
         **/
        $this->app->instance('request', $request);

        Facade::clearResolvedInstance('request');

        /**
        循环运行本类的成员$this->$bootstrappers[]下的成员数组
         **/
        $this->bootstrap();

        return (new Pipeline($this->app))
                    ->send($request) //加载全局中间件
                    ->through($this->app->shouldSkipMiddleware() ? [] : $this->middleware)
                    ->then($this->dispatchToRouter());

        /**
        $this->dispatchToRouter() 控制器运行之后返回的响应，响应由Symfony的组件完成
         **/
    }
```
此时当前请求相关的【request】已保存【注册】在窗口里的instances[request] = $request里了
`$this->bootstrap()`运行后
- 会完成环境配置文件的解析并将其保存在超级变量$_SERVER,$_ENV上
- 同时处理配置目录config/下的所有配置文件，并保存
- 设置错误，异常等自定义处理
- 注册自定义的伪装【他们叫门面？】
- 注册框架安装的所有扩展包【为laravel写的扩展包，有服务提供注册机制】并运行服务提供类的register方法
- 运行服务提供类的boot方法

### router 路由注册服务【路由服务类处理】
- 路由注册由 RouteServiceProvider 完成
- web路由注册代码流程
```php
Route::middleware('web')
             ->namespace($this->namespace)
             ->group(base_path('routes/web.php'));
```
`Route::middleware('web')` 此时将触发以下代码  
```php
Illuminate\Support\Facades\Facade

public static function __callStatic($method, $args)
    {
        /**
        static::$resolvedInstance[$name] = static::$app[$name];
        运行后得到Application类的对象，并且调用Application[$name] 该方法会触发ArrayAccess接口并实例化当前的门面子类如Route
         **/
        $instance = static::getFacadeRoot();

        /****/
        if (! $instance) {
            throw new RuntimeException('A facade root has not been set.');
        }

        return $instance->$method(...$args);
    }

public static function getFacadeRoot()
    {
        /**
        得到当前调用的门面伪装类并使用Application实例化返回
         **/
        return static::resolveFacadeInstance(static::getFacadeAccessor());
    }
protected static function getFacadeAccessor()
    {
        return 'router';
    }
    protected static function resolveFacadeInstance($name)
        {
            if (is_object($name)) {
                return $name;
            }
    
            if (isset(static::$resolvedInstance[$name])) {
                return static::$resolvedInstance[$name];
            }
    
            return static::$resolvedInstance[$name] = static::$app[$name];
        }
```


`static::$app[$name];`最终是容器从已经注册的池里检索到`'router'=> [\Illuminate\Routing\Router::class, \Illuminate\Contracts\Routing\Registrar::class, \Illuminate\Contracts\Routing\BindingRegistrar::class],
`Illuminate\Routing\Router::class类实例【反射】后返回

接着运行`Illuminate\Routing\Router->middleware('web')`激活魔术方法__call,从而运行如下代码  
```php 

public function __call($method, $parameters)
    {
        if (static::hasMacro($method)) {
            return $this->macroCall($method, $parameters);
        }

        /**
        Router类运行不存在的时候会运行到此
        当运行中间件方法时 $parameters=middle(web)传递过来的中间件别名参数

         当路由器调用：middleware,namesapce,domain,as时
         **/
        if ($method == 'middleware') {
            return (new RouteRegistrar($this))->attribute($method, is_array($parameters[0]) ? $parameters[0] : $parameters);
        }

        return (new RouteRegistrar($this))->attribute($method, $parameters[0]);
    }
```
此时是运行`new RouteRegistrar($this))`路由注册器,运行如下代码，保存路由属性
```php 
public function attribute($key, $value)
    {
        /**
        $key【可能是个方法method】
        判断不是本类规定的属性时
         **/
        if (! in_array($key, $this->allowedAttributes)) {
            throw new InvalidArgumentException("Attribute [{$key}] does not exist.");
        }

        //在此查看$this->>aliases[]和$this->>attributes[]数组
        //$this->attributes[middleware] = web;
        $this->attributes[Arr::get($this->aliases, $key, $key)] = $value;

        /**
        保存了中间件别名
        应用的命名空间
         **/
        $test = "show";
        return $this;
    }
```
   - 路由属性  
   
      | as | domain | middleware | name | namespace | prefix |
      |----|--------|------------|------|-----------|--------|
      | 别名| 域名   | 中间件     |  路由名称| 路由指向的空间 | 路由的前缀|
      
      
      
   此时` Route::middleware('web')` 运行完此方法后返回`RouteRegistrar`，接着运行  
   `RouteRegistrar->namespace($this->namespace)`，然后激活如下代码  
   ```php 
   RouteRegistrar类
   public function __call($method, $parameters)
       {
           /**
           [
           'get', 'post', 'put', 'patch', 'delete', 'options', 'any',
           ]当运行以上方法时
   
           Route::group(['middleware'=>'user.verify','prefix'=>'admin'],function (){
           Route::get("user/index","UsersController@index");
   
           Route::get("user/test","UsersController@test");
           });
   
   
   
            **/
           if (in_array($method, $this->passthru)) {
               return $this->registerRoute($method, ...$parameters);
           }
   
           /**
           [
           'as', 'domain', 'middleware', 'name', 'namespace', 'prefix',
           ]运行以上方法时
            **/
           if (in_array($method, $this->allowedAttributes)) {
               if ($method == 'middleware') {
                   return $this->attribute($method, is_array($parameters[0]) ? $parameters[0] : $parameters);
               }
   
               return $this->attribute($method, $parameters[0]);
           }
   
           throw new BadMethodCallException("Method [{$method}] does not exist.");
       }
   ```  
   ,运行后保存路由的属性-命名空间，接着运行`RouteRegistrar->group(base_path('routes/web.php'));`,此时加载  
   路由目录下的web路由文件，运行如下代码  
   ```php 
   //RouteRegistrar类 在实例化的时候$this->router = Illuminate\Routing\Router 实例
   //(new RouteRegistrar($this=Illuminate\Routing\Router))
   public function group($callback)
       {
           /**
           传递属性数组，路由文件地址
            **/
           $this->router->group($this->attributes, $callback);
       }
       
       protected function loadRoutes($routes)
           {
       
               if ($routes instanceof Closure) {
       
                   $temp = "运行路由定义的匿名函数";
                   $routes($this);
               } else {
                   $router = $this;
       
                   require $routes;//运行路由web.php文件
               }
           }
   ```
   假设路由web.php内容如下
   ```php 
   Route::group(['middleware'=>'user.verify','prefix'=>'admin'],function (){
       //Route::get("user/index","UsersController@index");
   
       /**
       Route触发门面基类并实例Router->get("user/test","UsersController@test")方法
        **/
       Route::get("user/index","UsersController@test");
   });
   ```
   此时会运行类似如下代码   [在运行前会把路由属性保存在组堆里groupStack]
   ```php 
   public function get($uri, $action = null)
       {
           /**
           会运行路由定义的get方法
           如Route::get(uri,action);
            **/
           return $this->addRoute(['GET', 'HEAD'], $uri, $action);
       }
   ```
   接着运行如下代码   
   ```php 
   protected function addRoute($methods, $uri, $action)
       {
           /**
           添加到路由集合类里
           得到路由对象
            **/
           return $this->routes->add($this->createRoute($methods, $uri, $action));
       }
       
       protected function createRoute($methods, $uri, $action)
           {
               // If the route is routing to a controller we will parse the route action into
               // an acceptable array format before registering it and creating this route
               // instance itself. We need to build the Closure that will call this out.
               if ($this->actionReferencesController($action)) {
                   //得到完整的控制器【带有命名空间】数组
                   $action = $this->convertToControllerAction($action);
               }
       
               /**
               $action会得到类似
                [
                   users=App\Http\Controllers\UsersController@index
                   controller=App\Http\Controllers\UsersController@index
                ]
       
                $method = [GET,HEAD]
                
                $uri = "users/index"
                **/
               $route = $this->newRoute(
                   //方法，完整的uri,action
                   $methods, $this->prefix($uri), $action
               );
       
               // If we have groups that need to be merged, we will merge them now after this
               // route has already been created and is ready to go. After we're done with
               // the merge we will be ready to return the route back out to the caller.
               if ($this->hasGroupStack()) {
                   $this->mergeGroupAttributesIntoRoute($route);
               }
       
               $this->addWhereClausesToRoute($route);
       
               return $route;
           }
   ```   
   
   路由集合类`$this->routes = new RouteCollection;`,路由的请求方法method  
   路由的地址uri,路由的行为action会映射为route路由对象 并保存在路由集合里[注册？]
   路由的uri会从路由属性prefix(groupStack里取prefix)拼接完整   
   路由的action其中uses,controller会从路由属性(groupStack)里取namespace构成完整的  
   controller拼接组合，route(Illuminate\Routing\Route) 中的action成员保存数据结构如下   
   ![route 的action结构图](images/route-action.png)
   [laravel 路由定义](https://learnku.com/docs/laravel/5.5/routing/1293)
   
   路由注册最终运行如下代码   
   ```php
   protected function addToCollections($route)
    {
        /**
        将路由添加到路由数组里
         路由对象取得域名，请求的uri参数
         **/
        $domainAndUri = $route->getDomain().$route->uri();

        /**
        routest[请求方式][服务器地址] = [路由对象]
         **/
        foreach ($route->methods() as $method) {
            //路由的请求方法-路由的完整uri=路由对象
            //路由对象保存了大量的路由定义文件里的规则
            //$this->routes[请求方法][完整的uri]=路由对象
            $this->routes[$method][$domainAndUri] = $route;
        }

        //路由请求方法+完整的请求uri=路由对象
        $this->allRoutes[$method.$domainAndUri] = $route;
    }
   ```
   路由注册完成后，routeCollection里保存的路由结构图如下   
   ![route路由结构图](images/routecollection_all.png)
   ![route路由结构图](images/routecollection_all1.png)
   ![route路由结构图](images/routecollection_all2.png)
   
   

##### http kernel 【handle方法代码】
```php 

return (new Pipeline($this->app))
                    ->send($request) //加载全局中间件
                    ->through($this->app->shouldSkipMiddleware() ? [] : $this->middleware)
                    ->then($this->dispatchToRouter());
```  
then方法代码如下   
```php
public function then(Closure $destination)
    {
        //$this->pipes 中间件类，元素【中间件类】一个个的弹出
        //$this->carry() 实例化中间件类并运行中间件类的handle方法
        //$destination 匿名函数
        $pipeline = array_reduce(
            /**
            第一次时：为全局中间件
            第二次时：路由定义的中间件类，Kernel内核定义的路由分组中间件类
             **/
            array_reverse($this->pipes), $this->carry(), $this->prepareDestination($destination)
        );
        //$this->passable 是Request类的对象
        $this->passable['test'] = 'jack';
        /**
        protected function dispatchToRouter()
        {
        return function ($request) {
        $this->app->instance('request', $request);

        return $this->router->dispatch($request);
        };
        }

        这里的调用如下$this->passable 当前的请求对象
         **/

        //这里运行的匿名函数是
        /**
         * function ($passable) use ($destination) {
               return $destination($passable);
          };
         */
        return $pipeline($this->passable);
    }
```   
中间件类运行如下   
```php 
protected function carry()
    {
        return function ($stack, $pipe) {

            /**
             * $passable 当前请求对象
             * $pipe 中间件类
             */
            return function ($passable) use ($stack, $pipe) {
                if (is_callable($pipe)) {
                    // If the pipe is an instance of a Closure, we will just call it directly but
                    // otherwise we'll resolve the pipes out of the container and call it with
                    // the appropriate method and arguments, returning the results back out.
                    return $pipe($passable, $stack);
                } elseif (! is_object($pipe)) {
                    list($name, $parameters) = $this->parsePipeString($pipe);

                    // If the pipe is a string we will parse the string and resolve the class out
                    // of the dependency injection container. We can then build a callable and
                    // execute the pipe function giving in the parameters that are required.
                    $pipe = $this->getContainer()->make($name);

                    $parameters = array_merge([$passable, $stack], $parameters);
                } else {
                    // If the pipe is already an object we'll just make a callable and pass it to
                    // the pipe as-is. There is no need to do any extra parsing and formatting
                    // since the object we're given was already a fully instantiated object.
                    $parameters = [$passable, $stack];
                }  n

                //运行中间件的handle方法
                /**
                 * ...$parameters  第一个参数为当前请求的对象Request，第二个参数为
                 *function ($passable) use ($stack, $pipe) {
                     if (is_callable($pipe)) {

                        return $pipe($passable, $stack);
                     } elseif (! is_object($pipe)) {
                         list($name, $parameters) = $this->parsePipeString($pipe);

                         $pipe = $this->getContainer()->make($name);

                          $parameters = array_merge([$passable, $stack], $parameters);
                     } else {

                         $parameters = [$passable, $stack];
                    }
                     return method_exists($pipe, $this->method)
                                   ? $pipe->{$this->method}(...$parameters)
                                   : $pipe(...$parameters);
                 };
                 第二个参数是当前的匿名函数，第一个参数$passable=当前请求对象，$pipe每个中间件类
                 * 示例文件位于lessones/html/php
                 * array_reduce,array_reverse的使用
                 * 当中间件的handle方法返回真时则会继续循环中间件类，返回false时中间件将不在运行
                 */
                return method_exists($pipe, $this->method)
                                ? $pipe->{$this->method}(...$parameters)
                                : $pipe(...$parameters);
            };
        };
    }
```   
中间件类结构如下所示    
![全局中间件类数组](images/middleware.png)
中间件类会先实例【反射】运行handle方法，中间件返回false或是中间件exit时`array_reverse($this->pipes), $this->carry(), $this->prepareDestination($destination)`   
第三个参数![dispatchroute](images/dispatchroute1.png)
![dispatchroute](images/dispatchroute2.png)

将不会运行，即先让全局中间件类先运行完成，后再进行路由调度,将当前请求调度到应用   
```php 
 /**
     * Dispatch the request to the application.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response|\Illuminate\Http\JsonResponse
     */
    public function dispatch(Request $request)
    {
        $this->currentRequest = $request;

        return $this->dispatchToRoute($request);
    }
    
     public function dispatchToRoute(Request $request)
        {
            $temp = "test uri";
            return $this->runRoute($request, $this->findRoute($request));
        }
        
        
        //检索路由
      protected function findRoute($request)
         {
             //从路由池里匹配当前请求  从而得到具体的路由对象
             $this->current = $route = $this->routes->match($request);
     
             //将当前的路由对象保存在容器里
             $this->container->instance(Route::class, $route);
     
             return $route;
         }
```   
检索路由过程如下   
```php 
public function match(Request $request)
    {
        /**
        路由先匹配请求方式 $this->routes[$method][$domainAndUri] = $route;
        从路由池里找到具体的路由对象
         **/
        $routes = $this->get($request->getMethod());

        // First, we will see if we can find a matching route for this current request
        // method. If we can, great, we can just return it so that it can be called
        // by the consumer. Otherwise we will check for routes with another verb.
        //验证当前的请求是否匹配路由的请求方式，uri链接，协议，主机地址
        $route = $this->matchAgainstRoutes($routes, $request);

        if (! is_null($route)) {
            //绑定路由
            return $route->bind($request);
        }

        // If no route was found we will now check if a matching route is specified by
        // another HTTP verb. If it is we will need to throw a MethodNotAllowed and
        // inform the user agent of which HTTP verb it should use for this route.
        $others = $this->checkForAlternateVerbs($request);

        if (count($others) > 0) {
            return $this->getRouteForMethods($request, $others);
        }

        $temp = "在此查看url到路由匹配规则";

        throw new NotFoundHttpException;
    }

```   

其中` $routes = $this->get($request->getMethod());`运行得到如下数据如图所示   
![路由检索后的结果](images/findroute1.png)
![路由检索后的结果](images/findroute2.png)
![路由检索后的结果](images/findroute3.png)

以method方式从路由集合routeCollection里检索到路由    
验证是否匹配请求   
```php 
protected function matchAgainstRoutes(array $routes, $request, $includingMethod = true)
    {
        /**
        $routes[method][uri]=route object
        经过匹配检索后得到routes[uri]=route object
         **/
        list($fallbacks, $routes) = collect($routes)->partition(function ($route) {
            return $route->isFallback;
        });

        return $routes->merge($fallbacks)->first(function ($value) use ($request, $includingMethod) {
        
        //运行Illuminate\Routing\Route->matches()
            return $value->matches($request, $includingMethod);
        });
    }
    
     public function matches(Request $request, $includingMethod = true)
        {
            $this->compileRoute();
    
            foreach ($this->getValidators() as $validator) {
                if (! $includingMethod && $validator instanceof MethodValidator) {
                    continue;
                }
    
                /**
                 * $validators = [
                new UriValidator, new MethodValidator,
                new SchemeValidator, new HostValidator,
                ];
                 */
                if (! $validator->matches($this, $request)) {
                    return false;
                }
            }
    
            return true;
        }
```   

在routeCollection先根据请求method进行匹配，匹配得到的路由routes[route]   
在循环，分别检测每个路由的uri,method,schema,host是否完全匹配，再返回具体的路由对象  
```php
 protected function matchAgainstRoutes(array $routes, $request, $includingMethod = true)
    {
        /**
        $routes[method][uri]=route object
        经过匹配检索后得到routes[uri]=route object
         **/
        list($fallbacks, $routes) = collect($routes)->partition(function ($route) {
            return $route->isFallback;
        });

        return $routes->merge($fallbacks)->first(function ($value) use ($request, $includingMethod) {
            return $value->matches($request, $includingMethod);
        });
    }
    
```   

` return $routes->merge($fallbacks)->first(function ($value) use ($request, $includingMethod) {
             return $value->matches($request, $includingMethod);
         });` 具体运行如下   
         
         
```php 
public function matches(Request $request, $includingMethod = true)
    {
        $this->compileRoute();

        foreach ($this->getValidators() as $validator) {
            if (! $includingMethod && $validator instanceof MethodValidator) {
                continue;
            }

            /**
             * $validators = [
            new UriValidator, new MethodValidator,
            new SchemeValidator, new HostValidator,
            ];
             */
            if (! $validator->matches($this, $request)) {
                return false;
            }
        }

        return true;
    }
```   

经过循环请求method匹配到的路由数组，分别验证请求uri,method,schema,host得到指定的路由对象   

运行路由   
```php 
protected function runRoute(Request $request, Route $route)
    {
        $request->setRouteResolver(function () use ($route) {
            return $route;
        });

        $this->events->dispatch(new Events\RouteMatched($route, $request));

        return $this->prepareResponse($request,
            $this->runRouteWithinStack($route, $request)
        );
    }
```   

带中间件运行   
```php 
 protected function runRouteWithinStack(Route $route, Request $request)
    {
        $shouldSkipMiddleware = $this->container->bound('middleware.disable') &&
                                $this->container->make('middleware.disable') === true;

        //得到路由设置的中间件类和控制器设置的中间件类
        //路由定义的中间件，分组中间件，路由中间件类
        //本类已经保存了Http/Kernel内核下定义的中间件组和路由中间件别名
        //用户在route/web.php定义的中间件简短名称转换为完整的类名返回
        $middleware = $shouldSkipMiddleware ? [] : $this->gatherRouteMiddleware($route);

        //这里的中间件数据是web中间件
        $test1 = "这里看一下中间件到底有几个";
        return (new Pipeline($this->container))
                        ->send($request)
                        ->through($middleware)
                        ->then(function ($request) use ($route) {
                            return $this->prepareResponse(
                                $request, $route->run()
                            );
                        });
    }
```   
关于路由中间件和控制器中间件的取得过程简短说明   
    - 先从route下的action里取出中间件名称即action【middleware】  
    - 再从控制器【基类】里取取中间件名称【注意控制器的中间件方法only,except】  
    - 再将取回的中间件名称从一开始注册的中间件组，路由中间件数组里取回   
    
 中间件数组如下所示   
 ![中间件组](images/middlewareKernel1.png)
 ![中间件组](images/middlewareKernel2.png)
 
 
 中间件类运行正常后，再运行如下代码    
 ```php 
 public function run()
     {
         $this->container = $this->container ?: new Container;
 
         try {
             /**
             控制器是字符串时类似App\Controller\Admin\Users@xxx
              **/
             if ($this->isControllerAction()) {
                 return $this->runController();
             }
 
             /**
             如果是回调函数时
              **/
             return $this->runCallable();
         } catch (HttpResponseException $e) {
             return $e->getResponse();
         }
     }
     
     protected function runController()
         {
             return $this->controllerDispatcher()->dispatch(
                 //得到控制器对象，控制器方法名
                 $this, $this->getController(), $this->getControllerMethod()
             );
         }
 ```   
 得到控制器   
 ```php 
  public function getController()
     {
         if (! $this->controller) {
             // return Str::parseCallback($this->action['uses']);  从该数组取出设置的路由取索引0得到控制器类
             $class = $this->parseControllerCallback()[0];
 
             //实例化控制器
             $this->controller = $this->container->make(ltrim($class, '\\'));
         }
 
         return $this->controller;
     }
 ```   
 
 得到控制器的动作   
 
 ```php 
 
 protected function getControllerMethod()
     {
         return $this->parseControllerCallback()[1];
     }
 
     /**
      * Parse the controller.
      *
      * @return array
      */
     protected function parseControllerCallback()
     {
         return Str::parseCallback($this->action['uses']);
     }
 ```   
 
 控制器调度运行  
 
 ![控制器调度](images/dispatch1.png)
 
 响应结果处理   
 ```php 
 public static function toResponse($request, $response)
     {
         if ($response instanceof Responsable) {
             $response = $response->toResponse($request);
         }
 
         if ($response instanceof PsrResponseInterface) {
             $response = (new HttpFoundationFactory)->createResponse($response);
         } elseif (! $response instanceof SymfonyResponse &&
                    ($response instanceof Arrayable ||
                     $response instanceof Jsonable ||
                     $response instanceof ArrayObject ||
                     $response instanceof JsonSerializable ||
                     is_array($response))) {
             /**
             该类为Symfony组件的响应组件，具体文档位于
             https://symfony.com/doc/current/components/http_foundation.html#response
              **/
             $response = new JsonResponse($response);
         } elseif (! $response instanceof SymfonyResponse) {
             $response = new Response($response);
         }
 
         if ($response->getStatusCode() === Response::HTTP_NOT_MODIFIED) {
             $response->setNotModified();
         }
 
         /**
         准备响应
          **/
         return $response->prepare($request);
     }
 ```  
 
 end   
 ![send all to client](images/send.png)
 [fastcgi_finish_request](http://php.net/manual/zh/function.fastcgi-finish-request.php)

