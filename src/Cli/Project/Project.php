<?php

declare(strict_types=1);

namespace RageKit\Cli\Project;

use RageKit\Cli\RageKitException;
use Symfony\Component\Yaml\Yaml;

/** The app being built: its project.yml, split into tpc settings and the `ragekit:` section. */
final class Project
{
    /**
     * @param array<string, mixed> $config  tpc settings (paths still relative to $dir)
     * @param array<string, mixed> $options the `ragekit:` keys other than `backend`
     */
    private function __construct(
        public readonly string $dir,
        public readonly string $name,
        public readonly string $backend,
        public readonly array $config,
        public readonly array $options,
    ) {
    }

    public static function load(string $dir, ?string $backendOverride = null, ?string $kitOverride = null): self
    {
        $dir = Paths::normalize($dir);
        $file = "$dir/project.yml";
        if (!is_file($file)) {
            throw new RageKitException("no project.yml in $dir");
        }
        $config = Yaml::parseFile($file) ?? [];
        $options = $config['ragekit'] ?? [];
        unset($config['ragekit']);

        $backend = $backendOverride ?? (string) ($options['backend'] ?? 'slint');
        unset($options['backend']);
        if ($kitOverride !== null) {
            $options['kit'] = $kitOverride;
        }

        return new self($dir, (string) ($config['name'] ?? basename($dir)), $backend, $config, $options);
    }

    /** A copy with one `ragekit:` option set, e.g. from a command-line flag. */
    public function withOption(string $name, mixed $value): self
    {
        return new self($this->dir, $this->name, $this->backend, $this->config, [$name => $value] + $this->options);
    }
}
