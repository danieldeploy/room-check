<?php

declare(strict_types = 1);

namespace LanguageDetection;

/**
 * Class Language
 *
 * @copyright Patrick Schur
 * @license https://opensource.org/licenses/mit-license.html MIT
 * @author Patrick Schur <patrick_schur@outlook.de>
 * @package LanguageDetection
 */
class Language extends NgramParser
{
    protected $tokens = [];

    public function __construct(array $lang = [], string $dirname = '')
    {
        if (empty($dirname))
        {
            $dirname = __DIR__ . '/../../resources/*/*.php';
        }
        else if (!\is_dir($dirname) || !\is_readable($dirname))
        {
            throw new \InvalidArgumentException('Provided directory could not be found or is not readable');
        }
        else
        {
            $dirname = \rtrim($dirname, '/');
            $dirname .= '/*/*.php';
        }

        $isEmpty = empty($lang);
        $tokens = [];

        foreach (\glob($dirname) as $file)
        {
            if ($isEmpty || \in_array(\basename($file, '.php'), $lang))
            {
                $tokens += require $file;
            }
        }

        foreach ($tokens as $lang => $value) {
            $this->tokens[$lang] = \array_flip($value);
        }
    }

    public function detect(string $str): LanguageResult
    {
        $str = \mb_strtolower($str);
        $samples = $this->getNgrams($str);
        $result = [];

        if (\count($samples) > 0)
        {
            foreach ($this->tokens as $lang => $value)
            {
                $index = $sum = 0;

                foreach ($samples as $v)
                {
                    if (isset($value[$v]))
                    {
                        $x = $index++ - $value[$v];
                        $y = $x >> (PHP_INT_SIZE * 8);
                        $sum += ($x + $y) ^ $y;
                        continue;
                    }

                    $sum += $this->maxNgrams;
                    ++$index;
                }

                $result[$lang] = 1 - ($sum / ($this->maxNgrams * $index));
            }

            \arsort($result, SORT_NUMERIC);
        }

        return new LanguageResult($result);
    }
}
