<?php

namespace tourze\workerman\yii2\commands;

use tourze\workerman\yii2\server\HttpServer;
use Yii;
use yii\console\Controller;

class SwooleController extends Controller
{

    /**
     * Run workerman http server
     *
     * @param string $app Running app
     * @throws \yii\base\InvalidConfigException
     */
    public function actionHttp($app)
    {
        /** @var HttpServer $server */
        $server = new HttpServer;
        $server->run($app);
    }
}
