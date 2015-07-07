<?php
$webapikey = "0123456789ABCDEF0123456789ABCDEF";		//Steam WebAPI key
$min_check_delay = 600;						//time from last check in seconds
$file_last_checked_schema = "last_checked_schema.txt";		//file for time of last check
$file_last_known_schema = "last_known_schema.txt";		//file with the last known schema url
$file_sqlitedb = "latest.sq3";					//the sqlite database file.
$ftp_enabled = true;						//automatically upload generated database to ftp
$ftp_uri = "ftp://mylittlewebhost.com/subfolder/schema.sq3";	//uri of remote destination file
$ftp_user = "username";						//username for ftp
$ftp_pass = "pa$$word";						//password for ftp
?>
