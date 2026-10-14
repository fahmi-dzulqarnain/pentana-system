<?php

namespace Tests\Feature\Localisation;

use Symfony\Component\Finder\Finder;
use Tests\TestCase;

class TranslationCompletenessTest extends TestCase
{
    use AppTranslationKey;

    /** Directories whose __()/trans()/trans_choice()/@lang() literals must be translated. */
    public const SCAN_PATHS = ['app', 'resources/views'];

    /** @return array<string, list<string>> key => locations */
    private function keysInCode(): array
    {
        $keys = [];
        $files = Finder::create()->files()->in(array_map('base_path', self::SCAN_PATHS))
            ->name(['*.php']);

        foreach ($files as $file) {
            $src = $file->getContents();
            $found = preg_match_all('/(?<![\w>:$])(?:__|trans_choice|trans|@lang)\(\s*([\'"])((?:\\\\.|(?!\1).)*)\1/s', $src, $m, PREG_OFFSET_CAPTURE);
            $this->assertNotFalse($found, 'Regex failed on '.$file->getRelativePathname());
            foreach ($m[2] as [$raw, $offset]) {
                $key = stripcslashes($raw);
                if (self::isAppKey($key)) {
                    $line = substr_count(substr($src, 0, $offset), "\n") + 1;
                    $keys[$key][] = $file->getRelativePathname().':'.$line;
                }
            }
        }

        return $keys;
    }

    /** @return array<string, string> */
    private function translations(string $locale): array
    {
        return json_decode(file_get_contents(lang_path("$locale.json")), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_every_key_in_code_is_translated(): void
    {
        $code = $this->keysInCode();

        foreach (['ms', 'id'] as $locale) {
            $missing = array_diff_key($code, $this->translations($locale));
            $this->assertSame([], array_map(fn ($l) => implode(', ', $l), $missing),
                "Missing from lang/$locale.json (key => where used)");
        }
    }

    public function test_translations_keep_every_placeholder(): void
    {
        // Only app keys: laravel-lang's upstream framework entries may use different placeholder casing.
        foreach (['ms', 'id'] as $locale) {
            $json = $this->translations($locale);
            foreach (array_keys($this->keysInCode()) as $key) {
                if (! isset($json[$key])) {
                    continue; // reported by test_every_key_in_code_is_translated
                }
                preg_match_all('/:[a-zA-Z_]+/', $key, $k);
                preg_match_all('/:[a-zA-Z_]+/', $json[$key], $v);
                // Unique sets: a trans_choice key repeats :count per plural form; ms/id have one form.
                $this->assertEqualsCanonicalizing(array_unique($k[0]), array_unique($v[0]), "lang/$locale.json placeholders differ for: $key");
            }
        }
    }

    public function test_json_files_are_valid_and_values_are_non_empty(): void
    {
        // Whole files: json() throws on invalid JSON; emptiness is checked on every entry.
        foreach (['ms', 'id'] as $locale) {
            foreach ($this->translations($locale) as $key => $value) {
                $this->assertNotSame('', trim($value), "Empty translation in $locale.json for: $key");
            }
        }
    }
}
