<?php

declare(strict_types=1);

/*
| Every user-facing string exists in both Bangla and English (project rule 12), with the same
| :placeholders, so switching language never shows a raw key or drops an amount or a name.
*/

/**
 * @param  array<array-key, mixed>  $strings
 * @return array<string, string> dotted key => text
 */
function flattenTranslations(array $strings, string $prefix = ''): array
{
    $flat = [];

    foreach ($strings as $key => $value) {
        $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

        if (is_array($value)) {
            $flat += flattenTranslations($value, $path);
        } else {
            $flat[$path] = (string) $value;
        }
    }

    return $flat;
}

/**
 * @return list<string>
 */
function placeholdersIn(string $text): array
{
    preg_match_all('/:([a-z_]+)/', $text, $matches);
    $names = array_values(array_unique($matches[1]));
    sort($names);

    return $names;
}

/**
 * @return array<string, array{0: string}>
 */
function translationFiles(): array
{
    $files = [];

    foreach (array_unique([...glob(__DIR__.'/../../../lang/en/*.php') ?: [], ...glob(__DIR__.'/../../../lang/bn/*.php') ?: []]) as $path) {
        $files[basename($path)] = [basename($path)];
    }

    ksort($files);

    return $files;
}

it('has every key in both languages with the same placeholders', function (string $file): void {
    $root = __DIR__.'/../../../lang';

    expect(is_file("{$root}/en/{$file}"))->toBeTrue("lang/en/{$file} is missing")
        ->and(is_file("{$root}/bn/{$file}"))->toBeTrue("lang/bn/{$file} is missing");

    $en = flattenTranslations(require "{$root}/en/{$file}");
    $bn = flattenTranslations(require "{$root}/bn/{$file}");

    expect(array_keys(array_diff_key($en, $bn)))->toBe([], "keys missing in lang/bn/{$file}")
        ->and(array_keys(array_diff_key($bn, $en)))->toBe([], "keys missing in lang/en/{$file}");

    $mismatched = [];

    foreach ($en as $key => $text) {
        if (placeholdersIn($text) !== placeholdersIn($bn[$key])) {
            $mismatched[] = $key;
        }
    }

    expect($mismatched)->toBe([], "placeholders differ in {$file}");
})->with(translationFiles());
