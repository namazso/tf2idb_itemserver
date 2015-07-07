<?php
require("config.php");
require("vdf.php");

function array_merge_recursive_distinct ( array &$array1, array &$array2 )
{
	$merged = $array1;
	foreach ( $array2 as $key => &$value )
	{
		if ( is_array ( $value ) && isset ( $merged [$key] ) && is_array ( $merged [$key] ) )
			$merged [$key] = array_merge_recursive_distinct ( $merged [$key], $value );
		else
			$merged [$key] = $value;
	}
	return $merged;
}

function resolve_prefabs($item, &$schema)
{
	if(isset($item["prefab"]))
	{
		$prefabs = explode(" ", $item["prefab"]);
		foreach($prefabs as $prefab)
		{
			$prefab_data = $schema["prefabs"][$prefab];
			if(is_array($prefab_data))
				$item = array_merge_recursive_distinct($item, resolve_prefabs($prefab_data, $schema));
		}
	}
	return $item;
}

header('Content-Type: text/plain');

if(intval(file_get_contents($file_last_checked_schema)) + $min_check_delay > time())
	die("The schema was already checked recently!");
file_put_contents($file_last_checked_schema, time());
$schema = json_decode(file_get_contents("http://api.steampowered.com/IEconItems_440/GetSchema/v0001/?key=" . $webapikey))->result;
if(!isset($schema->items_game_url))
	die("Something went wrong!");
$last_known_schema = file_get_contents($file_last_known_schema);
if($last_known_schema == $schema->items_game_url)
	die("No need to update");
file_put_contents($file_last_known_schema, $schema->items_game_url);

$schema = vdf_decode(file_get_contents($schema->items_game_url))["items_game"];

//$schema = vdf_decode(file_get_contents("item_schema.txt"))["items_game"];

unlink($file_sqlitedb);
$db = new SQLite3($file_sqlitedb);

$db->exec('CREATE TABLE "tf2idb_class" ("id" INTEGER NOT NULL ,"class" TEXT NOT NULL ,PRIMARY KEY ("id", "class"))');
$db->exec('CREATE TABLE "tf2idb_item_attributes" ("id" INTEGER NOT NULL,"attribute" INTEGER NOT NULL ,"value" TEXT NOT NULL, PRIMARY KEY ("id", "attribute") )');
$db->exec('CREATE TABLE "tf2idb_item" ("id" INTEGER PRIMARY KEY NOT NULL,"name" TEXT NOT NULL, "class" TEXT NOT NULL, "slot" TEXT, "quality" TEXT NOT NULL, "tool_type" TEXT, "min_ilevel" INTEGER, "max_ilevel" INTEGER, "baseitem" INTEGER, "holiday_restriction" TEXT, "has_string_attribute" INTEGER )');
$db->exec('CREATE TABLE "tf2idb_particles" ("id" INTEGER PRIMARY KEY NOT NULL, "name" TEXT NOT NULL )');
$db->exec('CREATE TABLE "tf2idb_equip_conflicts" ("name" TEXT NOT NULL, "region" TEXT NOT NULL, PRIMARY KEY ("name", "region"))');
$db->exec('CREATE TABLE "tf2idb_equip_regions" ("id" INTEGER NOT NULL, "region" TEXT NOT NULL, PRIMARY KEY ("id", "region"))');
$db->exec('CREATE INDEX "attribute" ON "tf2idb_item_attributes" ("attribute" ASC)');
$db->exec('CREATE INDEX "class" ON "tf2idb_class" ("class" ASC)');
$db->exec('CREATE INDEX "slot" ON "tf2idb_item" ("slot" ASC)');

// particles
$stmt = $db->prepare('INSERT INTO tf2idb_particles (id,name) VALUES (:id,:name)');
foreach($schema["attribute_controlled_attached_particles"] as $key => $value)
{
	$stmt->bindValue(':id', $key, SQLITE3_INTEGER);
	$stmt->bindValue(':name', $value["system"], SQLITE3_TEXT);
	$stmt->execute();
}

// conflicts
$stmt = $db->prepare('INSERT INTO tf2idb_equip_conflicts (name,region) VALUES (:name,:region)');
foreach($schema["equip_conflicts"] as $key => $value)
{
	foreach($value as $region => $unused)
	{
		$stmt->bindValue(':name', $key, SQLITE3_TEXT);
		$stmt->bindValue(':region', $region, SQLITE3_TEXT);
		$stmt->execute();
	}
}

// attributes
$attribute_types = array();
foreach($schema["attributes"] as $key => $value)
	$attribute_types[$value["name"]] = array("id" => $key, "atype" => (isset($value["attribute_type"]) ? $value["attribute_type"] : (isset($value["stored_as_integer"]) ? "integer" : "float")));

// items
$stmt = $db->prepare('INSERT INTO tf2idb_item
 (id, name, class, slot, quality, tool_type, min_ilevel, max_ilevel, baseitem, holiday_restriction, has_string_attribute) VALUES
(:id,:name,:class,:slot,:quality,:tool_type,:min_ilevel,:max_ilevel,:baseitem,:holiday_restriction,:has_string_attrib)');
$stmt_item_attributes = $db->prepare('INSERT INTO tf2idb_item_attributes (id,attribute,value) VALUES (:id,:attribute,:value)');
$stmt_class = $db->prepare('INSERT INTO tf2idb_class (id,class) VALUES (:id,:class)');
$stmt_equip_regions = $db->prepare('INSERT INTO tf2idb_equip_regions (id,region) VALUES (:id,:region)');
foreach($schema["items"] as $key => $value)
{
	if($key == "default")
		continue;
	$item = resolve_prefabs($value, $schema);
	if(!isset($item["name"])){print_r($item);break;}
	$baseitem = isset($item["baseitem"]);
	$tool = "";
	$has_string_attribute =	false;
	$holiday_restriction =	isset($item["holiday_restriction"]) ? $item["holiday_restriction"] : NULL;
	$min_ilevel =		isset($item["min_ilevel"]) ? $item["min_ilevel"] : NULL;
	$max_ilevel =		isset($item["max_ilevel"]) ? $item["max_ilevel"] : NULL;
	$item_slot = 		isset($item["item_slot"]) ? $item["item_slot"] : NULL;
	$item_quality =		isset($item["item_quality"]) ? $item["item_quality"] : "";
	if(isset($item["tool"]))
		$tool = $item["tool"]["type"];
	if(isset($item["attributes"]))
	{
		foreach($item["attributes"] as $name => $info)
		{
			$has_string_attribute = $has_string_attribute || $attribute_types[$name]["atype"] == "string";
			$stmt_item_attributes->bindValue(":id", $key, SQLITE3_INTEGER);
			$stmt_item_attributes->bindValue(":attribute", $attribute_types[$name]["id"], SQLITE3_INTEGER);
			$stmt_item_attributes->bindValue(":value", $info["value"], SQLITE3_TEXT);
//			print_r(array("id" => $key, "attribute" => $attribute_types[$name]["id"], "value" => $info["value"]));
			$stmt_item_attributes->execute();
		}
	}

	$stmt->bindValue(":id",			$key,				SQLITE3_INTEGER);
	$stmt->bindValue(":name",		$item["name"],			SQLITE3_TEXT);
	$stmt->bindValue(":class",		$item["item_class"],		SQLITE3_TEXT);
	$stmt->bindValue(":slot",		$item_slot,			SQLITE3_TEXT);
	$stmt->bindValue(":quality",		$item_quality,			SQLITE3_TEXT);
	$stmt->bindValue(":tool_type",		$tool,				SQLITE3_TEXT);
	$stmt->bindValue(":min_ilevel",		$min_ilevel,			SQLITE3_INTEGER);
	$stmt->bindValue(":max_ilevel", 	$max_ilevel,			SQLITE3_INTEGER);
	$stmt->bindValue(":baseitem",		$baseitem ? 1 : 0,		SQLITE3_INTEGER);
	$stmt->bindValue(":holiday_restriction",$holiday_restriction,		SQLITE3_TEXT);
	$stmt->bindValue(":has_string_attrib",	$has_string_attribute ? 1 : 0,	SQLITE3_INTEGER);
	$stmt->execute();

	if(isset($item["used_by_classes"]))
	{
		foreach($item["used_by_classes"] as $prof => $unused)
		{
			$stmt_class->bindValue(":id", $key, SQLITE3_INTEGER);
			$stmt_class->bindValue(":class", $prof, SQLITE3_TEXT);
			$stmt_class->execute();
		}
	}

	if(isset($item["equip_regions"])) $equip_region = $item["equip_regions"];
	if(isset($item["equip_region"])) $equip_region = $item["equip_region"];

	if(isset($equip_region))
	{
		if(is_array($equip_region))
		{
			foreach($equip_region as $region => $unused)
			{
				$stmt_equip_regions->bindValue(":id", $key, SQLITE3_INTEGER);
				$stmt_equip_regions->bindValue(":region", $region, SQLITE3_TEXT);
				$stmt_equip_regions->execute();
			}
		}
		else
		{
			$stmt_equip_regions->bindValue(":id", $key, SQLITE3_INTEGER);
			$stmt_equip_regions->bindValue(":region", $equip_region, SQLITE3_TEXT);
			$stmt_equip_regions->execute();
		}
	}
}

$db->close();

if($ftp_enabled)
{
	$ch = curl_init();
	$fp = fopen($file_sqlitedb, "r");
	curl_setopt($ch, CURLOPT_URL, $ftp_uri);
	curl_setopt($ch, CURLOPT_USERPWD, sprintf("%s:%s", $ftp_user, $ftp_pass));
	curl_setopt($ch, CURLOPT_UPLOAD, 1);
	curl_setopt($ch, CURLOPT_INFILE, $fp);
	curl_setopt($ch, CURLOPT_INFILESIZE, filesize($file_sqlitedb));
	curl_exec ($ch);
	$error_no = curl_errno($ch);
	curl_close ($ch);
	if ($error_no == 0)
		echo "File updated on FTP.";
	else
		echo "FTP upload failure. cURL errno " . $error_no;
}
else
	echo "File updated on server";
?>
