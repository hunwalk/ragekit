<?php

declare(strict_types=1);

namespace RageKit\Cli\Kits;

use RageKit\Cli\Kits\Kit;
use RageKit\Cli\Kits\KitRegistry;
use RageKit\Cli\RageKitException;

/**
 * `ragekit eject`: copies an installed kit into the app (kits/<name>/) to be customised freely, and
 * makes it the app's kit. Its components are renamed (ShadButton → GalleryButton) so the copy never
 * clashes with the original, which stays installed (Material is still the fallback for elements a
 * kit lacks). Code a kit fetches (kit.yml `fetch:`) isn't copied: the build fetches it again.
 */
final class KitEjector
{
    public function __construct(private readonly string $rageKitRoot)
    {
    }

    /** @return array<string, string> installed kits: name => folder */
    public function kits(string $projectDir): array
    {
        $registry = KitRegistry::discover($this->rageKitRoot, $projectDir);
        $out = [];
        foreach ($registry->names() as $name) {
            $out[$name] = $registry->get($name)->dir;
        }
        return $out;
    }

    public function eject(string $projectDir, string $kitName, ?string $name = null, bool $use = true): EjectResult
    {
        $registry = KitRegistry::discover($this->rageKitRoot, $projectDir);
        $kit = $registry->get($kitName);
        if ($name === null) {
            $name = self::projectName($projectDir);
            if (!self::validName($name)) {
                throw new RageKitException("the project's name '$name' can't be a kit name; give one: ragekit eject $kitName <name>");
            }
        } elseif (!self::validName($name)) {
            throw new RageKitException("'$name' can't be a kit name: use lowercase letters, digits and dashes, starting with a letter");
        }
        $target = rtrim($projectDir, '/') . "/kits/$name";
        if (file_exists($target)) {
            throw new RageKitException("kits/$name already exists; remove it or pick another name");
        }
        if ($registry->find($name) !== null) {
            throw new RageKitException("a kit named '$name' is already installed; pick another name: ragekit eject $kitName <name>");
        }

        $skip = array_map(fn($dependency) => rtrim((string) $dependency->target, '/'), $kit->fetch);
        self::copy(rtrim($kit->dir, '/'), $target, $skip);
        [$renamed, $fromPrefix, $toPrefix] = $this->rename($kit, $target, $name);
        $gitignore = null;
        foreach ($skip as $fetched) {
            if (str_starts_with($fetched, rtrim($kit->dir, '/') . '/')) {
                $gitignore = "kits/$name/" . substr($fetched, strlen(rtrim($kit->dir, '/')) + 1) . '/';
            }
        }
        if ($use) {
            self::useKit("$projectDir/project.yml", $name);
        }
        return new EjectResult($kitName, $name, $target, $renamed, $use, $gitignore, $fromPrefix, $toPrefix);
    }

    private static function validName(string $name): bool
    {
        return preg_match('/^[a-z][a-z0-9-]*$/', $name) === 1;
    }

    private static function projectName(string $projectDir): string
    {
        $yml = @file_get_contents("$projectDir/project.yml");
        if ($yml !== false && preg_match('/^name:\s*["\']?([^"\'#\n]*?)["\']?\s*(#.*)?$/m', $yml, $m) === 1 && $m[1] !== '') {
            return $m[1];
        }
        return basename($projectDir);
    }

    /** @param list<string> $skip folders not to copy (fetched code) */
    private static function copy(string $from, string $to, array $skip): void
    {
        @mkdir($to, 0777, true);
        foreach (scandir($from) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $source = "$from/$entry";
            if (in_array($source, $skip, true)) {
                continue;
            }
            if (is_dir($source)) {
                self::copy($source, "$to/$entry", $skip);
            } else {
                copy($source, "$to/$entry");
            }
        }
    }

    /**
     * Renames every component, global, struct and enum the kit's .slint files declare from the kit's
     * prefix (Shad…, Rk…) to one from the new name (Gallery…), in the .slint files and kit.yml; sets
     * kit.yml's name and notes where the copy came from.
     *
     * @return array{0: array<string, string>, 1: string, 2: string} old name => new name, old prefix, new prefix
     */
    private function rename(Kit $kit, string $dir, string $name): array
    {
        $slintFiles = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() === 'slint') {
                $slintFiles[] = $file->getPathname();
            }
        }
        $declared = [];
        foreach ($slintFiles as $file) {
            preg_match_all('/\b(?:component|global|struct|enum)\s+([A-Z]\w*)/', (string) file_get_contents($file), $m);
            $declared = [...$declared, ...$m[1]];
        }
        $components = array_values(array_filter([...array_map(fn(ElementSpec $e) => $e->component, $kit->elements), (string) $kit->window]));
        $prefix = preg_match('/^[A-Z][a-z0-9]*/', $components[0] ?? '', $m) === 1 ? $m[0] : '';
        foreach ($components as $component) {
            if (!str_starts_with($component, $prefix)) {
                $prefix = '';
            }
        }
        $newPrefix = implode('', array_map('ucfirst', explode('-', $name)));
        $renamed = [];
        foreach (array_unique($declared) as $old) {
            if ($prefix === '' || str_starts_with($old, $prefix)) {
                $renamed[$old] = $newPrefix . substr($old, strlen($prefix));
            }
        }
        uksort($renamed, fn(string $a, string $b) => strlen($b) <=> strlen($a));
        $replace = function (string $text) use ($renamed): string {
            foreach ($renamed as $old => $new) {
                $text = (string) preg_replace('/\b' . preg_quote($old, '/') . '\b/', $new, $text);
            }
            return $text;
        };
        foreach ($slintFiles as $file) {
            file_put_contents($file, $replace((string) file_get_contents($file)));
        }
        $yml = $replace((string) file_get_contents("$dir/kit.yml"));
        $yml = (string) preg_replace('/^name:.*$/m', "name: $name", $yml, 1);
        $header = "# Ejected from the {$kit->name} kit of RageKit {$this->version()}: this copy is your app's code now.\n"
            . "# Edit its .slint files freely (ragekit dev reloads them); RageKit updates to {$kit->name} won't reach it.\n";
        file_put_contents("$dir/kit.yml", $header . $yml);
        return [$renamed, $prefix, $newPrefix];
    }

    /** The RageKit release this checkout is at, from its changelog. */
    private function version(): string
    {
        $changelog = (string) @file_get_contents("$this->rageKitRoot/CHANGELOG.md");
        $unreleased = str_contains($changelog, '## [Unreleased]');
        if (preg_match('/^## \[(\d+\.\d+\.\d+)\]/m', $changelog, $m) === 1) {
            return $m[1] . ($unreleased ? '+' : '');
        }
        return 'dev';
    }

    /** Sets `ragekit: kit: <name>` in project.yml, editing the text so comments and layout stay. */
    private static function useKit(string $file, string $name): void
    {
        $lines = explode("\n", (string) file_get_contents($file));
        $block = null;
        foreach ($lines as $i => $line) {
            if (preg_match('/^ragekit:\s*(#.*)?$/', $line) === 1) {
                $block = $i;
                break;
            }
        }
        if ($block === null) {
            $text = rtrim(implode("\n", $lines), "\n");
            file_put_contents($file, ($text === '' ? '' : "$text\n") . "ragekit:\n  kit: $name\n");
            return;
        }
        $indent = '  ';
        for ($i = $block + 1; $i < count($lines); $i++) {
            $line = $lines[$i];
            if (trim($line) === '' || str_starts_with(ltrim($line), '#')) {
                continue;
            }
            if (!preg_match('/^(\s+)/', $line, $m)) {
                break; // the block ended
            }
            $indent = $m[1];
            if (preg_match('/^(\s+kit:\s*)[^#\s]*(.*)$/', $line, $kit) === 1) {
                $lines[$i] = $kit[1] . $name . $kit[2];
                file_put_contents($file, implode("\n", $lines));
                return;
            }
        }
        array_splice($lines, $block + 1, 0, [$indent . "kit: $name"]);
        file_put_contents($file, implode("\n", $lines));
    }
}
