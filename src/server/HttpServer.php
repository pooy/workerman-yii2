<?php

namespace tourze\workerman\yii2\server;

use tourze\workerman\yii2\Application;
use tourze\workerman\yii2\Container;
use tourze\workerman\yii2\log\Logger;
use Workerman\Connection\ConnectionInterface;
use Workerman\Protocols\Http;
use Workerman\Worker;
use Yii;
use yii\base\ErrorException;
use yii\helpers\ArrayHelper;
use yii\helpers\FileHelper;

/**
 * HTTP服务器
 *
 * @package tourze\workerman\yii2\server
 */
class HttpServer extends Server
{

    /**
     * @var array 当前配置文件
     */
    public $config = [];

    /**
     * @var string 缺省文件名
     */
    public $indexFile = 'index.php';

    /**
     * @var bool 是否开启xhprof调试
     */
    public $xhprofDebug = false;

    /**
     * @var bool
     */
    public $debug = false;

    /**
     * @var string
     */
    public $root;

    /**
     * @var Worker
     */
    public $server;

    /**
     * @var string
     */
    public $sessionKey = 'JSESSIONID';

    /**
     * @inheritdoc
     */
    public function run($app)
    {
        $this->config = is_array($app) ? $app : (array) Yii::$app->params['workermanHttp'][$app];
        if (isset($this->config['xhprofDebug']))
        {
            $this->xhprofDebug = $this->config['xhprofDebug'];
        }
        if (isset($this->config['debug']))
        {
            $this->debug = $this->config['debug'];
        }
        $this->root = $this->config['root'];
        $this->server = new Worker("http://{$this->config['host']}:{$this->config['port']}");
        foreach ($this->config['server'] as $k => $v)
        {
            $this->server->{$k} = $v;
        }

        $this->server->onWorkerStart = [$this, 'onWorkerStart'];
        $this->server->onWorkerReload = [$this, 'onWorkerReload'];
        $this->server->onWorkerStop = [$this, 'onWorkerStop'];
        $this->server->onMessage = [$this, 'onMessage'];

        $_SERVER['SERVER_ADDR'] = '127.0.0.1';
        $_SERVER['SERVER_NAME'] = 'localhost';

        Worker::runAll();
    }

    /**
     * Worker启动时触发
     *
     * @param Worker $worker
     */
    public function onWorkerStart($worker)
    {
        $this->setProcessTitle($this->name . ': worker');

        // 关闭Yii2自己实现的异常错误
        defined('YII_ENABLE_ERROR_HANDLER') || define('YII_ENABLE_ERROR_HANDLER', false);
        // 每个worker都创建一个独立的app实例

        // 加载文件和一些初始化配置
        if (isset($this->config['bootstrapFile']))
        {
            foreach ($this->config['bootstrapFile'] as $file)
            {
                require $file;
            }
        }
        $config = [];
        foreach ($this->config['configFile'] as $file)
        {
            $config = ArrayHelper::merge($config, include $file);
        }

        if (isset($this->config['bootstrapRefresh']))
        {
            $config['bootstrapRefresh'] = $this->config['bootstrapRefresh'];
        }

        // 为Yii分配一个新的DI容器
        if (isset($this->config['persistClasses']))
        {
            Container::$persistClasses = ArrayHelper::merge(Container::$persistClasses, $this->config['persistClasses']);
            Container::$persistClasses = array_unique(Container::$persistClasses);
        }
        Yii::$container = new Container();

        if ( ! isset($config['components']['assetManager']['basePath']))
        {
            $config['components']['assetManager']['basePath'] = $this->root . '/assets';
        }
        $config['aliases']['@webroot'] = $this->root;
        $config['aliases']['@web'] = '/';
        $this->app = Application::$workerApp = new Application($config);
        Yii::setLogger(new Logger());
        $this->app->setRootPath($this->root);
        $this->app->setServer($this->server);
        $this->app->prepare();
    }

    /**
     * @param Worker $worker
     */
    public function onWorkerReload($worker)
    {
    }

    /**
     * @param Worker $worker
     */
    public function onWorkerStop($worker)
    {
    }

    /**
     * 执行请求
     *
     * @param ConnectionInterface $connection
     * @param mixed               $data
     * @return bool|void
     */
    public function onMessage($connection, $data)
    {
        // 测试DI Container性能
//        $j = 100000;
//        $s1 = microtime(true);
//        for ($i=0; $i<$j; $i++)
//        {
//            $obj = Yii::createObject('yii\web\Request');
//        }
//        $t1 = microtime(true) - $s1;
//        // 更换新的Container
//        $s2 = microtime(true);
//        Yii::$container = new Container();
//        for ($i=0; $i<$j; $i++)
//        {
//            $obj = Yii::createObject('yii\web\Request');
//        }
//        $t2 = microtime(true) - $s2;
//        $response->end(json_encode(['t1' => $t1, 't2' => $t2]));
//        return;

        //$id = posix_getpid();
        //echo "id: $id\n";
//        $t = '<pre>';
//        $t .= print_r($_SERVER, true);
//        $t .= '</pre>';
//        return $connection->send($t);

        //xdebug_start_trace();

        if ($this->xhprofDebug)
        {
            xhprof_enable(XHPROF_FLAGS_MEMORY | XHPROF_FLAGS_CPU);
        }

        $uri = $_SERVER['REQUEST_URI'];
        $file = $this->root . $uri;
        if ($uri != '/' && is_file($file) && pathinfo($file, PATHINFO_EXTENSION) != 'php')
        {
            // 非php文件, 最好使用nginx来输出
            Http::header('Content-Type: ' . FileHelper::getMimeTypeByExtension($file));
            Http::header('Content-Length: ' . filesize($file));
            $connection->close(file_get_contents($file));
            return;
        }
        else
        {
            // 准备环境信息
            // 只要进入PHP的处理流程, 都默认转发给Yii来做处理
            // 这样意味着, web目录下的PHP文件, 不会直接执行
            $file = $this->root . '/' . $this->indexFile;
            $_SERVER['SCRIPT_FILENAME'] = $file;
            $_SERVER['DOCUMENT_ROOT'] = $this->root;
            $_SERVER['SCRIPT_NAME'] = $_SERVER['DOCUMENT_URI'] = '/' . $this->indexFile;

            // 使用clone, 原型模式
            // 所有请求都clone一个原生$app对象
            $this->app->getRequest()->setUrl(null);
            $app = clone $this->app;
            Yii::$app =& $app;
            $app->setConnection($connection);
            $app->setErrorHandler(clone $this->app->getErrorHandler());
            $app->setRequest(clone $this->app->getRequest());
            $app->setResponse(clone $this->app->getResponse());
            $app->setView(clone $this->app->getView());
            $app->setSession(clone $this->app->getSession());
            $app->setUser(clone $this->app->getUser());
            // 部分组件是可以复用的, 所以直接引用
            //$app->setUrlManager($this->app->getUrlManager());

            try
            {
                //$t = '<pre>';
                //$t .= print_r($_SERVER, true);
                //$t .= print_r($request, true);
                //$t .= '</pre>';
                //$response->end($t);
                //return;
                $app->run();
                $app->afterRun();
            }
            catch (ErrorException $e)
            {
                $app->afterRun();
                if ($this->debug)
                {
                    echo (string) $e;
                    echo "\n";
                    $connection->send('');
                }
                else
                {
                    $app->getErrorHandler()->handleException($e);
                }
            }
            catch (\Exception $e)
            {
                $app->afterRun();
                if ($this->debug)
                {
                    echo (string) $e;
                    echo "\n";
                    $connection->send('');
                }
                else
                {
                    $app->getErrorHandler()->handleException($e);
                }
            }
            // 还原环境变量
            Yii::$app = $this->app;
            unset($app);
        }

        //xdebug_stop_trace();
        //xdebug_print_function_stack();

        if ($this->xhprofDebug)
        {
            $xhprofData = xhprof_disable();
            $xhprofRuns = new \XHProfRuns_Default();
            $runId = $xhprofRuns->save_run($xhprofData, 'xhprof_test');
            echo "http://127.0.0.1/xhprof/xhprof_html/index.php?run=" . $runId . '&source=xhprof_test'."\n";
        }
    }
}
