<?php
    /**
     * Copyright 2012 Santosh Sahoo (me@santoshsahoo.com)
     *
     * Licensed under the Apache License, Version 2.0 (the "License"); you may
     * not use this file except in compliance with the License. You may obtain
     * a copy of the License at
     *
     *     http://www.apache.org/licenses/LICENSE-2.0
     *
     * Unless required by applicable law or agreed to in writing, software
     * distributed under the License is distributed on an "AS IS" BASIS, WITHOUT
     * WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied. See the
     * License for the specific language governing permissions and limitations
     * under the License.
     */
    // Report runtime errors
    error_reporting(E_ALL & ~(E_STRICT|E_NOTICE));
	ob_start();

    //Default route. You can(should) override them in /app/config/routes.inc
    $_ROUTES = array(
            'default' => array(
                    'url' => '/^\/(?<controller>[\w\d]+)?\/?(?<action>[\w\d]+)?\/?(?<id>.*)?\/?$/i',
                    'controller' => 'home',
                    'action' => 'index'
            ), // /{controller:home}/{action:index}/{id:null}
    );

    define('__PHYRE_HEADER__',
        "<!DOCTYPE html><html><head>
        <style type='text/css'>
            body{font:1em sans-serif; padding:10%; background-color:#fff;}
            pre{background-color:#FFFFCC; padding:1%; border:solid 1px #ddd; border-radius:4px;}
            hr{border:none; border-bottom:solid 1px #ddd;}
        </style>
        <body>");

	define('__ROOT__', 	dirname(__FILE__).'/app/');
    define('__CONTROLLERS__', __ROOT__.'controllers/');
    define('__VIEWS__', __ROOT__.'views/');
    define('__LIB__', 	__ROOT__.'lib/');
    define('__CONFIG__', 	__ROOT__.'config/');

    $http_status_codes = array(100 => "Continue", 101 => "Switching Protocols", 102 => "Processing", 200 => "OK", 201 => "Created", 202 => "Accepted", 203 => "Non-Authoritative Information", 204 => "No Content", 205 => "Reset Content", 206 => "Partial Content", 207 => "Multi-Status", 300 => "Multiple Choices", 301 => "Moved Permanently", 302 => "Found", 303 => "See Other", 304 => "Not Modified", 305 => "Use Proxy", 306 => "(Unused)", 307 => "Temporary Redirect", 308 => "Permanent Redirect", 400 => "Bad Request", 401 => "Unauthorized", 402 => "Payment Required", 403 => "Forbidden", 404 => "Not Found", 405 => "Method Not Allowed", 406 => "Not Acceptable", 407 => "Proxy Authentication Required", 408 => "Request Timeout", 409 => "Conflict", 410 => "Gone", 411 => "Length Required", 412 => "Precondition Failed", 413 => "Request Entity Too Large", 414 => "Request-URI Too Long", 415 => "Unsupported Media Type", 416 => "Requested Range Not Satisfiable", 417 => "Expectation Failed", 418 => "I'm a teapot", 419 => "Authentication Timeout", 420 => "Enhance Your Calm", 422 => "Unprocessable Entity", 423 => "Locked", 424 => "Failed Dependency", 424 => "Method Failure", 425 => "Unordered Collection", 426 => "Upgrade Required", 428 => "Precondition Required", 429 => "Too Many Requests", 431 => "Request Header Fields Too Large", 444 => "No Response", 449 => "Retry With", 450 => "Blocked by Windows Parental Controls", 451 => "Unavailable For Legal Reasons", 494 => "Request Header Too Large", 495 => "Cert Error", 496 => "No Cert", 497 => "HTTP to HTTPS", 499 => "Client Closed Request", 500 => "Internal Server Error", 501 => "Not Implemented", 502 => "Bad Gateway", 503 => "Service Unavailable", 504 => "Gateway Timeout", 505 => "HTTP Version Not Supported", 506 => "Variant Also Negotiates", 507 => "Insufficient Storage", 508 => "Loop Detected", 509 => "Bandwidth Limit Exceeded", 510 => "Not Extended", 511 => "Network Authentication Required", 598 => "Network read timeout error", 599 => "Network connect timeout error");

    if(file_exists(__CONFIG__.'config.inc')) require_once (__CONFIG__.'config.inc');
    ini_set("display_errors", __DEBUG__);

    if(file_exists(__CONFIG__.'includes.inc')) require_once (__CONFIG__.'includes.inc');
    if(file_exists(__CONFIG__.'routes.inc')) require_once (__CONFIG__.'routes.inc');

	//error handler function
	function custom_error($errno, $errstr, $errfile, $errline, $errcontext){
        $err = error_get_last();
        exception_handler($err);
	}

    function exception_handler(Exception $ex){
        ob_end_clean();

        $errorCode = 500;
        if(is_a($ex, PhyreException)){
            $errorCode = $ex->errorCode;
        }

        header("HTTP/1.0 {$errorCode} {$http_status_codes[$errorCode]}");

        if(__DEBUG__){
            echo __PHYRE_HEADER__;
            echo "<h1>Phyre: <small>{$errorCode} Error</small></h1>";
            echo "<hr/>";
            echo "<p>{$ex->getMessage()}</p>";
            echo "<h4>Stack trace</h4>";
            echo "<pre>";
            echo $ex->getTraceAsString();
            echo "</pre>";
            if($ex->extraData){
                echo "<h4>Additional Information</h4>";
                var_dump($ex->extraData);
            }
            exit;
        }

        $error_view = new TemplateView("error/{$errorCode}", $ex, FALSE);
        $error_view->render();
    }

    //set error handler
    set_error_handler("custom_error", E_STRICT);
    set_exception_handler ('exception_handler');

	class Dispatcher{
		static $ResolvedRouteCache = array();
		static $ControllerClassPrefix = '';

        static function dispatch($_ROUTES){
            list($path) = explode('?', $_SERVER['REQUEST_URI']);
            if(self::find_route($_ROUTES, $path, $args, $params, $name, $route) == FALSE){
				$msg = "No route found for {$_SERVER['REQUEST_METHOD']}:\"$path\"";
				throw new PhyreException($msg, 404, $extraData=array('path'=>$path, 'args'=>$args, 'route'=>$route, 'routename'=> $name));
			}
			exit;
        }

        private static function find_route($_ROUTES, $path, &$args, &$params, &$name, &$route){
            foreach($_ROUTES as $name=>$params){
                $route = is_array($params)? $params['url'] : $params;
                if (preg_match($route, $path, $args)) {
                    if(self::resolve_controller($args, $params)){
                        return TRUE;
                    }
                    return FALSE;
                }
            }
            return FALSE;
        }

        private static function resolve_controller($args, $params){
            $controllername 	= empty($args['controller'])? $params['controller'] : trim($args['controller']);
            $controllerclass	= self::$ControllerClassPrefix.ucwords($controllername).'Controller';
            $path 				= empty($params['path'])? __CONTROLLERS__ : $params['path'] ;
            $controllerfile		= strtolower($path. (empty($params['file'])? "$controllerclass.php" : $params['file'] ));
			//print $controllerfile; exit;
            if(file_exists($controllerfile)){
                require_once($controllerfile);
				if(class_exists($controllerclass)){
                    $controller = new $controllerclass($controllername);
                    $controller->name = $controllername;
                    if(is_a($controller, 'Controller')){ //Web typed controllers
                        return $controller->execute($args, $params);
                    }
                }
                else{
					throw new PhyreException("Controller '$controllerclass' not defined in $controllerfile", $extraData=$params);
				}
            }

            return FALSE;
        }
    }

	abstract class Controller{
		public static $view_engine = "TemplateView";
        private $name;
        protected $args;
        protected $request_args;
        protected $model;
        protected $session;

        function __construct($name){
            $this->name = $name;
        }

        function execute($args, $params){
            //$this->session = $_SESSION ?? null;
            $this->request_args = $request_args = array_merge($_GET, $_POST, $args);
            $request_method = strtoupper($_SERVER['REQUEST_METHOD']);
            $action_name = empty($args['action'])? $params['action'] : str_replace('-', '_', $args['action']);
			//var_dump($args); print $action_name; exit; //DEBUG
			$this->args = array_merge($args, $params);
            $this->args['action'] = $action_name; //override empty actions
            $action_method_name = $request_method .'_'. $action_name;

            //print($action_method_name);exit;

            if(!is_callable(array($this, $action_method_name))){
                $action_method_name = $action_name;
            }
            if(is_callable(array($this, $action_method_name))){
                $method = new ReflectionMethod($this, $action_method_name);
                $parameters = $method->getParameters();
                $param_values = array();
                if(count($parameters)>0){
                    foreach($parameters as $param){
                        $param_values[] = empty($request_args[$param->name])? NULL: $request_args[$param->name];
                    }
                }

				if(method_exists('App', 'pre_execute')) App::pre_execute($this->args);
                $view = call_user_func_array(array($this, $action_method_name), $param_values);
                if(is_a($view, 'View')){
                    $view->render();
                }
                else{
                    header('Content-type: application/json');
                    echo json_encode($view);
                    exit;
                }
				if(method_exists('App', 'post_execute')) App::post_execute($this->args);
                return TRUE;
            }
            return FALSE;
        }

        protected function view($template=FALSE, $model=FALSE, $view=FALSE){
            if(!$template){
                $template = $this->args['action'];
            }
            if(!$model){
                $model = $this->model;
            }
            return new self::$view_engine("{$this->name}/$template", $model, $view);
        }

        protected function json($model){
            return new JsonView($model);
        }

        protected function redirect($location, $is_permanent=FALSE){
            if($is_permanent){
                header('HTTP/1.1 301 Moved Permanently');
            }
            header("Location: $location");
        }

        protected function error404($message){
            throw new PhyreException($msg, 404, $extraData=$message);
        }
    }

	abstract class View{
        public function render()  {
            ob_end_clean();
            ob_start();
            $this->render_content();
            ob_end_flush();
        }

        public function render_content(){
            echo 'Dummy View';
        }
    }

	class TemplateView extends View{
        private $template;
        private $model;
        private $viewdata;

        public function __construct($template, $model, $viewdata){
            $this->template = $template;
            $this->model = $model;
            $this->viewdata = $viewdata;
        }

        public function render_content(){
            $template_path = $this->template.'.php';
            if(file_exists(__VIEWS__.$template_path)){
                chdir(__VIEWS__);
                $viewdata = $this->viewdata;
                if(is_array($this->model)){
                    extract($this->model);
                }
                include_once($template_path);
            }
            else{
                throw(new PhyreException("Template not found at '$template_path'"));
            }
        }
    }

	class PhyreException extends Exception {
        public $errorCode;
        public $extraData;

        public function __construct($message, $errorCode=500, $extraData=FALSE) {
            parent::__construct($message);
            $this->errorCode = $errorCode;
            $this->extraData = $extraData;
        }
    }

	class JsonView extends View{
        private $model;

        public function __construct($model=FALSE){
            $this->model = $model;
        }

        public function render_content(){
            header('Content-type: application/json');
            print json_encode($this->model);
        }
    }

	Dispatcher::dispatch($_ROUTES);

	// end output buffer and echo the page content
    ob_end_flush();
?>