<?php

declare(strict_types=1);

namespace RageKit\Cli\Rageblade;

/**
 * One node of a Rageblade expression (the PHP subset templates may use). Kinds:
 * int, float, string, bool (value) · var (name) · const (NAME) · call (method name; children = args)
 * · context (screen method called on $context in a window template) · store ([store, member, isCall];
 * children = args) · not, neg (one child) · binary (operator;
 * two children) · ternary (condition, then, else) · array (items) · item (value, or key and value).
 */
final class Node
{
    /** @param list<Node|null> $children */
    public function __construct(
        public readonly string $kind,
        public readonly mixed $value = null,
        public readonly array $children = [],
    ) {
    }
}
