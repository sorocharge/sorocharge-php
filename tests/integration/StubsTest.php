<?php

declare(strict_types=1);

namespace Sorocharge\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Keeps php/stubs/sorocharge.stub.php honest: every class and function the
 * loaded extension defines (test hooks aside) must appear in the stub with
 * the same parent, finality, methods, and parameter names, and vice versa.
 */
final class StubsTest extends TestCase
{
    private const STUB = __DIR__ . '/../../php/stubs/sorocharge.stub.php';

    /** @return array{classes: array<string, array<string, mixed>>, functions: array<string, list<string>>} */
    private static function extensionSurface(): array
    {
        $ext = new \ReflectionExtension('sorocharge');
        $classes = [];
        foreach ($ext->getClasses() as $class) {
            $methods = [];
            foreach ($class->getMethods() as $method) {
                if ($method->getDeclaringClass()->getName() !== $class->getName()) {
                    continue;
                }
                $methods[$method->getName()] = array_map(static fn ($p) => $p->getName(), $method->getParameters());
            }
            ksort($methods);
            $classes[$class->getName()] = [
                'parent' => $class->getParentClass() ? $class->getParentClass()->getName() : null,
                'final' => $class->isFinal(),
                'methods' => $methods,
            ];
        }
        $functions = [];
        foreach ($ext->getFunctions() as $function) {
            if (str_starts_with($function->getName(), 'Sorocharge\\Internal\\')) {
                continue; // test-hooks only; never in a shipped build
            }
            $functions[$function->getName()] = array_map(static fn ($p) => $p->getName(), $function->getParameters());
        }
        ksort($classes);
        ksort($functions);
        return ['classes' => $classes, 'functions' => $functions];
    }

    /** Parses the stub's declarations without loading it (the names are taken). */
    private static function stubSurface(): array
    {
        $tokens = \PhpToken::tokenize(file_get_contents(self::STUB));
        $namespace = '';
        $classes = [];
        $functions = [];
        $currentClass = null;
        $depth = 0;
        $classDepth = null;
        $final = false;
        $n = count($tokens);

        $nextName = static function (int $i) use ($tokens, $n): array {
            for ($j = $i + 1; $j < $n; $j++) {
                if (in_array($tokens[$j]->id, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                    return [$tokens[$j]->text, $j];
                }
            }
            throw new \LogicException('name expected');
        };
        $params = static function (int $i) use ($tokens, $n): array {
            $names = [];
            $paren = 0;
            for ($j = $i; $j < $n; $j++) {
                if ($tokens[$j]->text === '(') {
                    $paren++;
                } elseif ($tokens[$j]->text === ')') {
                    if (--$paren === 0) {
                        break;
                    }
                } elseif ($paren === 1 && $tokens[$j]->id === T_VARIABLE) {
                    $names[] = substr($tokens[$j]->text, 1);
                }
            }
            return $names;
        };
        $resolve = static function (string $name) use (&$namespace): string {
            return str_starts_with($name, '\\') ? substr($name, 1) : ($namespace === '' ? $name : "$namespace\\$name");
        };

        for ($i = 0; $i < $n; $i++) {
            $t = $tokens[$i];
            if ($t->id === T_NAMESPACE) {
                [$namespace] = $nextName($i);
            } elseif ($t->id === T_FINAL) {
                $final = true;
            } elseif ($t->id === T_CLASS) {
                [$name, $i] = $nextName($i);
                $currentClass = $resolve($name);
                $parent = null;
                if ($tokens[$i + 2]->id === T_EXTENDS) {
                    [$parentName, $i] = $nextName($i + 2);
                    $parent = $resolve($parentName);
                }
                $classes[$currentClass] = ['parent' => $parent, 'final' => $final, 'methods' => []];
                $final = false;
                $classDepth = $depth;
            } elseif ($t->id === T_FUNCTION) {
                [$name, $j] = $nextName($i);
                if ($currentClass !== null) {
                    $classes[$currentClass]['methods'][$name] = $params($j);
                } else {
                    $functions[$resolve($name)] = $params($j);
                }
                $i = $j;
            } elseif ($t->text === '{') {
                $depth++;
            } elseif ($t->text === '}') {
                $depth--;
                if ($currentClass !== null && $depth === $classDepth) {
                    $currentClass = null;
                }
            }
        }
        foreach ($classes as &$class) {
            ksort($class['methods']);
        }
        ksort($classes);
        ksort($functions);
        return ['classes' => $classes, 'functions' => $functions];
    }

    public function testStubMatchesTheLoadedExtension(): void
    {
        self::assertEquals(self::extensionSurface(), self::stubSurface());
    }
}
