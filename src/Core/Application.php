<?php

# @Author: crababy
# @Date:   2018-03-21T15:54:17+08:00
# @Last modified by:   crababy
# @Last modified time: 2018-03-21T15:54:24+08:00
# @License: http://www.opensource.org/licenses/mit-license.php MIT License
#

namespace Cbworker\Core;

use Cbworker\Core\Container;
use Cbworker\Core\Event\Event;
use \Workerman\Protocols\Http;
use Cbworker\Library\Helper;
use Cbworker\Library\Mysql;
use Cbworker\Library\RedisDb;
use Cbworker\Library\Queue;
use Cbworker\Library\StatisticClient;

class Application extends Container  {

  private $project = '';

  private $language = 'zh';

  private static $_instance = null;

  public $worker;

  public $conn;

  public static function getInstance($worker) {
    if(empty(self::$_instance)) {
      self::$_instance = new self($worker);
    }
    return self::$_instance;
  }

  private function __construct($worker) {
    $this->worker = $worker;
    $this->project = $worker->name;
    $this->setShared('config', function() {
      require_once ROOT_PATH . '/Config/Config.php';
      return $config;
    });

    $this->setShared('mysql', function() {
      return new Mysql($this['config']['mysql']);
    });

    $this->setShared('redis', function() {
      return new RedisDb($this['config']['redis']);
    });

    $this->setShared('queue', function() {
      return new Queue($this['redis']);
    });

    $this->setShared('lang', function() {
      require_once ROOT_PATH . '/Config/Lang.php';
      return $lang;
    });
    //监听事件
    Event::listen('init', function() {});
  }

  private function __clone() {}

  /**
   * 启动
   * @return [type] [description]
   */
  public function run($connection) {
    $this->conn = $connection;
    $rsp = ['code' => -1];
    //触发事件
    Event::tigger('init');
    $content_type = isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : '';
    if (preg_match("/application\/json/i", $content_type)) {
      $req = json_decode($GLOBALS['HTTP_RAW_POST_DATA'], TRUE);
    } else if (preg_match("/text\/xml/i", $content_type)) {
      $req = $GLOBALS['HTTP_RAW_POST_DATA'];
    } else {
      $req = array_merge($_GET, $_POST);
    }
    $this->getLanguage();
    $this->check();

    $url_info = parse_url($_SERVER['REQUEST_URI']);
    $_info = explode('/', $url_info['path']);;
    $req['class'] = isset($_info[1]) && !empty($_info[1]) ? ucfirst($_info[1]) : 'Index';
    $req['method'] = isset($_info[2]) && !empty($_info[2]) ? $_info[2] : 'index';

    if (isset($this['config']['statistic']) && $this['config']['statistic']['report'] && $this['config']['statistic']['address']){
      StatisticClient::tick($this->project, $req['class'], $req['method']);
    }

    try {
      $this->methodDispatch($req, $rsp);
    } catch (\Exception $ex) {
      $rsp['code'] = $ex->getCode();
      if(empty($ex->getMessage())) {
        $rsp['desc'] = isset($this['lang'][$this->language][$rsp['code']]) ? $this['lang'][$this->language][$rsp['code']] : "系统异常[{$rsp['code']}]";
      } else {
        $rsp['desc'] = $ex->getMessage();
      }
      Helper::logger("Run:", $rsp['desc'], Helper::ERROR);
    }
    $this->reportStatistic($req['class'], $req['method'], $rsp['code'] == 0 ? 1 : 0, $rsp['code'] == 0 ? 200 : $rsp['code'], $rsp['desc']);
    $this->formatMessage();
    if(is_array($rsp)) {
      $rsp = json_encode($rsp, JSON_UNESCAPED_UNICODE);
    }
    Helper::logger("Result:", $rsp);
    Helper::logger('End:', '----------------------------------');
    $connection->send($rsp);
    $this->conn = null;
  }

  /**
   * 请求分发
   * @return [type] [description]
   */
  private function methodDispatch($req, &$rsp) {
    $controller = $this['config']['namespace'] . 'Controller\\'.$req['class'];

    if(!class_exists($controller) || !method_exists($controller, $req['method'])) {
      throw new \Exception("Controller {$req['class']} or Method {$req['method']} is Not Exists", 1002);
    }
    Helper::logger('Start:', '----------------------------------');
    Helper::logger('User_Agent:', $_SERVER['HTTP_USER_AGENT']);
    Helper::logger("Params:", $req);

    //请求频率校验
    $this->checkRequestLimit($req['class'], $req['method']);

    $handler_instance = new $controller($this);
    $rsp['code'] = $handler_instance->{$req['method']}($req, $rsp);
    $rsp['desc'] = isset($this['lang'][$this->language][$rsp['code']]) ? $this['lang'][$this->language][$rsp['code']] : "系统异常[{$rsp['code']}]";
  }

  /**
   * 返回JSON数据
   * @param array $data [description]
   */
  private function formatMessage() {
    Http::header("Access-Control-Allow-Origin:*");
    Http::header("Access-Control-Max-Age: 86400");
    Http::header("Access-Control-Allow-Method: POST, GET");
    Http::header("Access-Control-Allow-Headers: Origin, X-CSRF-Token, X-Requested-With, Content-Type, Accept");
    Http::header("Content-type: application/json;charset=utf-8");
  }


  /**
   * 访问频率限制
   * @return [type] [description]
   */
  private function checkRequestLimit($class, $method) {
    $clientIp = Helper::getClientIp();
    $apiLimitKey = "ApiLimit:{$class}:{$method}:{$clientIp}";
    $limitSecond = isset($this['config']['apiLimit'][$class][$method]['limitSecond']) ? $this['config']['apiLimit'][$class][$method]['limitSecond'] : 10;
    $limitCount = isset($this['config']['apiLimit'][$class][$method]['limitCount']) ? $this['config']['apiLimit'][$class][$method]['limitCount'] : 100000;
    $ret = $this['redis']->RedisCommands('get', $apiLimitKey);
    if (false === $ret) {
      $this['redis']->RedisCommands('setex', $apiLimitKey, $limitSecond, 1);
    } else {
      if($ret >= $limitCount) {
        $this['redis']->RedisCommands('expire', $apiLimitKey, 10);
        Helper::logger('checkRequestLimit:', "{$ret} Request Fast");
        throw new \Exception("Request faster", 1005);
      } else {
        $this['redis']->RedisCommands('incr', $apiLimitKey);
      }
    }
    return true;
  }


  /**
   * 接口请求状态上报
   * @param  string  $class   [description]
   * @param  string  $method  [description]
   * @param  integer $success [description]
   * @param  integer $code    [description]
   * @param  string  $message [description]
   * @return [type]           [description]
   */
  private function reportStatistic($class = 'Index', $method = 'index', $success = 0, $code = 0, $message = 'error') {
    if (isset($this['config']['statistic']) && $this['config']['statistic']['report'] && $this['config']['statistic']['address']) {
      StatisticClient::report($this->project, $class, $method, $success, $code, $message, $this['config']['statistic']['address']);
    }
  }

  /**
   * 校验请求方式
   * @return [type] [description]
   */
  private function check() {
    /*
    if(!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
      throw new \Exception("Error Request Method", -1);
    }
    */
  }

  /**
   * 设置语言类型
   * @return [type] [description]
   */
  private function getLanguage($default = 'zh') {
    if(isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
      $language = strtolower($_SERVER['HTTP_ACCEPT_LANGUAGE']);
      if(in_array($language, array('zh', 'en'))) {
        $this->language = $language;
      }
    } else {
      $this->language = $default;
    }
  }


}
