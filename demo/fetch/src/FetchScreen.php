<?php

namespace Demo;

use RageKit\Http\Http;
use RageKit\Http\Request;
use RageKit\Http\Response;
use RageKit\Modules;
use RageKit\Screen;

/** Looks up a repository with GitHub's API and downloads its README (the http module). */
final class FetchScreen extends Screen
{
    public string $repo = 'hunwalk/ragekit';
    public string $status = 'Type owner/name and press Look up.';
    public string $name = '—';
    public string $description = '';
    public string $stats = '';
    public float $downloaded = 0.0;
    public string $downloadStatus = '';
    private ?Request $download = null;

    public static function template(): string
    {
        return 'templates/Fetch.rk';
    }

    public function available(): bool
    {
        return Modules::available('http');
    }

    public function lookUp(): void
    {
        $this->status = 'Looking up ' . $this->repo . '…';
        Http::get('https://api.github.com/repos/' . trim($this->repo))
            ->header('Accept', 'application/vnd.github+json')
            ->timeout(10)
            ->send(function (Response $r): void {
                $this->show($r);
            });
    }

    public function download(): void
    {
        if ($this->download !== null) {
            $this->download->cancel();
        }
        $this->downloaded = 0.0;
        $this->downloadStatus = 'Downloading…';
        $this->download = Http::get('https://raw.githubusercontent.com/' . trim($this->repo) . '/HEAD/README.md')
            ->saveTo('downloads/README.md')
            ->onProgress(function (int $received, int $total): void {
                $this->downloaded = $total > 0 ? $received / $total : 0.5;
                $this->downloadStatus = 'Downloading… ' . $received . ' bytes';
            })
            ->send(function (Response $r): void {
                $this->download = null;
                $this->downloaded = $r->ok() ? 1.0 : 0.0;
                $this->downloadStatus = $r->ok() ? 'Saved to downloads/README.md' : self::problem($r);
            });
    }

    public function cancel(): void
    {
        if ($this->download !== null) {
            $this->download->cancel();
            $this->download = null;
            $this->downloaded = 0.0;
            $this->downloadStatus = 'Cancelled';
        }
    }

    private function show(Response $r): void
    {
        if (!$r->ok()) {
            $this->status = self::problem($r);
            return;
        }
        $data = json_decode($r->body, true);
        if (!is_array($data)) {
            $this->status = 'GitHub sent something that isn\'t JSON';
            return;
        }
        $this->status = 'Found it.';
        $this->name = (string) ($data['full_name'] ?? $this->repo);
        $this->description = self::shorten((string) ($data['description'] ?? ''), 52);
        $this->stats = '★ ' . (string) ($data['stargazers_count'] ?? 0) . '   forks ' . (string) ($data['forks_count'] ?? 0)
            . '   open issues ' . (string) ($data['open_issues_count'] ?? 0);
    }

    /** Labels don't wrap: cut long text (at a UTF-8 character boundary). */
    private static function shorten(string $text, int $bytes): string
    {
        if (strlen($text) <= $bytes) {
            return $text;
        }
        $end = $bytes;
        while ($end > 0 && (ord($text[$end]) & 0xC0) === 0x80) {
            $end--;
        }
        return rtrim(substr($text, 0, $end)) . '…';
    }

    private static function problem(Response $r): string
    {
        return self::shorten($r->error !== '' ? 'Failed: ' . $r->error : 'GitHub answered ' . $r->status, 52);
    }
}
