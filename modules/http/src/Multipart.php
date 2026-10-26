<?php

namespace RageKit\Http;

/**
 * A multipart/form-data body, as an HTML form with file inputs sends it:
 *
 *     $form = (new Multipart())
 *         ->field('caption', 'Holiday')
 *         ->file('images[]', 'beach.jpg', $bytes, 'image/jpeg');
 *     Http::post($url, '')->multipart($form)->send(…);
 *
 * Values and file contents are any bytes. Quotes and line breaks in names and file names are
 * percent-encoded, as browsers do.
 */
final class Multipart
{
    private string $boundary;

    /** @var list<array{0: string, 1: string, 2: string, 3: string}> name, file name ('' = a field), type, content */
    private array $parts = [];

    public function __construct()
    {
        $this->boundary = 'ragekit-' . bin2hex(random_bytes(16));
    }

    public function field(string $name, string $value): Multipart
    {
        $this->parts[] = [$name, '', '', $value];
        return $this;
    }

    /** A file part; the type defaults to application/octet-stream. */
    public function file(string $name, string $filename, string $content, string $type = 'application/octet-stream'): Multipart
    {
        $this->parts[] = [$name, $filename === '' ? 'file' : $filename, $type, $content];
        return $this;
    }

    public function boundary(): string
    {
        return $this->boundary;
    }

    public function contentType(): string
    {
        return 'multipart/form-data; boundary=' . $this->boundary;
    }

    public function body(): string
    {
        $body = '';
        foreach ($this->parts as $part) {
            [$name, $filename, $type, $content] = $part;
            if (str_contains($content, '--' . $this->boundary)) {
                // 16 random bytes make this practically impossible; never send a broken body anyway.
                throw new \RuntimeException('a multipart part contains the boundary');
            }
            $body .= '--' . $this->boundary . "\r\n" . 'Content-Disposition: form-data; name="' . self::quoted($name) . '"';
            if ($filename !== '') {
                $body .= '; filename="' . self::quoted($filename) . '"' . "\r\nContent-Type: " . str_replace(["\r", "\n"], '', $type);
            }
            $body .= "\r\n\r\n" . $content . "\r\n";
        }
        return $body . '--' . $this->boundary . "--\r\n";
    }

    private static function quoted(string $text): string
    {
        return str_replace(['"', "\r", "\n"], ['%22', '%0D', '%0A'], $text);
    }
}
