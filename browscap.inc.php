<?php

class Browscap
{
	private static $path = WEBSITE_ROOT . "/var/browscap/browscap.bin";
	
	/**
	 * Content offset data on file browscap.bin tab-sepparated offset count
	 *
	 * @var array
	 */
	private static $contentOffsets = NULL;
	
	/**
	 * Detail offset data on file browscap.bin tab-sepparated offset count
	 *
	 * @var array
	 */
	private static $detailOffsets = NULL;
	
	/**
	 * Read browscap.bin and extract header data.
	 * 
	 * @internal
	 */
	private static function Init()
	{
		$usageBefore = memory_get_usage();
	
		if (!file_exists(self::$path)) {
			throw new Exception("Browscap binary data file does not exists. Convert it first!");
		}
	
		$handle = fopen(self::$path, "rb");
		
		self::$contentOffsets = [ ];		
		
		$buff = fread($handle, 4);
		$contentCount = unpack("N", $buff)[1];
	
		for ($i = 0; $i < $contentCount; $i ++) {
			
			$subkey = fread($handle, 2);
				
			$buff = fread($handle, 8);
			$data = unpack("Noffset/Ncount", $buff);

			self::$contentOffsets[$subkey] = $data["offset"] . "\t" . $data["count"];
		}
		
		self::$detailOffsets = [ ];
		
		$buff = fread($handle, 4);
		$detailCount = unpack("N", $buff)[1];
		
		for ($i = 0; $i < $detailCount; $i++) {
			
			$subkey = fread($handle, 3);
			
			$buff = fread($handle, 8);
			$data = unpack("Noffset/Ncount", $buff);
			
			self::$detailOffsets[$subkey] = $data["offset"] . "\t" . $data["count"];
		}
		
		fclose($handle);
	
		
		$used = memory_get_usage() - $usageBefore;
	
		__::Debug("Browscap memory consumption: " . $used . " bytes");
	}
	
	public static function GetBrowser($userAgent)
	{
		if (self::$contentOffsets == NULL) {
			self::Init();
		}
		
		$userAgent = strtolower($userAgent);
		
		foreach (self::GetPatterns($userAgent) as $patterns) {
			
			$patternToMatch = '/^(?:' . str_replace("\t", ')|(?:', $patterns) . ')$/i';
			
			if (!preg_match($patternToMatch, $userAgent)) {
				continue;
			}
			
			// strtok() requires less memory than explode()
			$pattern = strtok($patterns, "\t");
			
			while ($pattern !== false) {
				
				$pattern       = str_replace('[\d]', '(\d)', $pattern);
				$quotedPattern = '/^' . $pattern . '$/i';
				$matches       = [];
				
				if (preg_match($quotedPattern, $userAgent, $matches)) {
					// Insert the digits back into the pattern, so that we can search the settings for it
					if (count($matches) > 1) {
						array_shift($matches);
						foreach ($matches as $oneMatch) {
							$numPos  = strpos($pattern, '(\d)');
							$pattern = substr_replace($pattern, $oneMatch, $numPos, 4);
						}
					}
					
					// Try to get settings - as digits have been replaced to speed up the pattern search (up to 90 faster),
					// we won't always find the data in the first step - so check if settings have been found and if not,
					// search for the next pattern.
					
					$result = self::GetDetail($pattern);
					if (count($result)) {
						$browser = new class extends CArrayTranslator {
							public $version;
							public $browser;
							public $browserMarker;
							public $majorVersion;
							public $minorVersion;
							public $platform;
							public $isMobileDevice;
							public $isTablet;
							public $crawler;
							public $deviceType;
							public $devicePointingMethod;
						};

						// set boolean type
						foreach ($result as $name => &$val) {
							
							if ($val === "true") {
								$val = true;
							}
							if ($val === "false") {
								$val = false;
							}
						}
						unset($val);
						
						$browser->version = $result["Version"];
						$browser->browser = $result["Browser"];
						$browser->browserMarker = $result["Browser_Maker"];
						$browser->majorVersion = $result["MajorVer"];
						$browser->minorVersion = $result["MinorVer"];
						$browser->platform = $result["Platform"];
						$browser->isMobileDevice = $result["isMobileDevice"];
						$browser->isTablet = $result["isTablet"];
						$browser->crawler = $result["Crawler"];
						$browser->deviceType = $result["Device_Type"];
						$browser->devicePointingMethod = $result["Device_Pointing_Method"];
						
						return $browser;
					}
				}
				
				$pattern = strtok("\t");
			}
		}
		
	}
	
	/**
	 * Gets a hash or an array of hashes from the first characters of a pattern/user agent, that can
	 * be used for a fast comparison, by comparing only the hashes, without having to match the
	 * complete pattern against the user agent.
	 *
	 * With the variants options, all variants from the maximum number of pattern characters to one
	 * character will be returned. This is required in some cases, the a placeholder is used very
	 * early in the pattern.
	 *
	 * Example:
	 *
	 * Pattern: "Mozilla/* (Nintendo 3DS; *) Version/*"
	 * User agent: "Mozilla/5.0 (Nintendo 3DS; U; ; en) Version/1.7567.US"
	 *
	 * In this case the has for the pattern is created for "Mozilla/" while the pattern
	 * for the hash for user agent is created for "Mozilla/5.0". The variants option
	 * results in an array with hashes for "Mozilla/5.0", "Mozilla/5.", "Mozilla/5",
	 * "Mozilla/" ... "M", so that the pattern hash is included.
	 *
	 * @param  string       $pattern
	 * @param  bool         $variants
	 * @return string|array
	 */
	public static function GetHashForPattern($pattern, $variants = false)
	{
		$regex   = '/^([^\.\*\?\s\r\n\\\\]+).*$/';
		$pattern = substr($pattern, 0, 32);
		$matches = [];
	
		if (!preg_match($regex, $pattern, $matches) || !isset($matches[1])) {
			return ($variants ? [md5('')] : md5(''));
		}
	
		$string = $matches[1];
	
		if (true === $variants) {
			$patternStarts = [];
	
			for ($i = strlen($string); $i >= 1; --$i) {
				$string          = substr($string, 0, $i);
				$patternStarts[] = md5($string);
			}
	
			// Add empty pattern start to include patterns that start with "*",
			// e.g. "*FAST Enterprise Crawler*"
			$patternStarts[] = md5('');
	
			return $patternStarts;
		}
	
		return md5($string);
	}
	
	/**
	 * Gets the subkey for the pattern, generated from the given string
	 *
	 * @param  string $string
	 * @return string
	 */
	public static function GetPatternCacheSubkey($string)
	{
		return $string[0] . $string[1];
	}
	
	/**
	 * Gets the sub key for the pattern, generated from the given string
	 *
	 * @param  string $string
	 * @return string
	 */
	public static function GetPartCacheSubKey($string)
	{
		return $string[0] . $string[1] . $string[2];
	}
	
	/**
	 * Converts browscap match patterns into preg match patterns.
	 *
	 * @param string $user_agent
	 * @param string $delimiter
	 *
	 * @return string
	 */
	public static function PregQuote($user_agent, $delimiter = '/')
	{
		$pattern = preg_quote($user_agent, $delimiter);
	
		// the \\x replacement is a fix for "Der gro\xdfe BilderSauger 2.00u" user agent match
		return str_replace(['\*', '\?', '\\x'], ['.*', '.', '\\\\x'], $pattern);
	}
	
	/**
	 * Reverts the quoting of a pattern.
	 *
	 * @param  string $pattern
	 * @return string
	 */
	public static function PregUnQuote($pattern)
	{
		// Fast check, because most parent pattern like 'DefaultProperties' don't need a replacement
		if (preg_match('/[^a-z\s]/i', $pattern)) {
			// Undo the \\x replacement, that is a fix for "Der gro\xdfe BilderSauger 2.00u" user agent match
			// @source https://github.com/browscap/browscap-php
			$pattern = preg_replace(
				['/(?<!\\\\)\\.\\*/', '/(?<!\\\\)\\./', '/(?<!\\\\)\\\\x/'],
				['\\*', '\\?', '\\x'],
				$pattern
			);
	
			// Undo preg_quote
			$pattern = str_replace(
				[
					'\\\\', '\\+', '\\*', '\\?', '\\[', '\\^', '\\]', '\\$', '\\(', '\\)', '\\{', '\\}', '\\=',
					'\\!', '\\<', '\\>', '\\|', '\\:', '\\-', '\\.', '\\/',
				],
				[
					'\\', '+', '*', '?', '[', '^', ']', '$', '(', ')', '{', '}', '=', '!', '<', '>', '|', ':',
					'-', '.', '/',
				],
				$pattern
			);
		}
	
		return $pattern;
	}
	
	/**
	 * Gets some possible patterns that have to be matched against the user agent. With the given
	 * user agent string, we can optimize the search for potential patterns:
	 * - We check the first characters of the user agent (or better: a hash, generated from it)
	 * - We compare the length of the pattern with the length of the user agent
	 *   (the pattern cannot be longer than the user agent!)
	 *
	 * @param string $userAgent
	 *
	 * @return \Generator
	 */
	private static function GetPatterns($userAgent)
	{
		$starts = self::GetHashForPattern($userAgent, true);
		$length = strlen($userAgent);
	
		// add special key to fall back to the default browser
		$starts[] = str_repeat('z', 32);
	
		// get patterns, first for the given browser and if that is not found,
		// for the default browser (with a special key)
		foreach ($starts as $tmpStart) {
			
			$tmpSubkey = self::GetPatternCacheSubkey($tmpStart);
			
			list($offset, $count) = explode("\t", self::$contentOffsets[$tmpSubkey]);
			$dataSlice = self::ExtractData($offset, $count);
			
			$found = false;
	
			foreach ($dataSlice as $buffer) {
				list($tmpBuffer, $len, $patterns) = explode("\t", $buffer, 3);
	
				if ($tmpBuffer === $tmpStart) {
					if ($len <= $length) {
						yield trim($patterns);
					}
	
					$found = true;
				} elseif ($found === true) {
					break;
				}
			}
		}
	
		yield '';
	}
	
	/**
	 * Extract binary data
	 *
	 * @param integer $offset
	 * @param integer $count
	 * @return array
	 */
	private static function ExtractData($offset, $count)
	{
		$handle = fopen(self::$path, "rb");
		
		fseek($handle, $offset);
		
		$content = [ ];
		for ($i = 0; $i < $count; $i++) {
			$buff = fread($handle, 4);
			$length = unpack("N", $buff)[1];
			
			$content[] = fread($handle, $length);
		}
		
		fclose($handle);
		
		return $content;
	}
	
	/**
	 * Get detail values from pattern given
	 *
	 * @param string $pattern
	 * @return array
	 */
	private static function GetDetail($pattern)
	{		
		// The pattern has been pre-quoted on generation to speed up the pattern search,
		// but for this check we need the unquoted version
		$unquotedPattern = self::PregUnQuote($pattern);
		
		$pattern     = strtolower($unquotedPattern);
		$patternhash = md5($pattern);
		$subkey      = self::GetPartCacheSubKey($patternhash);
		
		list($offset, $count) = explode("\t", self::$detailOffsets[$subkey]);
		$data = self::ExtractData($offset, $count);
		
		$return = [];
		
		foreach ($data as $buffer) {
			list($tmpBuffer, $values) = explode("\t", $buffer, 2);
			
			if ($tmpBuffer === $patternhash) {
				$values = explode("\n", $values);
				
				foreach ($values as $pair) {
					$pair = explode("=", $pair);
					$return[$pair[0]] = $pair[1];
				}
				
				break;
			}
		}
		
		if (isset($return["Parent"])) {
			$return = array_merge(self::GetDetail(self::PregQuote($return["Parent"])), $return);
		}
		unset($return["Parent"]);
		
		return $return;
	}
}




