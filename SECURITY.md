# Security

RageKit is experimental and has had no security review. Don't build apps that handle untrusted
input or sensitive data with it yet without reviewing the code yourself.

## Reporting a vulnerability

Please report vulnerabilities privately through GitHub's
[private vulnerability reporting](https://github.com/hunwalk/ragekit/security/advisories/new)
rather than in a public issue. Include what's affected, how to reproduce it, and the impact you
expect. You'll get an answer as soon as possible; this is a small project, so please allow some
time for a fix.

## Scope

In scope: the `ragekit` command (it downloads and builds code), the native bridge and host,
modules in this repository, and the code generated into apps. Vulnerabilities in TypePHP, Slint,
PHP or other dependencies belong to those projects; tell us too if RageKit's use of them makes
things worse.
