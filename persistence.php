<?php
	$entityType = ucfirst($_REQUEST["entity"]);
	$entityId = $_REQUEST["id"];
	$method = $_SERVER["REQUEST_METHOD"];
	$body = json_decode(file_get_contents("php://input"),true);

	$database = new PDO("sqlite:_data/management.db");
	$database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

	if($method == "GET"){
		// READ ENTITIES
		
		$where = "1 ";
		foreach($_REQUEST as $paramKey => $paramValue){
			if(in_array($paramKey, array("id", "page", "_dc", "entity", "start", "limit")))
				continue;
			if($paramKey == "filter"){
				//[{"property":"invoice","value":2,"exactMatch":true}]
				$filters = json_decode($paramValue, true);
				foreach($filters as $filter){
					if($filter["exactMatch"] === true)
						$where .= "AND `" . $filter["property"] . "` = \"" .  $filter["value"]. "\" ";
				}
				continue;
			}
			if(strpos($paramKey, "has_") === 0){
				$paramKey = substr($paramKey, 4);
				$where .= "AND " . "`$paramKey` IS " . ($paramValue == "true" ? "NOT" : "") . " NULL ";
			}else{
				$where .= "AND " . "`$paramKey` LIKE \"$paramValue\" ";
			}
		}
		
		if(empty($entityId)){
			// LIST
			$sql = "SELECT * FROM `" . $entityType . "` WHERE $where;";
			$statement = $database->query($sql);
			$values = $statement->fetchAll(PDO::FETCH_ASSOC);
			echo json_encode(array("success" => true, "items" => $values, "sql" => $sql));			
		}else{
			// SINGLE ITEM
			$sql = "SELECT * FROM `" . $entityType . "` WHERE `id` = \"" . $entityId . "\" AND $where;";
			$statement = $database->query($sql);
			$values = $statement->fetchAll(PDO::FETCH_ASSOC);
			$value = null;
			if(count($values) > 0){
				$value = $values[0];
			}
			echo json_encode($value);
		}
	}else if($method == "PUT" || $method == "POST"){
		$columns = preg_replace('/[^a-z0-9_]+/i','',array_keys($body));
		$values = array_values($body);
 
		if($method == "POST"){
			// CREATE NEW ENTITY
			$cols = "";
			$vals = "";
			for ($i = 0; $i < count($columns); $i++) {
				if($columns[$i] == "id"){
					continue;
				}
				$cols .= (empty($cols) ? "" : ", ") . "`" . $columns[$i] . "`";
				$vals .= (empty($vals) ? "" : ", ") . ($values[$i] === null || $values[$i] === "0" ? "NULL" : $values[$i] === false ? "0" : "\"" . $values[$i] . "\"");
			}			
			$sql = "INSERT INTO `$entityType` ($cols) VALUES ($vals);";
		}else if($method == "PUT"){
			// UPDATE EXISTING ENTITY
			if(empty($entityId)){
				throw new Exception("No entity ID specified.");
			}
			$set = "";
			for ($i = 0; $i < count($columns); $i++) {
				if($columns[$i] == "id")
					continue;
				$set .= ($i > 0 ? "," : "") . "`" . $columns[$i] . "`=";
				$set .= ($values[$i] === null || $values[$i] == "0" ? "NULL" : "\"" . $values[$i] . "\"");
			}			
			$sql = "UPDATE `$entityType` SET $set WHERE id=\"$entityId\";"; 
		}
		try{
			$database->exec($sql);
			$lastId = $database->lastInsertId();
			echo json_encode(array("success" => true, "items" => array( "id" => $lastId)));
		}catch(PDOException $e){
			echo json_encode(array("success" => false, "reason" => $e->getMessage(), "sql" => $sql));
		}
	}else if($method == "DELETE"){
		// DELETE EXISTING ENTITY
		if(empty($entityId)){
			$database = null;
			throw new Exception("No entity ID specified.");
		}
		$database->exec("DELETE FROM `$entityType` WHERE id=\"$entityId\";");
		echo json_encode(array("success" => true));
	}else{
		$database = null;
		throw new Exception("Method unknown.");
	}
	
	$database = null;
?>
