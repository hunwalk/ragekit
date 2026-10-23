<?php

declare(strict_types=1);

namespace RageKit\Tests\Core;

use PHPUnit\Framework\TestCase;
use RageKit\RootStore;
use RageKit\Store;

final class TestSession extends Store
{
    public string $userName = '';

    public function signOut(): void
    {
        $this->userName = '';
        $this->emit('signedOut');
    }

    public function ping(): void
    {
        $this->emit('nobodyListens');
    }
}

final class TestStore extends RootStore
{
    public TestSession $session;

    public function __construct()
    {
        parent::__construct();
        $this->session = new TestSession();
    }
}

final class OtherStore extends RootStore
{
}

final class StoreTest extends TestCase
{
    public function testListenersRunInOrder(): void
    {
        $session = new TestSession();
        $session->userName = 'ann';
        $log = [];
        $session->on('signedOut', function () use (&$log, $session): void { $log[] = "first {$session->userName}"; });
        $session->on('signedOut', function () use (&$log): void { $log[] = 'second'; });
        $session->signOut();
        self::assertSame(['first ', 'second'], $log);
    }

    public function testEmitWithoutListenersIsFine(): void
    {
        (new TestSession())->ping();
        $this->addToAssertionCount(1);
    }

    public function testGetReturnsTheTypedRoot(): void
    {
        $store = new TestStore();
        self::assertSame($store, TestStore::get());
        self::assertSame($store->session, TestStore::get()->session);
        self::assertSame($store, RootStore::current());
    }

    public function testANewRootReplacesTheOld(): void
    {
        $first = new TestStore();
        $first->session->userName = 'ann';
        $second = new TestStore();
        self::assertSame($second, TestStore::get());
        self::assertSame('', TestStore::get()->session->userName);
    }

    public function testGetBeforeAnyRootIsAClearError(): void
    {
        new OtherStore();
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage("no TestStore yet: return new TestStore() from your App's createStore()");
        TestStore::get();
    }
}
