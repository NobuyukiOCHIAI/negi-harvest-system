<?php
function log_error(string $message, array $stage): void {
    $dir = '/home/love-media/forc_logs';
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
	
	$date = date("Y-m-d H:i:s");
 	$dateF = date("Ymd");
	
	$file = $dir."/".$dateF.'_error.txt';
	$contents .= $date."\t".$message."\t".implode($stage)."\n";
	
	$handling = fopen($file,  'a');
	chmod($file, 0777);
	fwrite($handling, $contents);
}
?>
