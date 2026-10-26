<?php

namespace Fix;

use RageKit\App;
use RageKit\Http\Http;
use RageKit\Http\Multipart;
use RageKit\Http\Response;
use RageKit\Screen;

/** Posts a form with a field and two files to the URL in upload-url.txt, prints the answer, quits. */
final class UploadApp extends App
{
    protected function firstScreen(): Screen
    {
        return new ResultScreen();
    }

    protected function title(): string
    {
        return 'upload';
    }

    protected function start(): void
    {
        $url = trim((string) file_get_contents(App::resolve('upload-url.txt')));
        $bytes = '';
        for ($i = 0; $i < 256; $i++) {
            $bytes .= chr($i);
        }
        $form = (new Multipart())
            ->field('caption', 'Hello "there"')
            ->file('payload', 'payload.json', '{"text":"hi"}', 'application/json')
            ->file('images[]', 'all-bytes.bin', $bytes);
        Http::post($url, '')->multipart($form)->send(function (Response $r): void {
            echo $r->ok() ? $r->body : 'error ' . $r->status . ' ' . $r->error;
            echo "\n";
            $this->quit();
        });
    }
}
