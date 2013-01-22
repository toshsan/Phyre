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

    $_ROUTES = array(
            'default' => array(
                    'url' => '/^\/(?<controller>[\w\d]+)?\/?(?<action>[\w\d]+)?\/?(?<id>.*)?\/?$/i',
                    'controller' => 'home',
                    'action' => 'index'
            ), // /{controller:home}/{action:index}/{id:null}
    );

	//set error handler
	//set_error_handler("custom_error");

	define('__ROOT__', 	dirname(__FILE__).'/app/');
    define('__CONTROLLERS__', __ROOT__.'controllers/');
    define('__VIEWS__', __ROOT__.'views/');
    define('__LIB__', 	__ROOT__.'lib/');
    define('__CONFIG__', 	__ROOT__.'config/');

    if(file_exists(__CONFIG__.'config.inc')) require_once (__CONFIG__.'config.inc');
    ini_set("display_errors", __DEBUG__);
	
    if(file_exists(__CONFIG__.'includes.inc')) require_once (__CONFIG__.'includes.inc');

	//error handler function
	function custom_error($errno, $errstr){
		header("HTTP/1.0 500 Internal Server Error");
		$error_view = new TemplateView("error/500", FALSE, FALSE);
		$error_view->render();
	}

	function show_404($reason='404:Page not found'){
        header('HTTP/1.0 404 Not Found');
        if(__DEBUG__){
            print "$reason<hr/>404 Error(debug) | Phyre";
        }
        else {
            $error_view = new TemplateView("error/404");
            $error_view->render();
        }
    }

	class Router{
        function route($_ROUTES){
            list($path) = explode('?', $_SERVER['REQUEST_URI']);
            if(self::find_route($_ROUTES, $path, $args, $params, $name, $route) == FALSE){
				$msg = "<h1>404: No route found for {$_SERVER['REQUEST_METHOD']}:\"$path\"</h1><hr/>";
				$msg .= "Args<br/><pre>";
				$msg .= print_r($args, true);
				$msg .= "Route: $name:$route";
				$msg .="</pre>";
				show_404($msg);
			}
			exit;
        }

        private function find_route($_ROUTES, $path, &$args, &$params, &$name, &$route){
            foreach($_ROUTES as $name=>$params){
                $route = is_array($params)? $params['url'] : $params;
                if (preg_match($route, $path, $args)) {
                    if(self::resolve_controller($args, $params)){
                        return TRUE;
                    }
                }
            }
            return FALSE;
        }

        private function resolve_controller($args, $params){
            $controllername 	= empty($args['controller'])? $params['controller'] : trim($args['controller']);
            $controllerclass	= ucwords($controllername).'Controller';
            $path 				= empty($params['path'])? __CONTROLLERS__ : $params['path'] ;
            $controllerfile		= strtolower($path. (empty($params['file'])? "class.$controllerclass.php" : $params['file'] ));
			//print $controllerfile; exit;
            if(file_exists($controllerfile)){
                require_once($controllerfile);
				if(class_exists($controllerclass)){
                    $controller = new $controllerclass($controllername);
                    if(is_a($controller, 'Controller')){
                        $controller->name = $controllername;
                        return $controller->execute($args, $params);
                    }
                }else{
					print "Controller '$controllerclass' not defined in $controllerfile";
				}
            }
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
            $this->session = $_SESSION;
            $this->request_args = $request_args = array_merge($_GET, $_POST, $args);
            $request_method = strtoupper($_SERVER['REQUEST_METHOD']);
            $action_name = empty($args['action'])? $params['action'] : $args['action'];
			//var_dump($args); print $action_name; exit; //DEBUG
			$this->args = array_merge($args, $params);
            $this->args['action'] = $action_name; //override empty actions
            $action_method_name = $request_method .'_'. $action_name;

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
                    echo $view;
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

        protected function show_404(){
            show_404();
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
                throw(new TemplateNotFoundException($template_path));
            }
        }
    }

	class TemplateNotFoundException extends Exception {
        public function __construct($template) {
            parent::__construct($template);
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

	Router::route($_ROUTES);

	// end output buffer and echo the page content
    ob_end_flush();
?>