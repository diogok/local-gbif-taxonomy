<?php


$data_dir = __DIR__.'/data';
if(!file_exists($data_dir)) {
  mkdir($data_dir);
}

if(!file_exists($data_dir."/backbone-current.zip")) {
  passthru('wget http://rs.gbif.org/datasets/backbone/backbone-current.zip -O '.$data_dir.'/backbone-current.zip');
  passthru('unzip '.$data_dir.'/backbone-current.zip -d '.$data_dir.'/backbone');
}

$xml = simplexml_load_string(file_get_contents($data_dir."/backbone/meta.xml"));

function go($core) {
  global $data_dir;

  $opt = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
  ];

  $db = new PDO("mysql:host=mysql;dbname=ipt;port3306;charset=utf8",'root','ipt123',$opt);

  $ignore = (int) $core["ignoreHeaderLines"];
  $enclose = (string) $core["fieldsEnclosedBy"];
  if($enclose == "") {
    $enclose = chr(0);
  }
  $sep =(string) $core["fieldsTerminatedBy"];
  if($sep=="\\t") {
    $sep="\t";
  }
  $file = (string) $core->files->location;

  $rowType = (string) $core['rowType'];
  $table = strtolower(substr($rowType,strrpos($rowType,'/') +1 ));
  $pk='taxonID';

  $fields = [];
  if($table != 'taxon') {
    $fields[] = $pk;
  }

  foreach($core->field as $field) {
    $term = (string)$field["term"];
    $fields[] = substr($term,strrpos($term,"/") + 1) ;
  }

  $create= "CREATE TABLE IF NOT EXISTS `".$table."` (\n";
  foreach($fields as $i=>$f) {
    if($i >0) $create .= ",";

    if($f == $pk) {
      $create .= "`".$f."` VARCHAR(1024)\n";
    } else if($f=='references' || $f == 'description' || $f=='bibliographicCitation'  || $f=='locationRemarks') {
      $create .= "`".$f."` TEXT\n";
    } else if($table=='multimedia') {
      $create .= "`".$f."` VARCHAR(2048)\n";
    } else {
      $create .= "`".$f."` VARCHAR(1024)\n";
    }
  }
  if($table=='taxon') {
    $create .= ",PRIMARY KEY (`".$pk."`)\n";
  } else {
    $create .= ",INDEX (`".$pk."`)\n";
  }
  $create .=') CHARACTER SET=utf8 ENGINE=InnoDB;';
  echo $create."\n";

  $db->exec($create);

  $sql = "INSERT INTO `".$table."` (";
  foreach($fields as $i=>$f) {
    if($i>0) $sql .= ',';
    $sql .= "`".$f."`";
  }
  $sql .= ") VALUES (";
  foreach($fields as $i=>$f) {
    if($i>0) $sql .= ',';
    $sql .= "?";
  }
  $sql .= ") ON DUPLICATE KEY UPDATE ";
  foreach($fields as $i=>$f) {
    if($i>0) $sql .= ',';
    $sql .= "`".$f."`=values(`".$f."`)";
  }
  $sql .= ';';
  echo $sql."\n";

  $stmt = $db->prepare($sql);

  $csv = fopen($data_dir."/backbone/".$file,'r');
  for($i=0;$i<$ignore;$i++) {
    fgetcsv($csv,0,$sep,$enclose);
  }

  $db->beginTransaction();
  $i=0;
  while(($row = fgetcsv($csv,0,$sep,$enclose)) !== FALSE) {
    while(count($row) != count($fields)) {
      $row[] = "";
    }
    $a=$stmt->execute($row);
    $i++;
    if(( $i % 500 ) == 0) {
      $db->commit();
      $db->beginTransaction();
      echo $i."\n";
    }
  }
  $db->commit();
  fclose($csv);

}

$core = $xml->core;
#go($core);
foreach($xml->extension as $ext) {
  go($ext);
}

