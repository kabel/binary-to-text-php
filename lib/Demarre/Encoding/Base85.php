<?php
/**
 * Binary-to-text PHP Utilities
 *
 * @package     binary-to-text-php
 * @link        https://github.com/kabel/binary-to-text-php
 * @author      Kevin Abel
 * @copyright   2013 Kevin Abel
 * @license     http://opensource.org/licenses/MIT  MIT
 */

namespace Demarre\Encoding;

use \InvalidArgumentException;

/**
 * Class for binary-to-text encoding with a base of 85
 *
 * It employs an algorithm that encodes 4 bytes of binary
 * data into 5 RADIX-85 characters.
 *
 * @package binary-to-text-php
 */
class Base85 extends EncodingAbstract
{
    const RADIX = 85;
    const RADIX_BYTE = 256;
    
    const ENCODE_BYTES  = 5;
    const DECODE_BYTES  = 4;
    const DECODE_MAX    = 0xffffffff;

    const ALPHABET_ASCII_START   = '!';
    const ALPHABET_ASCII_END     = 'u';
    const ALPHABET_SYM_Z         = '.-:+=^!/*?&<>()[]{}@%$#';
    const ALPHABET_SYM_RFC_1924  = '!#$%&()*+-;<=>?@^_`{|}~';

    const PAD_RAW      = "\0";
    const PAD_ENCODED  = 84;

    const EXCEPTION_Z  = 0;
    const EXCEPTION_Y  = 0x20202020;

    const ADOBE_PREFIX   = '<~';
    const ADOBE_POSTFIX  = '~>';

    const BTOA_PREFIX   = 'xbtoa Begin';
    const BTOA_POSTFIX  = 'xbtoa End';

    /**
     * Generates data length and checksum values used in BTOA encoding.
     *
     * @param string $value  A binary string used in encoding
     * @return array  An array of the five strings
     *     (size-decimal, size-hexadecimal, XOR, Checksum, ROT sum)
     */
    public static function generateGuards($value)
    {
        $size = strlen($value);

        // Check xor, sum, rot
        $check_xor = $check_sum = $check_rot = 0;
        for ($i = 0; $i < $size; $i++) {
            $c = ord($value[$i]);
            $check_xor ^= $c;
            $check_sum += $c + 1;
            $check_rot <<= 1;
            if ($check_rot & 0x80000000) {
                $check_rot += 1;
            }
            $check_rot += $c;
        }

        return array(
            sprintf('%0.0f', $size),
            sprintf('%x', $size),
            sprintf('%x', $check_xor),
            sprintf('%x', $check_sum),
            sprintf('%x', $check_rot)
        );
    }

    /**
     * Parses a fully encoded BTOA string for the original data length
     * and checksum values.
     *
     * @param string $value  A fully encoded BTOA string
     * @throws InvalidArgumentException  for unrocognized strings
     * @return array  An array of the five strings
     *     (size-decimal, size-hexadecimal, XOR, Checksum, ROT sum)
     */
    public static function getGuards($value)
    {
        $line = strrpos($value, EncodingInterface::EOL);
        if ($line === false) {
            throw new InvalidArgumentException('Invalid ASCII85 BTOA encoded data');
        }

        $guardLine = trim(substr($value, $line + 1));
        $hexDigits = '[0-9a-f]+';
        $pattern = '/^' . self::BTOA_POSTFIX . '[ ]+'
            . 'N[ ]+(\d+)[ ]+(' . $hexDigits .')[ ]+'
            . 'E[ ]+(' . $hexDigits . ')[ ]+'
            . 'S[ ]+(' . $hexDigits . ')[ ]+'
            . 'R[ ]+(' . $hexDigits . ')'
            . '$/i';

        if (!preg_match($pattern, $guardLine, $match)) {
            throw new InvalidArgumentException('Invalid ASCII85 BTOA encoded data');
        }

        return array(
            $match[1],
            $match[2],
            $match[3],
            $match[4],
            $match[5]
        );
    }

    /**
     * Checks if the passed guard values from BTOA encoding
     * match the guard values generated by the passed string
     *
     * @param string $value  The decoded binary string
     * @param array $guards  The guard values from the encoded string
     * @return boolean
     */
    public static function validateGuards($value, $guards)
    {
        $actualGuards =  self::generateGuards($value);
        return $actualGuards === $guards;
    }

    protected $_exceptions;

    /**
     * Constructor
     *
     * @param string $chars
     * @param string $padFinalGroup
     * @param array $exceptions
     * @throws InvalidArgumentException
     */
    public function __construct($chars, $padFinalGroup = false, $exceptions = array())
    {
        // Ensure validity of $chars
        if (strlen($chars) !== self::RADIX) {
            throw new InvalidArgumentException('$chars must be a string of 85 characters');
        }

        // Ensure validity of exceptions array
        foreach ($exceptions as $char) {
            if (strlen($char) > 1) {
                throw new InvalidArgumentException('$exceptions must be a hashmap of 32-bit integer values to a single character');
            }
        }

        $this->_chars = $chars;
        $this->_exceptions = $exceptions;
        $this->_padFinalGroup = $padFinalGroup;
    }

    /**
     * Returns the 5 RADIX-85 characters from a passed 4 byte group
     *
     * @param int $group  A 32-bit unsign integer
     * @return string
     */
    protected function encodeGroup($group)
    {
        $encodedString = '';
        $chars = $this->_chars;
        $i = self::ENCODE_BYTES;
        $divisor = pow(self::RADIX, $i - 1);

        for (; $i > 0; $i--) {
            $value = $group / $divisor % self::RADIX;
            $encodedString .= $chars[$value];
            $divisor /= self::RADIX;
        }

        return $encodedString;
    }

    public function encode($rawString)
    {
        //pad to 4 byte groups
        $length = $origLength = strlen($rawString);
        if ($i = $origLength % self::DECODE_BYTES) {
            for (; $i < self::DECODE_BYTES; $i++) {
                $rawString .= self::PAD_RAW;
                $length++;
            }
        }

        $encodedString = '';
        $encodedLength = $length * self::ENCODE_BYTES / self::DECODE_BYTES;

        $exceptions = $this->_exceptions;

        // Unpack string into an array of 32-bit unsigned integers
        $groups = unpack('N*', $rawString);
        $group = array_shift($groups);
        while (!is_null($group)) {
            if (!empty($exceptions) && isset($exceptions[$group])) {
                $encodedString .= $exceptions[$group];
            } else {
                $encodedString .= $this->encodeGroup($group);
            }

            $group = array_shift($groups);
        }


        if ($length != $origLength && !$this->_padFinalGroup) {
            $encodedString = substr($encodedString, 0, $origLength - $length);
        }

        return $encodedString;
    }

    /**
     * Return the binary characters from an encoded RADIX-85 quintet
     *
     * @param int $group  Sum of a RADIX-85 quintet
     * @return string|false  The binary string or false for an invalid quintet
     */
    protected function decodeGroup($group)
    {
        // check for invalid decoded character (overflow)
        if ($group < 0 || $group > self::DECODE_MAX) {
            return false;
        }
        
        $rawString = '';
        $i = self::DECODE_BYTES;
        $divisor = pow(self::RADIX_BYTE, $i - 1);

        for (; $i > 0; $i--) {
            $rawString .= chr($group / $divisor % self::RADIX_BYTE);
            $divisor /= self::RADIX_BYTE;
        }

        return $rawString;
    }

    public function decode($encodedString)
    {
        if (!is_string($encodedString) || !strlen($encodedString)) {
            // Empty string, nothing to decode
            return '';
        }

        $chars = $this->_chars;
        $exceptions = array_flip($this->_exceptions);

        $length = $origLength = strlen($encodedString);

        $rawString = '';

        // Get index of encoded characters
        if ($this->_charmap) {
            $charmap = $this->_charmap;
        } else {
            $charmap = array();

            for ($i = 0; $i < self::RADIX; $i++) {
                $charmap[$chars[$i]] = $i;
            }

            $this->_charmap = $charmap;
        }

        $group = $i = $j = 0;
        while ($i < $origLength) {
            if (isset($charmap[$encodedString[$i]])) {
                $group = $group * self::RADIX + $charmap[$encodedString[$i++]];
                $j++;

                if ($j % self::ENCODE_BYTES == 0) {
                    $quartet = $this->decodeGroup($group);
                    
                    if ($quartet === false) {
                        return null;
                    }
                    
                    $rawString .= $quartet;
                    $group = 0;
                }
            } elseif (isset($exceptions[$encodedString[$i]])) {
                // check for the exception only at quintet start
                if ($j % self::ENCODE_BYTES != 0) {
                    return null;
                }

                $rawString .= $this->decodeGroup($exceptions[$encodedString[$i++]]);
                // increment the length to compinsate for the compressed exception
                $length += 4;
            } else {
                return null;
            }
        }

        
        $origLength = $length;
        
        // check for leftover bits due to exceptions and padding
        if ($i = $length % self::ENCODE_BYTES) {
            for (; $i < self::ENCODE_BYTES; $i++) {
                $group = $group * self::RADIX + self::PAD_ENCODED;
                $origLength--;
            }
            
            $quartet = $this->decodeGroup($group);
            
            if ($quartet === false) {
                return null;
            }

            $rawString .= $this->decodeGroup($group);
        }

        if ($length != $origLength && !$this->_padFinalGroup) {
            $rawString = substr($rawString, 0, $origLength - $length);
        }

        return $rawString;
    }

    public function clean($value, $type = null)
    {
        switch ($type)
        {
            case Scheme::BASE85_ADOBE:
                if (substr($value, 0, 2) == self::ADOBE_PREFIX) {
                    $value = substr($value, 2);
                }

                if (substr($value, 0, -2) == self::ADOBE_POSTFIX) {
                    $value = substr($value, 0, -2);
                }
                break;
            case Scheme::BASE85_BTOA:
                $line = strpos($value, EncodingInterface::EOL);
                if ($line !== false) {
                    $value = substr($value, $line + 1);

                    $line = strrpos($value, EncodingInterface::EOL);
                    $value = substr($value, 0, $line);
                }
                break;
        }

        return parent::clean($value);
    }

    public function format($value, $length = 0, $type = null, $original = '')
    {
        switch ($type) {
            case Scheme::BASE85_ADOBE:
                $value = self::ADOBE_PREFIX . $value . self::ADOBE_POSTFIX;
                break;
            case Scheme::BASE85_BTOA:
                list($size_dec, $size_hex, $check_xor, $check_sum, $check_rot) = self::generateGuards($original);
                $value = parent::format($value, $length);
                return sprintf(
                    self::BTOA_PREFIX . EncodingInterface::EOL . '%s' . EncodingInterface::EOL
                        . self::BTOA_POSTFIX . ' N %s %s E %s S %s R %s' . EncodingInterface::EOL,
                    $value,
                    $size_dec,
                    $size_hex,
                    $check_xor,
                    $check_sum,
                    $check_rot
                );
        }

        return parent::format($value, $length);
    }
}