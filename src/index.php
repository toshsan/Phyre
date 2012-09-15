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
    $_routes = array(
            'default' => array(
                    'url' => '/^\/((?<controller>[\w\d]+)(\/(?<action>[\w\d]+)(\/(?<id>.*))?)?)?$/i',
                    'controller' => 'home',
                    'action' => 'index'
            ), // /{controller:home}/{action:index}/{id:null}
    );
    require('config/config.inc');
    ini_set("display_errors", __DEBUG__);
    define('__ROOT__', 	dirname(__FILE__));
    define('__CONTROLLERS__', dirname(__FILE__).'/controllers/');
    define('__VIEWS__', dirname(__FILE__).'/views/');
    define('__LIB__', 	dirname(__FILE__).'/lib/');
    require(__LIB__.'includes.inc');
    Router::route($_routes);
    function show_404($reason='404:Page not found'){
        header('HTTP/1.0 404 Not Found');
        if(__DEBUG__){
            print "Error(debug): $reason";
        }
        else {
            $error_view = new TemplateView("error/404");
            $error_view->render();
        }
    }
    class Router{
        private function find_route($_routes, $path, &$args, &$pattern){
            foreach($_routes as $name=>$pattern){
                if(is_array($pattern)){
                    $route = $pattern['url'];
                }else{
                    $route = $pattern;
                }
    
                if (preg_match($route, $path, $args)) {
                    if(is_array($pattern)){
                        $args2 = array_merge($pattern, $args);
                    }
                    if($found = self::resolve_controller($args2)){
                        return $found;
                    }
                }
            }
            return false;
        }
        private function resolve_controller($args){
            $controllername 	= array_key_exists('controller', $args)? trim($args['controller']): 'home';
            $controllerclass	= ucwords($controllername).'Controller';
            $path 				= array_key_exists('path', $args)? $args['path'] : __CONTROLLERS__;
            $handler_file 		= strtolower($path. (array_key_exists('file', $args)? $args['file'] : "class.$controllerclass.php"));
            if(file_exists($handler_file)) {
                require_once($handler_file);
                if(class_exists($controllerclass)){
                    $controller = new $controllerclass($controllername);
                    if(is_a($controller, 'Controller')){
                        $controller->name = $controllername;
                        return $controller->execute($args);
                    }
                }
            }
        }
        function route($_routes){
            list($path) = explode('?', $_SERVER['REQUEST_URI']);
            $match = self::find_route($_routes, $path, $args, $pattern);
            if(!$match){
                show_404("No route found for {$_SERVER['REQUEST_METHOD']}:\"$path\"");
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
        function execute($args){
            $this->args = $args;
            $this->session = $_SESSION;
            $this->request_args = $request_args = array_merge($_GET, $_POST, $args);
            $request_method = strtoupper($_SERVER['REQUEST_METHOD']);
            $action_name = empty($args['action'])? 'index' : $args['action'];
            $args['action'] = $action_name; //override empty actions
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
                        $param_values[] = array_key_exists($param->name, $request_args)? $request_args[$param->name]: FALSE;
                    }
                }
                $view = call_user_func_array(array($this, $action_method_name), $param_values);
                if(is_a($view, 'View')){
                    $view->render();
                }
                else{
                    echo $view;
                }
                return true;
            }
            return false;
        }
        protected function view($template=false, $model=false, $view=false){
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
        protected function redirect($location, $is_permanent=false){
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
        public function __construct($model=false){
            $this->model = $model;
        }
        public function render_content(){
            header('Content-type: application/json');
            print json_encode($this->model);
        }
    }
    // end output buffer and echo the page content
    ob_end_flush();
?>