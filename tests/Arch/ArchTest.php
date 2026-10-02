<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Architecture rules (SOMITI_SPEC.md §3, §6.1)
|--------------------------------------------------------------------------
*/

arch()->preset()->php();

arch()->preset()->security()->ignoring('App\Providers');

arch('application code declares strict types')
    ->expect('App')
    ->toUseStrictTypes();

arch('domain classes are final')
    ->expect('App\Domain')
    ->classes()
    ->toBeFinal();

test('money code contains no floats or float rounding helpers', function (): void {
    $forbiddenFunctions = ['round', 'floor', 'ceil', 'number_format', 'floatval', 'fdiv'];
    $violations = [];

    foreach (moneySensitiveFiles() as $file) {
        $tokens = array_values(array_filter(
            PhpToken::tokenize((string) file_get_contents($file)),
            fn (PhpToken $token): bool => ! $token->isIgnorable(),
        ));

        foreach ($tokens as $index => $token) {
            $name = strtolower(ltrim($token->text, '\\'));
            $previous = $tokens[$index - 1] ?? null;
            $next = $tokens[$index + 1] ?? null;

            $isFloatType = $token->is(T_STRING) && $name === 'float';
            $isFloatCast = $token->is(T_DOUBLE_CAST);
            $isFloatLiteral = $token->is(T_DNUMBER);
            $isForbiddenCall = $token->is([T_STRING, T_NAME_FULLY_QUALIFIED])
                && in_array($name, $forbiddenFunctions, true)
                && $next?->text === '('
                && ! $previous?->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION]);

            if ($isFloatType || $isFloatCast || $isFloatLiteral || $isForbiddenCall) {
                $violations[] = sprintf('%s:%d `%s`', $file, $token->line, $token->text);
            }
        }
    }

    expect($violations)->toBe([]);
});

test('filament classes never write models directly', function (): void {
    $forbidden = '/(?<!\$this)->(save|update|delete|forceDelete|insert|increment|decrement)\s*\(|::(create|insert|query\(\)->update)\s*\(|\bDB::/';
    $violations = [];

    foreach (phpFilesIn(appDirectory('Filament')) as $file) {
        foreach (file($file) ?: [] as $number => $line) {
            if (preg_match($forbidden, $line) === 1) {
                $violations[] = sprintf('%s:%d', $file, $number + 1);
            }
        }
    }

    expect($violations)->toBe([]);
});

/**
 * @return list<string>
 */
function moneySensitiveFiles(): array
{
    return [...phpFilesIn(appDirectory('Domain')), ...phpFilesIn(appDirectory('Support/Money'))];
}

/**
 * @return list<string>
 */
function phpFilesIn(string $directory): array
{
    if (! is_dir($directory)) {
        return [];
    }

    $files = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }

    sort($files);

    return $files;
}

function appDirectory(string $path): string
{
    return dirname(__DIR__, 2).'/app/'.$path;
}
