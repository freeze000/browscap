<?php

	define("WEBSITE_ROOT", "path/to/you/website");

	$browscapIni = WEBSITE_ROOT . "/tmp/php_browscap.ini";
	$browscapBin = WEBSITE_ROOT . "/var/browscap/browscap.bin";
	
	if (!file_exists($browscapBin)) {
		
		$fileData = file_get_contents("http://browscap.org/stream?q=PHP_BrowsCapINI");
		
		if ($fileData === false)
		{
			exit;
		}
		
		file_put_contents($browscapIni, $fileData);
		
		$removeDownloadedFile = true;
	}
	
	
	$patterns = [ ];
	
	
	$inputHandle = fopen($browscapIni, "r");
	while (!feof($inputHandle)) {
		
		$str = fgets($inputHandle);
		
		$str = trim($str);
		
		if (preg_match('/(?<=\[)(?:[^\r\n]*[?*][^\r\n]*)(?=\])|(?<=\[)(?:[^\r\n*?]+)(?=\])(?![^\[]*Comment=)/', $str, $matches) > 0) {
			$pattern = $matches[0];
			
			$patterns[] = $pattern;
		}
	}
	fclose($inputHandle);
	
	
	usort($patterns, function($a, $b) {
		if (strlen($a) == strlen($b)) {
			return 0;
		}
		return (strlen($a) > strlen($b)) ? -1 : 1;
	});
	
	
	$data = [ ];
	
	foreach ($patterns as $pattern) {
		
		if ('GJK_Browscap_Version' === $pattern) {
			continue;
		}
			
		$pattern     = strtolower($pattern);
		$patternHash = Browscap::GetHashForPattern($pattern, false);
		$tmpLength   = GetPatternLength($pattern);
		
		// special handling of default entry
		if ($tmpLength === 0) {
			$patternHash = str_repeat('z', 32);
		}
		
		if (!isset($data[$patternHash])) {
			$data[$patternHash] = [];
		}
		
		if (!isset($data[$patternHash][$tmpLength])) {
			$data[$patternHash][$tmpLength] = [];
		}
		
		$pattern = Browscap::PregQuote($pattern, "/");
		
		// Check if the pattern contains digits - in this case we replace them with a digit regular expression,
		// so that very similar patterns (e.g. only with different browser version numbers) can be compressed.
		// This helps to speed up the first (and most expensive) part of the pattern search a lot.
		if (strpbrk($pattern, '0123456789') !== false) {
			$compressedPattern = preg_replace('/\d/', '[\d]', $pattern);
		
			if (!in_array($compressedPattern, $data[$patternHash][$tmpLength])) {
				$data[$patternHash][$tmpLength][] = $compressedPattern;
			}
		} else {
			$data[$patternHash][$tmpLength][] = $pattern;
		}
	}
	
	
	// sorting of the data is important to check the patterns later in the correct order, because
	// we need to check the most specific (=longest) patterns first, and the least specific
	// (".*" for "Default Browser")  last.
	//
	// sort by pattern start to group them
	ksort($data);
	// and then by pattern length (longest first)
	foreach (array_keys($data) as $key) {
		krsort($data[$key]);
	}
	
	// write optimized file (grouped by the first character of the has, generated from the pattern
	// start) with multiple patterns joined by tabs. this is to speed up loading of the data (small
	// array with pattern strings instead of an large array with single patterns) and also enables
	// us to search for multiple patterns in one preg_match call for a fast first search
	// (3-10 faster), followed by a detailed search for each single pattern.
	$contents = [];
	foreach ($data as $patternHash => $tmpEntries) {
		if (empty($tmpEntries)) {
			continue;
		}
	
		$subkey = Browscap::GetPatternCacheSubkey($patternHash);
	
		if (!isset($contents[$subkey])) {
			$contents[$subkey] = [];
		}
	
		foreach ($tmpEntries as $tmpLength => $tmpPatterns) {
			if (empty($tmpPatterns)) {
				continue;
			}
	
			$chunks = array_chunk($tmpPatterns, 50); // TODO send to config
	
			foreach ($chunks as $chunk) {
				$contents[$subkey][] = $patternHash . "\t" . $tmpLength . "\t" . implode("\t", $chunk);
			}
		}
	}
	unset($data);
	
	
	
	$inputHandle = fopen($browscapIni, "r");
	
	$contentDetails = [ ];
	$pattern = "";
	$values = [ ];
	
	while (!feof($inputHandle)) {
		$str = fgets($inputHandle);
	
		$str = trim($str);
	
		// section detected
		if (preg_match('/^\[(.+?)\]/', $str, $matches) > 0) {
			if (!empty($pattern)) {			
				$pattern = strtolower($pattern);
				$patternHash = md5($pattern);
				$subkey = Browscap::GetPartCacheSubKey($patternHash);
				
				$contentDetails[$subkey][] = $patternHash . "\t" . implode("\n", $values);
			}
	
			$pattern = $matches[1];
			$values = [ ];
			continue;
		}
	
		// skip comments
		if ($str[0] == ";") {
			continue;
		}
	
		// simple re for this ini file
		if (preg_match('/^([0-9A-Za-z_]+)\s*=\s*"?(.+?)"?$/', $str, $matches) > 0) {
			$values[] = $matches[1] . "=" . $matches[2];
		}
	}
	
	fclose($inputHandle);
	
	
	$hashContentOffsets = [ ];
	$hashDetailOffsets = [ ];
	
	$outputHandle = fopen($browscapBin, "wb");
	
	// write hash pointer
	
	fwrite($outputHandle, pack("N", count($contents)));
	
	foreach ($contents as $subkey => $patternList) {
		fwrite($outputHandle, $subkey);
		
		$offset = ftell($outputHandle);
		
		$hashContentOffsets[$subkey] = $offset;
		
		fwrite($outputHandle, pack("NN", 0, 0));
	}
	
	
	// write details pointer
	
	fwrite($outputHandle, pack("N", count($contentDetails)));
	
	foreach ($contentDetails as $subkey => $detailList) {
		
		fwrite($outputHandle, $subkey);
		
		$offset = ftell($outputHandle);
		
		$hashDetailOffsets[$subkey] = $offset;
		
		fwrite($outputHandle, pack("NN", 0, 0));
	}
	
	
	// write index
	
	foreach ($contents as $subkey => $patternList) {
		
		$offset = $hashContentOffsets[$subkey];
		
		$current = ftell($outputHandle);
		
		fseek($outputHandle, $offset, SEEK_SET);
		
		fwrite($outputHandle, pack("NN", $current, count($patternList)));
		
		fseek($outputHandle, $current, SEEK_SET);
		
		foreach ($patternList as $string)
		{
			fwrite($outputHandle, pack("N", strlen($string)));
			fwrite($outputHandle, $string);
		}
	}
	
	foreach ($contentDetails as $subkey => $detailList) {
		
		$offset = $hashDetailOffsets[$subkey];
		
		$current = ftell($outputHandle);
		
		fseek($outputHandle, $offset, SEEK_SET);
		
		fwrite($outputHandle, pack("NN", $current, count($detailList)));
		
		fseek($outputHandle, $current, SEEK_SET);
		
		foreach ($detailList as $string) {
			fwrite($outputHandle, pack("N", strlen($string)));
			fwrite($outputHandle, $string);
		}
	}
	
	
	fclose($outputHandle);
	
	if ($removeDownloadedFile) {
		unlink($browscapIni);
	}
	
	function GetPatternLength($pattern)
	{
		return strlen(str_replace('*', '', $pattern));
	}








