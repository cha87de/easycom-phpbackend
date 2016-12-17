<?php
$entity = $_REQUEST["entity"];
$action = $_REQUEST["action"];

date_default_timezone_set("Europe/Berlin");

$database = null;
function getDatabase(){
	global $database;
	if($database != null)
		return $database;
	
	$database = new PDO("sqlite:_data/management.db");
	$database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
	return $database;
}

$actions = array(

	"invoice" => array(
		"pdf" => function(){
			if(!isset($_REQUEST["invoice"]) || $_REQUEST["invoice"] == ""){
				throw new Exception("No invoice specified.");
			}
			$invoiceid = $_REQUEST["invoice"];
		
			$db = getDatabase();
		
			// read content from database
			$invoiceData = array();
			$sql = "SELECT 
				Invoice.*,
				Customer.*,
				Invoice.number AS invoicenumber,
				Customer.number AS customernumber
			FROM 
				`Invoice` 
				INNER JOIN `Customer` ON `Invoice`.`customer` = `Customer`.`id`
			 WHERE `Invoice`.`id` = $invoiceid;";
			$statement = $db->query($sql);
			$invoiceData = $statement->fetchAll(PDO::FETCH_ASSOC);			

			$sql = "SELECT 
				`Invoiceitem`.*
			FROM 
				`Invoiceitem` 
			 WHERE `Invoiceitem`.`invoice` = $invoiceid ORDER BY position ASC";
			$statement = $db->query($sql);
			$invoiceitemData = $statement->fetchAll(PDO::FETCH_ASSOC);
			$invoiceData["invoice_sum"] = 0;
			foreach($invoiceitemData as $key => $value){
				$sum = $value["amount"]*$value["price"];
				$invoiceData[0]["invoice_sum"] += $sum;
				$invoiceData[0]["invoice_items"] .= $value["amount"] ." & ". latexSpecialChars($value["caption"]) ." & \EUR{" . number_format($value["price"], 2, ',', '.') . " } & \EUR{" . number_format($sum, 2, ',', '.') . "} \\\\ \n";
			}

			$sql = "SELECT 
				`Timerecord`.*
			FROM 
				`Timerecord` 
			 WHERE `Timerecord`.`invoice` = $invoiceid ORDER BY start ASC";
			$statement = $db->query($sql);
			$timerecordData = $statement->fetchAll(PDO::FETCH_ASSOC);
			$sum = 0;
			foreach($timerecordData as $key => $value){
				$diff = ($value["end"]-$value["start"])/60;
				$sum += $diff;
				
				$diff_h = intval($diff / 60.0);
				$diff_i =  sprintf('%02d', $diff % 60);
				$sum_h = intval($sum / 60.0);
				$sum_i =  sprintf('%02d', $sum % 60);
				$invoiceData[0]["timerecords"] .= "" . date("d.m.Y", $value["start"]) . " & " . date("H:i", $value["start"]) . " & " . date("H:i", $value["end"]) . " & " . $diff_h . ":" . $diff_i . " & " . latexSpecialChars($value["comment"]) . " & " . $sum_h . ":" . $sum_i . " \\\\ \n";
			}
					
			// read latex template into variable
			$template = file_get_contents("_data/invoicing/template.tex");
			
			// replace fields in template
			$variables = array(
				"__invoice_nr__",
				"__invoice_date__",
				"__customer_nr__",
				"__customer_name__",
				"__customer_street__",
				"__customer_city__",
				"__customer_taxid__",
				"__invoice_items__",
				"__invoice_sum__",
				"__workinghours_items__"
			);
			$contents = array(
				$invoiceData[0]["invoicenumber"],
				date("d.m.Y", $invoiceData[0]["creationdate"]),
				$invoiceData[0]["customernumber"],
				latexSpecialChars($invoiceData[0]["name"]),
				latexSpecialChars($invoiceData[0]["street"]),
				latexSpecialChars($invoiceData[0]["zipcode"] . " " . $invoiceData[0]["city"]),
				latexSpecialChars(empty($invoiceData[0]["taxid"]) ? "" : "USt-IdNr. " . $invoiceData[0]["taxid"]),
				$invoiceData[0]["invoice_items"],
				number_format($invoiceData[0]["invoice_sum"], 2, ',', '.'),
				$invoiceData[0]["timerecords"]
			);
			
			// compile tex to pdf
			copy("_data/invoicing/logo.png", "/tmp/logo.png");
			$template = str_replace($variables, $contents, $template);
			file_put_contents("/tmp/tmp.tex", $template);
			exec("cd /tmp/ && pdflatex -interaction=nonstopmode tmp.tex");
			exec("cd /tmp/ && pdflatex -interaction=nonstopmode tmp.tex");
			//unlink("/tmp/tmp.tex");
			
			
			// send pdf to callee
			header("Content-Description: File Transfer");
			header("Content-type: application/pdf");
			header("Content-Disposition: attachment; filename=invoice_".$invoiceData[0]["invoicenumber"].".pdf");
			header("Content-Transfer-Encoding: binary");
			header("Connection: Keep-Alive");
			header("Expires: 0");
			header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
			header("Pragma: public");
			readfile("/tmp/tmp.pdf");
			unlink("/tmp/tmp.pdf");
		}
	),
	
	"timerecord" => array(
		"account" => function(){
			$customer = $_REQUEST["customer"];
			$selectedTimerecords = isset($_REQUEST["timerecords"]) && $_REQUEST["timerecords"] != "" ? explode(",",$_REQUEST["timerecords"]) : array();
			$now = time();
			$db = getDatabase();
			
			$db->beginTransaction();
			
			// get unaccounted timerecords
			$sql = "SELECT * FROM `Timerecord` WHERE `customer` = \"" . $customer . "\" AND (`invoice` = 0 OR `invoice` IS NULL) AND end IS NOT NULL;";
			$statement = $db->query($sql);
			$values = $statement->fetchAll(PDO::FETCH_ASSOC);
			if(count($values) <= 0){
				throw new Exception("No unaccounted timerecords available.");
			}

			// sum by rate
			$accounting = array();
			$start = 0; $end = 0;
			foreach($values as $key => $value){
				// consider only selected timerecords (if selection is available)
				if(count($selectedTimerecords) > 0 && !in_array($value["id"], $selectedTimerecords)){
					unset($values[$key]);
					continue; // skip this record
				}
				$rate = $value["rate"];
				$timediff = ($value["end"] - $value["start"]) / 60 / 60; # in hours
				if(isset($accounting[$rate]['sum']))
					$timediff += $accounting[$rate]['sum'];
				$accounting[$rate]['sum'] = $timediff;
				
				if(!isset($accounting[$rate]['start']) || $value["start"] < $accounting[$rate]['start'])
					$accounting[$rate]['start'] = $value["start"];
				if(!isset($accounting[$rate]['end']) || $value["end"] > $accounting[$rate]['end'])
					$accounting[$rate]['end'] = $value["end"];					
			}
			if(count($accounting) <= 0){
				throw new Exception("No unaccounted timerecords available.");
			}

			// find invoice number
			$prefix = date("ym", $now) . "-";
			$sql = "SELECT replace(`number`, '" . $prefix . "', '') AS number FROM `Invoice` WHERE `number` LIKE '" . $prefix . "%' ORDER BY `number` DESC LIMIT 0,1";
			$statement = $db->query($sql);
			$numbers = $statement->fetchAll(PDO::FETCH_ASSOC);
			if(count($numbers) <= 0){
				$invoicenumber = date("ym", $now) . "-01";
			}else{
				$number = intval($numbers[0]["number"]);
				$number++;
				$invoicenumber = date("ym", $now) . "-" . sprintf("%02d", $number);
			}

			// create new invoice
			$stmt = $db->prepare("INSERT INTO `Invoice` (`number`, `customer`, `creationdate`, `ispaid`) VALUES (:number, :customer, :creationdate, 0)");
			$stmt->bindParam(':number', $invoicenumber);
			$stmt->bindParam(':customer', $customer);
			$stmt->bindParam(':creationdate', $now);
			$stmt->execute();
			$invoice = $db->lastInsertId();
			
			// create new invoiceitems (grouped by timerecord rates)
			$stmt = $db->prepare("INSERT INTO `Invoiceitem` (`invoice`, `position`, `amount`, `caption`, `price`) VALUES (:invoice, :position, :amount, :caption, :price)");			
			$position = 0;
			foreach($accounting as $key => $value){
				$position++;
				$amount = round($value["sum"], 1);
				$caption = "Arbeitseinheiten vom " . date("d.m.Y", $value["start"]) . " bis " . date("d.m.Y", $value["end"]) . "";
				$price = $key;
				$stmt->bindParam(':invoice', $invoice);
				$stmt->bindParam(':position', $position);
				$stmt->bindParam(':amount', $amount);
				$stmt->bindParam(':caption', $caption);
				$stmt->bindParam(':price', $price);
				$stmt->execute();
			}
			
			$stmt = $db->prepare("UPDATE `Timerecord` SET `invoice` = :invoice WHERE `id` = :id");
			foreach($values as $key => $value){
				$id = $value["id"];
				$stmt->bindParam(':invoice', $invoice);				
				$stmt->bindParam(':id', $id);
				$stmt->execute();
			}
			$db->commit();
			
			echo json_encode(array("success" => true, "invoice" => $invoice));
			
		}
	)

);

function latexSpecialChars( $string ){
    $map = array( 
            "#"=>"\\#",
            "$"=>"\\$",
            "%"=>"\\%",
            "&"=>"\\&",
            "~"=>"\\~{}",
            "_"=>"\\_",
            "^"=>"\\^{}",
            "\\"=>"\\textbackslash",
            "{"=>"\\{",
            "}"=>"\\}",
    );
    return preg_replace( "/([\^\%~\\\\#\$%&_\{\}])/e", "\$map['$1']", $string );
}

if(!isset($actions[$entity][$action])){
	throw new Exception("Entity/Action not defined");
}
try{
	$function = $actions[$entity][$action];
	$function();
}catch(Exception $e){
	echo json_encode(array("success" => false, "reason" => $e->getMessage() ));
}

?>
