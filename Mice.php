<?php

error_reporting(E_ALL);
ini_set("log_errors",1);

set_time_limit(60);

class Mice
{
	var $conn;
	var $configXML;
	
	
	/* ----------------------------------------------------------------------------
		Constructor
	---------------------------------------------------------------------------- */	
	 
	 function Mice() {
	 	$this->configXML = simplexml_load_file("/var/www/mouse/conf/config.xml");
	 	
	 	$db_info = $this->dbInfo(); 
	 	$this->conn = mysql_connect ("localhost", $db_info['user'], $db_info['pass']);
		mysql_select_db ($db_info['db_name'], $this->conn);	 	

	 }	 
	
	 	 
	 /* ----------------------------------------------------------------------------
	
		GENERIC 

	---------------------------------------------------------------------------- */	 
	
	/**
	* Generic sql data getter
	* 
	* @returns array with data objects
	*/	
	function genericSQL($sql) {
	
		$results = array();
        $Result = mysql_query( $sql );
        while ($row = mysql_fetch_object($Result)) {
        	$results[] = $row;
        }
        
        return($results);

	}
	
	/* ----------------------------------------------------------------------------
	
		ANALYSIS

	---------------------------------------------------------------------------- */

	/**
	* Get the data for a specific mouse over s specified time range
	*
	* @returns nested array with this structure:
	* 	array(
	*		
	*			'boxes' =>
	*			'box' => count => x data count
	*					 x => x coordinate
	*					 y => y coordinate 
	*					
	*			'data' => data array with dataset arrays like [rfid, antenna, box, time]
	*			'datacount' => datacount
	*	)
	*/	
	function getRfidData($parameters) {
	
		$rfid = $parameters['rfid'];
		$from = $parameters['from'];
		$to = $parameters['to'];
		
		$all = $parameters['all'] or $all = false;
	
		$table_rfids = $this->getTableName('rfids');
		$table_data = $this->getTableName('data');
		$table_box = $this->getTableName('boxes');
		
		// getting the boxes with the coordinates		
		$box_sql =  "SELECT id, xcoord, ycoord FROM $table_box";
		$box_result = mysql_query($box_sql);
	
 		if(!$box_result) {
 			return "Invalid query: " . mysql_error() . "\n";
 		}
 		
 		$boxes = array();
 		
 		while ( list($id, $x, $y)  = mysql_fetch_row($box_result) ){
			$boxes[ "$id" ]['x'] = "$x";
			$boxes[ "$id" ]['y'] = "$y";
 		}
		
		
		// getting data
		$sql =  "SELECT rfid,ant, UNIX_TIMESTAMP(time) AS ux_time FROM $table_data WHERE time BETWEEN '$from 00:00:00' AND DATE_ADD('$to 00:00:00', INTERVAL 1 DAY) AND rfid = '$rfid'";
 		$result = mysql_query($sql);
 		
 		if(!$result) {
 			return "Invalid query: " . mysql_error() . "\n";
 		}
 			
 		$summary = array();
 		$summary['from'] = $from; 
 		$summary['to'] = $to;
 		$summary['boxes'] = array();
 		$summary['antennas'] = array();
 		$summary['data'] = array();
 		$summary['data_count'] = 0;
 		$datasets = array();
 		
 		while ( $dataset = mysql_fetch_array($result) ) {
 			
 			// Keep track of antennas
 			$ant = $dataset[1];
 			if( isset($summary['antennas']["$ant"]) ) {
 				$summary['antennas']["$ant"] += 1;
 			} else {
 				$summary['antennas']["$ant"] = 1;
 			}
 			
 			// Keep track of boxes
 			$box = substr($ant,0,2);
 			if( isset($summary['boxes'][$box]) ) {
 				$summary['boxes']["$box"]['count'] += 1;	
 			} else {
 				$summary['boxes']["$box"]['count'] = 1;
 				$summary['boxes']["$box"]['x'] = $boxes[$box]['x'];
 				$summary['boxes']["$box"]['y'] = $boxes[$box]['y'];
 			}
 			
 			// Keep track of data. This doen't work when you want to return the data to the client.
 			// Seems to be a bug in amfphp.
 			if($all) {
 				$datasets[] = $dataset;
 			}
 			
 			// increse data counter
 			$summary['data_count'] += 1;
 			
 		}
 		
 		// free memory
 		mysql_free_result($result);
 		
		if($all) {
			return array($summary,$datasets);	
		} else {
			return array($summary);
		}
		
		
	}
	
/**
	* Get data for the monthly rfid/box analysis
	*
	* @returns nested array with this structure:
	* 	array(
	*		
	*	rfid=>
	*			'boxes' =>
	*				'box' => datacount box
	*			'datacount' => datacount
	*
	*	)
	*
	*/	
	function monthlyRfidBox($parameters) {
	
		$year = $parameters['year'];
		$month = $parameters['month'];
		
		if( isset($parameters['from']) AND isset($parameters['from']) ) {
			$from = sprintf("%02s", $parameters['from']);
			$to = sprintf("%02s",$parameters['to']);
			$daytime_clause = "AND TIME(`time`) BETWEEN '$from:00:00' AND '$to:00:00'";
		}
		
		$table_rfids = $this->getTableName('rfids');
		$table_dir = $this->getTableName('direction_results');
		
		$sql =  "SELECT rfid, (SELECT sex FROM $table_rfids WHERE $table_dir.rfid = $table_rfids.id ) AS sex, CAST(box AS UNSIGNED) as box_int, MONTH(`time`) AS month, YEAR(`time`) AS year 
		 		FROM $table_dir WHERE MONTH(time) = $month 
 				AND YEAR(time) = $year AND dir='in' $daytime_clause";
		
 		$result = mysql_query($sql);
 		
 		if(!$result) {
 			return "Invalid query: " . mysql_error() . "\n";
 		}
 		
 		$data = array();
 		
 		while ( $dataset = mysql_fetch_object($result) ) {
 		
 			$rfid = $dataset->rfid;
 			$box = $dataset->box_int;
	 		$sex = $dataset->sex;
	 		
 			if( isset($data[$rfid]) ) {
 			
 				// Keep track of boxes
 				if( isset($data[$rfid]['boxes'][$box]) ) {
 					$data[$rfid]['boxes']["$box"] += 1;	
 				} else {
 					$data[$rfid]['boxes']["$box"] = 1;
 				}
 				
 				// increse data counter
 				$data[$rfid]['data_count'] += 1;
 			
 			} else {
 				
 				$data[$rfid] = array();
 				$boxes = array();
 				$boxes["$box"] = 1;
 				
 				$data[$rfid]['boxes'] = $boxes;
 				$data[$rfid]['sex'] = $sex;
 				$data[$rfid]['data_count'] = 1;
 					
 			}
 		
 		}
 		
 		$data_indexed = array();
 		
 		foreach ($data as $rfid => $values) {

 			if( $rfid != null) {
 			
 				$data_obj = NULL;
 				$data_obj->rfid = $rfid;
 				$data_obj->sex = $values['sex'];
 				$data_obj->boxes = $values['boxes'];
	 			$data_obj->data_count = $values['data_count'];
 			
	 			$data_indexed[] = $data_obj;
	 		}
 		
 		}
		
		
		return $data_indexed;
		
	}
	
	/**
	* Get data for the monthly rfid/antenna analysis
	*
	* @returns nested array with this structure:
	* 	array(
	*		
	*	rfid=>
	*		'antennas' =>
	*		'antenna' => datacount antenna
	*		'datacount' => datacount
	*
	*	)
	*
	*/	
	function monthlyRfidAnt($parameters) {
	
		$year = $parameters['year'];
		$month = $parameters['month'];
		
		if( isset($parameters['from']) AND isset($parameters['from']) ) {
			$from = sprintf("%02s", $parameters['from']);
			$to = sprintf("%02s",$parameters['to']);
			$daytime_clause = "AND TIME(`time`) BETWEEN '$from:00:00' AND '$to:00:00'";
		
		}
		
		
		$sql =  "SELECT rfid, (SELECT sex FROM " . $this->getTableName('rfids') . " WHERE " . $this->getTableName('data') . ".rfid = " . $this->getTableName('rfids') . ".id ) AS sex, CAST(ant AS UNSIGNED) as ant_int, MONTH(`time`) AS month, YEAR(`time`) AS year 
		 		FROM " . $this->getTableName('data') . " WHERE MONTH(time) = $month 
 				AND YEAR(time) = $year $daytime_clause";
 		$result = mysql_query($sql);
 		
 		if(!$result) {
 			return "Invalid query: " . mysql_error() . "\n";
 		}
 		
 		$data = array();
 		
 		while ( $dataset = mysql_fetch_object($result) ) {
 		
 			$rfid = $dataset->rfid;
 			$ant = $dataset->ant_int;
	 		$sex = $dataset->sex;
	 		
 			if( isset($data[$rfid]) ) {
 			
 				// Keep track of boxes
 				if( isset($data[$rfid]['antennas'][$ant]) ) {
 					$data[$rfid]['antennas']["$ant"] += 1;	
 				} else {
 					$data[$rfid]['antennas']["$ant"] = 1;
 				}
 				
 				// increse data counter
 				$data[$rfid]['data_count'] += 1;
 			
 			} else {
 				
 				$data[$rfid] = array();
 				$antennas = array();
 				$antennas["$ant"] = 1;
 				
 				$data[$rfid]['antennas'] = $antennas;
 				$data[$rfid]['sex'] = $sex;
 				$data[$rfid]['data_count'] = 1;
 					
 			}
 		
 		}
 		
 		$data_indexed = array();
 		
 		foreach ($data as $rfid => $values) {

 			if( $rfid != null) {
 			
 				$data_obj = NULL;
 				$data_obj->rfid = $rfid;
 				$data_obj->sex = $values['sex'];
 				$data_obj->antennas = $values['antennas'];
	 			$data_obj->data_count = $values['data_count'];
 			
	 			$data_indexed[] = $data_obj;
	 		}
 		
 		}
		
		
		return $data_indexed;
		
	}
	
	
	
	 /* ----------------------------------------------------------------------------
	
		GRAPH

	---------------------------------------------------------------------------- */	 
	
	/**
	* Returns the graph data in xml for the network view
	* @returns array with graph xml
	*/	
	function graphData($year,$month, $limit = 3600) {
		
		$xml = new DOMDocument( "1.0", "UTF-8" );
		$xml->preserveWhiteSpace = false;
		$xml->formatOutput = true;
		
		$limit--;
		
		/*
		* RFID
		*/
		$graph = $xml->createElement('graph');
		$meetings_month_db = $this->getGraphData($year, $month, $limit);
		$meetings_month = array();
		$rfids = array();
		
		while ( $meeting_month = mysql_fetch_object($meetings_month_db) ) {
		
			$from_to = $meeting_month->rfid_from . '_' . $meeting_month->rfid_to;
			$to_from = $meeting_month->rfid_to . '_' . $meeting_month->rfid_from;
			
			
			// adding rfid data to hash
			if( isset($rfids[$meeting_month->rfid_from]) ) {
				$rfids[$meeting_month->rfid_from]['sec'] += $meeting_month->dt_sec;
			} else {
				$rfids[$meeting_month->rfid_from] = 
					array( 
						'sec' => $meeting_month->dt_sec,
						'sex' => $meeting_month->rfid_from_sex
					);
			}
			
			
			if( isset($rfids[$meeting_month->rfid_to]) ) {
				$rfids[$meeting_month->rfid_from]['sec'] += $meeting_month->dt_sec;
			} else {
				$rfids[$meeting_month->rfid_to] = 
					array( 
						'sec' => $meeting_month->dt_sec,
						'sex' => $meeting_month->rfid_to_sex
					);
			}
			
			// adding edge data as hash
			$meetings_month[$from_to]['sec'] += $meeting_month->dt_sec;
			$meetings_month[$from_to]['count'] += 1;
			
		}
	
		
		/*
		* NODES (hash to xml)
		*/
		foreach( $rfids as $rfid => $props ) {
			//<Node id="1" prop="1(1)"/>
			$node = $xml->createElement('Node');
			$node->setAttribute('id', $rfid);
			$node->setAttribute('sec', $props['sec']);	
			$node->setAttribute('sex', $props['sex']);		
			
			$graph->appendChild($node);
		}
		
		/*
		* EDGES
		*/
		
		// Getting min / max values and sectors for the edge thickness
		// Need to make a copy of the array cause the keys are destroyed with usort
		$meetings_month_copy = $meetings_month;
		usort($meetings_month_copy, array(&$this, "sortBySec"));
		
		$max_meet_item = end($meetings_month_copy);
		$max_meet = $max_meet_item['sec'];
		$min_meet = reset($meetings_month_copy);
		
		if($limit > $min_meet['sec']) {
			$value = $limit + 1;
		} else {
			$value = $min_meet['sec'] + 1;
		}

		$sector = floor( ($max_meet - $value) / 10 );
		
		foreach( $meetings_month as $key => $data ) {
			//<Edge fromID="1.2.3" toID="1.2.3.5"/>
			
			list( $from, $to ) = split( '_', $key );
			
			$sec = $data['sec'];
			$count = $data['count'];
			
			
			//if( ($sec >= $limit) AND ($count > 1) ) {
			if( ($sec > $limit) && ($count > 1) ) {
				$edge = $xml->createElement('Edge');
				$edge->setAttribute('fromID', $from);
				$edge->setAttribute('toID', $to);
				$edge->setAttribute('sec', $sec);
				$edge->setAttribute('count', $count);
				
				$strength = ceil($sec / $sector);
				$edge->setAttribute('thickness', $strength);
				$edge->setAttribute('strength', $strength);
				
				$graph->appendChild($edge);
			}
				
			//$stringData = "\t\t\"$from\" -- \"$to\";\n";
			//fwrite($fh, $stringData);
		}
		
		$xml->appendChild($graph);
		//$xml->save('../xml/graph_fromdb.xml');
		return $xml->saveXML();
		
		//$stringData = '}';
		//fwrite($fh, $stringData);
	}	
	
	
	/*
	* Data getter for the graph data
	* Do not call directly !!
	* @returns mysql resource
	*/
	function getGraphData($year, $month, $limit) {
		
		return mysql_query("SELECT  rfid_from,(SELECT sex FROM " . $this->getTableName('rfids') . " WHERE " . $this->getTableName('rfids') . ".id = rfid_from) AS rfid_from_sex, 
								rfid_to, (SELECT sex FROM " . $this->getTableName('rfids') . " WHERE " . $this->getTableName('rfids') . ".id = rfid_to) AS rfid_to_sex,
								box, TIME_TO_SEC(dt) AS dt_sec FROM " . $this->getTableName('meetings') . " WHERE YEAR(`from`) = '$year' AND MONTH(`from`) = '$month' 
								ORDER BY box;");
								
	
	}
	
	/**
	* Returns the edge data
	* 
	* @returns array with graph xml
	*/		
	function getEdgeData($year, $month, $fromId, $toId) {
	
		$sql_from_to = "SELECT  rfid_from, rfid_to, box,typ, `from` ,`to` , dt," .
				"TIME_TO_SEC(dt) AS dt_sec FROM " . $this->getTableName('meetings') . " WHERE YEAR(`from`) = '$year' AND MONTH(`from`) = '$month' " .
				"AND (rfid_from = '$fromId' AND rfid_to = '$toId') " .
				"ORDER BY TIME_TO_SEC(dt)";
				

		$sql_to_from = "SELECT  rfid_from, rfid_to, box,typ, `from` ,`to` , dt, " .
				"TIME_TO_SEC(dt) AS dt_sec FROM " . $this->getTableName('meetings') . " WHERE YEAR(`from`) = '$year' AND MONTH(`from`) = '$month' " .
				"AND (rfid_from = '$toId' AND rfid_to = '$fromId') " .
				"ORDER BY TIME_TO_SEC(dt)";
				

        $edge_data;
        
        $edges_from_to_db = mysql_query( $sql_from_to );		
		while ( $dataset = mysql_fetch_object($edges_from_to_db) ) {
			
			$data = NULL;
			$data->rfid_from =  $dataset->rfid_from;
			$data->rfid_from_sex =  $dataset->rfid_from_sex;			
			$data->rfid_to =  $dataset->rfid_to;
			$data->rfid_to_sex =  $dataset->rfid_to_sex;			
			$data->box =  $dataset->box;			
			$data->from =  $dataset->from;			
			$data->to =  $dataset->to;						
			$data->dt =  $dataset->dt;
			$data->dt_sec =  $dataset->dt_sec;
			$data->typ =  $dataset->typ;
			
			$edge_data[] = $data;
		
			
		}
		
		$edges_to_from_db = mysql_query( $sql_to_from );
		while ( $dataset = mysql_fetch_object($edges_to_from_db) ) {
		
			$data = NULL;
			$data->rfid_from =  $dataset->rfid_from;
			$data->rfid_from_sex =  $dataset->rfid_from_sex;			
			$data->rfid_to =  $dataset->rfid_to;
			$data->rfid_to_sex =  $dataset->rfid_to_sex;			
			$data->box =  $dataset->box;			
			$data->from =  $dataset->from;			
			$data->to =  $dataset->to;						
			$data->dt =  $dataset->dt;
			$data->dt_sec =  $dataset->dt_sec;
		
			// Switching meeting typ
			switch($dataset->typ) {
				case '1':
					$data->typ = '2';				
				break;
				case '2':
					$data->typ = '1';				
				break;
				case '3':
					$data->typ = '4';				
				break;
				case '4':
					$data->typ = '3';
				break;				
			};
			
			$edge_data[] = $data;
			
		}
	
	
		return $edge_data;
	
	}
	
	
	
	
	 /* ----------------------------------------------------------------------------
	
		DB DATE RANGE 

	---------------------------------------------------------------------------- */	 
	
	/**
	* Get the date range of the data
	* @returns array with min/max date
	*/	
	
	function dbDateRange($min_field, $max_field) {
	
		$sql = "SELECT ( UNIX_TIMESTAMP( MIN(DATE($min_field)))) AS min_time, (UNIX_TIMESTAMP(MAX(DATE($max_field)))) AS max_time FROM " . $this->getTableName('results');
        $Result = mysql_query( $sql );
        while ($row = mysql_fetch_object($Result)) {
			return($row);
        }

		//return($sql);
	
	}

	 	 
	 /* ----------------------------------------------------------------------------
	
		EXCEL EXPORT

	---------------------------------------------------------------------------- */	 
	 	 
	/**
	* Export a datagrid to Excel. 
	* This function is called directly from the flex application so do not change the name or edit !
	*
	* Uses Spreadsheet_Excel_Writer: http://pear.php.net/manual/en/package.fileformats.spreadsheet-excel-writer.php 
	*
	* @returns url where you can grab the data
	*/	
	function exportToExcel($exportData, $filename) {

		/*
			getting the directorie, url, filename
		*/
		$downloadXML = $this->configXML->xpath("//downloaddir");		
		$downloadDir = implode('', $downloadXML);
		
		$downloadUrlXML = $this->configXML->xpath("//downloadurl");
		$downloadURL = implode('', $downloadUrlXML);
		
		$xlsfile = $filename . '_created_' . date('Y-m-d') . '.xls';
		$file = $downloadDir.$xlsfile;
		// write excel
		require_once 'Spreadsheet/Excel/Writer.php';
		$workbook = new Spreadsheet_Excel_Writer($file);
		
		/*
			formats
		*/
		
		// header
		$header_format =& $workbook->addFormat();
		$header_format->setColor('white'); // white
		$header_format->setFgColor(23); // grey
		$header_format->setBold(1); // bold
		
		// alphanumeric
		$text_format =& $workbook->addFormat();
		$text_format->setBold(0);
		$text_format->setColor('black'); // black
		
		// date fromat
		$date_format =& $workbook->addFormat();
		$date_format->setBold(0);
		$date_format->setColor('black'); // black
		$date_format->setNumFormat('YYYY-MM-DD');
		
		// numerical format
		$num_format =& $workbook->addFormat();
		$num_format->setBold(0);
		$num_format->setColor('black'); // black
		$num_format->setNumFormat('0');
		
		/*
			data 
		*/
		foreach( $exportData as $gridId => $dataMap) {

			// worksheet
			$sheet_name = $sort = $this->getLabelField($gridId);
			$worksheet =& $workbook->addWorksheet( $sheet_name );
			
			// get columns order
			$columns = $this->getColumnSort($gridId);
			
			foreach($dataMap as $column => $values) {
	
				if( isset($columns[$column]) ) {
					
					$col = $columns[$column];
					
					// column header
					// getLabel
					$label = $this->getLabelForColumn($gridId, $column);
					
					$row = 0;
					
					$worksheet->writeString($row, $col, $label, $header_format);	
					$row++;
					
					// get sort
					$sort = $this->getSortForField($gridId, $column);
					
					// get width
					$width = $this->getWidthForField($gridId, $column);
					$worksheet->setColumn($col, $col, $width / 7);
					
					// column format
					$col_format;
					
					// write data cells in the format based on the sort value
					switch($sort) {
						case "alphanum":
							for ( $i = 0; $i < count($values); $i++) {
								$worksheet->writeString($i + 1, $col, $values[$i], $text_format);	
							}			
						break;
						case "date":
							for ( $i = 0; $i < count($values); $i++) {
								$worksheet->writeString($i + 1, $col, $values[$i], $date_format);	
							}			
						break;
						case "numeric":
							//$col_format = $num_format;
							for ( $i = 0; $i < count($values); $i++) {
								$worksheet->writeNumber($i + 1, $col, $values[$i], $num_format);	
								
							}			
						break;
						default:
							for ( $i = 0; $i < count($values); $i++) {
								$worksheet->writeString($i + 1, $col, $values[$i], $text_format);
							}			
						break;
					}
				}
			}
		}

		$workbook->close();
		
		return $downloadURL . $xlsfile;
		//return $test;
	}
	
	/* ----------------------------------------------------------------------------
	
		NETDRAW EXPORT

	---------------------------------------------------------------------------- */	 
	/**
	* Export graph data as vna file which can be used in netdraw.
	* This function is called directly from the flex application so do not change the name or edit !
	*
	* @returns Url to vna file 
	*/	
	function exportToNetdraw($xml_data, $filename) {
	
		// getting the directorie, url, filename
		$downloadXML = $this->configXML->xpath("//downloaddir");		
		$downloadDir = implode('', $downloadXML);
		
		$downloadUrlXML = $this->configXML->xpath("//downloadurl");
		$downloadURL = implode('', $downloadUrlXML);
				
		// the vna file
		$vnafile = $downloadDir.$filename . '.vna';
				
		$vnafh = fopen($vnafile,"w") or die("Unable to create file!");


		
		// xml
		$xml = simplexml_load_string($xml_data);
		
		// Nodes

		$nodes = '';
		$nodes_properties = '';
		
		foreach ($xml->xpath('//Node') as $node) { 
		
			$id = $node->attributes()->id;
			$sex = $node->attributes()->sex;
			
			$nodes .= "$id \"$sex\"\n"; 
			
			if($sex == 'f') {
				$nodes_properties .= "$id 8388863 1\n";
			} else if( $sex == 'm' ){
				$nodes_properties .= "$id 16776960 7\n";
			} else {
				$nodes_properties .= "$id 12632256 10\n";
			}
			
		}
		
		// Node data
		fwrite($vnafh, "*Node data\n");
		fwrite($vnafh, "ID sex\n");
		
		fwrite($vnafh, $nodes . "\n");

		// Node properties		
		fwrite($vnafh, "*Node properties\n");
		fwrite($vnafh, "ID color shape\n");
		
		fwrite($vnafh, $nodes_properties . "\n");
		
		// Edges (ties)	
		fwrite($vnafh, "*Tie data\n");
		fwrite($vnafh, "from to weight\n");
		
		foreach ($xml->xpath('//Edge') as $edge) { 
		
			$from = $edge->attributes()->fromID;
			$to = $edge->attributes()->toID;
			$weight = $edge->attributes()->sec;
		
			fwrite($vnafh, "$from $to $weight\n");
			
		}	
		
		fclose($vnafh);
		return $downloadURL . $filename . '.vna';
		
	}
	
	/* ----------------------------------------------------------------------------
	
		PNG EXPORT

	---------------------------------------------------------------------------- */	 
	/**
	* Export as png file.
	* This function is called directly from the flex application so do not change the name or edit !
	*
	* @returns Url to png file 
	*/	
	function exportToPng($pngdata, $filename) {
	
		// getting the directorie, url, filename
		$downloadXML = $this->configXML->xpath("//downloaddir");		
		$downloadDir = implode('', $downloadXML);
		
		$downloadUrlXML = $this->configXML->xpath("//downloadurl");
		$downloadURL = implode('', $downloadUrlXML);
		
		$file = $downloadDir.$filename . '.png';
		
		// the png
		$decoded = base64_decode($pngdata);
		file_put_contents($file, $decoded);
		
		return $downloadURL . $filename . '.png';
	
	}
	
	/* ----------------------------------------------------------------------------
	
		DBASE EXPORT

	---------------------------------------------------------------------------- */	 
	/**
	* Export the data for a specific mouse over s specified time range to a dbase file
	*
	* @returns url of created dbase file
	*/	
	function exportToDbase($rfid, $from, $to) {
	
		$parameters['rfid'] = $rfid;
		$parameters['from'] = $from;
		$parameters['to'] = $to;
		$parameters["all"] = 1;
		
		list($summary, $data) = $this->getRfidData($parameters);
		
		if(sizeof($data) == 0) {
			return null;
		}
		
		// boxes
		$boxes = $summary['boxes'];
 		
 		// where to store the files
 		$downloadXML = $this->configXML->xpath("//downloaddir");		
		$downloadDir = implode('', $downloadXML);
		
		$downloadUrlXML = $this->configXML->xpath("//downloadurl");
		$downloadURL = implode('', $downloadUrlXML);
 		
 		// Adding data to dbf files
 		
 		// definition for the dbf files
 		$def = array(
			array("ID","N", 5,0), 		
			array("RFID","C", 10),
			array("YEAR","C", 4),
			array("MONTH","C", 2),
			array("TIME","C", 8),
			array("DATETIME","C", 19),
			array("BOX","C", 2),
			array("ANTENNA","C", 2),
			array("X","N", 3, 0),
			array("Y","N", 3, 0),
		);
 		
		// create a dbf_file
		//$dbf_file = $downloadDir.$rfid . "_" . $year . "_" . $month . ".dbf";
		// $dbf_file = "data_for_" . $rfid . "_" . $from . "_" . $to . ".csv";
		$filename = "data_for_" . $rfid . "_" . $from . "_" . $to . ".dbf";
		$dbf_file = $downloadDir . $filename;

		if (!dbase_create($dbf_file, $def)) {
		  return "Error, can't create the database: $dbf_file\n";
		}
		
		// open dbf file for reading and writing
		$dbf_file = dbase_open ($dbf_file, 2);
		//$fh = fopen ($downloadDir.$dbf_file, "w");
		
		// write data 
		// the datasets have the form: [rfid, antenna, box, time]
		$id = 0;
		foreach($data as $dataset) {
			
			
			list($rfid, $antenna, $unix_time) = $dataset;
			$box = substr($antenna, 0, 2);
			$datetime = date('Y-m-d H:i:s', $unix_time);
			$month = date('m', $unix_time);
			$year = date('Y', $unix_time);
			$time = date('H:i:s', $unix_time);
			$x = $boxes["$box"]['x'];
			$y = $boxes[$box]['y'];
			
			$dataset_converted = array($id, $rfid, $year, $month, $time, $datetime, $antenna, $box, $x, $y);
			$str = implode(',', $dataset_converted) . "\n";
			
			//fwrite($fh, $str);
			dbase_add_record ($dbf_file, $dataset_converted)
				or die ("Could not add record to dbf file $dbase_file.");
			
			$id++;
		}
		
		dbase_close($dbf_file);
		//fclose($fh);
		
		
		// returning url
		return $downloadURL.$filename;
	}
	 
	/* ----------------------------------------------------------------------------
	
		ITEMS TAB FUNCTIONS

	---------------------------------------------------------------------------- */
	 
	/**
	* Get the information (list) for all mice in the database
	* @returns An Array 
	*/	
	function getMice($gridId, $start = null, $end = null) {
		
		$field_string = $this->getFields($gridId);
		$sort_string = $this->getInitSort($gridId);
		
		$sql = "SELECT id,last,sex,implant_date FROM " . $this->getTableName('rfids') . " ORDER BY $sort_string DESC";
        $Result = mysql_query( $sql );
        while ($row = mysql_fetch_object($Result)) {
        
			$mouse = NULL;
			$mouse->id = $row->id;
			$mouse->sex = $row->sex;			
        	$mouse->last = $row->last;			
        	$mouse->implant_date = $row->implant_date;			
			
			//data count 
        	$count = $this->getDataCount('rfid_count', $row->id, $start, $end);
			
			if($count->data_count > 0 AND $count->data_count != NULL) {
			   	$mouse->data_count = $count->data_count;
				$mouse->dir_count = $count->dir_count;
				$mouse->res_count = $count->res_count;
				$mice[] = $mouse;
			}
        }
        
		return($mice);
		
	}
	
	/**
	* Get the information (list) for all boxes in the database
	* @returns An Array 
	*/	
	function getBoxes($gridId, $start = null, $end = null) {
		
		$field_string = $this->getFields($gridId);
		$sort_string = $this->getInitSort($gridId);
		
	
		$sql = "SELECT id,last,xcoord,ycoord FROM " . $this->getTableName('boxes') . " ORDER BY $sort_string DESC";
        $Result = mysql_query( $sql );
        while ($row = mysql_fetch_object($Result)) {
        
        	$box = NULL;
        	$box->id = $row->id;
        	$box->last = $row->last;
        	$box->xcoord = $row->xcoord;
        	$box->ycoord = $row->ycoord;        	
        	
        	//data count 
        	$count = $this->getDataCount('box_count', $row->id, $start, $end);
        	
        	if($count->data_count > 0 AND $count->data_count != NULL) {
        	
				$box->data_count = $count->data_count;
				$box->dir_count = $count->dir_count;
				$box->res_count = $count->res_count;
				$boxes[] = $box;
				
			}
			
        }
		
		return($boxes);
		
	}
	
	/**
	* Get the information (list) for all antennas in the database
	* @returns An Array 
	*/	
	function getAntennas($gridId, $start = null, $end = null) {
		
		$field_string = $this->getFields($gridId);
		$sort_string = $this->getInitSort($gridId);
		
		$sql = "SELECT id,last FROM " . $this->getTableName('antennas') . " ORDER BY $sort_string DESC";
		
        $Result = mysql_query( $sql );
        while ($row = mysql_fetch_object($Result)) {
        	
        	$antenna = NULL;
        	$antenna->id = $row->id;
			$antenna->last = $row->last;
			
			
        	//data count 
        	$count = $this->getDataCount('ant_count', $row->id, $start, $end);
        	
        	if($count->data_count > 0 AND $count->data_count != NULL) {
        	
				$antenna->data_count = $count->data_count;
				$antenna->dir_count = $count->dir_count;
				$antenna->res_count = $count->res_count;
				$antennas[] = $antenna;
				
			}
        

        }
		
		return($antennas);
		
	}
	
		
	
	/* ----------------------------------------------------------------------------
	
		DATA FUNCTIONS

	---------------------------------------------------------------------------- */
	
	
	/**
	* Get the Data for the selected Mouse
	* @returns An Array 
	*/
	
	function getData($gridId, $field, $value, $start, $end, $export = false) {
		
		$field_string = $this->getFields($gridId);
		$sort_string = $this->getInitSort($gridId);
		
		$table_data = $this->getTableName('data');
			
		$sql = "SELECT $field_string FROM $table_data WHERE $field='$value' AND $table_data.time BETWEEN '$start 00:00:00' AND (SELECT DATE_ADD('$end 00:00:00', INTERVAL 1 DAY)) ORDER BY $sort_string DESC";
		
		if( $export ) {
			return array('gridId' => $gridId, 'sql'=> $sql);
		}
			
		$Result = mysql_query( $sql );
        while ($row = mysql_fetch_object($Result)) {
			$dataset[] = $row;
        }
        
		return($dataset);
		
	}
	
	/**
	* Get the Data for the selected Mouse with gender data for the rfids
	* @returns An Array 
	*/
	
	function getDataRfid($gridId, $field, $value, $start, $end, $export = false) {
		
		$field_string = $this->getFields($gridId, $this->getTableName('data'));
		$sort_string = $this->getInitSort($gridId);
		
		$table_data = $this->getTableName('data');
		$table_rfids = $this->getTableName('rfids');		
			
		$sql = "SELECT $field_string FROM $table_data, $table_rfids WHERE $field='$value' AND $table_data.rfid = $table_rfids.id AND $table_data.time BETWEEN '$start 00:00:00' AND (SELECT DATE_ADD('$end 00:00:00', INTERVAL 1 DAY)) ORDER BY $table_data.$sort_string DESC";
		
		if( $export ) {
			return array('gridId' => $gridId, 'sql'=> $sql);
		}
			
		$Result = mysql_query( $sql );
        while ($row = mysql_fetch_object($Result)) {
			$dataset[] = $row;
        }
        
		return($dataset);
		
	}
	
	
	/**
	* Get the Data for the selected Box
	* For the boxes this must be handled special
	* @returns An Array 
	*/
	
	function getBoxData($gridId, $field, $value, $start, $end, $export = false) {
		
		$field_string = $this->getFields($gridId);
		$sort_string = $this->getInitSort($gridId);
		
		$table_data = $this->getTableName('data');
				
		$sql = "SELECT $field_string FROM $table_data WHERE $field REGEXP'^$value' AND $table_data.time BETWEEN '$start 00:00:00' AND (SELECT DATE_ADD('$end 00:00:00', INTERVAL 1 DAY)) ORDER BY $sort_string DESC";
		
		if( $export ) {
			return array('gridId' => $gridId, 'sql'=> $sql);
		}
			
		$Result = mysql_query( $sql );
        while ($row = mysql_fetch_object($Result)) {
			$dataset[] = $row;
        }
        
		return($dataset);
		
	}
	
	/**
	* Get the Data for the selected Box with gender data for the rfids
	* For the boxes this must be handled special
	* @returns An Array 
	*/
	
	function getBoxDataRfid($gridId, $field, $value, $start, $end, $export = false) {
		
		$field_string = $this->getFields($gridId, $this->getTableName('data'));
		$sort_string = $this->getInitSort($gridId);
		
		$table_data = $this->getTableName('data');
		$table_rfids = $this->getTableName('rfids');
		$table_ant = $this->getTableName('antennas');
				
		$sql = "SELECT $field_string,$table_ant.box FROM $table_data , $table_rfids, $table_ant WHERE $table_data.$field =  $table_ant.id AND $table_ant.box = '$value' AND $table_data.rfid = $table_rfids.id AND $table_data.time BETWEEN '$start 00:00:00' AND (SELECT DATE_ADD('$end 00:00:00', INTERVAL 1 DAY)) ORDER BY $sort_string DESC";
		
		if( $export ) {
			return array('gridId' => $gridId, 'sql'=> $sql);
		}
			
		$Result = mysql_query( $sql );
        while ($row = mysql_fetch_object($Result)) {
			$dataset[] = $row;
        }
        
		return($dataset);
		
	}
	
	/**
	* Get the direction data for the selected item
	* @returns An Array 
	*/
	
	function getDirRes($gridId, $field, $value, $start, $end, $export = false) {
		
		$field_string = $this->getFields($gridId);
		$sort_string = $this->getInitSort($gridId);
		
		$table_dir = $this->getTableName('direction_results');
		
		$sql = "SELECT $field_string FROM $table_dir WHERE $field='$value' AND $table_dir.time BETWEEN '$start 00:00:00' AND (SELECT DATE_ADD('$end 00:00:00', INTERVAL 1 DAY)) ORDER BY $sort_string DESC";
		
		if( $export ) {
			return array('gridId' => $gridId, 'sql'=> $sql);
		}
			
		$Result = mysql_query( $sql );
        while ($row = mysql_fetch_object($Result)) {
			$dataset[] = $row;
        }
        
		return($dataset);
		
	}
	
	/**
	* Get the direction data for the selected item with gender data for the rfids
	* @returns An Array 
	*/
	
	function getDirResRfid($gridId, $field, $value, $start, $end, $export = false) {
		
		$field_string = $this->getFields($gridId, 'dir');
		$sort_string = $this->getInitSort($gridId);		
		
		$table_dir = $this->getTableName('direction_results');
		$table_rfids = $this->getTableName('rfids');
			
		$sql = "SELECT $field_string FROM $table_dir, $table_rfids WHERE $table_dir.$field='$value' AND $table_dir.rfid = $table_rfids.id AND $table_dir.time BETWEEN '$start 00:00:00' AND (SELECT DATE_ADD('$end 00:00:00', INTERVAL 1 DAY)) ORDER BY $table_dir.$sort_string DESC";
		
		if( $export ) {
			return array('gridId' => $gridId, 'sql'=> $sql);
		}
			
		$Result = mysql_query( $sql );
        while ($row = mysql_fetch_object($Result)) {
			$dataset[] = $row;
        }
        
		return($dataset);
		
	}
	
	/**
	* Get the Data for the selected Box
	* @returns An Array 
	*/
	
	// function getRes($gridId, $field, $value, $start, $end, $export = false) {
// 		
// 		$field_string = $this->getFields($gridId);
// 		$sort_string = $this->getInitSort($gridId);
// 			
// 		$sql = "SELECT $field_string FROM " . $this->getTableName('results') . " WHERE $field='$value' AND DATE(box_in) >= '$start'  AND DATE(box_out) <='$end' ORDER BY $sort_string DESC";
// 		
// 		if( $export ) {
// 			return array('gridId' => $gridId, 'sql'=> $sql);
// 		}
// 			
// 		$Result = mysql_query( $sql );
//         while ($row = mysql_fetch_object($Result)) {
// 			$dataset[] = $row;
//         }
//         
// 		return($dataset);
// 		
// 	}
	
	/**
	* Get the stay results for the selected box or rfid with the gender data for the rfids.
	* @returns An Array 
	*/
	
	function getRes($gridId, $field, $value, $start, $end, $export = false) {
		
		$field_string = $this->getFields($gridId, 'res');
		$sort_string = $this->getInitSort($gridId);
		
		$table_res = $this->getTableName('results');
		$table_rfids = $this->getTableName('rfids');		
			
		$sql = "SELECT $field_string FROM $table_res, $table_rfids WHERE $table_res.$field='$value' AND $table_res.rfid = $table_rfids.id AND box_in >= '$start 00:00:00' AND $table_res.box_out <= (SELECT DATE_ADD('$end 00:00:00', INTERVAL 1 DAY)) ORDER BY $table_res.$sort_string DESC";
		
		if( $export ) {
			return array('gridId' => $gridId, 'sql'=> $sql);
		}
			
		$Result = mysql_query( $sql );
        while ($row = mysql_fetch_object($Result)) {
			$dataset[] = $row;
        }
        
		return($dataset);
		
	}
	
	/**
	* Get the data for the passed grids and their corresponding sql.
	* After data collecting send the data to the exportToExcel function to write the excel
	*
	* @returns  
	*/
	function directExportData( $gridWithSql, $filename ) {
	
		$gridIdsWithData = array();
	
		foreach( $gridWithSql as $gridId => $gridSql ) {
		
			$gridIdsWithData[$gridId] = array();
			
			// getting data
			$Result = mysql_query( $gridSql );
			
			
	        while( $fieldDataMap = mysql_fetch_assoc($Result) ) {
	        
	        	foreach ( $fieldDataMap as $field => $value) {
	        		$gridIdsWithData[$gridId][$field][] = $value;
				}
				
				//$gridIdsWithData[$gridId][] = $fieldDataMap;
				
    	    }
		}
	
		$filename = $this->exportToExcel( array_reverse($gridIdsWithData), $filename );
		return $filename;
	
	}
	
	/**
	* Delete the file with the passed url - which ist the first element of the params array - from the server. 
	*/
	function deleteFile($params) {
	
			
		// filename out of the url	
		$filename = array_pop( split('/', $params[0]) );
	
		// download directory
		$downloadXML = $this->configXML->xpath("//downloaddir");		
		$downloadDir = implode('', $downloadXML);
		
		if( file_exists( $downloadDir.$filename ) ) {
			if ( unlink($downloadDir.$filename) ) {
				return 1;
			} else {
				return "couldn't delete " . $downloadDir.$filename;
			}
		} else {
			return "no file for $fileurl:" . $downloadDir.$filename;
		}
		
	}
	
	/* ----------------------------------------------------------------------------
	
		DATA EDITING FUNCTIONS

	---------------------------------------------------------------------------- */	 
	
	/**
	* Delete an rfid with all its data from the database
	* 
	* The $params array have to contain the id of the item (rfid) to delete
	*
	* @returns Array
	*/
	function deleteRfid($params) {
		
		$rfid = $params[0];
		$days = array();
		$days_res = mysql_query( "SELECT DISTINCT DATE(`time`) AS day FROM " . $this->getTableName('data') . " WHERE rfid= '$rfid' GROUP BY day" ) or die (mysql_error());
		
		
        while( $day_row = mysql_fetch_array($days_res, MYSQL_NUM) ) {
        	$days[] = $day_row[0];
        }

        // Delete rfid data from data table
        $del_data_res = mysql_query("DELETE FROM " . $this->getTableName('data') . " WHERE rfid = '$rfid'") or die(mysql_error()); ;
        $del_dir_res = mysql_query("DELETE FROM " . $this->getTableName('direction_results') . " WHERE rfid = '$rfid'") or die(mysql_error()); 
        $del_res_res = mysql_query("DELETE FROM " . $this->getTableName('results') . " WHERE rfid = '$rfid'") or die(mysql_error());
        
        // delete rfid from rfid table
        $del_rfid = mysql_query("DELETE FROM " . $this->getTableName('rfids') . " WHERE id = '$rfid'") or die(mysql_error());
        
        return $days;
		
	}
	
	/* ----------------------------------------------------------------------------
	
		HELPER FUNCTIONS

	---------------------------------------------------------------------------- */
	
	
	/**
	* get the name of a table based on it's id.
	* Valid id's are the of the <db><tables><table> nodes in the configuration xml
	* @returns a String
	*/
	function getTableName($tableId) {
		
		$table_name = $this->configXML->xpath("//db/tables/table[@id='$tableId']");
		if($table_name) {
			return implode(',', $table_name);
		} else {
			return '[table not found]';
		}
		

	}
	
	
	/**
	* build the comma seperated string for the fields we want to get from the db
	* @returns a String
	*/
	
	function getFields($gridId, $default_table = null) {
	
		$fields_string = "*";
		$cols = $this->configXML->xpath("//grids//grid[@id='$gridId']/col");
		
		//return print_r($cols, true);
		$fields = array();
	
		foreach ($cols as $col) {
		
			$attributes = $col->attributes();

			$sql 	= $attributes['sql'];
			$field 	= $attributes['field'];
			$table 	= $attributes['table'];
		
			if($sql) {
				$sql = $sql . " AS " . $field;
				array_push($fields, $sql);
				
			} else if ($field) {
			
				if($table) {
					array_push($fields, $table . '.' . $field);
				} else if ($default_table) {
					array_push($fields, $default_table . '.' . $field);				
				} else {
					array_push($fields, $field);
				}
				
			}
		
		}
		
		
		
		//$fields = $this->configXML->xpath("//grids//grid[@id='$gridId']/col/@field");
		
		// Return a asteriks, so that no error is thrown, but all available fields in the table are selected
		
		if ( empty($fields) ) {
		    $fields_string = "*";
		} else {
		    $fields_string = implode(',', $fields);
		}


		return $fields_string;
	
	}
	
	/**
	* get the initial sort field
	* @returns a String
	*/
	
	function getInitSort($gridId) {
		
		$sort = $this->configXML->xpath("//grid[@id='$gridId']/@initsort");
		
		$sort_string = implode('', $sort);

		return $sort_string;
	
	}
	
	/**
	* get the sort type (alphanumeric, date, numeric) for the given field in the given grid
	* @returns a String
	*/
	
	function getSortForField($gridId, $field) {
		
		$sort = $this->configXML->xpath("//grid[@id='$gridId']/col[@field='$field']/@sort");
		
		$sort_string = implode('', $sort);

		return $sort_string;
	
	}
	
	/**
	* get the column width
	* @returns a number
	*/
	
	function getWidthForField($gridId, $field) {
		
		$width = $this->configXML->xpath("//grid[@id='$gridId']/col[@field='$field']/@width");
		
		$width_string = implode('', $width);

		return $width_string;
	
	}
	
	/**
	* get the grid label
	* @return the grid label string
	*/
	function getLabelField($gridId) {
	
		$header = $this->configXML->xpath("//grid[@id='$gridId']/@label");
		$header_label = implode('', $header);

		return $header_label;
	}
	
	/**
	* get the labelfor the given column field name in the given grid
	* @returns a String
	*/
	function getLabelForColumn($gridId, $field) {
		
		$label = $this->configXML->xpath("//grid[@id='$gridId']/col[@field='$field']/@label");
		
		$label_string = implode('', $label);

		return $label_string;
	
	}
	
	/**
	* get an associative array in the form fieldname => order for the given grid
	*
	* @returns Array
	*/
	function getColumnSort($gridId) {
	
		$columns = $this->configXML->xpath("//grid[@id='$gridId']/col");
		
		$columns_order = array();

		for($i = 0; $i < count($columns); $i++)
		{
			$field = $columns[$i]['field'];
			$columns_order["$field"] = "$i";
		}

		return $columns_order;
	}
	
	/**
	* get counts for an item (antenna,box,rfid)
	* @returns Object
	*/
	
	function getDataCount($count_table, $item_id, $start, $end) {
	
		if($start AND $end) {
			$range_clause = "AND day BETWEEN Date('$start') AND Date('$end')";
		} else {
			$range_clause = '';
		}
		$data_count = "SELECT SUM(data_count) as data_count,SUM(dir_count) as dir_count, SUM(res_count) as res_count FROM $count_table WHERE id='$item_id' $range_clause";
		$Result = mysql_query( $data_count);		
		return (mysql_fetch_object($Result));
	}
	
	/**
	* Sort function
	*/ 
	function sortBySec($a, $b) {
 		return ($a["sec"] > $b["sec"]) ? +1 : -1;
	}
	
	/**
	* get database name and login credentials
	* @returns a String
	*/
	function dbInfo() {
		
		$db_info = array();
		$db_node = simplexml_load_string( array_shift( $this->configXML->xpath("db") )->asXML() );
		$db_info['db_name'] = (string) array_shift( $db_node->xpath("dbname"));
		$db_info['user'] = (string) array_shift( $db_node->xpath("dbuser"));
		$db_info['pass'] = (string) array_shift( $db_node->xpath("dbpass"));

		return $db_info;
	
	}

		
}

?>