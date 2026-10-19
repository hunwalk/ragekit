<?php

/**
 * Subprocess of ClassScanner: loads RageKit core + the app's PHP files (through an autoloader
 * built from their class declarations, so nothing runs) and prints, as JSON, what templates may
 * use from every concrete Component subclass.
 *
 * Usage: php introspect.php <input.json>   input: {"rageKitRoot": "...", "files": ["..."]}
 */

declare(strict_types=1);

$input = json_decode((string) file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
$root = $input['rageKitRoot'];
$projectDir = $input['projectDir'];

$map = [
    'RageKit\\App' => "$root/src/App.php",
    'RageKit\\Backend' => "$root/src/Backend.php",
    'RageKit\\Component' => "$root/src/Component.php",
    'RageKit\\Screen' => "$root/src/Screen.php",
    'RageKit\\Layout' => "$root/src/Layout.php",
];
foreach ($input['files'] as $file) {
    foreach (declaredClasses($file) as $class) {
        $map[$class] = $file;
    }
}
spl_autoload_register(function (string $class) use ($map): void {
    if (isset($map[$class])) {
        require_once $map[$class];
    }
});

$out = [];
foreach (array_keys($map) as $class) {
    if (str_starts_with($class, 'RageKit\\') || !class_exists($class)) {
        continue;
    }
    $reflection = new ReflectionClass($class);
    if ($reflection->isAbstract() || !$reflection->isSubclassOf('RageKit\\Component')) {
        continue;
    }
    $out[] = describe($reflection, $projectDir);
}
echo json_encode($out, JSON_THROW_ON_ERROR);

/** @return list<string> fully qualified names of the classes, interfaces, traits and enums declared in $file */
function declaredClasses(string $file): array
{
    $tokens = token_get_all((string) file_get_contents($file));
    $namespace = '';
    $classes = [];
    $count = count($tokens);
    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];
        if (!is_array($token)) {
            continue;
        }
        if ($token[0] === T_NAMESPACE) {
            $namespace = '';
            for ($j = $i + 1; $j < $count && $tokens[$j] !== ';' && $tokens[$j] !== '{'; $j++) {
                if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_STRING, T_NAME_QUALIFIED], true)) {
                    $namespace .= $tokens[$j][1];
                }
            }
        }
        if (in_array($token[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
            $previous = $i - 1;
            while ($previous >= 0 && is_array($tokens[$previous]) && $tokens[$previous][0] === T_WHITESPACE) {
                $previous--;
            }
            if ($previous >= 0 && is_array($tokens[$previous]) && in_array($tokens[$previous][0], [T_DOUBLE_COLON, T_NEW], true)) {
                continue; // Foo::class or new class
            }
            for ($j = $i + 1; $j < $count; $j++) {
                if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                    $classes[] = ltrim($namespace . '\\' . $tokens[$j][1], '\\');
                    break;
                }
            }
        }
    }
    return $classes;
}

function typeName(?ReflectionType $type): string
{
    if (!$type instanceof ReflectionNamedType) {
        return 'mixed';
    }
    $name = $type->getName() === 'array' ? 'list' : $type->getName();
    return $type->allowsNull() && $name !== 'mixed' ? "?$name" : $name;
}

/** @return array<string, mixed> */
function describe(ReflectionClass $class, string $projectDir): array
{
    $base = ['RageKit\\Component', 'RageKit\\Screen', 'RageKit\\Layout'];
    // What Layout itself offers templates: its maximised flag and window buttons.
    $layoutMembers = ['maximized', 'minimize', 'toggleMaximize', 'close'];
    $inherited = fn(ReflectionProperty|ReflectionMethod $member): bool => in_array($member->getDeclaringClass()->getName(), $base, true)
        && !in_array($member->getName(), $layoutMembers, true);
    $props = [];
    foreach ($class->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
        if (!$property->isStatic() && !$inherited($property)) {
            $props[$property->getName()] = typeName($property->getType());
        }
    }
    $computed = [];
    $handlers = [];
    $required = [];
    foreach ($class->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if ($method->isStatic() || str_starts_with($method->getName(), '__') || $inherited($method)) {
            continue;
        }
        $handlers[$method->getName()] = array_map(fn(ReflectionParameter $p) => typeName($p->getType()), $method->getParameters());
        $required[$method->getName()] = $method->getNumberOfRequiredParameters();
        if ($method->getNumberOfRequiredParameters() === 0) {
            $computed[$method->getName()] = typeName($method->getReturnType());
        }
    }
    $constants = [];
    foreach ($class->getReflectionConstants() as $constant) {
        if (!in_array($constant->getDeclaringClass()->getName(), $base, true)) {
            $constants[$constant->getName()] = $constant->getValue();
        }
    }
    // template() is relative to the project root unless absolute; '' = not declared.
    $declared = (string) $class->getName()::template();
    $absolute = preg_match('~^([A-Za-z]:[\\\\/]|[\\\\/])~', $declared) === 1;
    $template = $declared === '' ? '' : ($absolute ? $declared : rtrim($projectDir, '/\\') . '/' . $declared);
    $isLayout = $class->isSubclassOf('RageKit\\Layout');
    $context = $isLayout ? (string) $class->getName()::context() : '';
    $members = [];
    if ($context !== '' && interface_exists($context)) {
        foreach ((new ReflectionClass($context))->getMethods() as $method) {
            $members[$method->getName()] = [
                'return' => typeName($method->getReturnType()),
                'params' => array_map(fn(ReflectionParameter $p) => typeName($p->getType()), $method->getParameters()),
                'required' => $method->getNumberOfRequiredParameters(),
            ];
        }
    }
    $constructor = $class->getConstructor();
    return [
        'name' => $class->getName(),
        'short' => $class->getShortName(),
        'file' => (string) $class->getFileName(),
        'screen' => $class->isSubclassOf('RageKit\\Screen'),
        'template' => $template,
        'templateDeclared' => $declared,
        'props' => (object) $props,
        'computed' => (object) $computed,
        'handlers' => (object) $handlers,
        'constants' => (object) $constants,
        'requiredParams' => (object) $required,
        'isLayout' => $isLayout,
        'layout' => $class->isSubclassOf('RageKit\\Screen') ? (string) $class->getName()::layout() : '',
        'context' => $context,
        'contextMembers' => (object) $members,
        'interfaces' => $class->getInterfaceNames(),
        'constructible' => $constructor === null || $constructor->getNumberOfRequiredParameters() === 0,
    ];
}
