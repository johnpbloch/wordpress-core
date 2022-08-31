<?php
/**
 * SimplePie
 *
 * A PHP-Based RSS and Atom Feed Framework.
 * Takes the hard work out of managing a complete RSS/Atom solution.
 *
 * Copyright (c) 2004-2016, Ryan Parman, Sam Sneddon, Ryan McCue, and contributors
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without modification, are
 * permitted provided that the following conditions are met:
 *
 * 	* Redistributions of source code must retain the above copyright notice, this list of
 * 	  conditions and the following disclaimer.
 *
 * 	* Redistributions in binary form must reproduce the above copyright notice, this list
 * 	  of conditions and the following disclaimer in the documentation and/or other materials
 * 	  provided with the distribution.
 *
 * 	* Neither the name of the SimplePie Team nor the names of its contributors may be used
 * 	  to endorse or promote products derived from this software without specific prior
 * 	  written permission.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND ANY EXPRESS
 * OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY
 * AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDERS
 * AND CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR
 * CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR
 * SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY
 * THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR
 * OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 * POSSIBILITY OF SUCH DAMAGE.
 *
 * @package SimplePie
 * @copyright 2004-2016 Ryan Parman, Sam Sneddon, Ryan McCue
 * @author Ryan Parman
 * @author Sam Sneddon
 * @author Ryan McCue
 * @link http://simplepie.org/ SimplePie
 * @license http://www.opensource.org/licenses/bsd-license.php BSD License
 */

/**
 * Miscellanous utilities
 *
 * @package SimplePie
 */
class SimplePie_Misc
{
	public static function time_hms($seconds)
	{
		$time = '';

		$hours = floor($seconds / 3600);
		$remainder = $seconds % 3600;
		if ($hours > 0)
		{
			$time .= $hours.':';
		}

		$minutes = floor($remainder / 60);
		$seconds = $remainder % 60;
		if ($minutes < 10 && $hours > 0)
		{
			$minutes = '0' . $minutes;
		}
		if ($seconds < 10)
		{
			$seconds = '0' . $seconds;
		}

		$time .= $minutes.':';
		$time .= $seconds;

		return $time;
	}

	public static function absolutize_url($relative, $base)
	{
		$iri = SimplePie_IRI::absolutize(new SimplePie_IRI($base), $relative);
		if ($iri === false)
		{
			return false;
		}
		return $iri->get_uri();
	}

	/**
	 * Get a HTML/XML element from a HTML string
	 *
	 * @deprecated Use DOMDocument instead (parsing HTML with regex is bad!)
	 * @param string $realname Element name (including namespace prefix if applicable)
	 * @param string $string HTML document
	 * @return array
	 */
	public static function get_element($realname, $string)
	{
		$return = array();
		$name = preg_quote($realname, '/');
		if (preg_match_all("/<($name)" . SIMPLEPIE_PCRE_HTML_ATTRIBUTE . "(>(.*)<\/$name>|(\/)?>)/siU", $string, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE))
		{
			for ($i = 0, $total_matches = count($matches); $i < $total_matches; $i++)
			{
				$return[$i]['tag'] = $realname;
				$return[$i]['full'] = $matches[$i][0][0];
				$return[$i]['offset'] = $matches[$i][0][1];
				if (strlen($matches[$i][3][0]) <= 2)
				{
					$return[$i]['self_closing'] = true;
				}
				else
				{
					$return[$i]['self_closing'] = false;
					$return[$i]['content'] = $matches[$i][4][0];
				}
				$return[$i]['attribs'] = array();
				if (isset($matches[$i][2][0]) && preg_match_all('/[\x09\x0A\x0B\x0C\x0D\x20]+([^\x09\x0A\x0B\x0C\x0D\x20\x2F\x3E][^\x09\x0A\x0B\x0C\x0D\x20\x2F\x3D\x3E]*)(?:[\x09\x0A\x0B\x0C\x0D\x20]*=[\x09\x0A\x0B\x0C\x0D\x20]*(?:"([^"]*)"|\'([^\']*)\'|([^\x09\x0A\x0B\x0C\x0D\x20\x22\x27\x3E][^\x09\x0A\x0B\x0C\x0D\x20\x3E]*)?))?/', ' ' . $matches[$i][2][0] . ' ', $attribs, PREG_SET_ORDER))
				{
					for ($j = 0, $total_attribs = count($attribs); $j < $total_attribs; $j++)
					{
						if (count($attribs[$j]) === 2)
						{
							$attribs[$j][2] = $attribs[$j][1];
						}
						$return[$i]['attribs'][strtolower($attribs[$j][1])]['data'] = SimplePie_Misc::entities_decode(end($attribs[$j]));
					}
				}
			}
		}
		return $return;
	}

	public static function element_implode($element)
	{
		$full = "<$element[tag]";
		foreach ($element['attribs'] as $key => $value)
		{
			$key = strtolower($key);
			$full .= " $key=\"" . htmlspecialchars($value['data'], ENT_COMPAT, 'UTF-8') . '"';
		}
		if ($element['self_closing'])
		{
			$full .= ' />';
		}
		else
		{
			$full .= ">$element[content]</$element[tag]>";
		}
		return $full;
	}

	public static function error($message, $level, $file, $line)
	{
		if ((ini_get('error_reporting') & $level) > 0)
		{
			switch ($level)
			{
				case E_USER_ERROR:
					$note = 'PHP Error';
					break;
				case E_USER_WARNING:
					$note = 'PHP Warning';
					break;
				case E_USER_NOTICE:
					$note = 'PHP Notice';
					break;
				default:
					$note = 'Unknown Error';
					break;
			}

			$log_error = true;
			if (!function_exists('error_log'))
			{
				$log_error = false;
			}

			$log_file = @ini_get('error_log');
			if (!empty($log_file) && ('syslog' !== $log_file) && !@is_writable($log_file))
			{
				$log_error = false;
			}

			if ($log_error)
			{
				@error_log("$note: $message in $file on line $line", 0);
			}
		}

		return $message;
	}

	public static function fix_protocol($url, $http = 1)
	{
		$url = SimplePie_Misc::normalize_url($url);
		$parsed = SimplePie_Misc::parse_url($url);
		if ($parsed['scheme'] !== '' && $parsed['scheme'] !== 'http' && $parsed['scheme'] !== 'https')
		{
			return SimplePie_Misc::fix_protocol(SimplePie_Misc::compress_parse_url('http', $parsed['authority'], $parsed['path'], $parsed['query'], $parsed['fragment']), $http);
		}

		if ($parsed['scheme'] === '' && $parsed['authority'] === '' && !file_exists($url))
		{
			return SimplePie_Misc::fix_protocol(SimplePie_Misc::compress_parse_url('http', $parsed['path'], '', $parsed['query'], $parsed['fragment']), $http);
		}

		if ($http === 2 && $parsed['scheme'] !== '')
		{
			return "feed:$url";
		}
		elseif ($http === 3 && strtolower($parsed['scheme']) === 'http')
		{
			return substr_replace($url, 'podcast', 0, 4);
		}
		elseif ($http === 4 && strtolower($parsed['scheme']) === 'http')
		{
			return substr_replace($url, 'itpc', 0, 4);
		}

		return $url;
	}

	public static function array_merge_recursive($array1, $array2)
	{
		foreach ($array2 as $key => $value)
		{
			if (is_array($value))
			{
				$array1[$key] = SimplePie_Misc::array_merge_recursive($array1[$key], $value);
			}
			else
			{
				$array1[$key] = $value;
			}
		}

		return $array1;
	}

	public static function parse_url($url)
	{
		$iri = new SimplePie_IRI($url);
		return array(
			'scheme' => (string) $iri->scheme,
			'authority' => (string) $iri->authority,
			'path' => (string) $iri->path,
			'query' => (string) $iri->query,
			'fragment' => (string) $iri->fragment
		);
	}

	public static function compress_parse_url($scheme = '', $authority = '', $path = '', $query = '', $fragment = '')
	{
		$iri = new SimplePie_IRI('');
		$iri->scheme = $scheme;
		$iri->authority = $authority;
		$iri->path = $path;
		$iri->query = $query;
		$iri->fragment = $fragment;
		return $iri->get_uri();
	}

	public static function normalize_url($url)
	{
		$iri = new SimplePie_IRI($url);
		return $iri->get_uri();
	}

	public static function percent_encoding_normalization($match)
	{
		$integer = hexdec($match[1]);
		if ($integer >= 0x41 && $integer <= 0x5A || $integer >= 0x61 && $integer <= 0x7A || $integer >= 0x30 && $integer <= 0x39 || $integer === 0x2D || $integer === 0x2E || $integer === 0x5F || $integer === 0x7E)
		{
			return chr($integer);
		}

		return strtoupper($match[0]);
	}

	/**
	 * Converts a Windows-1252 encoded string to a UTF-8 encoded string
	 *
	 * @static
	 * @param string $str