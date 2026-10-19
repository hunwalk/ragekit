# Third-party software

RageKit's own code is licensed under the GNU General Public License v3 (see [LICENSE](LICENSE)).
It depends on the software below. None of it is stored in this repository: `ragekit setup`
installs the toolchain, and kits and modules fetch their third-party code on the first build
(each pinned to an exact version and checksum).

## Built into your app

What ends up in, or next to, an executable RageKit builds:

| Software | Used for | License |
|---|---|---|
| RageKit | its runtime: the core classes (`App`, `Screen`, …), the generated glue, the bridge library and the host | GPLv3 (see below) |
| [Slint](https://slint.dev) 1.18.1 | draws the UI (`libslint_cpp` next to the executable) | GPLv3 (how RageKit uses it); Slint also offers a Royalty-free and a commercial license |
| [PHPX](https://github.com/swoole/phpx) | the runtime layer TypePHP's generated C++ uses | Apache-2.0 |
| [PHP](https://www.php.net) | PHP's engine and standard library in normal builds (on Linux your system's `libphp` next to the executable, or a private PHP linked statically; DLLs on Windows) | PHP License 3.01 |
| [GMP](https://gmplib.org), [MPFR](https://www.mpfr.org) | big-number support PHPX uses (system libraries, linked dynamically in normal builds) | LGPLv3+ (GMP also GPLv2+) |
| [php-nano](https://github.com/swoole/php-nano) | the stripped-down PHP runtime compiled into Nano builds | BSD-3-Clause |
| [Slint Material](https://material.slint.dev) | the `material` kit's components | MIT |
| [miniaudio](https://miniaud.io) 0.11.22 | the `audio` module | public domain or MIT No Attribution |
| [libcurl](https://curl.se/libcurl/) (the system's, linked dynamically; not shipped) | the `http` module on Linux | curl license (MIT-style) |

**RageKit and your app.** RageKit's runtime is compiled into every app it builds, so an app you
distribute is a combined work with GPLv3 code: it must be distributed under the GPLv3 too
(including its source). Closed-source apps aren't possible with RageKit today. This may change in a
later version (for example with an exception for the runtime); it isn't decided.

**Slint and your app.** RageKit uses Slint under the GPLv3, which fits a GPLv3 app. Slint's own
license terms (including the attribution its Royalty-free License asks for) are at
[slint.dev/licensing](https://slint.dev/licensing).

`libslint_cpp` itself contains many open-source Rust libraries; Slint lists them with their
licenses in `licenses/THIRDPARTY.md` of its package (`.ragekit/slint/licenses/` after setup).
Ship those notices with your app, along with the licenses above for what your app contains.

## Used only while building

| Software | Used for | License |
|---|---|---|
| [TypePHP](https://github.com/swoole/typephp) | compiles PHP to C++ and machine code (installed by `ragekit setup`, with RageKit's patches from `toolchain/typephp/patches/`) | GPL-3.0-only |
| [Symfony Console, Yaml](https://symfony.com) | the `ragekit` command | MIT |
| a C++ compiler (GCC or MSVC) | compiling the generated C++ | your installation's |

TypePHP's license covers the compiler, not the programs it compiles. The code it links into
apps comes from PHPX (Apache-2.0) and PHP, listed above.
