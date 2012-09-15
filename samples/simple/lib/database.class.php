<?php
    class Database{
        private $pdo;
        private $database;
        private $user;
        private $pass;
        private $host;
        private $engine;
    
        function __construct($database=false, $user=false, $pass=false, $host=false, $engine=false){
            $this->engine = ($engine) ? $engine : 'mysql';
            $this->host = ($host)? $host : DB_HOST;
            $this->database = ($database)? $database : DB_DATABASE;
            $this->user = ($user)? $user : DB_USERNAME;
            $this->pass = ($pass)? $pass : DB_PASSWORD;
        }
    
        private function ensure_conn(){
            if(!$this->pdo){
                $dns = $this->engine.':dbname='.$this->database.";host=".$this->host;
                $this->pdo = new PDO($dns, $this->user, $this->pass);
                $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            }
        }
    
        private function execute_sql($sql, $params=false){
            $this->ensure_conn();
            $stmp = $this->pdo->prepare($sql);
            if($params){
                $stmp->execute($params);
            }
            else{
                 $stmp->execute();
            }
            return $stmp;
        }
    
    
        public function select_one($table, $where, $cache=false, $ttl=3600){
            $sql = $this->createSelect($table, $where);
            $whereParams = $this->getWhereParameters($where);
            return $this->get($sql, $whereParams);
        }
    
        public function select_all($table, $where, $cache=false, $ttl=3600){
            $sql = $this->createSelect($table, $where);
            $whereParams = $this->getWhereParameters($where);
            return $this->query($sql, $whereParams);
        }
    
        public function query($sql, $params=false, $cache=false, $ttl=3600) {
            if(__DEBUG__) $cache = false; //debug
    
            if($cache && ($data = apc_fetch($cache))){
                return $data;
            }
    
            $stmp = $this->execute_sql($sql, $params);
            $data = $stmp->fetchAll(PDO::FETCH_ASSOC);
    
            if($cache && $ttl){
                apc_store($cache, $data, $ttl);
            }
    
            return $data;
        }
    
        public function get($sql, $params=false, $cache=false, $ttl=3600){
            if(__DEBUG__) $cache = false; //debug
            if($cache && ($data = apc_fetch($cache))){
                return $data;
            }    
            $stmp = $this->execute_sql($sql, $params);   
            $data = $stmp->fetch(PDO::FETCH_ASSOC);
    
            if($cache && $ttl){
                apc_store($cache, $data, $ttl);
            }
    
            return $data;
        }
    
        public function scalar($sql, $params=false, $cache=false, $ttl=3600){
            if(__DEBUG__) $cache = false; //debug
            if($cache && ($data = apc_fetch($cache))){
                return $data;
            }
            $stmp = $this->execute_sql($sql, $params);   
            $data = $stmp->fetchColumn();
    
            if($cache && $ttl){
                apc_store($cache, $data, $ttl);
            }
    
            return $data;
        }
    
        public function insert($table, $values){
            $this->ensure_conn();
            $sql = $this->createInsert($table, $values);
            $stmp = $this->pdo->prepare($sql);
            $stmp->execute($values);
			return $this->pdo->lastInsertId();
        }
    
        public function update($table, $values, $where){
            $this->ensure_conn();
            $sql = $this->createUpdate($table, $values, $where);
            $whereParams = $this->getWhereParameters($where);
    
            $stmp = $this->pdo->prepare($sql);
            return $stmp->execute(array_merge($values, $whereParams));
        }
    
        public function delete($table, $where){
            $this->ensure_conn();
            $sql         = $this->createDelete($table, $where);
            $whereParams = $this->getWhereParameters($where);
            $stmp = $this->pdo->prepare($sql);
            return $stmp->execute($whereParams);
        }
    
        public function execute($sql, $params=false){
            $this->execute_sql($sql, $params);
        }
    
        protected function getWhereParameters($where){
            $whereParams = array();
            foreach ($where as $key => $value) {
                $whereParams[":W_{$key}"] = $value;
            }
            return $whereParams;
        }
    
        protected function createSelect($table, $where){
            return "SELECT * FROM " . $table . $this->createSqlWhere($where);
        }
    
        protected function createUpdate($table, $values, $where){
            $sqlValues = array();
            foreach (array_keys($values) as $key) {
                $sqlValues[] = "{$key} = :{$key}";
            }
            return "UPDATE {$table} SET " . implode(', ', $sqlValues) . $this->createSqlWhere($where);
        }
    
        protected function createInsert($table, $values){
            $sqlValues = array();
            foreach (array_keys($values) as $key) {
                $sqlValues[] = ":{$key}";
            }
            return "INSERT INTO {$table} (" . implode(', ', array_keys($values)) . ") VALUES (" . implode(', ', $sqlValues) . ")";
        }
    
        protected function createDelete($table, $where){
            return "DELETE FROM {$table}" . $this->createSqlWhere($where);
        }
    
        protected function createSqlWhere($where){
            if (count((array) $where) == 0) return null;
    
            $whereSql = array();
            foreach ($where as $key => $value) {
                $whereSql[] = "{$key} = :W_{$key}";
            }
            return ' WHERE ' . implode(' AND ', $whereSql);
        }
    
        public function now(){
            return date ("Y-m-d H:i:s");
        }
    }
?>