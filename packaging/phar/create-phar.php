<?php

	$buildRoot ="build";
/*	if(file_exists($buildRoot.DIRECTORY_SEPARATOR.'filestore.phar.bz2')){
		unlink($buildRoot.DIRECTORY_SEPARATOR.'filestore.phar.bz2');
	}*/
	$oPhar = new Phar($buildRoot.DIRECTORY_SEPARATOR."filestore.phar", FilesystemIterator::CURRENT_AS_FILEINFO | FilesystemIterator::KEY_AS_FILENAME, "filestore.phar");
	$oPhar->startBuffering();

	$oPhar->buildFromDirectory("src",'/.php$/');
	$oPhar->addFromString ('filestored',str_replace("#!/usr/bin/php\n",'',file_get_contents('src/filestored')));
	$oPhar->compressFiles(Phar::BZ2);
	$oPhar->setStub(file_get_contents('packaging/phar/stub.php'));

	$oPhar->stopBuffering(); 
