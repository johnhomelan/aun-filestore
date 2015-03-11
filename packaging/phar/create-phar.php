<?php

	$buildRoot ="build";
/*	if(file_exists($buildRoot.DIRECTORY_SEPARATOR.'filestore.phar.bz2')){
		unlink($buildRoot.DIRECTORY_SEPARATOR.'filestore.phar.bz2');
	}*/
	$phar = new Phar($buildRoot.DIRECTORY_SEPARATOR."filestore.phar", FilesystemIterator::CURRENT_AS_FILEINFO | FilesystemIterator::KEY_AS_FILENAME, "filestore.phar");
	$phar->startBuffering();

	$phar->buildFromDirectory("src",'/.php$/');
	$phar->addFile('src/filestored','filestored');
	$phar->compressFiles(Phar::BZ2);
	$phar->setStub(file_get_contents('packaging/phar/stub.php'));

	$phar->stopBuffering(); 
