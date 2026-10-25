<?php

declare(strict_types=1);

namespace RageKit\Tests\Core;

use PHPUnit\Framework\TestCase;
use RageKit\Collection;

class CollectionDevice
{
    public function __construct(public string $name = '')
    {
    }
}

final class CollectionHeadset extends CollectionDevice
{
}

final class CollectionOther
{
}

final class CollectionDevices extends Collection
{
    public const OF = CollectionDevice::class;
}

final class CollectionTest extends TestCase
{
    private function devices(string ...$names): CollectionDevices
    {
        return new CollectionDevices(array_map(fn(string $n) => new CollectionDevice($n), $names));
    }

    /** @return list<string> */
    private static function names(CollectionDevices $c): array
    {
        $out = [];
        foreach ($c as $i => $device) {
            $out[] = "$i:{$device->name}";
        }
        return $out;
    }

    public function testAddCountIterate(): void
    {
        $c = new CollectionDevices();
        $c->add(new CollectionDevice('a'));
        $c->add(new CollectionDevice('b'));
        self::assertCount(2, $c);
        self::assertSame(['0:a', '1:b'], self::names($c));
    }

    public function testArrayAppendAndIndex(): void
    {
        $c = new CollectionDevices();
        $d = new CollectionDevice('a');
        $c[] = $d;
        self::assertSame($d, $c[0]);
        self::assertSame($d, $c->get(0));
    }

    public function testSetReplacesAndAppendsAtCount(): void
    {
        $c = $this->devices('a', 'b');
        $c->set(1, new CollectionDevice('B'));
        $c->set(2, new CollectionDevice('c'));
        $c[0] = new CollectionDevice('A');
        self::assertSame(['0:A', '1:B', '2:c'], self::names($c));
    }

    public function testRemoveReindexes(): void
    {
        $c = $this->devices('a', 'b', 'c');
        $c->remove(0);
        self::assertSame('b', $c->get(0)->name);
        unset($c[0]);
        self::assertSame(['0:c'], self::names($c));
    }

    public function testClearAndIsEmpty(): void
    {
        $c = $this->devices('a');
        self::assertFalse($c->isEmpty());
        $c->clear();
        self::assertTrue($c->isEmpty());
        self::assertSame([], $c->all());
    }

    public function testConstructFromArray(): void
    {
        $c = $this->devices('a', 'b');
        self::assertSame(['a', 'b'], array_map(fn(CollectionDevice $d) => $d->name, $c->all()));
    }

    public function testWrongClassRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(CollectionDevices::class . ' holds ' . CollectionDevice::class . ', not ' . CollectionOther::class);
        (new CollectionDevices())->add(new CollectionOther());
    }

    public function testSubclassAccepted(): void
    {
        $c = new CollectionDevices();
        $c[] = new CollectionHeadset('h');
        self::assertSame(['0:h'], self::names($c));
    }

    public function testOutOfRange(): void
    {
        $c = $this->devices('a');
        foreach ([fn() => $c->get(5), fn() => $c->set(7, new CollectionDevice()), fn() => $c->remove(-1), fn() => $c[5]] as $i => $call) {
            try {
                $call();
                self::fail("call $i didn't throw");
            } catch (\OutOfRangeException $e) {
                self::assertStringContainsString('has no item', $e->getMessage());
            }
        }
    }

    public function testIssetOutOfRangeIsFalse(): void
    {
        $c = $this->devices('a');
        self::assertTrue(isset($c[0]));
        self::assertFalse(isset($c[1]));
        self::assertFalse(isset($c[-1]));
    }
}
