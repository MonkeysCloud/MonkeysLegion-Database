<?php

declare(strict_types=1);

namespace MonkeysLegion\Database\Tests\Cache\Utils;

use MonkeysLegion\Database\Cache\Enum\Constants;
use PHPUnit\Framework\TestCase;

class CacheKeyValidatorTest extends TestCase
{
    /** @var array<string, string> */
    private array $getInvalidCharacterExamples = [
        'curly braces' => 'key{with}braces',
        'parentheses' => 'key(with)parens',
        'at symbol' => 'key@symbol',
        'colon' => 'key:colon',
        'space' => 'key with space',
        'backslash' => 'key\\backslash',
        'forward slash' => 'key/slash',
    ];

    /** @var array<string, string> */
    private array $getValidCharacterExamples = [
        'underscore' => 'valid_key',
        'hyphen' => 'valid-key',
        'dot' => 'valid.key',
        'alphanumeric' => 'validkey123',
    ];

    public function testValidationPatternConstant(): void
    {
        $pattern = Constants::CACHE_KEY_VALIDATION_PATTERN;

        // Test invalid characters are detected
        foreach ($this->getInvalidCharacterExamples as $example) {
            $this->assertEquals(1, preg_match($pattern, $example));
        }

        // Test valid characters are allowed
        foreach ($this->getValidCharacterExamples as $example) {
            $this->assertEquals(0, preg_match($pattern, $example));
        }
    }
}
